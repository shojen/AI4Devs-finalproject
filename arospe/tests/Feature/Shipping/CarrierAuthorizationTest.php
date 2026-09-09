<?php

use App\Livewire\Shipping\Index;
use App\Models\ShippingCarrier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// GET route('shipping.index') -- HTTP layer
// =====================================================================

test('guests are redirected to the login page when visiting the shipping screen', function () {
    $this->get(route('shipping.index'))->assertRedirect(route('login'));
});

test('a signed-in user without shipping.view is forbidden from the shipping screen', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $this->get(route('shipping.index'))->assertForbidden();
});

test('a user holding shipping.view can reach the shipping screen', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('shipping.view');
    $this->actingAs($actor);

    $this->get(route('shipping.index'))->assertOk();
});

test('a Super Admin holding zero permission rows can reach the shipping screen', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('shipping.index'))->assertOk();
});

// =====================================================================
// mount() -- component layer, per Livewire::test(). Phase 5 code-review
// finding L4: pinning this directly, since the HTTP test above is
// satisfied by the route's own can:shipping.view middleware alone and
// would stay green even if mount()'s own gate were deleted.
// =====================================================================

test('mounting the component directly without shipping.view is refused', function () {
    $this->withoutExceptionHandling();
    $actor = User::factory()->create();
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

// =====================================================================
// toggleCarrier() -- component layer, per Livewire::test()
// =====================================================================

test('a user without shipping.edit is refused when toggling a carrier, and its state is unchanged', function () {
    $this->withoutExceptionHandling();
    $actor = User::factory()->create();
    $actor->givePermissionTo('shipping.view');
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    expect(fn () => Livewire::test(Index::class)->call('toggleCarrier', $carrier->id, false))
        ->toThrow(AuthorizationException::class);

    expect($carrier->fresh()->is_active)->toBeTrue();
});

test('a user holding shipping.edit can toggle a carrier, as the control', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.edit']);
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    Livewire::test(Index::class)->call('toggleCarrier', $carrier->id, false);

    expect($carrier->fresh()->is_active)->toBeFalse();
});

test('a Super Admin holding no explicit shipping.edit grant can still toggle a carrier', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Livewire::test(Index::class)->call('toggleCarrier', $carrier->id, false);

    expect($carrier->fresh()->is_active)->toBeFalse();
});

test('toggling a non-existent carrier id fails cleanly with ModelNotFoundException, not a silent no-op', function () {
    $this->withoutExceptionHandling();
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.edit']);
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Index::class)->call('toggleCarrier', (string) Str::uuid(), false))
        ->toThrow(ModelNotFoundException::class);
});
