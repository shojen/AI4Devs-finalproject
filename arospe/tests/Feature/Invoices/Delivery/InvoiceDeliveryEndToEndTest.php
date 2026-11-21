<?php

// Story 0104 T-32 (level Q) -- end to end through the real queue wiring: the action, the queued
// notification, the real mail transport (the array mailer) and the NotificationSent listener.

use App\Actions\Invoices\RenderInvoicePdf;
use App\Actions\Invoices\SendInvoice;
use App\Enums\InvoiceDeliveryStatus;
use App\Models\InvoiceDelivery;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;
use Tests\Support\Invoices\Delivering;

beforeEach(function () {
    Delivering::prepare(fakeNotifications: false);
    $this->actor = Delivering::sender('invoices.send');
});

test('on the sync queue the row ends sent and the mail reaches the transport with the PDF attached under the download name (T-32)', function () {
    $invoice = Delivering::invoice(overrides: ['number' => 'F2026-00042']);
    $address = Delivering::addressOf($invoice);

    $delivery = app(SendInvoice::class)($invoice);

    /** @var list<SentMessage> $messages */
    $messages = array_values(array_filter(
        Mail::mailer('array')->getSymfonyTransport()->messages()->all(),
        fn (SentMessage $message): bool => collect($message->getOriginalMessage()->getAttachments())->isNotEmpty(),
    ));
    $email = $messages[0]->getOriginalMessage();

    expect($delivery->fresh()->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($delivery->fresh()->sent_at)->not->toBeNull()
        ->and($messages)->toHaveCount(1)
        ->and($email->getTo()[0]->getAddress())->toBe($address)
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe('invoice-F2026-00042.pdf')
        ->and($email->getAttachments()[0]->getMediaType().'/'.$email->getAttachments()[0]->getMediaSubtype())->toBe('application/pdf');
});

test('a render exception in the worker, after the configured tries, ends the row failed with a bounded reason and the next send is an original (T-32)', function () {
    config(['invoicing.delivery.tries' => 3, 'invoicing.delivery.backoff_seconds' => 5]);
    $invoice = Delivering::invoice();
    $this->mock(RenderInvoicePdf::class, fn ($mock) => $mock->shouldReceive('__invoke')->andThrow(new RuntimeException('Chromium crashed at /opt/chromium/bin for customer.private@example.com')));
    Queue::fake();

    $delivery = app(SendInvoice::class)($invoice);

    $job = Queue::pushed(SendQueuedNotifications::class)->first();

    expect($job)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($job->tries)->toBe(3)
        ->and($delivery->fresh()->status)->toBe(InvoiceDeliveryStatus::Queued);

    // The worker: attempt, and on the final failure call the job's failed hook.
    $thrown = null;

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    $job->failed($thrown);

    $row = $delivery->fresh();

    expect($row->status)->toBe(InvoiceDeliveryStatus::Failed)
        ->and($row->failed_at)->not->toBeNull()
        ->and(mb_strlen((string) $row->failure_reason))->toBeLessThanOrEqual(500)
        ->and((string) $row->failure_reason)->not->toContain('customer.private@example.com')
        ->and((string) $row->failure_reason)->not->toContain('/opt/chromium')
        ->and(app(SendInvoice::class)($invoice)->is_copy)->toBeFalse()
        ->and(InvoiceDelivery::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
});
