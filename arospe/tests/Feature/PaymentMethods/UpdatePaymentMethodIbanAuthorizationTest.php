<?php

// Story 0038, Phase 4 security audit finding F-1: App\Actions\PaymentMethods\UpdatePaymentMethodIban
// shipped with no authorization of its own -- the story's own task file argued this was
// consistent with the rest of this repo's actions, and that argument did not hold up
// (CreateUser/UpdateUser both self-authorize). This file is the deny + control pair that proves
// the action is gated INDEPENDENTLY of App\Livewire\PaymentMethods\Index -- calling it directly,
// the way a future Artisan command, queued job or Epic 3 order-flow caller would.
//
// Modelled on tests/Feature/ShippingZones/ShippingZoneAuthorizationTest.php's own shape.

use App\Actions\PaymentMethods\UpdatePaymentMethodIban;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('UpdatePaymentMethodIban is refused for an actor lacking payment-methods.edit and writes no row', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $actor = User::factory()->create();
    $this->actingAs($actor);

    $caught = null;

    try {
        app(UpdatePaymentMethodIban::class)($method, 'ES9121000418450200051332');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);
    expect($method->fresh()->iban)->toBeNull();
});

test('UpdatePaymentMethodIban succeeds for an actor holding payment-methods.edit, as the control', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $actor = User::factory()->create();
    $actor->givePermissionTo('payment-methods.edit');
    $this->actingAs($actor);

    app(UpdatePaymentMethodIban::class)($method, 'ES9121000418450200051332');

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

test('a Super Admin actor holding zero permission rows passes UpdatePaymentMethodIban', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    app(UpdatePaymentMethodIban::class)($method, 'ES9121000418450200051332');

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});

// Phase 5 code review finding N4: this action self-authorizes but did not, until this fix, also
// validate -- a direct caller bypassing App\Livewire\PaymentMethods\Index could persist a
// structurally invalid or checksum-failing IBAN with no error at all. These two tests prove the
// action now enforces App\Rules\Iban itself, independently of the component.
test('UpdatePaymentMethodIban rejects an invalid IBAN and writes no row, called directly', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $actor = User::factory()->create();
    $actor->givePermissionTo('payment-methods.edit');
    $this->actingAs($actor);

    expect(fn () => app(UpdatePaymentMethodIban::class)($method, 'NOTANIBAN'))
        ->toThrow(ValidationException::class);

    expect($method->fresh()->iban)->toBeNull();
});

test('UpdatePaymentMethodIban normalises a space-separated, lowercase IBAN before persisting, called directly', function () {
    $method = PaymentMethod::factory()->create(['iban' => null]);

    $actor = User::factory()->create();
    $actor->givePermissionTo('payment-methods.edit');
    $this->actingAs($actor);

    app(UpdatePaymentMethodIban::class)($method, 'es91 2100 0418 4502 0005 1332');

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});
