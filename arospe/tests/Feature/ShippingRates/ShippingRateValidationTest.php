<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): see CreateShippingRateTest.php's file banner -- the same
// applies here. Every test below is expected to fail (class/table not found) until
// database-expert/backend-expert implement the shipping_rates table, App\Models\ShippingRate,
// App\Actions\Shipping\CreateShippingRate and App\Concerns\ShippingRateValidationRules.
//
// Corrected at Phase 4 RE-audit (finding N-1): the `invalid_rate_attributes` dataset used to be
// defined INLINE, right here, with a comment claiming a bare dataset() call in one file is
// addressable by name from any other file in the same run. That claim was false -- reproduced as
// false by execution, in isolation, per-directory, AND across the full suite alike -- and it made
// UpdateShippingRateTest.php's own ->with('invalid_rate_attributes') silently fail to resolve as
// a top-level PHPUnit ERROR that never showed up in any per-test pass/fail count. The dataset now
// lives in this directory's own Datasets.php (Pest's first-class, actually-cross-file-scoped
// mechanism for exactly this) -- see that file's own banner comment for the full mechanism. 0033's
// R-7 reasoning is still why it is shared rather than copy-pasted: a validation rule threaded
// through one call site and not the other fails silently in one direction only, and update is the
// direction nobody writes a bespoke test for.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['shipping.create', 'shipping.edit']);
    $this->actingAs($this->actor);
});

test('an invalid shipping rate is rejected with a validation message, and no rate is created', function (Closure $buildAttributes, string $expectedErrorKey) {
    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $caught = null;

    try {
        app(CreateShippingRate::class)($buildAttributes($carrier->id, $zone->id));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey($expectedErrorKey);

    expect(ShippingRate::count())->toBe(0);
})->with('invalid_rate_attributes');

// D-3: a single-weight bracket is legal -- min == max must not be treated as an inverted range.
test('a minimum weight equal to the maximum is accepted', function () {
    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Exacto',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '2',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ]);

    expect($rate->fresh())->not->toBeNull();
    expect((string) $rate->fresh()->min_weight_kg)->toBe('2.000')
        ->and((string) $rate->fresh()->max_weight_kg)->toBe('2.000');
});

// D-4/D-7: 'nullable' must sit FIRST on maxWeightRules() and short-circuit -- the rule most
// likely to be written backwards (`lte` on the min field instead of `gte` on the max field),
// which fails only for open-ended rates. A null max_weight_kg with ANY min must be accepted, and
// gte:min_weight_kg must NOT fire against it.
test('a null max_weight_kg with any min_weight_kg is accepted, and gte:min_weight_kg does not fire', function () {
    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Y superior',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '999',
        'price' => '12.00',
        'delivery_estimate' => '24h',
    ]);

    expect($rate->fresh())->not->toBeNull();
    expect($rate->fresh()->max_weight_kg)->toBeNull();
});

test('a price of exactly 0 is accepted', function () {
    $carrier = ShippingCarrier::factory()->create();
    $zone = ShippingZone::factory()->create();

    $rate = app(CreateShippingRate::class)([
        'name' => 'Gratis',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '1',
        'price' => '0.00',
        'delivery_estimate' => '24h',
    ]);

    expect($rate->fresh()->price)->toBe('0.00');
});
