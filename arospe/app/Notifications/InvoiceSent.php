<?php

namespace App\Notifications;

use App\Actions\Invoices\BuildInvoicePdfFileName;
use App\Actions\Invoices\ClassifyDeliveryFailure;
use App\Actions\Invoices\RenderInvoicePdf;
use App\Enums\InvoiceDeliveryStatus;
use App\Models\InvoiceDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * The invoice e-mail (story 0104 FD-10, FD-11): one mail carrying the PDFs of the deliveries it
 * names. Queued after commit with an on-demand recipient: the notification itself holds delivery
 * ids only (no invoice or model), but the on-demand route serialises the address into the queue
 * payload; the guarantee is that the address cannot be redirected after queueing. `toMail()` re-reads the rows and renders every PDF
 * in the worker (nothing is stored); `failed()` records the final failure on the rows.
 */
class InvoiceSent extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /** @var int */
    public $backoff;

    /**
     * @param  list<string>  $deliveryIds
     */
    public function __construct(public readonly array $deliveryIds)
    {
        $this->tries = (int) config('invoicing.delivery.tries');
        $this->timeout = (int) config('invoicing.delivery.timeout_seconds');
        $this->backoff = (int) config('invoicing.delivery.backoff_seconds');
        $this->afterCommit = true;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deliveries = $this->loadDeliveries();
        $renderInvoicePdf = app(RenderInvoicePdf::class);
        $buildFileName = app(BuildInvoicePdfFileName::class);

        $numbers = $deliveries->map(fn (InvoiceDelivery $delivery): string => $delivery->invoice->number)->implode(', ');

        $mail = (new MailMessage)
            ->subject(trans_choice('invoices.mail.subject', $deliveries->count(), ['numbers' => $numbers]))
            ->greeting(__('invoices.mail.greeting'))
            ->line(trans_choice('invoices.mail.body', $deliveries->count(), ['numbers' => $numbers]));

        if ($deliveries->contains(fn (InvoiceDelivery $delivery): bool => $delivery->is_copy)) {
            $mail->line(__('invoices.mail.copy_notice'));
        }

        foreach ($deliveries as $delivery) {
            $pdf = $renderInvoicePdf($delivery->invoice, $delivery->is_copy);

            $mail->attachData($pdf->generatePdfContent(), $buildFileName($delivery->invoice), ['mime' => 'application/pdf']);
        }

        return $mail;
    }

    /**
     * Record the final failure on the rows that are still queued; a sent row never reverts.
     */
    public function failed(Throwable $exception): void
    {
        InvoiceDelivery::query()
            ->whereIn('id', $this->deliveryIds)
            ->where('status', InvoiceDeliveryStatus::Queued->value)
            ->update([
                'status' => InvoiceDeliveryStatus::Failed->value,
                'failed_at' => now(),
                'failure_reason' => app(ClassifyDeliveryFailure::class)($exception),
            ]);
    }

    /**
     * The rows in the order of the ids, each with its invoice; any id without a row is an error
     * raised before a single PDF is rendered.
     *
     * @return Collection<int, InvoiceDelivery>
     */
    private function loadDeliveries(): Collection
    {
        $found = InvoiceDelivery::query()->with('invoice')->whereIn('id', $this->deliveryIds)->get()->keyBy('id');

        return collect($this->deliveryIds)->map(function (string $id) use ($found): InvoiceDelivery {
            $delivery = $found->get($id);

            if (! $delivery instanceof InvoiceDelivery) {
                throw new RuntimeException('An invoice delivery of this mail no longer exists.');
            }

            return $delivery;
        });
    }
}
