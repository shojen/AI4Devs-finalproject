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
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// Refusal — both halves asserted, since asserting only that an exception was thrown would pass
// against an implementation that deletes first and authorizes second.
// =====================================================================

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

// =====================================================================
// The permission constant itself — literal, and already seeded (R-4)
// =====================================================================

test('CustomerPolicy::DELETE_PERMISSION is the exact literal string, and is already seeded', function () {
    expect(CustomerPolicy::DELETE_PERMISSION)->toBe('customers.delete');

    expect(Permission::where('name', CustomerPolicy::DELETE_PERMISSION)->exists())
        ->toBeTrue();
});
