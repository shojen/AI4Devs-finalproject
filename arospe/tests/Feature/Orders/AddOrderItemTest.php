<?php

use App\Actions\Orders\AddOrderItem;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0048, Phase 3 (TDD "red" step): App\Actions\Orders\AddOrderItem does not exist yet -- every
// test below is expected to fail with "Target class [App\Actions\Orders\AddOrderItem] does not
// exist" until backend-expert implements it. That failure is the correct, intended "red" outcome.
//
// Signature assumed throughout, per the task file's own "Files to create" section:
// __invoke(Order $order, string $productId, ?string $productVariantId, int $quantity): OrderItem.
// This story ships no route and no Livewire component, so every test here is action-level.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderEditor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    return $actor;
}

// --- Recalculation, both open statuses (R-3's mitigation) ---

test('adding a line item persists the new row and the parent subtotal equals the sum of all line totals', function (OrderStatus $status) {
    actingOrderEditor();

    $order = Order::factory()->create(['status' => $status]);
    $existingProduct = Product::factory()->create(['price' => '10.00']);
    OrderItem::factory()->for($order)->create(['product_id' => $existingProduct->id, 'quantity' => 1]);
    $order->forceFill(['subtotal' => '10.00', 'total' => '10.00'])->save();

    $newProduct = Product::factory()->create(['price' => '25.00']);

    app(AddOrderItem::class)($order, $newProduct->id, null, 2);

    $fresh = $order->fresh();
    $expectedSubtotal = $fresh->items()->get()->reduce(
        fn (string $carry, OrderItem $item): string => bcadd($carry, $item->line_total, 2),
        '0.00'
    );

    expect($order->items()->count())->toBe(2)
        ->and((string) $fresh->subtotal)->toBe($expectedSubtotal);
})->with('open_order_statuses');

// Identity, not a bare equality with subtotal alone (0045 D-8) -- stays true once 0053/0054/0037
// populate tax_amount/shipping_amount.
test('total equals subtotal plus tax_amount plus shipping_amount after an add', function (OrderStatus $status) {
    actingOrderEditor();

    $order = Order::factory()->create(['status' => $status]);
    $product = Product::factory()->create(['price' => '10.00']);

    app(AddOrderItem::class)($order, $product->id, null, 1);

    $fresh = $order->fresh();
    $expectedTotal = bcadd(bcadd($fresh->subtotal, $fresh->tax_amount, 2), $fresh->shipping_amount, 2);

    expect((string) $fresh->total)->toBe($expectedTotal);
})->with('open_order_statuses');

// --- The price snapshot: the highest-risk case ---

test('a newly added line item snapshots the product current price, name and sku at add-time', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $product = Product::factory()->create(['name' => 'Widget', 'sku' => 'WID-001', 'price' => '25.00']);

    $item = app(AddOrderItem::class)($order, $product->id, null, 1);

    expect((string) $item->unit_price)->toBe('25.00')
        ->and($item->product_name)->toBe('Widget')
        ->and($item->product_sku)->toBe('WID-001')
        ->and((string) $item->line_total)->toBe('25.00');
});

// Without this, an implementation that never resolves the catalog at all would still pass the
// regression test below -- this is the positive half.
test('a newly added line item snapshot is immutable once the product price later changes', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $product = Product::factory()->create(['price' => '25.00']);

    $item = app(AddOrderItem::class)($order, $product->id, null, 1);

    $product->update(['price' => '40.00']);

    $fresh = $item->fresh();

    expect((string) $fresh->unit_price)->toBe('25.00')
        ->and((string) $fresh->line_total)->toBe('25.00');
});

// Nothing in the implementation should touch a pre-existing item -- proves a recalculation loop
// iterating every item did not helpfully "update" them all.
test('adding a line item does not re-price any of the orders pre-existing line items', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $existingProduct = Product::factory()->create(['price' => '10.00']);
    $existingItem = OrderItem::factory()->for($order)->create([
        'product_id' => $existingProduct->id,
        'quantity' => 1,
    ]);
    $originalUnitPrice = (string) $existingItem->fresh()->unit_price;
    $originalLineTotal = (string) $existingItem->fresh()->line_total;

    $newProduct = Product::factory()->create(['price' => '99.00']);
    app(AddOrderItem::class)($order, $newProduct->id, null, 1);

    $freshExisting = $existingItem->fresh();

    expect((string) $freshExisting->unit_price)->toBe($originalUnitPrice)
        ->and((string) $freshExisting->line_total)->toBe($originalLineTotal);
});

