<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Orders\ToNumericString;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The gated undo of a mistaken collection (story 0106, FD-4, FD-5). `authorize('uncollect')` comes
 * first through the refusal-logging wrapper; then one transaction with the same locks as
 * CollectInvoice (order, then invoice). Refused, as a validation error keyed `invoice`: an invoice
 * that is not collected, voided, with a corrective, of an order that has a refund or is cancelled,
 * or of an out-of-date order. Otherwise the five collection columns are cleared and the order's
 * collected amount and payment status are recomputed. A financial reversal, so it logs a warning
 * with the actor, the invoice and the order.
 */
class UndoInvoiceCollection
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly AssertInvoicesAreCurrent $assertInvoicesAreCurrent,
        private readonly SyncOrderCollection $syncOrderCollection,
        private readonly ToNumericString $toNumericString,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Invoice $invoice): Invoice
    {
        $this->logRefusedPrivilegedAttempt->authorize('uncollect', $invoice, targetType: 'invoice', targetId: $invoice->getKey());

        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $previous = [];

        $undone = DB::transaction(function () use ($invoice, &$previous): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);

            ($this->assertInvoicesAreCurrent)($order);

            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $this->assertUndoable($locked, $order);

            $previous = [
                'previous_collected_at' => $locked->collected_at?->toIso8601String(),
                'previous_collected_by' => $locked->collected_by,
                'previous_payment_method_id' => $locked->collection_payment_method_id,
                'previous_collection_type' => $locked->collection_type,
                'was_carried_over' => (bool) $locked->collection_carried_over,
            ];

            $locked->forceFill([
                'collected_at' => null,
                'collected_by' => null,
                'collection_payment_method_id' => null,
                'collection_type' => null,
                'collection_carried_over' => false,
            ])->save();

            ($this->syncOrderCollection)($order);

            return $locked->setRelation('order', $order->refresh());
        });

        Log::warning('Invoice collection undone', [
            'actor_id' => $actor->getKey(),
            'invoice_id' => $undone->getKey(),
            'order_id' => $undone->order_id,
            ...$previous,
        ]);

        return $undone;
    }

    /**
     * @throws ValidationException
     */
    private function assertUndoable(Invoice $invoice, Order $order): void
    {
        $key = match (true) {
            ! $invoice->isCollected() => 'invoices.errors.not_collected',
            $invoice->voided_at !== null => 'invoices.errors.collect_voided',
            Invoice::query()->where('corrects_invoice_id', $invoice->getKey())->lockForUpdate()->value('id') !== null => 'invoices.errors.undo_has_corrective',
            bccomp(($this->toNumericString)($order->refunded_amount), '0.00', 2) > 0 => 'invoices.errors.undo_has_refund',
            $order->status === OrderStatus::Cancelled => 'invoices.errors.collection_cancelled',
            default => null,
        };

        if ($key !== null) {
            throw ValidationException::withMessages(['invoice' => [__($key)]]);
        }
    }
}
