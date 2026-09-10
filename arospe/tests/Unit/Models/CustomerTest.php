<?php

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer does not exist yet. Every test in
// this file is expected to fail (class not found) until backend-expert/database-expert implement
// it -- that is the correct, intended "red" outcome, per docs/workflow.md's TDD-first-step.
//
// Unit/Models tests in this repo never touch the database (Pest.php scopes RefreshDatabase to
// Feature/Browser only, matching SalesRegionTest.php's/UserTest.php's/ShippingCarrierTest.php's
// own shape) -- so the "produces a UUID id" check below calls HasUuids::newUniqueId() directly
// rather than persisting a factory-made row. The factory round-trip / persisted-id check lives in
// tests/Feature/Customers/PersistenceTest.php instead, where a real INSERT already happens for
// other reasons.
//
// "A customer is not a dashboard user" (D-11) is asserted here in its four STRUCTURAL forms --
// no HasRoles, no Authenticatable, no PasskeyUser, no SoftDeletes -- all class-shape checks with
// no DB involved. The one INTEGRATION half of that same acceptance criterion (a created customer
// leaves model_has_roles / model_has_permissions at zero rows) lives in
// tests/Feature/Customers/NotADashboardUserTest.php, since it requires a real persisted row.

use App\Models\Customer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Spatie\Permission\Traits\HasRoles;

test('the customer model reports a non-incrementing string key type', function () {
    $customer = new Customer;

    expect($customer->getKeyType())->toBe('string')
        ->and($customer->getIncrementing())->toBeFalse();
});

test('the customer model uses HasUuids and produces a 36-character UUID string id, never an integer', function () {
    $customer = new Customer;

    expect(class_uses_recursive(Customer::class))->toContain(HasUuids::class);

    // HasUuids::newUniqueId() is what the `creating` event calls to populate the primary key --
    // calling it directly here proves the id shape without persisting anything, matching this
    // folder's no-DB convention (see the file banner comment above).
    $id = $customer->newUniqueId();

    expect($id)->toBeString()
        ->and(strlen($id))->toBe(36)
        ->and(Str::isUuid($id))->toBeTrue()
        ->and(is_int($id))->toBeFalse();
});

// D-11 / the "not a dashboard user" acceptance criterion, structural half 1 of 3: a customer
// holds no dashboard role or permission, so it must not compose the package trait that grants a
// model the ability to hold either.
test('the customer model does not use Spatie HasRoles', function () {
    expect(class_uses_recursive(Customer::class))->not->toContain(HasRoles::class);
});

// D-11, structural half 2 of 3: a customer cannot authenticate into the panel, so it must not
// implement Laravel's Authenticatable contract (the interface every guard checks for) or extend
// the Authenticatable base class User itself extends.
test('the customer model does not implement Authenticatable and does not extend the Authenticatable base class', function () {
    $customer = new Customer;

    expect($customer)->not->toBeInstanceOf(Authenticatable::class)
        ->and(is_subclass_of(Customer::class, User::class))->toBeFalse();
});

// D-11, structural half 3 of 3: no passkey login either, and (per 0042's own hand-off note in
// this story's task file) no SoftDeletes yet -- that trait belongs to 0042's own migration. This
// assertion is DELETED, not worked around, the moment 0042 adds SoftDeletes to this model --
// see that story's task file for the explicit instruction to remove this half of the test.
test('the customer model does not implement PasskeyUser and does not use SoftDeletes', function () {
    $customer = new Customer;

    expect($customer)->not->toBeInstanceOf(PasskeyUser::class)
        ->and(class_uses_recursive(Customer::class))->not->toContain(SoftDeletes::class);
});
