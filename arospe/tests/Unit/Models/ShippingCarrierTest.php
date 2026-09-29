<?php

use App\Models\ShippingCarrier;

// Unit/Models tests in this repo never touch the database (Pest.php scopes
// RefreshDatabase to Feature/Browser only, matching SalesRegionTest.php's and
// UserTest.php's own shape) -- so `is_active` is exercised via setRawAttributes()
// rather than a real factory-created row. The "a factory-created carrier gets a
// real UUID id" check lives in tests/Feature/Shipping/ToggleShippingCarrierTest.php
// instead, where a persisted row already exists for other reasons.

test('the shipping carrier model reports a non-incrementing string key type', function () {
    $carrier = new ShippingCarrier;

    expect($carrier->getKeyType())->toBe('string')
        ->and($carrier->getIncrementing())->toBeFalse();
});

test('is_active casts to a real bool, not 0/1', function () {
    $carrier = new ShippingCarrier;
    $carrier->setRawAttributes(['is_active' => 1], true);

    expect($carrier->is_active)->toBeTrue()->toBeBool();

    $carrier->setRawAttributes(['is_active' => 0], true);

    expect($carrier->is_active)->toBeFalse()->toBeBool();
});

test('name and description are mass-assignable', function () {
    $carrier = new ShippingCarrier;

    $carrier->fill(['name' => 'MRW', 'description' => 'Entrega urgente']);

    expect($carrier->name)->toBe('MRW')
        ->and($carrier->description)->toBe('Entrega urgente');
});

test('is_active is not mass-assignable', function () {
    $carrier = new ShippingCarrier;

    $carrier->fill(['is_active' => false]);

    expect($carrier->isDirty('is_active'))->toBeFalse()
        ->and($carrier->is_active)->toBeNull();
});

// Phase 4 security-audit finding F-1 / Phase 5 code-review finding M2: `code` is
// deliberately NOT mass-assignable -- it is the seeder's idempotency key, and a
// mass-assigned edit would break re-seed idempotency by duplicating the row (proven
// by execution: a 4-row catalog became 5 on the next db:seed, with the resurrected
// row landing ACTIVE). Mirrors App\Models\SalesRegion's identical omission of `slug`.
test('code is not mass-assignable', function () {
    $carrier = new ShippingCarrier;

    $carrier->fill(['code' => 'INVENTED']);

    expect($carrier->isDirty('code'))->toBeFalse()
        ->and($carrier->code)->toBeNull();
});

test('the view and edit permission names are named once on the model', function () {
    expect(ShippingCarrier::VIEW_PERMISSION)->toBe('shipping.view')
        ->and(ShippingCarrier::EDIT_PERMISSION)->toBe('shipping.edit');
});
