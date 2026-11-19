<?php

// Story 0104 FD-10, FD-11 -- the InvoiceSent notification: the mail it builds in the worker and what its
// failed() hook writes. Tested directly (level N); the PDF engine is the package fake.

use App\Actions\Invoices\RenderInvoicePdf;
use App\Actions\Invoices\SendInvoice;
use App\Enums\InvoiceDeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\Invoices\Delivering;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    $this->fake = Delivering::prepare();
    $this->actor = Delivering::sender('invoices.send');
});

/**
 * The attachment names the download route gives each invoice, in order (the mail must agree).
 *
 * @return list<string>
 */
function downloadNamesOf(Invoice ...$invoices): array
{
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('invoices.view');
    $names = [];

    $original = test()->fake;

    foreach ($invoices as $invoice) {
        $fake = Pdf::fake();
        test()->actingAs($viewer)->get(route('invoices.download', $invoice))->assertOk();
        $names[] = FakesPdf::lastResponded($fake)->downloadName;
    }

    Pdf::swap($original);

    return $names;
}

// T-3
test('two sends in a row render an original then a copy, and only the copy PDF carries the COPY mark (T-3)', function () {
    $invoice = Delivering::invoice();

    app(SendInvoice::class)($invoice);
    app(SendInvoice::class)($invoice);

    [$original, $copy] = Delivering::sentNotifications();
    Delivering::mailOf($original);
    Delivering::mailOf($copy);

    $pdfs = Delivering::generated($this->fake);
    $mark = PdfFixtures::label('copy', 'es');

    expect($pdfs)->toHaveCount(2)
        ->and(Delivering::htmlOf($pdfs[0]))->not->toContain($mark)
        ->and(Delivering::htmlOf($pdfs[1]))->toContain($mark);
});

// T-4
test('the mail attaches one PDF per row under the download file name, application/pdf, rendered with each row is_copy (T-4)', function () {
    $invoice = Delivering::invoice(overrides: ['number' => 'F/2026/0001']);
    $corrective = Delivering::corrective($invoice, ['number' => 'R 2026/0002']);
    $original = Delivering::deliver($invoice, 'queued', false);
    $copy = Delivering::deliver($corrective, 'queued', true);

    $mail = Delivering::mailOf(new InvoiceSent([$original->id, $copy->id]));

    expect($mail->rawAttachments)->toHaveCount(2)
        ->and(array_column($mail->rawAttachments, 'name'))->toBe(downloadNamesOf($invoice, $corrective))
        ->and(array_column($mail->rawAttachments, 'name'))->toBe(['invoice-F-2026-0001.pdf', 'invoice-R-2026-0002.pdf'])
        ->and(collect($mail->rawAttachments)->every(fn (array $attachment): bool => ($attachment['options']['mime'] ?? null) === 'application/pdf' && $attachment['data'] !== ''))->toBeTrue();

    $pdfs = Delivering::generated($this->fake);
    $mark = PdfFixtures::label('copy', 'es');

    expect($pdfs)->toHaveCount(2)
        ->and(Delivering::htmlOf($pdfs[0]))->toContain('F/2026/0001')->not->toContain($mark)
        ->and(Delivering::htmlOf($pdfs[1]))->toContain('R 2026/0002')->toContain($mark);
});

test('the subject and body are translated and carry the invoice number, in es and en (T-4)', function (string $locale) {
    Invoicing::defaultStoreLanguage($locale);
    $invoice = Delivering::invoice(overrides: ['number' => 'F2026-00042']);
    $delivery = Delivering::deliver($invoice);

    $mail = Delivering::mailOf((new InvoiceSent([$delivery->id]))->locale($locale));
    $body = (string) $mail->render();

    expect($mail->subject)->toContain('F2026-00042')->not->toContain('invoices.mail.')
        ->and($body)->toContain('F2026-00042')->not->toContain('invoices.mail.');
})->with(['es', 'en']);

