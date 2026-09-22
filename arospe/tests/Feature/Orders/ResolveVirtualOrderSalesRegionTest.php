<?php

use App\Actions\Orders\ResolveVirtualOrderSalesRegion;
use App\Exceptions\NoDefaultSalesRegionException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SalesRegion;
use Database\Seeders\SalesRegionSeeder;
use Illuminate\Support\Facades\Schema;

// Story 0054: action-level tests only -- the story ships no route, component or view.
// Money and rates are compared as decimal STRINGS, never floats (R-3).

/**
 * Build an order with one virtual line item, billed to the given address. The factory's test IP
 * resolves to Spain; pass `ip_derived_country` in `$attributes` to override it.
 *
 * @param  array<string, mixed>  $attributes
 */
function virtualOrderBilledTo(?string $country, ?string $postalCode = null, array $attributes = [], string $subtotal = '100.00'): Order
{
    $order = Order::factory()->create([
        'billing_country' => $country,
        'billing_postal_code' => $postalCode,
        ...$attributes,
    ]);

    OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->virtual()->create()->id]);

    $order->forceFill(['subtotal' => $subtotal, 'total' => bcadd($subtotal, (string) $order->shipping_amount, 2)])->save();

    return $order->fresh();
}

function resolveVirtualRegion(Order $order): Order
{
    app(ResolveVirtualOrderSalesRegion::class)($order);

    return $order->fresh();
}

function catalogRegion(string $slug): SalesRegion
{
    return SalesRegion::query()->where('slug', $slug)->firstOrFail();
}

// --- Schema & model ---

test('the geo check columns exist and are null unless supplied', function () {
    expect(Schema::hasColumns('orders', ['ip_address', 'ip_derived_country', 'flag_reason']))->toBeTrue();

    $order = Order::factory()->create(['ip_address' => null, 'ip_derived_country' => null])->fresh();

    expect($order->ip_address)->toBeNull()
        ->and($order->ip_derived_country)->toBeNull()
        ->and($order->flag_reason)->toBeNull();
});

test('ip_address, ip_derived_country and flag_reason cannot be mass-assigned', function () {
    $attributes = (new Order)->fill([
        'ip_address' => '1.2.3.4',
        'ip_derived_country' => 'FR',
        'flag_reason' => 'x',
    ])->getAttributes();

    expect($attributes)->not->toHaveKeys(['ip_address', 'ip_derived_country', 'flag_reason']);
});

// --- Billing-address mapping ---

test('a matching IP country resolves the billing region and rate without flagging', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveVirtualRegion(virtualOrderBilledTo('ES', '28001'));

    expect($order->sales_region_id)->toBe(catalogRegion('es-peninsula')->id)
        ->and($order->tax_rate)->toBe(catalogRegion('es-peninsula')->rate)
        ->and($order->flagged_for_review)->toBeFalse()
        ->and($order->flag_reason)->toBeNull();
});

test('a Spanish billing postal code resolves to its own fiscal territory', function (string $postalCode, string $slug) {
    $this->seed(SalesRegionSeeder::class);

    expect(resolveVirtualRegion(virtualOrderBilledTo('ES', $postalCode))->sales_region_id)
        ->toBe(catalogRegion($slug)->id);
})->with([
    'Baleares' => ['07001', 'es-baleares'],
    'Las Palmas' => ['35001', 'es-canarias'],
    'Ceuta' => ['51001', 'es-ceuta'],
    'Melilla' => ['52001', 'es-melilla'],
    'Peninsula' => ['08001', 'es-peninsula'],
]);

test('an unmapped billing country or an empty billing snapshot falls back to the default without flagging', function (?string $country, ?string $postalCode) {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveVirtualRegion(virtualOrderBilledTo($country, $postalCode, ['ip_derived_country' => $country ?? 'ES']));

    expect($order->sales_region_id)->toBe(catalogRegion(SalesRegionSeeder::DEFAULT_SLUG)->id)
        ->and($order->flagged_for_review)->toBeFalse();
})->with([
    'unmapped country' => ['ZZ', null],
    'empty snapshot' => [null, null],
]);

test('an inactive matching region falls back to the default', function () {
    SalesRegion::factory()->withRate('20.000')->create(['slug' => 'fr', 'is_active' => false]);
    $default = SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveVirtualRegion(virtualOrderBilledTo('FR', null, ['ip_derived_country' => 'FR']));

    expect($order->sales_region_id)->toBe($default->id);
});

test('resolution reads the order snapshot, not the customer live address', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = virtualOrderBilledTo('ES', '35001');
    $first = resolveVirtualRegion($order);

    $order->customer->forceFill(['billing_country' => 'FR', 'billing_postal_code' => '75001'])->save();

    expect(resolveVirtualRegion($first)->sales_region_id)->toBe(catalogRegion('es-canarias')->id);
});

// --- The geo/fraud check ---

