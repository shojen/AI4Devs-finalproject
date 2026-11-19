<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceDeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\Order;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The ungated, transaction-bound collaborator of the invoice sends (story 0104 FD-15): called by
 * SendInvoice and SendOrderInvoices and, as the system, by the automatic invoicing of 0105. It
 * records one `queued` row per invoice inside the caller's transaction and queues ONE mail for
 * them after the commit.
 *
 * Lock order (FD-13): the caller already holds the order row; here the invoices are locked by id,
 * then the deliveries are read with a locking read (a plain read would use the transaction
 * snapshot and miss a delivery committed meanwhile), then the rows are inserted. A send is the
 * original when the invoice has no delivery other than `failed` ones (FD-1).
 *
 * If dispatching throws after the commit, the rows are set `failed` ("Dispatch failed") and the
 * error is rethrown as a sanitised RuntimeException (the original message can carry the address), so a mail that never left cannot make later sends copies (FD-9).
 */
class RecordInvoiceDeliveries
{
    public function __construct(
        private readonly ResolveInvoiceRecipient $resolveInvoiceRecipient,
        private readonly ResolveInvoiceLocale $resolveInvoiceLocale,
        private readonly ClassifyDeliveryFailure $classifyDeliveryFailure,
    ) {}

    /**
     * @param  list<Invoice>  $invoices
     * @return list<InvoiceDelivery>
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function __invoke(Order $lockedOrder, array $invoices, ?User $sentBy): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Invoice deliveries must be recorded inside the transaction that locked the order.');
        }

        if ($invoices === []) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.nothing_to_send')]]);
        }

        foreach ($invoices as $invoice) {
            if ($invoice->order_id !== $lockedOrder->getKey()) {
                throw new LogicException('Every invoice must belong to the locked order.');
            }
        }

        $max = (int) config('invoicing.delivery.max_attachments');

        if (count($invoices) > $max) {
            throw ValidationException::withMessages(['invoice' => [__('invoices.errors.too_many_attachments', ['max' => $max])]]);
        }

        $address = ($this->resolveInvoiceRecipient)($lockedOrder);
        $ids = array_map(fn (Invoice $invoice): string => (string) $invoice->getKey(), $invoices);

        Invoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

        /** @var list<string> $alreadyDelivered */
        $alreadyDelivered = InvoiceDelivery::query()
            ->whereIn('invoice_id', $ids)
            ->whereIn('status', [InvoiceDeliveryStatus::Queued->value, InvoiceDeliveryStatus::Sent->value])
            ->lockForUpdate()
            ->pluck('invoice_id')
            ->all();

        $now = now();
        $deliveries = [];

        foreach ($invoices as $invoice) {
            $delivery = InvoiceDelivery::forceCreate([
                'invoice_id' => $invoice->getKey(),
                'recipient_email' => $address,
                'is_copy' => in_array($invoice->getKey(), $alreadyDelivered, true),
                'status' => InvoiceDeliveryStatus::Queued,
                'sent_by' => $sentBy?->getKey(),
                'queued_at' => $now,
            ]);
            $delivery->setRelation('invoice', $invoice);
            $deliveries[] = $delivery;
        }

        $deliveryIds = array_map(fn (InvoiceDelivery $delivery): string => (string) $delivery->getKey(), $deliveries);
        $locale = ($this->resolveInvoiceLocale)();

        DB::afterCommit(function () use ($address, $deliveryIds, $locale): void {
            try {
                Notification::route('mail', $address)->notify((new InvoiceSent($deliveryIds))->locale($locale));
            } catch (Throwable $exception) {
                $reason = 'Dispatch failed: '.($this->classifyDeliveryFailure)($exception);

                InvoiceDelivery::query()
                    ->whereIn('id', $deliveryIds)
                    ->where('status', InvoiceDeliveryStatus::Queued->value)
                    ->update([
                        'status' => InvoiceDeliveryStatus::Failed->value,
                        'failed_at' => now(),
                        'failure_reason' => $reason,
                    ]);

                throw new RuntimeException($reason);
            }
        });

        return $deliveries;
    }
}
