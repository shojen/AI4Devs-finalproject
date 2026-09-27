<?php

// Story 0068 -- GET /settings/store-languages, gated by can:store-languages.view (not Spatie's
// permission: middleware -- same reason as sales-regions.index: Livewire 4's PersistentMiddleware
// allowlist carries Laravel's Authorize but not Spatie's PermissionMiddleware). Renders a
// PLACEHOLDER view only -- story 0069 replaces it -- so this file proves only that the route
// resolves and is gated, mirroring tests/Feature/SalesRegions/IndexTest.php's own HTTP-layer
// block. The literal URI is asserted directly (not via route()) because the task file's own
// contract names the exact path rather than a route name.

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('guests are redirected to the login page when visiting the store languages screen', function () {
    $this->get('/settings/store-languages')->assertRedirect(route('login'));
});

test('a signed-in user without store-languages.view is forbidden from the store languages screen', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $this->get('/settings/store-languages')->assertForbidden();
});

test('a user holding store-languages.view can reach the store languages screen', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('store-languages.view');
    $this->actingAs($actor);

    $this->get('/settings/store-languages')->assertOk();
});

test('a Super Admin holding zero permission rows can reach the store languages screen', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get('/settings/store-languages')->assertOk();
});
