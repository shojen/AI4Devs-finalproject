<?php

// Story 0041 — App\Policies\CustomerPolicy, auto-discovered for App\Models\Customer by name alone
// (no provider registration; see conventions/base-standards.md). Modelled directly on
// SalesRegionPolicy's shape (flat, tier-free abilities delegating straight to hasPermissionTo(),
// no target-dependent branch on update()) -- see that class's own test file,
// tests/Feature/Policies/SalesRegionPolicyTest.php, for the precedent this one copies.
//
// Story 0042 adds a fourth ability, delete() -- a flat permission check with no privilege-tier
// logic (D-3): any holder of customers.delete may delete any customer. The method-existence test
// below is updated in place (not worked around) to assert delete() now exists, per this story's
// own hand-off note in 0041's D-12.

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
// Method existence -- the structural pin that catches an ability silently going missing.
// =====================================================================

test('CustomerPolicy defines viewAny, create, update and delete', function () {
    expect(method_exists(CustomerPolicy::class, 'viewAny'))->toBeTrue()
        ->and(method_exists(CustomerPolicy::class, 'create'))->toBeTrue()
        ->and(method_exists(CustomerPolicy::class, 'update'))->toBeTrue()
        ->and(method_exists(CustomerPolicy::class, 'delete'))->toBeTrue();
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
// delete (story 0042)
// =====================================================================

test('delete is allowed for an actor holding customers.delete and denied for one without it', function () {
    $target = Customer::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('customers.delete');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('delete', $target))->toBeFalse();
});

// Narrowness -- this is the case that catches delete() checking the wrong permission string, a
// plausible copy-paste slip when adding a fourth method beside three near-identical siblings.
test('delete is denied for an actor holding every other customers permission but not customers.delete', function () {
    $target = Customer::factory()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['customers.view', 'customers.create', 'customers.edit']);

    expect(Gate::forUser($actor)->allows('delete', $target))->toBeFalse();
});

// No target-dependent branch, no ownership, no per-row nuance (D-3) -- any holder may delete any
// customer, even one created by a different administrator. Pins this so a later story cannot
// quietly add ownership semantics without a failing test to justify it.
test('delete answers identically for two different customers created by two different administrators, for the same actor', function () {
    $creatorOne = User::factory()->create();
    $creatorOne->givePermissionTo('customers.create');
    $customerOne = Customer::factory()->create();

    $creatorTwo = User::factory()->create();
    $creatorTwo->givePermissionTo('customers.create');
    $customerTwo = Customer::factory()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.delete');

    expect(Gate::forUser($actor)->allows('delete', $customerOne))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('delete', $customerTwo))->toBeTrue();
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
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});
