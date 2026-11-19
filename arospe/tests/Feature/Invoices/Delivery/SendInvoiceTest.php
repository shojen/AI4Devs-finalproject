<?php

// Story 0104 -- SendInvoice: one invoice to its customer (FD-1..FD-9, FD-16). Real actions and
// database; only the notification channel and the PDF engine are faked.

use App\Actions\Invoices\SendInvoice;
use App\Enums\InvoiceDeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\Invoicing;

beforeEach(function () {
    Delivering::prepare();
    $this->actor = Delivering::sender('invoices.send');
});

// T-1
test('the first send records one queued original with the customer address, the actor and the queue time, and queues one mail to that address only (T-1)', function () {
    $invoice = Delivering::invoice();
    $address = Delivering::addressOf($invoice);

    $delivery = app(SendInvoice::class)($invoice);

    $rows = InvoiceDelivery::query()->where('invoice_id', $invoice->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($delivery->id)
        ->and($delivery->is_copy)->toBeFalse()
        ->and($delivery->status)->toBe(InvoiceDeliveryStatus::Queued)
        ->and($delivery->recipient_email)->toBe($address)
        ->and($delivery->sent_by)->toBe($this->actor->id)
        ->and($delivery->queued_at)->not->toBeNull()
        ->and($delivery->sent_at)->toBeNull()
        ->and($delivery->failed_at)->toBeNull();

    Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
    Notification::assertSentOnDemand(InvoiceSent::class, fn ($notification, array $channels, $notifiable): bool => $notifiable->routes['mail'] === $address
        && $channels === ['mail']
        && Delivering::idsOf($notification) === [$delivery->id]
        && $notification->locale === 'es');
});

test('the mail is written in the store default language, not the acting admin locale (T-1)', function () {
    Invoicing::defaultStoreLanguage('en');
    $this->actor->forceFill(['ui_locale' => 'es'])->save();
    app()->setLocale('es');

    app(SendInvoice::class)(Delivering::invoice());

    expect(Delivering::sentNotifications()[0]->locale)->toBe('en');
});

// T-2
test('a send is the original when the invoice has no delivery other than failed ones and a copy otherwise (T-2)', function (array $earlier, bool $expectedCopy) {
    $invoice = Delivering::invoice();

    foreach ($earlier as $status) {
        Delivering::deliver($invoice, $status);
    }

    $delivery = app(SendInvoice::class)($invoice);

    expect($delivery->is_copy)->toBe($expectedCopy)
        ->and(InvoiceDelivery::query()->where('invoice_id', $invoice->id)->count())->toBe(count($earlier) + 1);
})->with([
    'no earlier delivery' => [[], false],
    'only failed' => [['failed'], false],
    'two failed' => [['failed', 'failed'], false],
    'queued' => [['queued'], true],
    'sent' => [['sent'], true],
    'queued and failed' => [['queued', 'failed'], true],
    'failed then sent' => [['failed', 'sent'], true],
]);

test('two sends in a row give an original then a copy (T-2)', function () {
    $invoice = Delivering::invoice();

    $first = app(SendInvoice::class)($invoice);
    $second = app(SendInvoice::class)($invoice);

    expect($first->is_copy)->toBeFalse()
        ->and($second->is_copy)->toBeTrue()
        ->and(InvoiceDelivery::query()->where('invoice_id', $invoice->id)->where('is_copy', false)->count())->toBe(1);
});

// T-6
test('a corrective follows the same original and copy rules, also when it corrects a voided invoice (T-6)', function (bool $voidedOriginal) {
    $standard = Delivering::invoice(overrides: $voidedOriginal ? ['voided_at' => now()] : []);
    $corrective = Delivering::corrective($standard);

    $first = app(SendInvoice::class)($corrective);
    $second = app(SendInvoice::class)($corrective);

    expect($first->is_copy)->toBeFalse()
        ->and($second->is_copy)->toBeTrue()
        ->and($first->invoice_id)->toBe($corrective->id)
        ->and(InvoiceDelivery::query()->where('invoice_id', $standard->id)->count())->toBe(0);
})->with([
    'corrective of a live invoice' => [false],
    'corrective of a voided invoice' => [true],
]);

// T-9
test('invoices.send alone sends a single invoice, and Super Admin and Administrator may too (T-9)', function (string $who) {
    $invoice = Delivering::invoice();
    $actor = User::factory()->create();

    if ($who === 'permission') {
        $actor->givePermissionTo('invoices.send');
    } else {
        $actor->assignRole($who);
    }

    $this->actingAs($actor);

    expect(app(SendInvoice::class)($invoice)->sent_by)->toBe($actor->id);
})->with(['permission', 'Super Admin', 'Administrator']);

// T-16
test('a soft-deleted customer is refused with the customer-deleted message, nothing is written or sent, and the invoice still downloads (T-16)', function () {
    $invoice = Delivering::invoice();
    $invoice->order->customer->delete();

    $errors = Delivering::refusal(fn () => app(SendInvoice::class)($invoice));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe(__('invoices.errors.customer_deleted'))
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('invoices.view');

    $this->actingAs($viewer)->get(route('invoices.download', $invoice))->assertOk();
});

test('a restored customer is an ordinary send (T-16)', function () {
    $invoice = Delivering::invoice();
    $customer = $invoice->order->customer;
    $customer->delete();
    $customer->restore();

    expect(app(SendInvoice::class)($invoice)->is_copy)->toBeFalse();
});

// T-17
test('an unusable customer address is refused before any row is written, and a valid unusual one is sent (T-17)', function (string $address, bool $usable) {
    $invoice = Delivering::invoice();
    $invoice->order->customer->forceFill(['email' => $address])->save();

    if ($usable) {
        expect(app(SendInvoice::class)($invoice)->recipient_email)->toBe($address);
        Notification::assertSentOnDemand(InvoiceSent::class, fn ($notification, array $channels, $notifiable): bool => $notifiable->routes['mail'] === $address);

        return;
    }

    $errors = Delivering::refusal(fn () => app(SendInvoice::class)($invoice));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe(__('invoices.errors.customer_email_unusable'))
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'empty' => ['', false],
    'whitespace' => ['   ', false],
    'no at sign' => ['customer.example.test', false],
    'no top-level domain' => ['a@b', false],
    'longer than an address may be' => ['a@'.str_repeat('b', 250).'.co', false],
    'plus addressing' => ['a+b@x.co', true],
]);

// T-18
test('when dispatching throws, the rows are failed with the dispatch-failed reason, the error reaches the caller and the next send is an original (T-18)', function () {
    $invoice = Delivering::invoice();
    $failing = Mockery::mock(ChannelManager::class);
    $failing->shouldReceive('send', 'sendNow')->andThrow(new RuntimeException('queue unavailable'));
    Notification::swap($failing);
    app()->instance(ChannelManager::class, $failing);
    app()->instance(Dispatcher::class, $failing);

    expect(fn () => app(SendInvoice::class)($invoice))->toThrow(Exception::class);

    $row = InvoiceDelivery::query()->where('invoice_id', $invoice->id)->sole();

    expect($row->status)->toBe(InvoiceDeliveryStatus::Failed)
        ->and($row->failed_at)->not->toBeNull()
        ->and(strtolower((string) $row->failure_reason))->toContain('dispatch failed');

    app()->instance(Dispatcher::class, Notification::fake());

    expect(app(SendInvoice::class)($invoice)->is_copy)->toBeFalse();
});

// T-19
test('a failure after the rows are inserted rolls everything back: no row and nothing sent (T-19)', function () {
    $invoice = Delivering::invoice();
    $armed = true;

    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'insert into `invoice_deliveries`')) {
            throw new RuntimeException('forced failure after the insert');
        }
    });

    expect(fn () => app(SendInvoice::class)($invoice))->toThrow(Exception::class);

    $armed = false;

    expect(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

// T-20
test('an order marked out of date refuses the send, and the send succeeds once the mark is cleared (T-20)', function () {
    $invoice = Delivering::invoice();
    $invoice->order->forceFill(['invoice_out_of_date_at' => now()])->save();

    $errors = Delivering::refusal(fn () => app(SendInvoice::class)($invoice));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->toBe(__('invoices.errors.out_of_date'))
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $invoice->order->forceFill(['invoice_out_of_date_at' => null])->save();

    expect(app(SendInvoice::class)($invoice)->is_copy)->toBeFalse();
});

// T-27b
test('the single send returns the delivery with its address, and every state refusal is keyed invoice (T-27b)', function () {
    $invoice = Delivering::invoice();

    $delivery = app(SendInvoice::class)($invoice);

    expect($delivery)->toBeInstanceOf(InvoiceDelivery::class)
        ->and($delivery->recipient_email)->toBe(Delivering::addressOf($invoice));

    $voided = Delivering::invoice(overrides: ['voided_at' => now()]);
    $deleted = Delivering::invoice();
    $deleted->order->customer->delete();
    $stale = Delivering::invoice();
    $stale->order->forceFill(['invoice_out_of_date_at' => now()])->save();

    foreach ([$voided, $deleted, $stale] as $refused) {
        expect(array_keys(Delivering::refusal(fn () => app(SendInvoice::class)($refused))))->toBe(['invoice']);
    }
});

// T-29
test('a voided standard invoice is refused as nothing to send and writes no row (T-29)', function () {
    $invoice = Delivering::invoice(overrides: ['voided_at' => now()]);

    $errors = Delivering::refusal(fn () => app(SendInvoice::class)($invoice));

    expect(array_keys($errors))->toBe(['invoice'])
        ->and($errors['invoice'][0])->not->toStartWith('invoices.errors.')
        ->and(InvoiceDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

// T-15
test('a customer address changed after queueing does not redirect the mail or the stored row (T-15)', function () {
    $invoice = Delivering::invoice();
    $original = Delivering::addressOf($invoice);

    $delivery = app(SendInvoice::class)($invoice);
    $invoice->order->customer->forceFill(['email' => 'moved@example.test'])->save();

    expect($delivery->fresh()->recipient_email)->toBe($original);
    Notification::assertSentOnDemand(InvoiceSent::class, fn ($notification, array $channels, $notifiable): bool => $notifiable->routes['mail'] === $original);
});

// T-31
test('delivery rows are never deleted by a later send (T-31)', function () {
    $invoice = Delivering::invoice();
    $failed = Delivering::deliver($invoice, 'failed');

    app(SendInvoice::class)($invoice);

    expect(InvoiceDelivery::query()->whereKey($failed->id)->exists())->toBeTrue()
        ->and(InvoiceDelivery::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
});

test('the send never mutates the invoice itself (T-31)', function () {
    $invoice = Delivering::invoice();
    $before = Invoice::query()->whereKey($invoice->id)->first()->getAttributes();

    app(SendInvoice::class)($invoice);

    expect(Invoice::query()->whereKey($invoice->id)->first()->getAttributes())->toBe($before);
});
