<?php

// Story 0104 -- authorization comes first and a refusal is logged (FD-14; T-23, T-24, T-28).

use App\Actions\Invoices\RecordInvoiceDeliveries;
use App\Actions\Invoices\SendInvoice;
use App\Actions\Invoices\SendOrderInvoices;
use App\Models\InvoiceDelivery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Invoicing;

beforeEach(function () {
    Delivering::prepare();
});

// T-23
test('a caller without invoices.send is refused before any state is read and nothing is written, whatever the invoice state (T-23)', function (array $permissions, string $state, string $operation) {
    $order = Invoicing::thousandOrder();
    $invoice = Delivering::invoice($order, $state === 'voided' ? ['voided_at' => now()] : []);

    match ($state) {
        'customer deleted' => $order->customer->delete(),
        'out of date' => $order->forceFill(['invoice_out_of_date_at' => now()])->save(),
        'cancelled' => $order->forceFill(['status' => 'cancelled'])->save(),
        default => null,
    };

    Delivering::sender(...$permissions);
    $touched = [];
    DB::listen(function ($query) use (&$touched): void {
        if (preg_match('/\b(invoices|invoice_lines|invoice_deliveries|invoice_sequences|invoice_settings|orders|customers)\b/i', $query->sql) === 1) {
            $touched[] = $query->sql;
        }
    });

    $call = $operation === 'single'
        ? fn () => app(SendInvoice::class)($invoice)
        : fn () => app(SendOrderInvoices::class)($order);

    expect($call)->toThrow(AuthorizationException::class)
        ->and($touched)->toBe([])
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'no permission' => [[]],
    'invoices.view only' => [['invoices.view']],
    'invoices.create only' => [['invoices.create']],
    'invoice-settings.manage only' => [['invoice-settings.manage']],
])->with([
    'live invoice' => ['live'],
    'voided invoice' => ['voided'],
    'customer deleted' => ['customer deleted'],
    'out of date' => ['out of date'],
    'cancelled order' => ['cancelled'],
])->with([
    'single' => ['single'],
    'order level' => ['order'],
]);

// T-24
test('a refusal of the single send writes exactly one warning with target_type invoice and the invoice id, and no e-mail address (T-24)', function () {
    $invoice = Delivering::invoice();
    $actor = Delivering::sender('invoices.view', 'invoices.create');
    Log::spy();

    expect(fn () => app(SendInvoice::class)($invoice))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'send'
            && ($context['target_type'] ?? null) === 'invoice'
            && ($context['target_id'] ?? null) === $invoice->id
            && ! str_contains((string) json_encode($context), '@')
            && ! str_contains((string) json_encode($context), Delivering::addressOf($invoice)))
        ->once();
});

test('an unauthenticated caller is refused and logged without an actor id for both operations (T-24)', function () {
    $invoice = Delivering::invoice();
    auth()->logout();
    Log::spy();

    expect(fn () => app(SendInvoice::class)($invoice))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SendOrderInvoices::class)($invoice->order))->toThrow(AuthorizationException::class)
        ->and(InvoiceDelivery::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null
            && ($context['ability'] ?? null) === 'send')
        ->twice();
});

test('every refused caller gets the same refusal whatever the invoice state, so the invoice cannot be probed (T-23)', function () {
    $live = Delivering::invoice();
    $voided = Delivering::invoice(overrides: ['voided_at' => now()]);
    Delivering::sender('invoices.view');
    $messages = [];

    foreach ([$live, $voided] as $invoice) {
        try {
            app(SendInvoice::class)($invoice);
        } catch (AuthorizationException $exception) {
            $messages[] = $exception->getMessage();
        }
    }

    expect($messages)->toHaveCount(2)->and($messages[0])->toBe($messages[1]);
});

// T-28
test('no send action accepts a recipient argument (T-28)', function (string $class, array $parameters) {
    $names = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod($class, '__invoke'))->getParameters());
    $types = array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), (new ReflectionMethod($class, '__invoke'))->getParameters());

    expect($names)->toBe($parameters)
        ->and($types)->not->toContain('string');
})->with([
    'SendInvoice' => [SendInvoice::class, ['invoice']],
    'SendOrderInvoices' => [SendOrderInvoices::class, ['order']],
    'RecordInvoiceDeliveries' => [RecordInvoiceDeliveries::class, ['lockedOrder', 'invoices', 'sentBy']],
]);
