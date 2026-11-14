<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderCancellationBlockedException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Manually cancel an existing order (story 0050, PRD §3.2), permitted only
 * while `Pending`/`Processing` and never while `payment_status` is
 * `PartiallyRefunded` -- refused with no confirmation path in every other
 * state.
 *
 * An independent action, not a call into or out of `TransitionOrderStatus`
 * (D-5): cancellation is a state-machine allow-list over TWO dimensions
 * (`status` AND `payment_status`), where `TransitionOrderStatus` is a rank
 * comparison over one. `OrderStatus::rank()` deliberately has no
 * `Cancelled` arm (story 0049 D-3), so cancellation cannot be expressed as
 * a rank comparison at all without reopening that guard.
 *
 * Performs, in exactly this order (each property below is pinned by its
 * own test rather than by a comment):
 *
 * 1. `Gate::authorize('cancel', $order)` -- OUTSIDE the transaction, so the
 *    permission refusal always wins; an unauthorized caller must not learn
 *    the order's current state from the refusal that follows.
 * 2. `DB::transaction()` (story 0091, D-4 lock order `orders -> products ->
 *    product_variants -> order_items`): lock the `orders` row and re-run both
 *    state guards on it. Refuse if the order is already `Cancelled`, with a
 *    `ValidationException` on the `status` field (D-3) -- runs ABOVE the
 *    blocked guard, or an already-cancelled order (not in
 *    `{Pending, Processing}`) would fall into the blocked branch and
 *    report the wrong reason for the wrong case. Then refuse if
 *    `! $order->isManuallyCancellable()`, via a DIRECT THROW of
 *    `OrderCancellationBlockedException` -- never a second `Gate` check,
 *    so it binds a Super Admin actor too (see `OrderPolicy::cancel()`'s
 *    own docblock for why a Gate-mediated state rule would be inert
 *    against exactly that actor).
 * 3. Read the lines with a PLAIN, non-locking read through the order's own
 *    relation and compute each line's returnable units R via
 *    `OrderItem::returnableStockUnits()`. When `$adjustStock` is true and
 *    some R is above zero, call `AdjustOrderStock` once with `+R` deltas
 *    BEFORE any `FOR UPDATE` on `order_items`.
 * 4. Lock the lines (`orderBy('id')`) and re-verify quantity, refunded and
 *    undeducted counters and the set of line ids against the plain read,
 *    throwing a `LogicException` (rolling the whole transaction back, stock
 *    change included) on a mismatch. Then write
 *    `stock_undeducted_quantity = U + R` only for the lines
 *    `AdjustOrderStock` reported as applied; `stock_refunded_not_out_quantity`
 *    is never touched.
 * 5. Write the status, via `forceFill()` -- `status` is omitted from `Order`'s
 *    `#[Fillable]`.
 *
 * A `DB::transaction()` exists here, unlike `TransitionOrderStatus`'s D-3
 * note: cancellation with stock return performs a second write (catalog
 * stock and line counters) that must commit or roll back together with the
 * status change (story 0091). `payment_status` is read and never written
 * (D-1) -- cancelling triggers no refund; refunding stays
 * `App\Actions\Orders\RecordRefund`'s separately-performed operation.
 */
class CancelOrder
{
    public function __construct(private readonly AdjustOrderStock $adjustOrderStock) {}

    /**
     * There is no `bool $confirmed` parameter, and its absence is
     * specified (D-7): PRD §3.2 says manual cancellation in the guarded
     * states is "blocked", full stop -- unlike the backward transition
     * `TransitionOrderStatus` explicitly makes confirmable, so a
     * confirmation bypass would contradict the PRD.
     *
     * `$adjustStock` (story 0091) opts in to returning the order's
     * non-refunded, still-deducted units to the catalog stock.
     */
    public function __invoke(Order $order, bool $adjustStock = false): Order
    {
        Gate::authorize('cancel', $order);

        DB::transaction(function () use ($order, $adjustStock): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            $this->assertCancellable($lockedOrder);

            /** @var array<string, OrderItem> $plainItems */
            $plainItems = $lockedOrder->items()->get()->keyBy('id')->all();

            /** @var list<array{order_item: OrderItem, delta: int}> $deltas */
            $deltas = [];

            if ($adjustStock) {
                foreach ($plainItems as $item) {
                    $returnable = $item->returnableStockUnits();

                    if ($returnable > 0) {
                        $deltas[] = ['order_item' => $item, 'delta' => $returnable];
                    }
                }
            }

            $appliedIds = $deltas === [] ? [] : ($this->adjustOrderStock)($lockedOrder, $deltas);

            $lockedItems = $lockedOrder->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lockedIds = $lockedItems->keys()->all();
            $plainIds = array_keys($plainItems);
            sort($lockedIds);
            sort($plainIds);

            if ($lockedIds !== $plainIds) {
                throw new LogicException('The order lines changed while the order was being cancelled.');
            }

            foreach ($lockedItems as $lockedItem) {
                $plainItem = $plainItems[$lockedItem->id];

                if (
                    $lockedItem->quantity !== $plainItem->quantity
                    || $lockedItem->refunded_quantity !== $plainItem->refunded_quantity
                    || $lockedItem->stock_undeducted_quantity !== $plainItem->stock_undeducted_quantity
                ) {
                    throw new LogicException('The order line changed while the order was being cancelled.');
                }
            }

            foreach ($deltas as $entry) {
                if (! in_array($entry['order_item']->id, $appliedIds, true)) {
                    continue;
                }

                $lockedItem = $lockedItems[$entry['order_item']->id];
                $lockedItem->forceFill([
                    'stock_undeducted_quantity' => $lockedItem->stock_undeducted_quantity + $entry['delta'],
                ])->save();
            }

            $lockedOrder->forceFill(['status' => OrderStatus::Cancelled])->save();
        });

        $order->forceFill(['status' => OrderStatus::Cancelled])->syncOriginalAttribute('status');

        return $order;
    }

    /**
     * The two state guards, run on the order row locked inside the transaction.
     */
    private function assertCancellable(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => __('orders.cancellation.already_cancelled'),
            ]);
        }

        if (! $order->isManuallyCancellable()) {
            throw new OrderCancellationBlockedException;
        }
    }
}
