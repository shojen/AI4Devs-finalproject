<?php

// Story 0042 — CustomerPolicy::delete(). This story ships NO route and NO Livewire component
// (see the task's own "Resolved questions" table: the delete CODE PATH belongs to 0044), so every
// assertion here goes through the Gate directly against the model instance -- the same
// action-level-not-HTTP-level shape tests/Feature/Customers/AuthorizationTest.php already
// establishes for create/update, per docs/testing/README.md's "an authorization test at the
// action layer and an HTTP one are not substitutes" rule.
//
// "Deleting" in this story is `Gate::authorize('delete', $customer)` followed by
// `$customer->delete()` -- there is no DeleteCustomer action class (D-2: a bare `use SoftDeletes;`
// is the whole implementation, so there is nothing for an action to wrap).

use App\Models\Customer;
use App\Models\User;
use App\Policies\CustomerPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// Happy path
// =====================================================================

test('an actor holding customers.delete deletes a customer successfully', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.delete');
    $this->actingAs($actor);

    $customer = Customer::factory()->create();

    Gate::authorize('delete', $customer);
    $customer->delete();

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

// =====================================================================
// Refusal — both halves asserted, since asserting only that an exception was thrown would pass
// against an implementation that deletes first and authorizes second.
// =====================================================================

test('an actor with no role is refused, and the customer is still active afterwards', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $customer = Customer::factory()->create();

    expect(fn () => Gate::authorize('delete', $customer))
        ->toThrow(AuthorizationException::class);

    expect(Customer::query()->find($customer->id))->not->toBeNull()
        ->and(Customer::query()->find($customer->id)->trashed())->toBeFalse();
});

// The case that catches delete() checking the wrong permission string -- a plausible copy-paste
// slip when adding a fourth method beside three near-identical siblings whose only difference is
// the constant they read.
test('an actor holding every other module\'s delete permission but not customers.delete is refused', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        'users.delete', 'products.delete', 'sales-regions.delete', 'shipping.delete',
    ]);

    $this->actingAs($actor);

    $customer = Customer::factory()->create();

    expect(fn () => Gate::authorize('delete', $customer))
        ->toThrow(AuthorizationException::class);

    expect(Customer::query()->find($customer->id))->not->toBeNull();
});

// Cheap check that catches an edit to the shared policy file that changed more than the one
// method this story owns -- a holder of customers.view alone still passes viewAny and still
// fails create/update/delete.
test('CustomerPolicy\'s three pre-existing abilities still answer as story 0041 specified', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view');

    $customer = Customer::factory()->create();

    expect(Gate::forUser($actor)->allows('viewAny', Customer::class))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('create', Customer::class))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('update', $customer))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('delete', $customer))->toBeFalse();
});

// =====================================================================
// Super Admin bypass
// =====================================================================

test('a Super Admin, who holds no direct customers.* grant, can delete a customer', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    $customer = Customer::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Gate::authorize('delete', $customer);
    $customer->delete();

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

// =====================================================================
// No tier system (D-3) — any holder may delete any customer
// =====================================================================

test('any holder of customers.delete may delete a customer created by a different administrator', function () {
    $creator = User::factory()->create();
    $creator->givePermissionTo('customers.create');
    $customer = Customer::factory()->create();

    $deleter = User::factory()->create();
    $deleter->givePermissionTo('customers.delete');
    $this->actingAs($deleter);

    Gate::authorize('delete', $customer);
    $customer->delete();

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

// =====================================================================
// The permission constant itself — literal, and already seeded (R-4)
// =====================================================================

test('CustomerPolicy::DELETE_PERMISSION is the exact literal string, and is already seeded', function () {
    expect(CustomerPolicy::DELETE_PERMISSION)->toBe('customers.delete');

    expect(Permission::where('name', CustomerPolicy::DELETE_PERMISSION)->exists())
        ->toBeTrue();
});

// =====================================================================
// Idempotency / repeat action
// =====================================================================

// Route-model binding (once a route exists -- 0044) resolves through the default, scoped query,
// so a trashed id simply does not bind: there is no "already deleted" domain branch to write.
// This pins the underlying mechanism the future route-model-bound delete handler relies on.
test('resolving an already-deleted customer through the default query is not-found, and no second write happens', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.delete');
    $this->actingAs($actor);

    $customer = Customer::factory()->create();
    $customer->delete();
    $originalDeletedAt = Customer::withTrashed()->findOrFail($customer->id)->deleted_at;

    expect(fn () => Customer::query()->findOrFail($customer->id))
        ->toThrow(ModelNotFoundException::class);

    // No second write happened -- the original timestamp is unchanged.
    expect(Customer::withTrashed()->findOrFail($customer->id)->deleted_at->eq($originalDeletedAt))
        ->toBeTrue();
});
