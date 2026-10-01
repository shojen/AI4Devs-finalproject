<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Story 0084 -- manually mark an order as paid (PRD: manual payment status).
 *
 * The only writer of `payment_status = Paid` and `paid_at` in the app. The
 * moment is always "now" (the click), so `__invoke()` takes no date
 * parameter; `LogRefusedPrivilegedAttempt` is constructor-injected to keep
 * the signature a one-parameter public contract.
 *
 * Performs, in this exact order:
 *
 * 1. Authorize `markPaid` through the refusal-logging wrapper, so the
 *    permission refusal always wins, reveals nothing about the order's
 *    state, and is recorded. This deliberately deviates from
 *    `CancelOrder`'s bare `Gate::authorize`.
 * 2. Refuse if `payment_status` is not `PendingPayment` (paid, partially
 *    refunded, refunded) -- a `ValidationException` on `payment_status`.
 * 3. Refuse if the order is `Cancelled` -- same field, different message.
 *    Checked AFTER already-paid, so `cancelled + refunded` reports "already
 *    paid". Both are direct throws (no second Gate check), so they bind a
 *    Super Admin too, and neither is logged: a state refusal is not a
 *    privilege attempt.
 * 4. Compare-and-set `UPDATE`: matches only a still-pending, not-cancelled
 *    row, so a lost race (two clicks, or a concurrent cancellation) writes
 *    nothing and `paid_at` is never rewritten. The two clauses are read
 *    separately above because the refusals differ; do not collapse them into
 *    `Order::isAwaitingPayment()`.
 *
 * No `DB::transaction()`: one statement, no follow-up row. A query-builder
 * `update()` fires no model events; there is no `Order` observer today, and
 * any future author adding one must account for that. The caller's instance
 * is synced in memory (no second write, no `refresh()`).
 */
class MarkOrderAsPaid
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    public function __invoke(Order $order): Order
    {
        $this->logRefusedPrivilegedAttempt->authorize('markPaid', $order, targetType: 'order', targetId: $order->id);

        $this->assertNotAlreadyPaid($order->payment_status);

        if ($order->status === OrderStatus::Cancelled) {
            throw $this->cancelledRefusal();
        }

        $now = $order->freshTimestamp()->startOfSecond();

        $affected = Order::query()
            ->whereKey($order->getKey())
            ->where('payment_status', PaymentStatus::PendingPayment->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->update([
                'payment_status' => PaymentStatus::Paid->value,
                'paid_at' => $now,
                'updated_at' => $now,
            ]);

        if ($affected === 1) {
            $order->setAttribute('payment_status', PaymentStatus::Paid);
            $order->setAttribute('paid_at', $now);
            $order->setAttribute('updated_at', $now);
            $order->syncOriginalAttributes(['payment_status', 'paid_at', 'updated_at']);

            return $order;
        }

        $current = Order::query()
            ->whereKey($order->getKey())
            ->select(['id', 'payment_status', 'status'])
            ->firstOrFail();

        $this->assertNotAlreadyPaid($current->payment_status);

        throw $this->cancelledRefusal();
    }

    /**
     * @throws ValidationException
     */
    private function assertNotAlreadyPaid(PaymentStatus $paymentStatus): void
    {
        if ($paymentStatus !== PaymentStatus::PendingPayment) {
            throw ValidationException::withMessages([
                'payment_status' => __('orders.payment.already_paid'),
            ]);
        }
    }

    private function cancelledRefusal(): ValidationException
    {
        return ValidationException::withMessages([
            'payment_status' => __('orders.payment.cancelled_blocked'),
        ]);
    }
}
