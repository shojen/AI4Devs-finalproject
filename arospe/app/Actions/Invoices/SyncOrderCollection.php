<?php

namespace App\Actions\Invoices;

use App\Actions\Orders\EveryLineFullyRefunded;
use App\Actions\Orders\ToNumericString;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Recomputes an order's collected amount and payment status from its rows (story 0106, FD-1),
 * never incrementing. Transaction-bound and idempotent: the order is re-read from its row (the caller
 * holds the lock) and its invoices are read under lock, so a stale instance passed in is never trusted, and nothing is
 * written when nothing changed. It never touches the order `status` and never `order_payments`.
 *
 * Collected amount: orders with invoices, the net collected; without invoices, a Cancelled order
 * keeps its stored value, a legacy (paid-like) order carries its billable amount, others 0.00.
 */
class SyncOrderCollection
{
    public function __construct(
        private readonly ComputeOrderInvoiceBalance $computeOrderInvoiceBalance,
        private readonly ResolveOrderPaymentStatus $resolveOrderPaymentStatus,
        private readonly EveryLineFullyRefunded $everyLineFullyRefunded,
        private readonly ToNumericString $toNumericString,
    ) {}

    /**
     * @throws LogicException
     */
    public function __invoke(Order $lockedOrder): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The collection of an order must be synchronised inside the transaction that locked it.');
        }

        // A plain re-read: the caller holds the order lock (every writer locks it first), and a
        // second locking read of `orders` would run after the callers' `order_items` locks, against
        // the documented lock order (orders, products, variants, order_items).
        $order = Order::query()->whereKey($lockedOrder->getKey())->firstOrFail();

        $balance = ($this->computeOrderInvoiceBalance)($order, true);
        $hasInvoices = Invoice::query()->where('order_id', $order->getKey())->lockForUpdate()->value('id') !== null;

        $collected = match (true) {
            $hasInvoices => $balance['netCollected'],
            $order->status === OrderStatus::Cancelled => ($this->toNumericString)($order->collected_amount),
            $balance['isLegacy'] => $balance['netCollected'],
            default => '0.00',
        };

        $paymentStatus = ($this->resolveOrderPaymentStatus)(
            $order->payment_status,
            $order->status,
            $hasInvoices,
            ($this->toNumericString)($order->refunded_amount),
            ($this->everyLineFullyRefunded)($order->items()->get()),
            $balance['billable'],
            $balance['netCollected'],
        );

        $order->forceFill([
            'collected_amount' => $collected,
            'payment_status' => $paymentStatus,
        ])->save();
    }
}
