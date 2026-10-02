<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Story 0084 -- manually mark an order as paid (PRD: manual payment status).
 *
 * The only writer of `payment_status = Paid` and of `order_payments` rows in
 * the app. The moment is always "now" (the click), so `__invoke()` takes no
 * date parameter; the payment method and type are the caller's choice and are
 * stored on the payment row, never on `orders`. `LogRefusedPrivilegedAttempt`
 * is constructor-injected to keep the public contract to three parameters.
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
 * 4. One `DB::transaction()` holding (a) the compare-and-set `UPDATE` on
 *    `orders`, which matches only a still-pending, not-cancelled row, and (b)
 *    only when exactly one row was affected, the `order_payments` INSERT. A
 *    lost race writes nothing; a unique violation on `order_id` (a data
 *    anomaly) propagates uncaught and rolls the `UPDATE` back. The two clauses
 *    are read separately above because the refusals differ; do not collapse
 *    them into `Order::isAwaitingPayment()`.
 *
 * A query-builder `update()` fires no model events; there is no `Order`
 * observer today, and any future author adding one must account for that. The
 * caller's instance is synced in memory (no second write, no `refresh()`).
 */
class MarkOrderAsPaid
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Order $order, PaymentMethod $paymentMethod, OrderPaymentType $type): Order
    {
        $this->logRefusedPrivilegedAttempt->authorize('markPaid', $order, targetType: 'order', targetId: $order->id);

        $this->assertNotAlreadyPaid($order->payment_status);

        if ($order->status === OrderStatus::Cancelled) {
            throw $this->cancelledRefusal();
        }

        $now = $order->freshTimestamp()->startOfSecond();

        $payment = DB::transaction(function () use ($order, $paymentMethod, $type, $now): ?OrderPayment {
            $affected = Order::query()
                ->whereKey($order->getKey())
                ->where('payment_status', PaymentStatus::PendingPayment->value)
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->update([
                    'payment_status' => PaymentStatus::Paid->value,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                return null;
            }

            return OrderPayment::query()->forceCreate([
                'order_id' => $order->getKey(),
                'payment_method_id' => $paymentMethod->getKey(),
                'type' => $type,
                'paid_at' => $now,
            ]);
        });

        if ($payment !== null) {
            $order->setAttribute('payment_status', PaymentStatus::Paid);
            $order->setAttribute('updated_at', $now);
            $order->syncOriginalAttributes(['payment_status', 'updated_at']);
            $order->setRelation('payment', $payment);

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
