<?php

namespace App\Actions\Orders;

use App\Concerns\OrderValidationRules;
use App\Enums\PaymentStatus;
use App\Events\OrderFullyRefunded;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Record a refund against one or more of an order's line items, expressed
 * as UNITS coming back rather than an arbitrary monetary amount (DR-1),
 * and derive the order's `payment_status` from the resulting line-item
 * state (D-4).
 *
 * Performs, in exactly this order (each property below is pinned by its
 * own test rather than by a comment):
 *
 * 1. `Gate::authorize('orders.refund')` -- a bare permission string, not an
 *    OrderPolicy ability (DR-2). The rule lives in the class that performs
 *    the operation, not in a caller that does not exist yet
 *    (docs/conventions/directory-structure.md). Runs FIRST so the
 *    permission refusal always wins -- an unauthorized caller must not
 *    learn the order's payment state from a validation refusal.
 * 2. Validate the payload SHAPE through App\Concerns\OrderValidationRules
 *    -- the items array and every quantity. This is shape only; ownership
 *    and the over-refund guard cannot be static rule arrays (see below).
 * 3. Open a DB::transaction(). Everything below runs inside it (D-12).
 * 4. Re-read the order and its items under a row lock -- not decoration
 *    (R-1): reading state INSIDE the lock is what makes the guards below
 *    atomic with the writes that follow them.
 * 5. Refuse by payment state. PendingPayment and Refunded -> refused
 *    (D-5, a ValidationException -- never an AuthorizationException,
 *    since this is never about WHO is asking). Accepted from Paid and
 *    PartiallyRefunded. Re-checked here, under the lock, rather than
 *    before the transaction, because a concurrent refund may have flipped
 *    it in between.
 * 6. Refuse by ownership. Every key in $items must be present in the
 *    locked collection -- an id naming another order's line item, or
 *    naming nothing at all, is refused, never a silent skip.
 * 7. Refuse by outstanding units. For each item:
 *    quantity_to_refund <= (quantity - refunded_quantity), evaluated
 *    against the item's CURRENT refunded_quantity, not its original
 *    quantity (D-6) -- the guard's real trigger.
 * 8. Write, per item: a `refunds` row (order_item_id, quantity,
 *    amount = quantity x unit_price, refunded_by = Auth::id(),
 *    reason = null -- see the $reason parameter's own docblock) then
 *    increment order_items.refunded_quantity via forceFill().
 * 9. Increment orders.refunded_amount by the sum of the amounts just
 *    written.
 * 10. Derive and write orders.payment_status (D-4), from the FINAL state
 *     of the locked items -- never from a delta:
 *     - every line fully refunded  -> Refunded
 *     - any line has units returned -> PartiallyRefunded
 *     - otherwise                   -> unchanged
 *     Evaluated in exactly that order, so a two-line order where BOTH
 *     lines are partially refunded reads PartiallyRefunded rather than
 *     falling into an unclassified case, and the last outstanding unit
 *     flips PartiallyRefunded -> Refunded within the same call.
 * 11. Return the refreshed Order.
 *
 * 12. AFTER the transaction commits (never inside it), dispatch
 *     OrderFullyRefunded when the committed payment_status is Refunded, so
 *     a rolled-back refund can never cancel an order (story 0052, D-5).
 *
 * `orders.status` is never written here -- the listener's action owns that.
 */
class RecordRefund
{
    use OrderValidationRules;

    public function __construct(
        private readonly ToNumericString $toNumericString,
    ) {}

    /**
     * @param  array<string, int>  $items  order_item_id => quantity_to_refund
     * @param  string|null  $reason  OQ-3, settled: accepted now so a future caller
     *                               never widens this public-contract signature, but deliberately IGNORED --
     *                               D-11 ships the `refunds.reason` column now and this story writes `null`
     *                               into it unconditionally; nothing here reads or writes a real reason yet.
     */
    public function __invoke(Order $order, array $items, ?string $reason = null): Order
    {
        Gate::authorize('orders.refund');

        Validator::make(
            ['items' => $items],
            ['items' => $this->refundItemsRules(), 'items.*' => $this->refundQuantityRules()],
        )->validate();

        $refreshedOrder = DB::transaction(function () use ($order, $items): Order {
            $order->refresh();

            /** @var Collection<string, OrderItem> $lockedItems */
            $lockedItems = $order->items()->lockForUpdate()->get()->keyBy('id');

            if (! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw ValidationException::withMessages([
                    'items' => __('orders.refunds.invalid_payment_state'),
                ]);
            }

            foreach (array_keys($items) as $orderItemId) {
                if (! $lockedItems->has($orderItemId)) {
                    throw ValidationException::withMessages([
                        'items' => __('orders.refunds.item_not_owned'),
                    ]);
                }
            }

            foreach ($items as $orderItemId => $quantityToRefund) {
                $lineItem = $lockedItems->get($orderItemId);
                $outstanding = $lineItem->quantity - $lineItem->refunded_quantity;

                if ($quantityToRefund > $outstanding) {
                    throw ValidationException::withMessages([
                        'items' => __('orders.refunds.exceeds_outstanding_units'),
                    ]);
                }
            }

            $totalRefundedAmount = '0.00';

            foreach ($items as $orderItemId => $quantityToRefund) {
                $lineItem = $lockedItems->get($orderItemId);

                $amount = bcmul(
                    ($this->toNumericString)((string) $lineItem->unit_price),
                    (string) $quantityToRefund,
                    2,
                );

                Refund::query()->forceCreate([
                    'order_item_id' => $lineItem->id,
                    'quantity' => $quantityToRefund,
                    'amount' => $amount,
                    'refunded_by' => Auth::id(),
                    'reason' => null,
                ]);

                $lineItem->forceFill([
                    'refunded_quantity' => $lineItem->refunded_quantity + $quantityToRefund,
                ])->save();

                $totalRefundedAmount = bcadd($totalRefundedAmount, $amount, 2);
            }

            $newRefundedAmount = bcadd(
                ($this->toNumericString)((string) $order->refunded_amount),
                $totalRefundedAmount,
                2,
            );

            $everyLineFullyRefunded = $lockedItems->every(
                fn (OrderItem $item): bool => $item->refunded_quantity === $item->quantity,
            );
            $anyLineRefunded = $lockedItems->contains(
                fn (OrderItem $item): bool => $item->refunded_quantity > 0,
            );

            $newPaymentStatus = match (true) {
                $everyLineFullyRefunded => PaymentStatus::Refunded,
                $anyLineRefunded => PaymentStatus::PartiallyRefunded,
                default => $order->payment_status,
            };

            $order->forceFill([
                'refunded_amount' => $newRefundedAmount,
                'payment_status' => $newPaymentStatus,
            ])->save();

            return $order->refresh();
        });

        if ($refreshedOrder->payment_status === PaymentStatus::Refunded) {
            OrderFullyRefunded::dispatch($refreshedOrder->id);
        }

        return $refreshedOrder;
    }
}
