<?php

use App\Models\ShippingRate;
use Illuminate\Support\Str;

// Story 0036, Phase 3 (TDD "red" step): App\Models\ShippingRate, its factory
// (database/factories/ShippingRateFactory.php) and the shipping_rates migration do not exist yet
// -- every test below is expected to fail (class/table not found) until
// database-expert/backend-expert implement them. That failure is the correct, intended "red"
// outcome.
//
// Companion to tests/Unit/Models/ShippingRateTest.php: the two checks below need a real
// persisted row (HasUuids populates the key on INSERT; a genuine decimal cast round trip needs a
// real write+read cycle), so they live here per RefreshDatabase's Feature/Browser-only scope
// (tests/Pest.php) -- mirroring tests/Feature/Models/ShippingZoneTest.php's own "a factory-created
// zone receives a uuidv7 string primary key" shape and
// tests/Feature/Shipping/ToggleShippingCarrierTest.php's identical carrier-side precedent.

test('a factory-created rate receives a uuidv7 string primary key', function () {
    $rate = ShippingRate::factory()->create();

    expect($rate->id)->toBeString()
        ->and(Str::isUuid($rate->id, 7))->toBeTrue();
});

test('two rates created in immediate succession sort lexicographically in creation order', function () {
    $first = ShippingRate::factory()->create();
    $second = ShippingRate::factory()->create();

    expect(Str::isUuid($first->id, 7))->toBeTrue()
        ->and(Str::isUuid($second->id, 7))->toBeTrue()
        ->and(strcmp((string) $first->id, (string) $second->id))->toBeLessThan(0);
});

// R-4: `decimal:2`/`decimal:3` round trip through a real INSERT + SELECT as strings, not floats
// -- the identical guard tests/Feature/Models/ProductVariantTest.php pins for its own price cast.
test('price and the weight brackets round trip through their declared casts on a real row', function () {
    $rate = ShippingRate::factory()->create([
        'price' => '19.99',
        'min_weight_kg' => '2',
        'max_weight_kg' => '5.5',
    ]);

    $fresh = $rate->fresh();

    expect($fresh->price)->toBeString()->and($fresh->price)->toBe('19.99')
        ->and((string) $fresh->min_weight_kg)->toBe('2.000')
        ->and((string) $fresh->max_weight_kg)->toBe('5.500');
});

// D-4: the open-ended state (max_weight_kg = null) must survive a real write+read cycle as a
// genuine NULL, never a stringified sentinel.
test('an open-ended rate persists a real null max_weight_kg through a full write/read cycle', function () {
    $rate = ShippingRate::factory()->openEnded()->create();

    expect($rate->fresh()->max_weight_kg)->toBeNull();
});

test('carrier() and zone() resolve to real, related models', function () {
    $rate = ShippingRate::factory()->create();

    expect($rate->carrier)->not->toBeNull()
        ->and($rate->carrier->id)->toBe($rate->shipping_carrier_id)
        ->and($rate->zone)->not->toBeNull()
        ->and($rate->zone->id)->toBe($rate->shipping_zone_id);
});