test('the subject and body differ between es and en (T-4)', function () {
    $invoice = Delivering::invoice(overrides: ['number' => 'F2026-00042']);
    $delivery = Delivering::deliver($invoice);

    $es = Delivering::mailOf((new InvoiceSent([$delivery->id]))->locale('es'));
    $en = Delivering::mailOf((new InvoiceSent([$delivery->id]))->locale('en'));

    expect($es->subject)->not->toBe($en->subject)
        ->and((string) $es->render())->not->toBe((string) $en->render());
});

// T-15
test('the queued payload carries only delivery ids: no model, no address, no invoice data (T-15)', function () {
    $invoice = Delivering::invoice();
    $address = Delivering::addressOf($invoice);

    $delivery = app(SendInvoice::class)($invoice);
    $payload = serialize(Delivering::sentNotifications()[0]);

    expect(Delivering::idsOf(Delivering::sentNotifications()[0]))->toBe([$delivery->id])
        ->and($payload)->toContain($delivery->id)
        ->and($payload)->not->toContain($address)
        ->and($payload)->not->toContain($invoice->number)
        ->and($payload)->not->toContain('App\\Models');
});

test('after the customer address changed, the mail built later still renders the row invoices without reading the customer (T-15)', function () {
    $invoice = Delivering::invoice();
    $delivery = Delivering::deliver($invoice);
    $invoice->order->customer->forceFill(['email' => 'moved@example.test'])->save();

    $mail = Delivering::mailOf(new InvoiceSent([$delivery->id]), $delivery->recipient_email);

    expect($mail->rawAttachments)->toHaveCount(1)
        ->and($delivery->fresh()->recipient_email)->not->toBe('moved@example.test');
});

// FD-11
test('the notification is queued after commit with the configured tries, backoff and timeout (FD-10, FD-11)', function () {
    config(['invoicing.delivery.tries' => 3, 'invoicing.delivery.backoff_seconds' => 7, 'invoicing.delivery.timeout_seconds' => 45]);
    Queue::fake();
    app()->forgetInstance(ChannelManager::class);
    Notification::clearResolvedInstance(ChannelManager::class);
    $notification = new InvoiceSent([Delivering::deliver(Delivering::invoice())->id]);

    expect($notification)->toBeInstanceOf(ShouldQueue::class);

    Notification::route('mail', 'customer@example.test')->notify($notification->locale('es'));

    $job = Queue::pushed(SendQueuedNotifications::class)->first();

    expect($job)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(45)
        ->and(Arr::wrap($job->backoff()))->toBe([7])
        ->and($job->afterCommit)->toBeTrue();
});

// T-13
test('failed() sets the rows failed with the time and a reason, and leaves sent rows alone (T-13)', function () {
    $invoice = Delivering::invoice();
    $queued = Delivering::deliver($invoice);
    $alreadySent = Delivering::deliver($invoice, 'sent', true);

    (new InvoiceSent([$queued->id, $alreadySent->id]))->failed(new RuntimeException('Transport refused the message'));

    $queued->refresh();
    $alreadySent->refresh();

    expect($queued->status)->toBe(InvoiceDeliveryStatus::Failed)
        ->and($queued->failed_at)->not->toBeNull()
        ->and($queued->failure_reason)->toBe('RuntimeException')
        ->and($alreadySent->status)->toBe(InvoiceDeliveryStatus::Sent)
        ->and($alreadySent->failed_at)->toBeNull()
        ->and($alreadySent->failure_reason)->toBeNull();
});

