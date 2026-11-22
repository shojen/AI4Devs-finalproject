<?php

// Story 0104 -- the invoice_deliveries data layer: model, factory states, Invoice relations and
// hasBeenSent(), and the invoices.send permission on InvoicePolicy.

use App\Enums\InvoiceDeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('the invoice_deliveries table has the specified columns', function () {
    expect(Schema::getColumnListing('invoice_deliveries'))->toEqualCanonicalizing([
        'id', 'invoice_id', 'recipient_email', 'is_copy', 'status', 'sent_by', 'queued_at', 'sent_at',
        'failed_at', 'failure_reason', 'created_at', 'updated_at',
    ]);
});

test('the status enum has the three cases', function () {
    expect(array_map(fn (InvoiceDeliveryStatus $case): string => $case->value, InvoiceDeliveryStatus::cases()))
        ->toBe(['queued', 'sent', 'failed']);
});

test('the factory default is a queued original with a UUID key and casts', function () {
    $delivery = InvoiceDelivery::factory()->create()->fresh();

    expect($delivery->id)->toBeString()->toHaveLength(36)
        ->and($delivery->status)->toBe(InvoiceDeliveryStatus::Queued)
        ->and($delivery->is_copy)->toBeFalse()
        ->and($delivery->queued_at)->not->toBeNull()
        ->and($delivery->sent_at)->toBeNull()
        ->and($delivery->failed_at)->toBeNull()
        ->and($delivery->sent_by)->toBeNull()
        ->and($delivery->invoice)->toBeInstanceOf(Invoice::class);
});

test('the factory states set status, timestamps and the copy flag', function () {
    $sent = InvoiceDelivery::factory()->sent()->create();
    $failed = InvoiceDelivery::factory()->failed()->create();
    $queued = InvoiceDelivery::factory()->queued()->create();
    $copy = InvoiceDelivery::factory()->copy()->create();

    expect($sent->status)->toBe(InvoiceDeliveryStatus::Sent)->and($sent->sent_at)->not->toBeNull()
        ->and($failed->status)->toBe(InvoiceDeliveryStatus::Failed)->and($failed->failed_at)->not->toBeNull()
        ->and($failed->failure_reason)->not->toBeNull()
        ->and($queued->status)->toBe(InvoiceDeliveryStatus::Queued)
        ->and($copy->is_copy)->toBeTrue();
});

test('the sender relation points to the user', function () {
    $user = User::factory()->create();
    $delivery = InvoiceDelivery::factory()->create(['sent_by' => $user->id]);

    expect($delivery->sender->is($user))->toBeTrue();
});

test('deliveries relation and latestDelivery return the newest by queued_at', function () {
    $invoice = Invoice::factory()->standard()->create();
    InvoiceDelivery::factory()->for($invoice)->failed()->create(['queued_at' => now()->subHours(2)]);
    $newest = InvoiceDelivery::factory()->for($invoice)->queued()->create(['queued_at' => now()->subHour()]);

    expect($invoice->deliveries)->toHaveCount(2)
        ->and($invoice->latestDelivery()->is($newest))->toBeTrue();
});

test('hasBeenSent is true only when a sent delivery exists', function () {
    $invoice = Invoice::factory()->standard()->create();
    expect($invoice->hasBeenSent())->toBeFalse();

    InvoiceDelivery::factory()->for($invoice)->queued()->create();
    InvoiceDelivery::factory()->for($invoice)->failed()->create();
    expect($invoice->hasBeenSent())->toBeFalse();

    InvoiceDelivery::factory()->for($invoice)->sent()->create();
    expect($invoice->hasBeenSent())->toBeTrue();
});

test('an invoice with deliveries cannot be deleted at the database level (restrict)', function () {
    $delivery = InvoiceDelivery::factory()->create();

    expect(fn () => Invoice::query()->whereKey($delivery->invoice_id)->toBase()->delete())
        ->toThrow(QueryException::class);
});

test('the send permission constant and catalog entry exist', function () {
    expect(InvoicePolicy::SEND_PERMISSION)->toBe('invoices.send')
        ->and(RolePermissionSeeder::INVOICE_PERMISSIONS)->toContain('invoices.send');
});

test('send is allowed for a holder of invoices.send only, with or without an invoice', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $invoice = Invoice::factory()->standard()->create();
    $sender = User::factory()->create();
    $sender->givePermissionTo('invoices.send');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('invoices.view', 'invoices.create');

    expect(Gate::forUser($sender)->allows('send', $invoice))->toBeTrue()
        ->and(Gate::forUser($sender)->allows('send', Invoice::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('send', $invoice))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('send', Invoice::class))->toBeFalse();
});

test('the seeder gives invoices.send to Administrator and the catalog has 49', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    expect(Role::findByName('Administrator')->hasPermissionTo('invoices.send'))->toBeTrue()
        ->and(Permission::count())->toBe(49);
});
