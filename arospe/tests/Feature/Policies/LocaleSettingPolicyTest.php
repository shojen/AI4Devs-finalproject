<?php

// Story 0068 -- App\Policies\LocaleSettingPolicy, auto-discovered for App\Models\LocaleSetting.
// Two abilities only (viewAny, update), and D25's deliberate design: the policy's own permission
// constants are a REFERENCE to StoreLanguagePolicy's constants, not a second pair of literal
// strings -- so a holder of store-languages.view / .edit governs both the catalog AND these two
// settings (R-15's stated widening). The constant-aliasing pin below is what stops the two
// silently drifting apart on a future rename, per D25.

use App\Models\LocaleSetting;
use App\Models\User;
use App\Policies\LocaleSettingPolicy;
use App\Policies\StoreLanguagePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// D25 -- the constant-aliasing pin. LocaleSettingPolicy's constants ARE
// StoreLanguagePolicy's, not independently-declared duplicates that merely happen to match today.
// =====================================================================

test('LocaleSettingPolicy borrows its permission constants from StoreLanguagePolicy rather than restating the literals', function () {
    expect(LocaleSettingPolicy::VIEW_PERMISSION)->toBe(StoreLanguagePolicy::VIEW_PERMISSION)
        ->and(LocaleSettingPolicy::EDIT_PERMISSION)->toBe(StoreLanguagePolicy::EDIT_PERMISSION)
        ->and(LocaleSettingPolicy::VIEW_PERMISSION)->toBe('store-languages.view')
        ->and(LocaleSettingPolicy::EDIT_PERMISSION)->toBe('store-languages.edit');
});

// =====================================================================
// viewAny
// =====================================================================

test('viewAny is allowed for a holder of store-languages.view, denied for a holder of neither', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.view');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('viewAny', LocaleSetting::class))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('viewAny', LocaleSetting::class))->toBeFalse();
});

test('viewAny is denied for a holder of store-languages.edit alone', function () {
    $editOnlyActor = User::factory()->create();
    $editOnlyActor->givePermissionTo('store-languages.edit');

    expect(Gate::forUser($editOnlyActor)->allows('viewAny', LocaleSetting::class))->toBeFalse();
});

// =====================================================================
// update -- the borrowed-permission binding this story's contract is built on (D25, R-15): a
// role granted exactly store-languages.edit can change both defaults; a role granted
// store-languages.view alone cannot.
// =====================================================================

test('update is allowed for a holder of store-languages.edit, denied for a holder of neither', function () {
    $settings = LocaleSetting::factory()->create();

    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.edit');

    $deniedActor = User::factory()->create();

    expect(Gate::forUser($allowedActor)->allows('update', $settings))->toBeTrue()
        ->and(Gate::forUser($deniedActor)->allows('update', $settings))->toBeFalse();
});

test('update is denied for a holder of store-languages.view alone', function () {
    $settings = LocaleSetting::factory()->create();

    $viewOnlyActor = User::factory()->create();
    $viewOnlyActor->givePermissionTo('store-languages.view');

    expect(Gate::forUser($viewOnlyActor)->allows('update', $settings))->toBeFalse();
});

test('update also authorizes against the class when no row exists yet', function () {
    $allowedActor = User::factory()->create();
    $allowedActor->givePermissionTo('store-languages.edit');

    expect(Gate::forUser($allowedActor)->allows('update', LocaleSetting::class))->toBeTrue();
});

// =====================================================================
// Denial is enforced server-side, not merely hidden in the UI
// =====================================================================

test('authorize throws AuthorizationException when update is denied', function () {
    $actor = User::factory()->create(); // holds no permission at all

    expect(fn () => Gate::forUser($actor)->authorize('update', LocaleSetting::class))
        ->toThrow(AuthorizationException::class);
});

// =====================================================================
// Super Admin bypass, holding zero permission rows.
// =====================================================================

test('a Super Admin actor passes every LocaleSettingPolicy ability while holding zero permission rows', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $settings = LocaleSetting::factory()->create();

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', LocaleSetting::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $settings))->toBeTrue();
});
