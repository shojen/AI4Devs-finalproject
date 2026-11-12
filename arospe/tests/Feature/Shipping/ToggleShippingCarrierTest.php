<?php

use App\Actions\Shipping\ToggleShippingCarrier;
use App\Models\ShippingCarrier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // PRD §2.4 AC 5 / acceptance criteria: configuring a carrier never contacts the
    // carrier. Any outbound HTTP request anywhere in this file's tests fails loudly
    // rather than actually leaving the test process.
    Http::preventStrayRequests();
});

function shippingCarrierAdministrator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['shipping.view', 'shipping.edit']);

    return $actor;
}

test('a shipping administrator enables a disabled carrier', function () {
    $actor = shippingCarrierAdministrator();
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->inactive()->create();

    app(ToggleShippingCarrier::class)($carrier, true);

    expect($carrier->fresh()->is_active)->toBeTrue();
});

test('a shipping administrator disables an active carrier', function () {
    $actor = shippingCarrierAdministrator();
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    app(ToggleShippingCarrier::class)($carrier, false);

    expect($carrier->fresh()->is_active)->toBeFalse();
});

test('setting an already-active carrier to active again is a no-op, not an error', function () {
    $actor = shippingCarrierAdministrator();
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    app(ToggleShippingCarrier::class)($carrier, true);

    expect($carrier->fresh()->is_active)->toBeTrue();
});

test('a carrier can be disabled and then re-enabled', function () {
    $actor = shippingCarrierAdministrator();
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    app(ToggleShippingCarrier::class)($carrier, false);
    expect($carrier->fresh()->is_active)->toBeFalse();

    app(ToggleShippingCarrier::class)($carrier, true);
    expect($carrier->fresh()->is_active)->toBeTrue();
});

test('two concurrent toggles serialize on the row lock rather than racing, and the last write wins', function () {
    $actor = shippingCarrierAdministrator();
    $this->actingAs($actor);
    $carrier = ShippingCarrier::factory()->create(['is_active' => true]);

    // Not a real concurrency test (Pest runs sequentially) -- this pins that the action
    // re-reads the row's current state from the database inside its own transaction
    // rather than trusting a stale in-memory instance, per Phase 4 finding F-2. A caller
    // holding a stale $carrier instance (is_active still true in memory) still writes
    // the value it explicitly requested, never a flip of the stale value.
    $stale = $carrier->fresh();
    app(ToggleShippingCarrier::class)($carrier, false);

    app(ToggleShippingCarrier::class)($stale, true);

    expect($carrier->fresh()->is_active)->toBeTrue();
});
