<?php

use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

// Story 0023, Phase 3 (TDD "red" step): App\Policies\ProductCategoryPolicy, App\Models\
// ProductCategory and its factory do not exist yet -- every test below is expected to fail
// (class not found) until backend-expert implements them in the next step of the TDD cycle.
//
// Every ability gets both an allow and a deny test, per docs/testing/qa/what-not-to-test.md's
// authorization rule -- D-9's own rationale for why the policy is created and tested now even
// though it has zero call sites until story 0025's UI wires it in.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('viewAny is allowed for an actor holding products.view and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', ProductCategory::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', ProductCategory::class))->toBeFalse();
});

test('create is allowed for an actor holding products.create and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.create');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('create', ProductCategory::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('create', ProductCategory::class))->toBeFalse();
});

test('update is allowed for an actor holding products.edit and denied for one without it', function () {
    $target = ProductCategory::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding products.delete and denied for one without it', function () {
    $target = ProductCategory::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.delete');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('delete', $target))->toBeFalse();
});

// Super Admin bypass, consistent with every other policy in this repo (UserPolicy, RolePolicy,
// SalesRegionPolicy, MediaPolicy).
test('a Super Admin actor passes every ProductCategoryPolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = ProductCategory::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', ProductCategory::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', ProductCategory::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});
