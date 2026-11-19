<?php

// Story 0101b -- authorization comes first and a refusal is logged (T-26, T-27). The gated entry
// point is IssueInvoice (FD-13); a caller without `invoices.create` learns nothing about the order.

use App\Actions\Invoices\IssueInvoice;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

// T-26
test('a caller without invoices.create and orders.view is refused before any invoice state is read, for any amount and order state (T-26)', function (array $permissions, ?string $amount, string $orderState) {
    $settings = $orderState === 'issuer missing' ? null : Invoicing::settings();
    $order = match ($orderState) {
        'invoiceable' => Invoicing::thousandOrder(),
        'cancelled' => Invoicing::thousandOrder(['status' => 'cancelled']),
        'out of date' => Invoicing::thousandOrder(['invoice_out_of_date_at' => now()]),
        'issuer missing' => Invoicing::thousandOrder(),
    };
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);
    $this->actingAs($actor);
    $before = Numbering::snapshot();
    $touched = [];

    DB::listen(function ($query) use (&$touched): void {
        if (preg_match('/\b(invoices|invoice_lines|invoice_sequences|invoice_settings|orders|customers)\b/i', $query->sql) === 1) {
            $touched[] = $query->sql;
        }
    });

    expect(fn () => app(IssueInvoice::class)($order, $amount))->toThrow(AuthorizationException::class)
        ->and($touched)->toBe([])
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($before);
})->with([
    'no permission, valid amount' => [[], '200.00', 'invoiceable'],
    'no permission, invalid amount' => [[], '-5', 'invoiceable'],
    'no permission, no amount' => [[], null, 'invoiceable'],
    'view only' => [['invoices.view'], '200.00', 'invoiceable'],
    'invoices.create only, no order view' => [['invoices.create'], '200.00', 'invoiceable'],
    'orders.view only, no invoices.create' => [['orders.view'], '200.00', 'invoiceable'],
    'settings manage only' => [['invoice-settings.manage'], '200.00', 'invoiceable'],
    'cancelled order' => [[], '200.00', 'cancelled'],
    'out of date order' => [['invoices.view'], '200.00', 'out of date'],
    'issuer data missing' => [[], '200.00', 'issuer missing'],
]);

test('every refused caller gets the same refusal whatever the order state, so the order cannot be probed (T-26)', function () {
    Invoicing::settings();
    $actor = User::factory()->create();
    $this->actingAs($actor);
    $messages = [];

    foreach ([Invoicing::thousandOrder(), Invoicing::thousandOrder(['status' => 'cancelled'])] as $order) {
        try {
            app(IssueInvoice::class)($order, '200.00');
        } catch (AuthorizationException $exception) {
            $messages[] = $exception->getMessage();
        }
    }

    expect($messages)->toHaveCount(2)->and($messages[0])->toBe($messages[1]);
});

// T-27
test('a refusal writes exactly one Log::warning naming invoice, carrying no amount or issuer data (T-27)', function () {
    Invoicing::settings();
    $order = Invoicing::thousandOrder();
    $actor = User::factory()->create();
    $actor->givePermissionTo('invoices.view');
    $this->actingAs($actor);
    Log::spy();

    try {
        app(IssueInvoice::class)($order, '123.45');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'create'
            && ($context['target_type'] ?? null) === 'invoice'
            && ! str_contains((string) json_encode($context), '123.45')
            && ! str_contains((string) json_encode($context), 'ESB12345678')
            && ! str_contains((string) json_encode($context), 'Arospe'))
        ->once();
});

test('an unauthenticated caller is refused and logged without an actor id (T-27)', function () {
    Invoicing::settings();
    $order = Invoicing::thousandOrder();
    Log::spy();

    expect(fn () => app(IssueInvoice::class)($order, '200.00'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null
            && ($context['ability'] ?? null) === 'create'
            && ($context['target_type'] ?? null) === 'invoice')
        ->once();
    expect(Invoice::query()->count())->toBe(0);
});

test('invoices.create without orders.view is refused and logged once as create on invoice (T-26, T-27)', function () {
    Invoicing::settings();
    $order = Invoicing::thousandOrder();
    $actor = User::factory()->create();
    $actor->givePermissionTo('invoices.create');
    $this->actingAs($actor);
    Log::spy();

    expect(fn () => app(IssueInvoice::class)($order, '200.00'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => ($context['ability'] ?? null) === 'create'
            && ($context['target_type'] ?? null) === 'invoice'
            && ($context['actor_id'] ?? null) === $actor->id)
        ->once();
});

test('a permitted issue logs no refusal (T-27)', function () {
    Invoicing::settings();
    $order = Invoicing::thousandOrder();
    Invoicing::manager();
    Log::spy();

    app(IssueInvoice::class)($order, '200.00');

    Log::shouldNotHaveReceived('warning');
});
