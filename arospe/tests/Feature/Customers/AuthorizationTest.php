<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Models\Customer;
use App\Models\User;
use App\Policies\CustomerPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer, App\Actions\Customers\CreateCustomer,
// App\Actions\Customers\UpdateCustomer and App\Policies\CustomerPolicy do not exist yet. Every
// test in this file is expected to fail (class not found) until backend-expert implements them
// -- that is the correct, intended "red" outcome.
//
// D-1: this story ships no route and no Livewire component, so EVERY authorization test here is
// action-level, per docs/testing/README.md's "an authorization test at the action layer and an
// HTTP one are not substitutes" rule -- 0044 owns the HTTP-level ones once a screen exists.
//
// The two policy-only checks from the story's own test plan (CustomerPolicy defines exactly
// viewAny/create/update and not delete; update() answers identically for any target) live in
// tests/Feature/Policies/CustomerPolicyTest.php instead, matching where every other policy's own
// structural tests live in this repo (see SalesRegionPolicyTest.php).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// CreateCustomer
// =====================================================================

test('an administrator holding customers.view but not customers.create is refused by CreateCustomer, and no row is written', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view');
    $this->actingAs($actor);

    $caught = null;

    try {
        app(CreateCustomer::class)(['name' => 'Cliente', 'email' => 'rechazado@example.com']);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    expect(Customer::count())->toBe(0);
});

// The positive 200-beside-the-403 case — a mistyped ability string would otherwise deny every
// actor and this refusal-only file would never notice.
test('an administrator holding customers.create succeeds at creating a customer', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.create');
    $this->actingAs($actor);

    $customer = app(CreateCustomer::class)(['name' => 'Cliente', 'email' => 'aceptado@example.com']);

    expect($customer->fresh())->not->toBeNull();
});

test('a Super Admin holding no individual customers permission succeeds at creating a customer', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $customer = app(CreateCustomer::class)(['name' => 'Cliente Super Admin', 'email' => 'superadmin-create@example.com']);

    expect($customer->fresh())->not->toBeNull();
});

// =====================================================================
// UpdateCustomer
// =====================================================================

test('an administrator holding customers.view but not customers.edit is refused by UpdateCustomer, and the row is left unchanged', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view');

    // Created via the model factory (not CreateCustomer), which has no authorization concern of
    // its own -- only the UPDATE call under test is what this test's actingAs() actor drives.
    $customer = Customer::factory()->create(['name' => 'Nombre Original']);

    $this->actingAs($actor);

    $caught = null;

    try {
        app(UpdateCustomer::class)($customer, array_merge($customer->only(['name', 'email']), ['name' => 'Nombre Nuevo']));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    expect(Customer::query()->findOrFail($customer->id)->name)->toBe('Nombre Original');
});

test('an administrator holding customers.edit succeeds at updating a customer', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.edit');

    $customer = Customer::factory()->create(['name' => 'Nombre Original']);

    $this->actingAs($actor);

    app(UpdateCustomer::class)($customer, array_merge($customer->only(['name', 'email']), ['name' => 'Nombre Nuevo']));

    expect(Customer::query()->findOrFail($customer->id)->name)->toBe('Nombre Nuevo');
});

test('a Super Admin holding no individual customers permission succeeds at updating a customer', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $customer = Customer::factory()->create(['name' => 'Nombre Original']);

    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    app(UpdateCustomer::class)($customer, array_merge($customer->only(['name', 'email']), ['name' => 'Nombre Super Admin']));

    expect(Customer::query()->findOrFail($customer->id)->name)->toBe('Nombre Super Admin');
});

// =====================================================================
// The permission strings themselves — asserted LITERALLY, and asserted to exist in the seeded
// catalog, so a typo in the constant cannot fail closed unnoticed (R-4). Assert the constants,
// not re-typed literals — that is the whole point of naming them once on the policy.
// =====================================================================

test('CustomerPolicy permission constants are the exact literal strings, and are already seeded', function () {
    expect(CustomerPolicy::VIEW_PERMISSION)->toBe('customers.view')
        ->and(CustomerPolicy::CREATE_PERMISSION)->toBe('customers.create')
        ->and(CustomerPolicy::EDIT_PERMISSION)->toBe('customers.edit');

    expect(Permission::where('name', CustomerPolicy::VIEW_PERMISSION)->exists())->toBeTrue()
        ->and(Permission::where('name', CustomerPolicy::CREATE_PERMISSION)->exists())->toBeTrue()
        ->and(Permission::where('name', CustomerPolicy::EDIT_PERMISSION)->exists())->toBeTrue();
});
