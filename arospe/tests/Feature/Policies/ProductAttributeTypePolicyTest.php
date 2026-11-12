<?php

use App\Models\ProductAttributeType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

// Story 0028, Phase 3 (TDD "red" step): App\Policies\ProductAttributeTypePolicy, App\Models\
// ProductAttributeType and its factory do not exist yet -- every test below is expected to fail
// (class not found) until backend-expert/database-expert implement them in the next step of the
// TDD cycle.
//
// Mirrors tests/Feature/Policies/ProductCategoryPolicyTest.php's exact shape: D6's own decision
// states this policy follows ProductCategoryPolicy's shape verbatim -- four abilities
// (viewAny/create/update/delete), each gated on the already-seeded products.* permissions, no
// target-dependent branch anywhere (update()/delete() still take the target instance as a
// parameter, unused inside the body, per D6's "future-proofing" note -- so a caller compiles
// against a stable signature even though nothing here reads $target today).
//
// Every ability gets both an allow and a deny test, per docs/testing/qa/what-not-to-test.md's
// authorization rule.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('viewAny is allowed for an actor holding products.view and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', ProductAttributeType::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', ProductAttributeType::class))->toBeFalse();
});

test('create is allowed for an actor holding products.create and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.create');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('create', ProductAttributeType::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('create', ProductAttributeType::class))->toBeFalse();
});

test('update is allowed for an actor holding products.edit and denied for one without it', function () {
    $target = ProductAttributeType::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding products.delete and denied for one without it', function () {
    $target = ProductAttributeType::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('products.delete');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('delete', $target))->toBeFalse();
});

// update()/delete() take the target instance as a parameter, never reading it inside the body
// (D6, future-proofing for a per-row rule that does not exist yet). This test proves the ability
// genuinely ignores which row it's asked about, rather than merely asserting the method compiles
// -- two different target instances must produce the identical answer for the identical actor.
test('update and delete ignore which row they are asked about, matching every other target-branch-free policy in this app', function () {
    $typeA = ProductAttributeType::factory()->create();
    $typeB = ProductAttributeType::factory()->create();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['products.edit', 'products.delete']);

    expect(Gate::forUser($actor)->allows('update', $typeA))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('update', $typeB))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('delete', $typeA))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('delete', $typeB))->toBeTrue();
});

// Super Admin bypass, consistent with every other policy in this repo (UserPolicy, RolePolicy,
// SalesRegionPolicy, MediaPolicy, ProductCategoryPolicy, ProductPolicy).
test('a Super Admin actor passes every ProductAttributeTypePolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = ProductAttributeType::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', ProductAttributeType::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', ProductAttributeType::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});