test('a line item naming a variant snapshots the variant own price and sku, not the parent products', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $product = Product::factory()->create(['name' => 'Shirt', 'sku' => 'SHIRT-BASE', 'price' => '15.00']);
    $variant = ProductVariant::factory()->for($product)->create(['sku' => 'SHIRT-RED-M', 'price' => '18.50']);

    $item = app(AddOrderItem::class)($order, $product->id, $variant->id, 1);

    expect((string) $item->unit_price)->toBe('18.50')
        ->and($item->product_sku)->toBe('SHIRT-RED-M')
        ->and($item->product_name)->toBe('Shirt')
        ->and($item->product_variant_id)->toBe($variant->id);
});

// --- Rejecting an invalid add ---

test('adding a line item for an unknown product is rejected, and no row is stored', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $unknownProductId = (string) Str::uuid7();

    expect(fn () => app(AddOrderItem::class)($order, $unknownProductId, null, 1))
        ->toThrow(ValidationException::class);

    expect($order->items()->count())->toBe(0);
});

test('adding a line item naming a variant that belongs to a different product is rejected', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $variantOfOtherProduct = ProductVariant::factory()->for($otherProduct)->create();

    expect(fn () => app(AddOrderItem::class)($order, $product->id, $variantOfOtherProduct->id, 1))
        ->toThrow(ValidationException::class);

    expect($order->items()->count())->toBe(0);
});

test('adding a line item with a non-positive quantity is rejected, and no row is stored', function (int $invalidQuantity) {
    actingOrderEditor();

    $order = Order::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, $invalidQuantity))
        ->toThrow(ValidationException::class);

    expect($order->items()->count())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

// AddOrderItem's own signature types $quantity as `int`, so a genuinely non-numeric value cannot
// reach the trait's own validation the way a raw HTTP/array payload could (that boundary is
// story 0055's) -- but a garbage value must still never create a row, regardless of which
// exception class the language's own type system produces at this action-level boundary.
test('adding a line item with a garbage quantity value never stores a row', function () {
    actingOrderEditor();

    $order = Order::factory()->create();
    $product = Product::factory()->create();

    try {
        app(AddOrderItem::class)($order, $product->id, null, /** @phpstan-ignore-next-line */ 'abc');
    } catch (Throwable $e) {
        // Either a ValidationException (if the action itself coerces/validates the raw value) or a
        // TypeError (PHP's own coercive-typing boundary on a non-numeric string) is acceptable --
        // what must never happen is a row surviving to be counted below.
    }

    expect($order->items()->count())->toBe(0);
});

// --- Tax recalculation coupling (D-8) ---

test('adding a line item recomputes tax_amount from the new subtotal when a rate is already resolved', function () {
    actingOrderEditor();

    $order = Order::factory()->create(['tax_rate' => '0.210']);
    $product = Product::factory()->create(['price' => '20.00']);

    app(AddOrderItem::class)($order, $product->id, null, 1);

    $fresh = $order->fresh();

    expect((string) $fresh->tax_amount)->toBe(bcmul((string) $fresh->subtotal, '0.210', 2));
});

// The negative half is the point (R-2): this story must not resolve a sales region.
test('adding a line item to an order with no resolved tax rate leaves tax_amount at zero and resolves no sales region', function () {
    actingOrderEditor();

    $order = Order::factory()->create(['tax_rate' => null, 'sales_region_id' => null]);
    $product = Product::factory()->create();

    app(AddOrderItem::class)($order, $product->id, null, 1);

    $fresh = $order->fresh();

    expect((string) $fresh->tax_amount)->toBe('0.00')
        ->and($fresh->sales_region_id)->toBeNull();
});

test('adding a line item never writes tax_rate itself', function () {
    actingOrderEditor();

    $order = Order::factory()->create(['tax_rate' => '0.100']);
    $product = Product::factory()->create();

    app(AddOrderItem::class)($order, $product->id, null, 1);

    expect((string) $order->fresh()->tax_rate)->toBe('0.100');
});
