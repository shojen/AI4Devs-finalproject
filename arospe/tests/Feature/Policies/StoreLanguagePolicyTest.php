<?php

// Story 0068 -- App\Policies\StoreLanguagePolicy, auto-discovered for App\Models\StoreLanguage
// by name alone (no provider registration; see conventions/base-standards.md). Mirrors
// tests/Feature/Policies/MediaPolicyTest.php's shape (four abilities, the MediaPolicy shape --
// not SalesRegionPolicy's two-of-four -- because every one of the four store-languages.* verbs
// has a real call site here: create/AddStoreLanguage, delete/RemoveStoreLanguage,
// update/SetDefaultStoreLanguage). The Super Admin bypass test passes regardless of the policy's
// own logic, since Gate::before grants a Super Admin actor before any policy is consulted.

use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// viewAny
// =====================================================================

test('viewAny is allowed for an actor holding store-languages.view and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', StoreLanguage::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', StoreLanguage::class))->toBeFalse();
});

// =====================================================================
// create
// =====================================================================

test('create is allowed for an actor holding store-languages.create and denied for one without it', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.create');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('create', StoreLanguage::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('create', StoreLanguage::class))->toBeFalse();
});

// Narrowness -- holding only store-languages.view is not sufficient for create. Distinct from the
// bare "denied for one without it" case above: this actor holds a real, different permission on
// the SAME module, so a policy that accidentally checked "any store-languages.* permission"
// rather than the exact store-languages.create string would pass this actor incorrectly.
test('create is denied for an actor holding only store-languages.view', function () {
    $viewOnlyActor = User::factory()->create();
    $viewOnlyActor->givePermissionTo('store-languages.view');

    expect(Gate::forUser($viewOnlyActor)->allows('create', StoreLanguage::class))->toBeFalse();
});

// =====================================================================
// update -- the default swap (SetDefaultStoreLanguage)
// =====================================================================

test('update is allowed for an actor holding store-languages.edit and denied for one without it', function () {
    $target = StoreLanguage::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $target))->toBeFalse();
});

test('update is denied for an actor holding only store-languages.view', function () {
    $target = StoreLanguage::factory()->create();

    $viewOnlyActor = User::factory()->create();
    $viewOnlyActor->givePermissionTo('store-languages.view');

    expect(Gate::forUser($viewOnlyActor)->allows('update', $target))->toBeFalse();
});

// =====================================================================
// delete -- remove / deactivate (RemoveStoreLanguage)
// =====================================================================

test('delete is allowed for an actor holding store-languages.delete and denied for one without it', function () {
    $target = StoreLanguage::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.delete');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('delete', $target))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('delete', $target))->toBeFalse();
});

test('delete is denied for an actor holding only store-languages.edit', function () {
    $target = StoreLanguage::factory()->create();

    $editOnlyActor = User::factory()->create();
    $editOnlyActor->givePermissionTo('store-languages.edit');

    expect(Gate::forUser($editOnlyActor)->allows('delete', $target))->toBeFalse();
});

// =====================================================================
// Denial is enforced server-side, not merely hidden in the UI
// =====================================================================

test('authorize throws AuthorizationException when create is denied', function () {
    $actor = User::factory()->create(); // holds no permission at all

    expect(fn () => Gate::forUser($actor)->authorize('create', StoreLanguage::class))
        ->toThrow(AuthorizationException::class);
});

// =====================================================================
// Super Admin bypass -- Gate::before grants a Super Admin actor regardless of whether
// StoreLanguagePolicy exists at all, exactly like SalesRegionPolicyTest's/MediaPolicyTest's
// equivalent. A Super Admin holding zero store-languages.* rows is allowed every ability.
// =====================================================================

test('a Super Admin actor passes every StoreLanguagePolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $target = StoreLanguage::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', StoreLanguage::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', StoreLanguage::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $target))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('delete', $target))->toBeTrue();
});
