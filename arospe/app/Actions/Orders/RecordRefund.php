<?php

namespace App\Actions\Orders;

use App\Concerns\OrderValidationRules;
use App\Enums\PaymentStatus;
use App\Events\OrderFullyRefunded;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

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
 * 4. Lock the `orders` row, then read the order's items with a PLAIN, non-locking
 *    read (story 0092b, D-4; same pattern as story 0090 D-7). Lock order is
 *    `orders -> products -> product_variants -> order_items` (docs/api/orders.md,
 *    lock-order convention, appsec L-1): `AdjustOrderStock` locks catalog rows, so it must run
 *    BEFORE any `FOR UPDATE` on `order_items`. The plain read is safe because every writer of
 *    an order's lines locks the `orders` row first, which serializes them before this read
 *    (the InnoDB read view starts after that lock is granted). Guards 5-7 decide on this
 *    plain read; step 7b confirms it on the locked rows. The caller's instance is
 *    refreshed on return, as before.
 * 5. Refuse by payment state. PendingPayment and Refunded -> refused
 *    (D-5, a ValidationException -- never an AuthorizationException,
 *    since this is never about WHO is asking). Accepted from Paid and
 *    PartiallyRefunded. Re-checked here, under the `orders` lock, rather than
 *    before the transaction, because a concurrent refund may have flipped
 *    it in between.
 * 6. Refuse by ownership. Every key in $items must be present in the
 *    order's items -- an id naming another order's line item, or
 *    naming nothing at all, is refused, never a silent skip.
 * 7. Refuse by outstanding units. For each item:
 *    quantity_to_refund <= (quantity - refunded_quantity), evaluated
 *    against the item's CURRENT refunded_quantity, not its original
 *    quantity (D-6) -- the guard's real trigger.
 * 7a. Validate `$stockReturns` (story 0092b, D-2): every key is a key of `$items`
 *    (checked before a `0` entry is skipped), units <= the units refunded now, and units <= R
 *    (`returnableStockUnits()` read before the refund). Business refusals are field-level
 *    `stock_returns`. Then, when any entry is non-zero, call `AdjustOrderStock` (positive
 *    deltas), which reports the ids whose stock was actually adjusted.
 * 7b. Lock the items (`orderBy('id')->lockForUpdate()`) and re-verify Q, F
 *    (`refunded_quantity`), U and K for every line in `$items` against the plain read; a
 *    mismatch or a missing line throws a LogicException and the transaction (stock change
 *    included) rolls back. Every write below uses these LOCKED rows.
 * 8. Write, per item: a `refunds` row (order_item_id, quantity,
 *    amount = quantity x unit_price, refunded_by = Auth::id(),
 *    reason = null -- see the $reason parameter's own docblock) then
 *    increment order_items.refunded_quantity via forceFill(). Story 0088:
 *    before that increment the line's stock counters follow the D8 refund
 *    rule (`applyRefundToStockCounters($quantity, $restocked)`, `$restocked` being the requested
 *    units only for lines whose stock `AdjustOrderStock` actually adjusted, 0 otherwise), persisted in the same
 *    save.
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
        private readonly AdjustOrderStock $adjustOrderStock,
    ) {}

    /**
     * @param  array<string, int>  $items  order_item_id => quantity_to_refund
     * @param  string|null  $reason  OQ-3, settled: accepted now so a future caller
     *                               never widens this public-contract signature, but deliberately IGNORED --
     *                               D-11 ships the `refunds.reason` column now and this story writes `null`
     *                               into it unconditionally; nothing here reads or writes a real reason yet.
     * @param  array<string, int>  $stockReturns  order_item_id => units to put back in stock (default: none);
     *                                            each at most the units refunded now and at most the units still out of inventory
     */
    public function __invoke(Order $order, array $items, ?string $reason = null, array $stockReturns = []): Order
    {
        Gate::authorize('orders.refund');

        Validator::make(
            ['items' => $items, 'stock_returns' => $stockReturns],
            [
                'items' => $this->refundItemsRules(),
                'items.*' => $this->refundQuantityRules(),
                'stock_returns' => $this->refundStockReturnRules(),
                'stock_returns.*' => $this->refundStockReturnUnitRules(),
            ],
        )->validate();

        $refreshedOrder = DB::transaction(function () use ($order, $items, $stockReturns): Order {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            /** @var Collection<string, OrderItem> $plainItems */
            $plainItems = $lockedOrder->items()->orderBy('id')->get()->keyBy('id');

            if (! $lockedOrder->isRefundable()) {
                throw ValidationException::withMessages([
                    'items' => __('orders.refunds.invalid_payment_state'),
                ]);
            }

            foreach (array_keys($items) as $orderItemId) {
                if (! $plainItems->has($orderItemId)) {
                    throw ValidationException::withMessages([
                        'items' => __('orders.refunds.item_not_owned'),
                    ]);
                }
            }

            foreach ($items as $orderItemId => $quantityToRefund) {
                $lineItem = $plainItems->get($orderItemId);
                $outstanding = $lineItem->quantity - $lineItem->refunded_quantity;

                if ($quantityToRefund > $outstanding) {
                    throw ValidationException::withMessages([
                        'items' => __('orders.refunds.exceeds_outstanding_units'),
                    ]);
                }
            }

            $this->assertStockReturnsAllowed($items, $stockReturns, $plainItems);

            $restockDeltas = [];

            foreach ($stockReturns as $orderItemId => $units) {
                if ($units > 0) {
                    $restockDeltas[] = ['order_item' => $plainItems->get($orderItemId), 'delta' => $units];
                }
            }

            $adjustedItemIds = $restockDeltas === []
                ? []
                : ($this->adjustOrderStock)($lockedOrder, $restockDeltas);

            /** @var Collection<string, OrderItem> $lockedItems */
            $lockedItems = $lockedOrder->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach (array_keys($items) as $orderItemId) {
                $plain = $plainItems->get($orderItemId);
                $locked = $lockedItems->get($orderItemId);

                if (
                    $locked === null
                    || $locked->quantity !== $plain->quantity
                    || $locked->refunded_quantity !== $plain->refunded_quantity
                    || $locked->stock_undeducted_quantity !== $plain->stock_undeducted_quantity
                    || $locked->stock_refunded_not_out_quantity !== $plain->stock_refunded_not_out_quantity
                ) {
                    throw new LogicException('The order line changed while its stock was being adjusted.');
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

                $restocked = in_array($lineItem->id, $adjustedItemIds, true)
                    ? (int) ($stockReturns[$lineItem->id] ?? 0)
                    : 0;

                $lineItem->applyRefundToStockCounters($quantityToRefund, $restocked);

                $lineItem->forceFill([
                    'stock_undeducted_quantity' => $lineItem->stock_undeducted_quantity,
                    'stock_refunded_not_out_quantity' => $lineItem->stock_refunded_not_out_quantity,
                    'refunded_quantity' => $lineItem->refunded_quantity + $quantityToRefund,
                ])->save();

                $totalRefundedAmount = bcadd($totalRefundedAmount, $amount, 2);
            }

            $newRefundedAmount = bcadd(
                ($this->toNumericString)((string) $lockedOrder->refunded_amount),
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
                default => $lockedOrder->payment_status,
            };

            $lockedOrder->forceFill([
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

    /**
     * Story 0092b, D-2: every refusal here is field-level `stock_returns`. The key check runs
     * before the zero skip; the cap uses R read from the plain items, before the refund.
     *
     * @param  array<string, int>  $items
     * @param  array<string, int>  $stockReturns
     * @param  Collection<string, OrderItem>  $plainItems
     *
     * @throws ValidationException
     */
    private function assertStockReturnsAllowed(array $items, array $stockReturns, Collection $plainItems): void
    {
        foreach ($stockReturns as $orderItemId => $units) {
            if (! array_key_exists($orderItemId, $items)) {
                throw ValidationException::withMessages([
                    'stock_returns' => __('orders.refunds.stock_return_not_in_refund'),
                ]);
            }

            if ($units === 0) {
                continue;
            }

            if ($units > $items[$orderItemId]) {
                throw ValidationException::withMessages([
                    'stock_returns' => __('orders.refunds.stock_return_exceeds_refunded'),
                ]);
            }

            if ($units > $plainItems->get($orderItemId)->returnableStockUnits()) {
                throw ValidationException::withMessages([
                    'stock_returns' => __('orders.refunds.stock_return_not_out_of_stock'),
                ]);
            }
        }
    }
}
