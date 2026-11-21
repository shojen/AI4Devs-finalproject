<?php

// Story 0101b (T-9, FD-17) -- App\Policies\InvoicePolicy, auto-discovered for App\Models\Invoice.
// Three abilities over two permissions: invoices.view (viewAny, view) and invoices.create (create).

use App\Models\Invoice;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A user holding exactly the given permissions.
 */
function invoicePolicyUser(string ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('the policy permission constants are the two literals of the catalog (FD-17)', function () {
    expect(InvoicePolicy::VIEW_PERMISSION)->toBe('invoices.view')
        ->and(InvoicePolicy::CREATE_PERMISSION)->toBe('invoices.create')
        ->and(RolePermissionSeeder::INVOICE_PERMISSIONS)->toBe(['invoice-settings.view', 'invoice-settings.manage', 'invoices.view', 'invoices.create', 'invoices.send', 'invoices.collect']);
});

test('viewAny and view are allowed for a holder of invoices.view alone (T-9)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $viewer = invoicePolicyUser('invoices.view');

    expect(Gate::forUser($viewer)->allows('viewAny', Invoice::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $invoice))->toBeTrue();
});

test('create is denied for a holder of invoices.view alone (T-9)', function () {
    expect(Gate::forUser(invoicePolicyUser('invoices.view'))->allows('create', Invoice::class))->toBeFalse();
});

test('create is allowed for a holder of invoices.create alone, which does not grant viewing (T-9)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $creator = invoicePolicyUser('invoices.create');

    expect(Gate::forUser($creator)->allows('create', Invoice::class))->toBeTrue()
        ->and(Gate::forUser($creator)->allows('viewAny', Invoice::class))->toBeFalse()
        ->and(Gate::forUser($creator)->allows('view', $invoice))->toBeFalse();
});

test('a holder of the invoice-settings permissions only is denied every invoice ability (T-9)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $manager = invoicePolicyUser('invoice-settings.view', 'invoice-settings.manage');

    expect(Gate::forUser($manager)->allows('viewAny', Invoice::class))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('view', $invoice))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', Invoice::class))->toBeFalse();
});

test('a user without any permission is denied every ability and authorize throws (T-9)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $nobody = User::factory()->create();

    expect(Gate::forUser($nobody)->allows('viewAny', Invoice::class))->toBeFalse()
        ->and(Gate::forUser($nobody)->allows('view', $invoice))->toBeFalse()
        ->and(Gate::forUser($nobody)->allows('create', Invoice::class))->toBeFalse()
        ->and(fn () => Gate::forUser($nobody)->authorize('create', Invoice::class))->toThrow(AuthorizationException::class);
});

test('the policy offers no update or delete ability: a holder of every invoice permission is still denied (FD-3)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $holder = invoicePolicyUser('invoices.view', 'invoices.create');

    expect(Gate::forUser($holder)->allows('update', $invoice))->toBeFalse()
        ->and(Gate::forUser($holder)->allows('delete', $invoice))->toBeFalse()
        ->and(Gate::forUser($holder)->allows('forceDelete', $invoice))->toBeFalse();
});

test('a Super Admin passes every ability while holding zero permission rows (T-9)', function () {
    $invoice = Invoice::factory()->standard()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toHaveCount(0)
        ->and(Gate::forUser($superAdmin)->allows('viewAny', Invoice::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('view', $invoice))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', Invoice::class))->toBeTrue();
});

test('the seeded Administrator role holds both new permissions (T-9)', function () {
    $granted = Role::findByName('Administrator')->permissions->pluck('name');

    expect($granted)->toContain('invoices.view')->toContain('invoices.create')
        ->and(Permission::query()->whereIn('name', ['invoices.view', 'invoices.create'])->count())->toBe(2);
});
