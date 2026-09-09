<?php

use App\Actions\Shipping\CreateShippingRate;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): see CreateShippingRateTest.php's file banner -- the same
// applies here. Every test below is expected to fail (class/table not found) until
// database-expert/backend-expert implement the shipping_rates table, App\Models\ShippingRate,
// App\Actions\Shipping\CreateShippingRate and App\Concerns\ShippingRateValidationRules.
//
// The `invalid_rate_attributes` dataset defined below is DELIBERATELY reused by
// UpdateShippingRateTest.php via ->with('invalid_rate_attributes') rather than copy-pasted --
// Pest's dataset registry is populated when the whole suite is collected, so a dataset() call in
// one file is addressable by name from any other file in the same run. 0033's R-7 reasoning
// applies directly: a validation rule threaded through one call site and not the other fails
// silently in one direction only, and update is the direction nobody writes a bespoke test for.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['shipping.create', 'shipping.edit']);
    $this->actingAs($this->actor);
});

/**
 * Ten named cases driving the PRD's `Scenario Outline: An invalid shipping rate is rejected`
 * (the first seven) plus three additional hardening cases this story's own validation trait
 * requires (D-7's decimal:N,M rules, the price ceiling). Each entry is [buildAttributes,
 * expectedErrorKey] -- buildAttributes receives a real, existing carrier id and zone id so only
 * ONE field under test is ever invalid at a time.
 */
dataset('invalid_rate_attributes', function () {
    $baseline = fn (string $carrierId, string $zoneId): array => [
        'name' => 'Estándar',
        'shipping_carrier_id' => $carrierId,
        'shipping_zone_id' => $zoneId,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ];

    return [
        'min_greater_than_max' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'min_weight_kg' => '5',
                'max_weight_kg' => '2',
            ]),
            'max_weight_kg',
        ],
        'negative_price' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '-1.00',
            ]),
            'price',
        ],
        'negative_min_weight' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'min_weight_kg' => '-1',
            ]),
            'min_weight_kg',
        ],
        'blank_name' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'name' => '',
            ]),
            'name',
        ],
        'blank_delivery_estimate' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'delivery_estimate' => '',
            ]),
            'delivery_estimate',
        ],
        'unknown_carrier' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'shipping_carrier_id' => (string) Str::uuid7(),
            ]),
            'shipping_carrier_id',
        ],
        'unknown_zone' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'shipping_zone_id' => (string) Str::uuid7(),
            ]),
            'shipping_zone_id',
        ],
        // D-7's maxWeightRules()/priceRules() use 'decimal:0,N', never a bare 'numeric' --
        // 'numeric' happily accepts scientific notation ('1e2' == 100), which decimal:0,2's
        // pattern has no e/E branch for and therefore rejects.
        'price_scientific_notation' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '1e2',
            ]),
            'price',
        ],
        // decimal:0,3 caps precision at 3 places, matching decimal(8,3) -- without it, 2.0001
        // reaches MySQL and is silently truncated or errors depending on strict mode.
        'weight_over_precision' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'max_weight_kg' => '2.0001',
            ]),
            'max_weight_kg',
        ],
        // Bounded so a forged payload cannot overflow decimal(10,2) into a raw SQLSTATE 22003
        // (a 500) instead of a field-level message.
        'price_over_ceiling' => [
            fn (string $carrierId, string $zoneId) => array_merge($baseline($carrierId, $zoneId), [
                'price' => '100000000.00',
            ]),
            'price',
        ],
    ];
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