// T-14
test('the stored failure reason is at most 500 characters and leaks no credentials, path or address (T-14)', function (string $message, array $leaks) {
    $delivery = Delivering::deliver(Delivering::invoice());

    (new InvoiceSent([$delivery->id]))->failed(new RuntimeException($message));

    $reason = (string) $delivery->fresh()->failure_reason;

    expect(mb_strlen($reason))->toBeLessThanOrEqual(500)
        ->and($reason)->not->toBe('');

    foreach ($leaks as $leak) {
        expect($reason)->not->toContain($leak);
    }
})->with([
    'file URI' => ['Cannot open file:///var/www/storage/app/tmp/inv.html', ['file://', '/var/www', 'inv.html']],
    'quoted e-mail local part' => ['Rejected <"john.smith"@example.com>', ['john.smith', 'example.com']],
    'SMTP username token' => ['Authentication failed for username "pm-token-1234-abcd"', ['pm-token-1234-abcd']],
    'key value secret' => ['Auth failed password=s3cr3t', ['s3cr3t']],
    'relative path' => ['Cannot write ./storage/logs/x.log', ['./storage', 'x.log']],
    'SMTP DSN with credentials' => ['Connection could not be established with host "smtp://mailer-user:S3cretPassw0rd@smtp.example.com:587"', ['S3cretPassw0rd', 'mailer-user:']],
    'storage path' => ['Unable to read /var/www/arospe/storage/app/private/invoices/F2026-00001.pdf', ['/var/www/arospe', 'storage/app/private']],
    'e-mail address' => ['Recipient customer.private@example.com was rejected', ['customer.private@example.com']],
    'long message' => [str_repeat('x', 5000), []],
    'long message with a secret at the end' => [str_repeat('y', 600).' smtp://u:EndSecret99@h', ['EndSecret99']],
]);

// T-22b
test('when one PDF of a three-attachment mail fails to render the whole mail fails and all three rows end failed (T-22b)', function () {
    $order = Invoicing::thousandOrder();
    $rows = collect(range(1, 3))->map(fn (): InvoiceDelivery => Delivering::deliver(Delivering::invoice($order)));
    $real = app(RenderInvoicePdf::class);
    $calls = 0;

    $this->mock(RenderInvoicePdf::class, function ($mock) use ($real, &$calls): void {
        $mock->shouldReceive('__invoke')->andReturnUsing(function (Invoice $invoice, bool $isCopy, bool $isSample = false) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('Chromium crashed');
            }

            return $real($invoice, $isCopy, $isSample);
        });
    });

    $notification = new InvoiceSent($rows->pluck('id')->all());
    $thrown = null;

    try {
        Delivering::mailOf($notification);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    $notification->failed($thrown);

    expect(InvoiceDelivery::query()->whereIn('id', $rows->pluck('id'))->where('status', 'failed')->count())->toBe(3);
});

// T-30
test('a delivery id that does not exist raises a clear exception, renders no PDF and marks nothing (T-30)', function () {
    $delivery = Delivering::deliver(Delivering::invoice());

    expect(fn () => Delivering::mailOf(new InvoiceSent(['00000000-0000-7000-8000-000000000000'])))->toThrow(Exception::class)
        ->and(Delivering::generated($this->fake))->toBe([])
        ->and($delivery->fresh()->status)->toBe(InvoiceDeliveryStatus::Queued);
});

test('the stored failure reason is a fixed category chosen by the exception class, never its message (F2)', function (Closure $makeException, string $expected) {
    $delivery = Delivering::deliver(Delivering::invoice());

    (new InvoiceSent([$delivery->id]))->failed($makeException());

    expect($delivery->fresh()->failure_reason)->toBe($expected);
})->with([
    'transport with a code' => [fn () => new TransportException('550 rejected a@b.test', 550), 'Mail transport error (SMTP 550)'],
    'transport without a code' => [fn () => new TransportException('boom'), 'Mail transport error'],
    'max attempts' => [fn () => new MaxAttemptsExceededException('x'), 'Timed out'],
    'pdf engine' => [fn () => new CouldNotTakeBrowsershot('/secret/path'), 'PDF rendering failed'],
    'anything else' => [fn () => new LogicException('a@b.test'), 'LogicException'],
]);
