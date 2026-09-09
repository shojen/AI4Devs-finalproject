<?php

use App\Models\ShippingRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

// Story 0036, Phase 3 (TDD "red" step): App\Policies\ShippingRatePolicy, App\Models\ShippingRate
// and its factory do not exist yet -- every test below is expected to fail (class/table not
// found) until database-expert/backend-expert implement them. That failure is the correct,
// intended "red" outcome.
//
// D-11 (Phase 2 review finding B1): `create`/`update`/`delete` each have a real call site now (in
// CreateShippingRate/UpdateShippingRate/DeleteShippingRate) -- see
// tests/Feature/ShippingRates/*AuthorizationTest.php-shaped tests in CreateShippingRateTest.php,
// UpdateShippingRateTest.php and DeleteShippingRateTest.php for the action-level deny/allow
// pairs. `viewAny` is the only genuinely callerless ability in this story (nothing here lists
// rates behind a gate -- ListShippingRatesByCarrier is a plain query and 0037 is its gating
// consumer), which is why this file drives every ability DIRECTLY through Gate::forUser(...) --
// a per-ability unit net that is sharper than an action test that happens to exercise one ability
// incidentally, following tests/Feature/Policies/ShippingZonePolicyTest.php's shape.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('viewAny is allowed for an actor holding shipping.view and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('shipping.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', ShippingRate::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', ShippingRate::class))->toBeFalse();
});

test('create is allowed for an actor holding shipping.create and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('shipping.create');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('create', ShippingRate::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('create', ShippingRate::class))->toBeFalse();
});

test('update is allowed for an actor holding shipping.edit and denied for one without it', function () {
    $target = ShippingRate::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('shipping.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $target))->toBeFalse();
});

test('delete is allowed for an actor holding shipping.delete and denied for one without it', function () {
    $target = ShippingRate::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('shipping.delete');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('delete', $target))->toBeFalse();
});

// Server-side enforcement -- not merely allows() returning false, per
// docs/testing/qa/what-not-to-test.md's authorization rule.
test('Gate::forUser denies each ability by throwing AuthorizationException, never merely returning false', function () {
    $target = ShippingRate::factory()->create();
    $deniedActor = User::factory()->create();

    expect(fn () => Gate::forUser($deniedActor)->authorize('viewAny', ShippingRate::class))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Gate::forUser($deniedActor)->authorize('create', ShippingRate::class))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Gate::forUser($deniedActor)->authorize('update', $target))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Gate::forUser($deniedActor)->authorize('delete', $target))
        ->toThrow(AuthorizationException::class);
});

// Super Admin bypass -- and, read together with D-5, documenting that the SAME Super Admin still
// CANNOT delete an in-use shipping zone, because that guard is deliberately not in a policy
// (0033 D-1's decisive argument, concretely proven here). This test proves the bypass exists at
// the RATE policy; tests/Feature/ShippingZones/DeleteShippingZoneTest.php proves the zone-delete
// guard survives it anyway.
test('a Super Admin actor passes every ShippingRatePolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = ShippingRate::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', ShippingRate::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', ShippingRate::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});

// The permission strings are asserted against RolePermissionSeeder's seeded catalog -- a
// permission string not in the catalog throws PermissionDoesNotExist at runtime, so this is a
// correctness test, not a style one. No new permission and no RolePermissionSeeder change (D-11).
test('the four permission strings ShippingRatePolicy gates on are all in the seeded shipping module', function () {
    expect(in_array('shipping', RolePermissionSeeder::MODULES, true))->toBeTrue();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.create', 'shipping.edit', 'shipping.delete']);

    expect($actor->getAllPermissions())->toHaveCount(4);
});
