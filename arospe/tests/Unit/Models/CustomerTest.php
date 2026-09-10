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
// "A customer is not a dashboard user" (D-11) is asserted here in its three STRUCTURAL forms --
// no HasRoles, no Authenticatable, no PasskeyUser -- all class-shape checks with no DB involved.
// The one INTEGRATION half of that same acceptance criterion (a created customer leaves
// model_has_roles / model_has_permissions at zero rows) lives in
// tests/Feature/Customers/NotADashboardUserTest.php, since it requires a real persisted row.
//
// Story 0042 removed a fourth structural form this file used to carry here -- "does not use
// SoftDeletes" -- per this file's own original comment naming that removal as the explicit
// trigger the moment SoftDeletes lands on Customer, rather than leaving it to bit-rot into a
// false claim. See the new SoftDeletes test near the bottom of this file, and 0042's own D-2/D-3
// for why the trait is correct here and carries no authentication weight.

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

// D-11, structural half 3 of 3: no passkey login, ever -- unaffected by story 0042.
test('the customer model does not implement PasskeyUser', function () {
    $customer = new Customer;

    expect($customer)->not->toBeInstanceOf(PasskeyUser::class);
});

// Story 0042: Customer DOES use SoftDeletes now, with no delete() override -- the inverse of the
// assertion this test replaces. Deleting a customer is a data-visibility concern (it disappears
// from the active list, from counts, from route-model binding), never an authentication control
// like it is for `users` -- a customer cannot authenticate at all (D-11 above), so this trait
// carries no authentication weight the way it does on App\Models\User.
test('the customer model uses SoftDeletes and defines no delete() override', function () {
    // getDeclaringClass() rather than hasMethod() -- delete() is always "present" via inheritance
    // from Model/SoftDeletes; what must be false is Customer itself declaring one.
    $declaringClass = (new ReflectionClass(Customer::class))->getMethod('delete')->getDeclaringClass()->getName();

    expect(class_uses_recursive(Customer::class))->toContain(SoftDeletes::class)
        ->and($declaringClass)->not->toBe(Customer::class);
});
