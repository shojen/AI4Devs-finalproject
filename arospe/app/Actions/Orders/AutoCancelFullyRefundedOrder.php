<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Cancel an order whose every line item has been fully refunded.
 *
 * SYSTEM-TRIGGERED, DELIBERATELY UNGATED, AND DELIBERATELY PAST BOTH STATUS
 * CLASSES -- a documented exception, not an omission:
 *
 * - No Gate::authorize(), no actor read (D-1). There is no actor: the trigger
 *   is a state transition. The only reachable entry point, RecordRefund, has
 *   already authorized `orders.refund` as its first statement. Any future
 *   caller outside that gated path inherits the obligation to gate itself.
 * - Writes `status` directly via forceFill(), bypassing TransitionOrderStatus
 *   and CancelOrder (D-2): both would refuse this transition (CancelOrder
 *   blocks Shipped/Delivered, which PRD 3.2 requires auto-cancel to handle),
 *   and neither may be loosened with a "system" flag.
 * - Re-reads the row under lockForUpdate() in its own transaction (D-6), and
 *   returns silently for a missing or already-Cancelled order (D-7).
 */
class AutoCancelFullyRefundedOrder
{
    public function __invoke(string $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->status === OrderStatus::Cancelled) {
                return;
            }

            $order->forceFill(['status' => OrderStatus::Cancelled])->save();
        });
    }
}
