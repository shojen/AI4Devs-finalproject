<?php

use App\Models\ShippingRate;
use Illuminate\Database\Eloquent\SoftDeletes;

// Story 0036, Phase 3 (TDD "red" step): App\Models\ShippingRate does not exist yet -- every test
// below is expected to fail (class not found) until database-expert/backend-expert implement it.
// That failure is the correct, intended "red" outcome.
//
// Unit/Models tests in this repo never touch the database (Pest.php scopes RefreshDatabase to
// Feature/Browser only, matching tests/Unit/Models/ShippingCarrierTest.php's and
// tests/Unit/Models/SalesRegionTest.php's own shape), so every assertion below is exercised via
// setRawAttributes() on a plain `new ShippingRate` rather than a real factory-created row.
//
// A FACTORY-CREATED row DOES need the database (HasUuids populates the key on insert, and a
// real decimal:2/decimal:3 round trip needs a real write+read cycle) -- those two checks
// therefore live in tests/Feature/Models/ShippingRateTest.php instead, following
// tests/Unit/Models/ShippingCarrierTest.php's OWN precedent, which defers its identical
// "a factory-created carrier gets a real UUID id" check to
// tests/Feature/Shipping/ToggleShippingCarrierTest.php for the exact same reason. This is a
// deliberate split from this story's own task-file listing (which named a single
// tests/Unit/Models/ShippingRateTest.php file), following the house convention this repo already
// establishes twice rather than the literal file list -- see this session's own report to
// backend-expert.

// D-7/R-4: `decimal:2`/`decimal:3` casts return STRINGS, not floats -- a value-only assertion
// passes against either cast and lets the drift ship.
test('price casts to a two-decimal string, and the weights cast to three-decimal strings', function () {
    $rate = new ShippingRate;
    $rate->setRawAttributes([
        'price' => '4.95',
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
    ], true);

    expect($rate->price)->toBeString()->and($rate->price)->toBe('4.95')
        ->and($rate->min_weight_kg)->toBeString()->and((string) $rate->min_weight_kg)->toBe('0.000')
        ->and($rate->max_weight_kg)->toBeString()->and((string) $rate->max_weight_kg)->toBe('2.000');
});

// Guards against a future column being added to #[Fillable] by reflex -- only the seven columns
// the story's own spec names may be mass-assigned.
test('only the documented fillable attributes are mass-assignable', function () {
    expect((new ShippingRate)->getFillable())->toBe([
        'name',
        'shipping_carrier_id',
        'shipping_zone_id',
        'min_weight_kg',
        'max_weight_kg',
        'price',
        'delivery_estimate',
    ]);
});

// D-14: not a preference. The zone-delete guard's count means what it says only because a
// deleted rate is really gone -- adding SoftDeletes later would silently make the count exclude
// trashed rates with no edit to the guard, per 0024 D-12's recorded trap.
test('the model does not use SoftDeletes', function () {
    expect(class_uses_recursive(ShippingRate::class))
        ->not->toHaveKey(SoftDeletes::class);
});
