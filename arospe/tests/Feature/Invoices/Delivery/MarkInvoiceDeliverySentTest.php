<?php

// Story 0104 FD-10 -- MarkInvoiceDeliverySent (listener on NotificationSent) and the status
// transitions of a delivery row together with InvoiceSent::failed().

use App\Actions\Invoices\SendInvoice;
use App\Enums\InvoiceDeliveryStatus;
use App\Listeners\MarkInvoiceDeliverySent;
use App\Notifications\InvoiceSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Tests\Support\Invoices\Delivering;

beforeEach(function () {
    Delivering::prepare();
    Delivering::sender('invoices.send');
});

function mailWasSent(Notification $notification): void
{
    event(new NotificationSent((new AnonymousNotifiable)->route('mail', 'customer@example.test'), $notification, 'mail', null));
}

// T-5
test('the listener sets every row of the notification sent with the time, and leaves other rows alone (T-5)', function () {
    $invoice = Delivering::invoice();
    $mine = Delivering::deliver($invoice);
    $mineToo = Delivering::deliver(Delivering::invoice($invoice->order));
    $someoneElses = Delivering::deliver(Delivering::invoice());

    mailWasSent(new InvoiceSent([$mine->id, $mineToo->id]));

    expect($mine->fresh()->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($mine->fresh()->sent_at)->not->toBeNull()
        ->and($mineToo->fresh()->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($someoneElses->fresh()->status)->toBe(InvoiceDeliveryStatus::Queued)
        ->and($someoneElses->fresh()->sent_at)->toBeNull();
});

test('the listener handles a NotificationSent event called directly (T-5)', function () {
    $delivery = Delivering::deliver(Delivering::invoice());

    app(MarkInvoiceDeliverySent::class)->handle(new NotificationSent(new AnonymousNotifiable, new InvoiceSent([$delivery->id]), 'mail', null));

    expect($delivery->fresh()->status)->toBe(InvoiceDeliveryStatus::Sent);
});

test('the listener ignores every other notification (T-5)', function () {
    $delivery = Delivering::deliver(Delivering::invoice());
    $other = new class extends Notification
    {
        /** @var list<string> */
        public array $deliveryIds = [];
    };
    $other->deliveryIds = [$delivery->id];

    mailWasSent($other);

    expect($delivery->fresh()->status)->toBe(InvoiceDeliveryStatus::Queued);
});

// T-13
test('a transient failure then success ends sent only, with no failure data left on the row (T-13)', function () {
    $delivery = Delivering::deliver(Delivering::invoice());
    $notification = new InvoiceSent([$delivery->id]);

    $notification->failed(new RuntimeException('temporary'));
    mailWasSent($notification);

    $row = $delivery->fresh();

    expect($row->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->failed_at)->toBeNull()
        ->and($row->failure_reason)->toBeNull();
});

// T-31
test('a sent row never reverts: a late failed() leaves it sent (T-31)', function () {
    $delivery = Delivering::deliver(Delivering::invoice());
    $notification = new InvoiceSent([$delivery->id]);

    mailWasSent($notification);
    $notification->failed(new RuntimeException('late failure'));

    $row = $delivery->fresh();

    expect($row->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($row->failed_at)->toBeNull()
        ->and($row->failure_reason)->toBeNull();
});

test('a row is never both failed and sent whatever the order of events (T-13, T-31)', function (array $events) {
    $delivery = Delivering::deliver(Delivering::invoice());
    $notification = new InvoiceSent([$delivery->id]);

    foreach ($events as $event) {
        $event === 'sent' ? mailWasSent($notification) : $notification->failed(new RuntimeException('boom'));
    }

    $row = $delivery->fresh();

    expect(! ($row->status === InvoiceDeliveryStatus::Sent && $row->failed_at !== null))->toBeTrue()
        ->and(! ($row->status === InvoiceDeliveryStatus::Failed && $row->sent_at !== null))->toBeTrue();
})->with([
    'sent, failed' => [['sent', 'failed']],
    'failed, sent' => [['failed', 'sent']],
    'failed, failed, sent' => [['failed', 'failed', 'sent']],
    'sent, sent' => [['sent', 'sent']],
]);

// T-12
test('a queued original that finally fails while a copy was sent leaves the next send a copy (T-12)', function () {
    $invoice = Delivering::invoice();

    $original = app(SendInvoice::class)($invoice);
    $copy = app(SendInvoice::class)($invoice);
    [$originalMail, $copyMail] = Delivering::sentNotifications();

    mailWasSent($copyMail);
    $originalMail->failed(new RuntimeException('final failure'));

    expect($original->fresh()->status)->toBe(InvoiceDeliveryStatus::Failed)
        ->and($copy->fresh()->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($copy->fresh()->is_copy)->toBeTrue()
        ->and(app(SendInvoice::class)($invoice)->is_copy)->toBeTrue();
});

// T-30
test('a notification of another send marks only its own rows, never a row it does not carry (T-30)', function () {
    $invoice = Delivering::invoice();
    $first = Delivering::deliver($invoice);
    $second = Delivering::deliver($invoice, 'queued', true);

    mailWasSent(new InvoiceSent([$first->id]));

    expect($first->fresh()->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($second->fresh()->status)->toBe(InvoiceDeliveryStatus::Queued);
});

test('a notification carrying a deleted delivery id marks nothing and does not mark a wrong row (T-30)', function () {
    $delivery = Delivering::deliver(Delivering::invoice());

    try {
        mailWasSent(new InvoiceSent(['00000000-0000-7000-8000-000000000000']));
    } catch (Throwable) {
        // A clear exception is an accepted outcome; a wrong row being marked is not.
    }

    expect($delivery->fresh()->status)->toBe(InvoiceDeliveryStatus::Queued);
});
