<?php

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\CalculateTaxAmount;
use App\Actions\Orders\RecalculateOrderTotals;
use App\Actions\Orders\ResolveOrderTaxRegion;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SalesRegion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0053a: `tax_rate` is a percentage. Every amount is asserted as a literal decimal STRING,
// never re-derived with the implementation's own formula -- restating the formula in the test is
// exactly how the missing `/ 100` shipped unnoticed in story 0048.

test('the tax amount is the percentage of the subtotal, rounded half-up to two decimals', function (string $subtotal, ?string $rate, string $expected) {
    expect(app(CalculateTaxAmount::class)($subtotal, $rate))->toBe($expected);
})->with([
    'whole percentage' => ['100.00', '21.000', '21.00'],
    'fractional percentage' => ['100.00', '7.500', '7.50'],
    'rounds down below half a cent' => ['10.75', '7.000', '0.75'],
    'rounds up at or above half a cent' => ['10.79', '7.000', '0.76'],
    'a rate of zero is a real rate' => ['100.00', '0.000', '0.00'],
    'a null rate invents no tax' => ['100.00', null, '0.00'],
    'a zero subtotal' => ['0.00', '21.000', '0.00'],
]);

/** Build a resolvable physical order with one line of the given price and quantity one. */
function physicalOrderWithLine(string $price, string $country, string $rate): Order
{
    if (SalesRegion::query()->where('is_default', true)->doesntExist()) {
        SalesRegion::factory()->isDefault()->withRate($rate)->create();
    }
    SalesRegion::factory()->withRate($rate)->create(['slug' => strtolower($country)]);

    $order = Order::factory()->create(['shipping_country' => $country]);
    OrderItem::factory()->for($order)->create([
        'product_id' => Product::factory()->physical()->create(['price' => $price])->id,
        'quantity' => 1,
    ]);
    $order->forceFill(['subtotal' => $price, 'total' => $price])->save();

    return $order->fresh();
}

test('resolution and recalculation agree on tax amount and total for the same subtotal and rate', function (string $price, string $rate) {
    $resolved = physicalOrderWithLine($price, 'FR', $rate);
    app(ResolveOrderTaxRegion::class)($resolved);

    $recalculated = Order::factory()->create(['tax_rate' => $rate]);
    OrderItem::factory()->for($recalculated)->create([
        'product_id' => Product::factory()->create(['price' => $price])->id,
        'quantity' => 1,
    ]);
    app(RecalculateOrderTotals::class)($recalculated);

    expect((string) $resolved->fresh()->tax_amount)->toBe((string) $recalculated->fresh()->tax_amount)
        ->and((string) $resolved->fresh()->total)->toBe((string) $recalculated->fresh()->total);
})->with([
    ['100.00', '21.000'],
    ['10.79', '7.000'],
    ['10.75', '7.000'],
    ['33.33', '10.000'],
    ['19.99', '4.500'],
]);

test('editing a resolved order keeps the tax at the region percentage instead of multiplying by the raw rate', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);

    $order = physicalOrderWithLine('100.00', 'FR', '21.000');
    app(ResolveOrderTaxRegion::class)($order);

    $resolved = $order->fresh();
    expect((string) $resolved->tax_amount)->toBe('21.00')
        ->and((string) $resolved->total)->toBe('121.00');

    app(AddOrderItem::class)($resolved, Product::factory()->physical()->create(['price' => '100.00'])->id, null, 1);

    $after = $order->fresh();

    expect((string) $after->subtotal)->toBe('200.00')
        ->and((string) $after->tax_amount)->toBe('42.00')
        ->and((string) $after->total)->toBe('242.00');
});
