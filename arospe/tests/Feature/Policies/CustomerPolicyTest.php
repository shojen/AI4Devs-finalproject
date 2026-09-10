<?php

// Story 0041 — App\Policies\CustomerPolicy, auto-discovered for App\Models\Customer by name alone
// (no provider registration; see conventions/base-standards.md). Modelled directly on
// SalesRegionPolicy's shape (flat, tier-free abilities delegating straight to hasPermissionTo(),
// no target-dependent branch on update()) -- see that class's own test file,
// tests/Feature/Policies/SalesRegionPolicyTest.php, for the precedent this one copies.
//
// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer, App\Policies\CustomerPolicy and the
// customers migration do not exist yet. Every test in this file is expected to fail (class/table
// not found) until backend-expert implements them -- that is the correct, intended "red" outcome.
//
// D-12: this story ships exactly THREE abilities -- viewAny, create, update. `delete()` is
// deliberately absent; 0042 adds it to this same file. The negative half of the method-existence
// test below is what keeps that a deliberate hand-off rather than a silent duplicate the day
// someone re-adds a delete() here by habit.

use App\Models\Customer;
use App\Models\User;
use App\Policies\CustomerPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// Method existence -- the structural pin that makes 0042's later addition of delete() a
// deliberate hand-off rather than a silent duplicate.
// =====================================================================

test('CustomerPolicy defines exactly viewAny, create and update, and does not define delete', function () {
    expect(method_exists(CustomerPolicy::class, 'viewAny'))->toBeTrue()
        ->and(method_exists(CustomerPolicy::class, 'create'))->toBeTrue()
        ->and(method_exists(CustomerPolicy::class, 'update'))->toBeTrue()
        ->and(method_exists(CustomerPolicy::class, 'delete'))->toBeFalse();
});

// =====================================================================
// viewAny
// =====================================================================

test('viewAny is allowed for an actor holding customers.view and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('customers.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', Customer::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', Customer::class))->toBeFalse();
});

// =====================================================================
// create
// =====================================================================

test('create is allowed for an actor holding customers.create and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('customers.create');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('create', Customer::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('create', Customer::class))->toBeFalse();
});

// =====================================================================
// update
// =====================================================================

test('update is allowed for an actor holding customers.edit and denied for one without it', function () {
    $target = Customer::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('customers.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $target))->toBeFalse();
});

// Narrowness -- holding only customers.view is not sufficient for update. Distinct from the bare
// "denied for one without it" case above: this actor holds a real, different permission on the
// SAME module, so a policy accidentally checking "any customers.* permission" would pass this
// actor incorrectly.
test('update is denied for an actor holding only customers.view', function () {
    $target = Customer::factory()->create();

    $viewOnlyActor = User::factory()->create();
    $viewOnlyActor->givePermissionTo('customers.view');

    expect(Gate::forUser($viewOnlyActor)->allows('update', $target))->toBeFalse();
});

// No target-dependent branch, no ownership, no per-row nuance (D-12) -- a customer holds no
// privilege tier and can never be the acting user, so update() must answer identically for two
// different customers, even ones created by two different administrators. Pins this so a later
// story cannot add ownership rules without a red test.
test('update answers identically for two different customers created by two different administrators, for the same actor', function () {
    $creatorOne = User::factory()->create();
    $creatorOne->givePermissionTo('customers.create');
    $customerOne = Customer::factory()->create();

    $creatorTwo = User::factory()->create();
    $creatorTwo->givePermissionTo('customers.create');
    $customerTwo = Customer::factory()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.edit');

    expect(Gate::forUser($actor)->allows('update', $customerOne))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('update', $customerTwo))->toBeTrue();
});

// =====================================================================
// Denial is enforced server-side, not merely hidden in the UI
// =====================================================================

test('authorize throws AuthorizationException when update is denied', function () {
    $actor = User::factory()->create(); // holds no permission at all
    $target = Customer::factory()->create();

    expect(fn () => Gate::forUser($actor)->authorize('update', $target))
        ->toThrow(AuthorizationException::class);
});

// =====================================================================
// Super Admin bypass -- Gate::before grants a Super Admin actor regardless of whether
// CustomerPolicy's own logic would allow it.
// =====================================================================

test('a Super Admin actor passes every CustomerPolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = Customer::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', Customer::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', Customer::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue();
});
