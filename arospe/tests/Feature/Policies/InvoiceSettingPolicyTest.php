<?php

// Story 0100 -- App\Policies\InvoiceSettingPolicy, auto-discovered for App\Models\InvoiceSetting.
// Two abilities (viewAny, update) over two own permissions; mirrors LocaleSettingPolicyTest.

use App\Models\InvoiceSetting;
use App\Models\User;
use App\Policies\InvoiceSettingPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('the policy permission constants are literals of the catalog', function () {
    expect(InvoiceSettingPolicy::VIEW_PERMISSION)->toBe('invoice-settings.view')
        ->and(InvoiceSettingPolicy::MANAGE_PERMISSION)->toBe('invoice-settings.manage')
        ->and(RolePermissionSeeder::INVOICE_PERMISSIONS)->toBe(['invoice-settings.view', 'invoice-settings.manage', 'invoices.view', 'invoices.create']);
});

test('viewAny is allowed for a holder of invoice-settings.view alone, denied for a holder of neither', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('invoice-settings.view');

    $nobody = User::factory()->create();

    expect(Gate::forUser($viewer)->allows('viewAny', InvoiceSetting::class))->toBeTrue()
        ->and(Gate::forUser($nobody)->allows('viewAny', InvoiceSetting::class))->toBeFalse();
});

test('viewAny is denied for a holder of invoice-settings.manage alone', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo('invoice-settings.manage');

    expect(Gate::forUser($manager)->allows('viewAny', InvoiceSetting::class))->toBeFalse();
});

test('update is allowed for a holder of invoice-settings.manage alone, against the class and against a row', function () {
    $row = InvoiceSetting::factory()->create();

    $manager = User::factory()->create();
    $manager->givePermissionTo('invoice-settings.manage');

    expect(Gate::forUser($manager)->allows('update', InvoiceSetting::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $row))->toBeTrue();
});

test('update is denied for a holder of invoice-settings.view alone and for a holder of neither', function () {
    $row = InvoiceSetting::factory()->create();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('invoice-settings.view');

    expect(Gate::forUser($viewer)->allows('update', $row))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('update', InvoiceSetting::class))->toBeFalse();
});

test('authorize throws AuthorizationException when update is denied', function () {
    expect(fn () => Gate::forUser(User::factory()->create())->authorize('update', InvoiceSetting::class))
        ->toThrow(AuthorizationException::class);
});

test('a Super Admin passes both abilities while holding zero permission rows, and the catalog holds both names', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', InvoiceSetting::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', InvoiceSetting::class))->toBeTrue()
        ->and(Permission::query()->whereIn('name', ['invoice-settings.view', 'invoice-settings.manage'])->count())->toBe(2);
});