test('a billing/IP country mismatch flags the order and resolves nothing', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveVirtualRegion(virtualOrderBilledTo('ES', '28001', ['ip_derived_country' => 'FR']));

    expect($order->flagged_for_review)->toBeTrue()
        ->and($order->flag_reason)->toBe(ResolveVirtualOrderSalesRegion::REASON_BILLING_IP_COUNTRY_MISMATCH)
        ->and($order->sales_region_id)->toBeNull()
        ->and($order->tax_rate)->toBeNull()
        ->and($order->tax_amount)->toBe('0.00');
});

test('a missing IP-derived country flags the order and resolves nothing', function (?string $country) {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveVirtualRegion(virtualOrderBilledTo('ES', '28001', ['ip_address' => '203.0.113.9', 'ip_derived_country' => $country]));

    expect($order->flagged_for_review)->toBeTrue()
        ->and($order->flag_reason)->toBe(ResolveVirtualOrderSalesRegion::REASON_IP_COUNTRY_MISSING)
        ->and($order->sales_region_id)->toBeNull()
        ->and($order->tax_rate)->toBeNull()
        ->and($order->tax_amount)->toBe('0.00');
})->with([null, '']);

test('the country comparison is case-insensitive', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveVirtualRegion(virtualOrderBilledTo('es', '28001', ['ip_derived_country' => 'ES']));

    expect($order->flagged_for_review)->toBeFalse()
        ->and($order->sales_region_id)->not->toBeNull();
});

test('an absent billing country is a data gap, not a mismatch', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveVirtualRegion(virtualOrderBilledTo(null, null, ['ip_derived_country' => 'FR']));

    expect($order->flagged_for_review)->toBeFalse()
        ->and($order->sales_region_id)->not->toBeNull();
});

test('a mixed basket is flagged and resolved by neither path', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = virtualOrderBilledTo('ES', '28001');
    OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->physical()->create()->id]);

    $order = resolveVirtualRegion($order);

    expect($order->flagged_for_review)->toBeTrue()
        ->and($order->flag_reason)->toBe(ResolveVirtualOrderSalesRegion::REASON_MIXED_BASKET)
        ->and($order->sales_region_id)->toBeNull();
});

// --- Rate and totals ---

test('the region rate is recorded and the total absorbs the tax', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create(['slug' => 'xx']);
    SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);

    $order = resolveVirtualRegion(virtualOrderBilledTo('FR', null, ['ip_derived_country' => 'FR']));

    expect($order->tax_rate)->toBe('21.000')
        ->and($order->tax_amount)->toBe('21.00')
        ->and($order->total)->toBe('121.00');
});

test('a null rate and a zero rate are stored differently', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create(['slug' => 'xx']);
    SalesRegion::factory()->create(['slug' => 'fr', 'rate' => null]);
    SalesRegion::factory()->withRate('0.000')->create(['slug' => 'de']);

    $unset = resolveVirtualRegion(virtualOrderBilledTo('FR', null, ['ip_derived_country' => 'FR']));
    $zero = resolveVirtualRegion(virtualOrderBilledTo('DE', null, ['ip_derived_country' => 'DE']));

    expect($unset->tax_rate)->toBeNull()
        ->and($unset->tax_amount)->toBe('0.00')
        ->and($zero->tax_rate)->toBe('0.000')
        ->and($zero->tax_amount)->toBe('0.00');
});

test('resolving twice is idempotent', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create(['slug' => 'xx']);
    SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);

    $once = resolveVirtualRegion(virtualOrderBilledTo('FR', null, ['ip_derived_country' => 'FR']));
    $twice = resolveVirtualRegion($once);

    expect($twice->total)->toBe('121.00')
        ->and($twice->tax_amount)->toBe('21.00')
        ->and($twice->sales_region_id)->toBe($once->sales_region_id);
});

// --- Refusals and atomicity ---

test('an all-physical order is refused and left untouched', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->physical()->create()->id]);

    expect(fn () => app(ResolveVirtualOrderSalesRegion::class)($order))->toThrow(InvalidArgumentException::class);

    $order = $order->fresh();

    expect($order->sales_region_id)->toBeNull()
        ->and($order->tax_amount)->toBe('0.00')
        ->and($order->flagged_for_review)->toBeFalse()
        ->and($order->flag_reason)->toBeNull();
});

test('a resolution that cannot find a default region leaves the order unchanged', function () {
    $order = virtualOrderBilledTo('ZZ', null, ['ip_derived_country' => 'ZZ']);

    expect(fn () => app(ResolveVirtualOrderSalesRegion::class)($order))->toThrow(NoDefaultSalesRegionException::class);

    $order = $order->fresh();

    expect($order->sales_region_id)->toBeNull()
        ->and($order->tax_rate)->toBeNull()
        ->and($order->tax_amount)->toBe('0.00')
        ->and($order->flagged_for_review)->toBeFalse()
        ->and($order->flag_reason)->toBeNull();
});
