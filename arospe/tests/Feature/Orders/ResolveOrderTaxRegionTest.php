<?php

use App\Actions\Orders\ResolveOrderTaxRegion;
use App\Concerns\ResolvesSalesRegionFromAddress;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SalesRegion;
use Database\Seeders\SalesRegionSeeder;

// Story 0053: action-level tests only -- the story ships no route, component or view.
// Money and rates are compared as decimal STRINGS, never floats (R-3).

/**
 * Build an order with one line item of the given product state, shipping to the given address.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderShippingTo(?string $country, ?string $postalCode = null, array $attributes = [], string $subtotal = '100.00'): Order
{
    $order = Order::factory()->create([
        'shipping_country' => $country,
        'shipping_postal_code' => $postalCode,
        ...$attributes,
    ]);

    OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->physical()->create()->id]);

    $order->forceFill(['subtotal' => $subtotal, 'total' => bcadd($subtotal, (string) $order->shipping_amount, 2)])->save();

    return $order->fresh();
}

function resolveTaxRegion(Order $order): Order
{
    app(ResolveOrderTaxRegion::class)($order);

    return $order->fresh();
}

function seededRegion(string $slug): SalesRegion
{
    return SalesRegion::query()->where('slug', $slug)->firstOrFail();
}

function seededRate(string $slug): string
{
    foreach (SalesRegionSeeder::SPAIN_TERRITORIES as $territory) {
        if ($territory['slug'] === $slug) {
            return $territory['rate'];
        }
    }

    throw new RuntimeException("Unknown territory {$slug}");
}

// --- Country -> region matching ---

test('a shipping country resolves to the activated, rated catalog row with that slug', function () {
    $fr = SalesRegion::factory()->withRate('20.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo('FR'));

    expect($order->sales_region_id)->toBe($fr->id)
        ->and($order->flagged_for_review)->toBeFalse();
});

test('the country match is case-insensitive on the order stored value', function (string $country) {
    $fr = SalesRegion::factory()->withRate('20.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    expect(resolveTaxRegion(orderShippingTo($country))->sales_region_id)->toBe($fr->id);
})->with(['FR', 'fr', 'Fr']);

test('tax_rate is snapshotted as the decimal string the region carries', function () {
    SalesRegion::factory()->withRate('20.500')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    expect((string) resolveTaxRegion(orderShippingTo('FR'))->tax_rate)->toBe('20.500');
});

// --- Spain's five fiscal territories ---

test('a Spanish postal prefix resolves to its fiscal territory at that territory own rate', function (string $postalCode, string $slug) {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveTaxRegion(orderShippingTo('ES', $postalCode));

    expect($order->sales_region_id)->toBe(seededRegion($slug)->id)
        ->and((string) $order->tax_rate)->toBe(seededRate($slug))
        ->and($order->flagged_for_review)->toBeFalse();
})->with([
    'Madrid' => ['28001', 'es-peninsula'],
    'Palma' => ['07001', 'es-baleares'],
    'Las Palmas' => ['35001', 'es-canarias'],
    'Santa Cruz de Tenerife' => ['38001', 'es-canarias'],
    'Ceuta' => ['51001', 'es-ceuta'],
    'Melilla' => ['52001', 'es-melilla'],
]);

test('a Spanish order never resolves to the Spain heading row', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveTaxRegion(orderShippingTo('ES', '28001'));

    expect($order->sales_region_id)->not->toBe(seededRegion(SalesRegionSeeder::SPAIN_SLUG)->id);
});

test('the postal prefix map keeps both Canary prefixes', function () {
    $map = ResolveOrderTaxRegion::SPAIN_POSTAL_PREFIX_TERRITORIES;

    expect($map[35])->toBe('es-canarias')
        ->and($map[38])->toBe('es-canarias');
});

test('the mapping lives on the shared trait the action composes', function () {
    expect(class_uses(ResolveOrderTaxRegion::class))->toContain(ResolvesSalesRegionFromAddress::class)
        ->and((new ReflectionClass(ResolvesSalesRegionFromAddress::class))->hasConstant('SPAIN_POSTAL_PREFIX_TERRITORIES'))->toBeTrue()
        ->and((new ReflectionClass(ResolveOrderTaxRegion::class))->getConstant('SPAIN_POSTAL_PREFIX_TERRITORIES'))->toBeArray();
});

test('a four-digit postal code with a lost leading zero falls back and flags instead of matching the Peninsula', function () {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveTaxRegion(orderShippingTo('ES', '7001'));

    expect($order->sales_region_id)->toBe(seededRegion(SalesRegionSeeder::DEFAULT_SLUG)->id)
        ->and($order->flagged_for_review)->toBeTrue();
});

// --- Fallback and flagging ---

test('an inactive matched region falls back to the default and flags, on all three columns', function () {
    $default = SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    SalesRegion::factory()->inactive()->withRate('5.000')->create(['slug' => 'fr']);

    $order = resolveTaxRegion(orderShippingTo('FR'));

    expect($order->sales_region_id)->toBe($default->id)
        ->and((string) $order->tax_rate)->toBe('21.000')
        ->and($order->flagged_for_review)->toBeTrue();
});

test('a country absent from the catalog falls back and flags', function () {
    $default = SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo('ZZ'));

    expect($order->sales_region_id)->toBe($default->id)
        ->and($order->flagged_for_review)->toBeTrue();
});

test('a null shipping country falls back and flags rather than throwing', function () {
    $default = SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo(null));

    expect($order->sales_region_id)->toBe($default->id)
        ->and($order->flagged_for_review)->toBeTrue();
});

test('a Spanish order with a missing or unmapped postal code falls back and flags', function (?string $postalCode) {
    $this->seed(SalesRegionSeeder::class);

    $order = resolveTaxRegion(orderShippingTo('ES', $postalCode));

    expect($order->sales_region_id)->toBe(seededRegion(SalesRegionSeeder::DEFAULT_SLUG)->id)
        ->and($order->flagged_for_review)->toBeTrue();
})->with([null, '99999', '00000', '53001']);

test('the fallback target is the row carrying is_default, not the seeder default slug', function () {
    $this->seed(SalesRegionSeeder::class);

    $fresh = resolveTaxRegion(orderShippingTo('ZZ'));
    expect(SalesRegion::query()->findOrFail($fresh->sales_region_id)->slug)->toBe(SalesRegionSeeder::DEFAULT_SLUG);

    // An administrator moves the default: the resolution must move with it.
    SalesRegion::query()->update(['is_default' => false]);
    $moved = seededRegion('es-canarias');
    $moved->forceFill(['is_default' => true])->save();

    expect(resolveTaxRegion(orderShippingTo('ZZ'))->sales_region_id)->toBe($moved->id);
});

test('a catalog with no default row makes the action throw rather than write a null region', function () {
    $order = orderShippingTo('ZZ');

    expect(fn () => app(ResolveOrderTaxRegion::class)($order))->toThrow(RuntimeException::class);
    expect($order->fresh()->sales_region_id)->toBeNull();
});

test('an active region with no configured rate records the region, keeps tax_rate null and flags', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $fr = SalesRegion::factory()->create(['slug' => 'fr', 'rate' => null]);

    $order = resolveTaxRegion(orderShippingTo('FR'));

    expect($order->sales_region_id)->toBe($fr->id)
        ->and($order->tax_rate)->toBeNull()
        ->and($order->flagged_for_review)->toBeTrue();
});

test('a region rated 0.000 is a real rate: no fallback and no flag', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $fr = SalesRegion::factory()->withRate('0.000')->create(['slug' => 'fr']);

    $order = resolveTaxRegion(orderShippingTo('FR'));

    expect($order->sales_region_id)->toBe($fr->id)
        ->and((string) $order->tax_rate)->toBe('0.000')
        ->and((string) $order->tax_amount)->toBe('0.00')
        ->and($order->flagged_for_review)->toBeFalse();
});

// --- The order's own address decides ---

test('a later change to the customer address does not change a resolved order', function () {
    $this->seed(SalesRegionSeeder::class);
    $customer = Customer::factory()->create(['shipping_country' => 'ES', 'shipping_postal_code' => '28001']);
    $order = orderShippingTo('ES', '28001', ['customer_id' => $customer->id]);

    $resolved = resolveTaxRegion($order);
    $customer->forceFill(['shipping_postal_code' => '35001'])->save();

    expect($order->fresh()->sales_region_id)->toBe($resolved->sales_region_id)
        ->and($resolved->sales_region_id)->toBe(seededRegion('es-peninsula')->id);
});

test('a later change to the region rate does not move a resolved order', function () {
    $fr = SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo('FR'));
    $fr->forceFill(['rate' => '10.000'])->save();

    expect((string) $order->fresh()->tax_rate)->toBe('21.000');
});

// --- Product type ---

test('an order whose line items are all physical is resolved', function () {
    $fr = SalesRegion::factory()->withRate('20.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $order = orderShippingTo('FR');
    OrderItem::factory()->count(2)->for($order)->create(['product_id' => Product::factory()->physical()->create()->id]);

    expect(resolveTaxRegion($order)->sales_region_id)->toBe($fr->id);
});

test('an all-virtual order is left completely untouched, the flag included', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $order = Order::factory()->create(['shipping_country' => 'ES', 'tax_amount' => '3.00', 'total' => '103.00', 'subtotal' => '100.00']);
    OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->virtual()->create()->id]);
    $before = $order->fresh()->getAttributes();

    $after = resolveTaxRegion($order);

    expect($after->getAttributes())->toBe($before)
        ->and($after->flagged_for_review)->toBeFalse();
});

test('a mixed order writes no region, rate, tax amount or total and flags', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $order = orderShippingTo('ES', '28001');
    OrderItem::factory()->for($order)->create(['product_id' => Product::factory()->virtual()->create()->id]);
    $before = $order->fresh();

    $after = resolveTaxRegion($order);

    expect($after->sales_region_id)->toBeNull()
        ->and($after->tax_rate)->toBeNull()
        ->and((string) $after->tax_amount)->toBe((string) $before->tax_amount)
        ->and((string) $after->total)->toBe((string) $before->total)
        ->and($after->flagged_for_review)->toBeTrue();
});

test('an order with a line item whose product was deleted is unresolvable: flagged, nothing else written', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $order = orderShippingTo('ES', '28001');
    $order->items()->firstOrFail()->forceFill(['product_id' => null])->save();

    $after = resolveTaxRegion($order->fresh());

    expect($after->sales_region_id)->toBeNull()
        ->and($after->tax_rate)->toBeNull()
        ->and($after->flagged_for_review)->toBeTrue();
});

// --- Idempotency ---

test('calling the action twice leaves all five written columns identical', function () {
    $this->seed(SalesRegionSeeder::class);
    $order = orderShippingTo('ES', '35001');

    $first = resolveTaxRegion($order);
    $second = resolveTaxRegion($first);

    foreach (['sales_region_id', 'tax_rate', 'tax_amount', 'total', 'flagged_for_review'] as $column) {
        expect($second->{$column})->toEqual($first->{$column});
    }
});

test('an order already resolved to another region is not overwritten', function () {
    $this->seed(SalesRegionSeeder::class);
    $order = orderShippingTo('ES', '28001', ['sales_region_id' => seededRegion('es-ceuta')->id]);

    expect(resolveTaxRegion($order)->sales_region_id)->toBe(seededRegion('es-ceuta')->id);
});

// --- The computed tax amount and total (D-13) ---

test('the total absorbs the tax amount computed from the rate as a percentage', function () {
    SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo('FR', null, [], '100.00'));

    expect((string) $order->tax_amount)->toBe('21.00')
        ->and((string) $order->total)->toBe('121.00');
});

test('a null rate and a 0.000 rate share a zero tax amount but stay distinguishable', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    SalesRegion::factory()->create(['slug' => 'fr', 'rate' => null]);
    SalesRegion::factory()->withRate('0.000')->create(['slug' => 'de']);

    $unrated = resolveTaxRegion(orderShippingTo('FR'));
    $zero = resolveTaxRegion(orderShippingTo('DE'));

    expect($unrated->tax_rate)->toBeNull()
        ->and((string) $unrated->tax_amount)->toBe('0.00')
        ->and((string) $unrated->total)->toBe('100.00')
        ->and($unrated->flagged_for_review)->toBeTrue()
        ->and((string) $zero->tax_rate)->toBe('0.000')
        ->and((string) $zero->tax_amount)->toBe('0.00')
        ->and($zero->flagged_for_review)->toBeFalse();
});

test('total is the full three-term identity when shipping_amount is non-zero', function () {
    SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo('FR', null, ['shipping_amount' => '5.50'], '100.00'));

    expect((string) $order->tax_amount)->toBe('21.00')
        ->and((string) $order->total)->toBe('126.50')
        ->and((string) $order->shipping_amount)->toBe('5.50');
});

test('the tax amount is rounded half-up to two decimals', function () {
    SalesRegion::factory()->withRate('7.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    // 10.75 x 7% = 0.7525 -> 0.75 ; 10.79 x 7% = 0.7553 -> 0.76
    expect((string) resolveTaxRegion(orderShippingTo('FR', null, [], '10.75'))->tax_amount)->toBe('0.75')
        ->and((string) resolveTaxRegion(orderShippingTo('FR', null, [], '10.79'))->tax_amount)->toBe('0.76');
});

test('the arithmetic recomputes from subtotal and never accumulates across two runs', function () {
    SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $first = resolveTaxRegion(orderShippingTo('FR', null, [], '100.00'));
    // Clear the idempotency guard so the second run genuinely reaches the arithmetic.
    $first->forceFill(['sales_region_id' => null])->save();
    $second = resolveTaxRegion($first);

    expect((string) $second->tax_amount)->toBe('21.00')
        ->and((string) $second->total)->toBe('121.00');
});

test('a fallback resolution is taxed at the default rate and still flagged', function () {
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();

    $order = resolveTaxRegion(orderShippingTo('ZZ', null, [], '100.00'));

    expect((string) $order->tax_rate)->toBe('21.000')
        ->and((string) $order->tax_amount)->toBe('21.00')
        ->and((string) $order->total)->toBe('121.00')
        ->and($order->flagged_for_review)->toBeTrue();
});

// --- Nothing else is written ---

test('subtotal, shipping_amount and both statuses are never written', function () {
    SalesRegion::factory()->withRate('21.000')->create(['slug' => 'fr']);
    SalesRegion::factory()->isDefault()->withRate('21.000')->create();
    $order = orderShippingTo('FR', null, ['shipping_amount' => '5.50'], '100.00');

    $after = resolveTaxRegion($order);

    expect((string) $after->subtotal)->toBe('100.00')
        ->and((string) $after->shipping_amount)->toBe('5.50')
        ->and($after->status)->toBe(OrderStatus::Pending)
        ->and($after->payment_status)->toBe(PaymentStatus::PendingPayment);
});

test('none of the five resolved columns is mass-assignable', function () {
    $fillable = (new Order)->getFillable();

    foreach (['sales_region_id', 'tax_rate', 'tax_amount', 'total', 'flagged_for_review'] as $column) {
        expect($fillable)->not->toContain($column);
    }
});
