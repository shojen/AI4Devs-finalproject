<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Story 0048 -- change a line item's quantity on an open order. Same
 * six-step order as AddOrderItem (see that class's own docblock for the
 * full reasoning), with steps 4-6 differing:
 *
 * 4. Validate: the item belongs to `$order` (`orderItemOwnershipRules()`,
 *    R-5); the quantity is `>= 1`.
 * 5. NO catalog read whatsoever -- this is the story's highest-risk line
 *    (D-4, R-1) and it is a NEGATIVE instruction: the new `line_total` is
 *    `$item->unit_price * $quantity`, using the EXISTING `unit_price`
 *    column. Never `$item->product->price`, never a re-resolution, never a
 *    "refresh the snapshot while we're here".
 * 6. `DB::transaction()` (story 0090, D-7 lock order `orders -> products ->
 *    product_variants -> order_items -> totals`): (1) lock the `orders` row and re-verify the
 *    hard block (F-4); (2) read the line with a PLAIN, non-locking read through
 *    `$lockedOrder->items()` (F-5) and take Q, F, U from it; (3) run the `< refunded_quantity`
 *    guard on that read and compute the stock delta and U'; (4) when `$adjustStock` is true and the
 *    delta is not zero, call AdjustOrderStock (it locks `products`, then `product_variants`, and
 *    writes stock); (5) only then lock the line (`lockForUpdate()`) and re-verify Q, F, U and the
 *    guard on the locked read, throwing a LogicException on any mismatch (the transaction, stock
 *    change included, rolls back); write `quantity`, `line_total` and the counter; (6) recompute and
 *    persist the parent's totals.
 *
 * Counters (D-2/D-3), with d the size of the change, F = `refunded_quantity` and U =
 * `stock_undeducted_quantity`: an increase is U + d; a decrease is U' = max(0, U - d). Stock moves
 * only with `$adjustStock = true`: an increase takes d out of stock, a decrease returns
 * k = max(0, d - U), the taken-out, non-refunded units that no longer fit. An unchanged quantity
 * moves nothing (D-5). `stock_refunded_not_out_quantity` is never touched here.
 *
 * Phase 4 security audit fixes applied to this action:
 * - F-1: `$lineTotal` is checked against the same decimal(10,2) column
 *   ceiling CreateOrder already enforces at create time (AssertWithinColumnCeiling).
 * - F-4: the hard block is re-checked a second time, inside the transaction,
 *   against an order re-read under `lockForUpdate()`.
 * - F-5: the line item is resolved THROUGH `$order->items()` rather than a
 *   global `OrderItem::query()`, a second, structural layer on top of
 *   `orderItemOwnershipRules()`'s own scoped `Rule::exists()`
 *   (docs/security/related-id-pair-resolution.md). Story 0090: the first fetch is now unlocked
 *   and the lock is taken after the stock locks (the project lock order puts `order_items` last).
 *   The plain read is correct because every writer of an existing order's lines locks the
 *   `orders` row first, so step 1 serializes them; the locked re-read (step 5) guards against a
 *   stale snapshot (an outer transaction or a future writer skipping the `orders` lock) and can only
 *   cause a refusal, never a wrong stock delta. The `orders` lock stays load-bearing.
 *
 * Post-condition (Phase 4 re-audit finding NEW-5): the `$order` PARAMETER is
 * never the row this action's own writes end up reflected on -- see
 * AddOrderItem's own docblock for the full reasoning (Laravel has no
 * identity map; RecalculateOrderTotals mutates a distinct, closure-local
 * `$lockedOrder`). Only the returned `OrderItem` is fresh; a caller needing
 * the order's own updated totals must re-fetch it.
 */
class UpdateOrderItemQuantity
{
    use OrderValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly RecalculateOrderTotals $recalculateOrderTotals,
        private readonly ToNumericString $toNumericString,
        private readonly AssertWithinColumnCeiling $assertWithinColumnCeiling,
        private readonly AdjustOrderStock $adjustOrderStock,
    ) {}

    public function __invoke(Order $order, string $orderItemId, int $quantity, bool $adjustStock = false): OrderItem
    {
        $order->refresh();

        $this->logRefusedPrivilegedAttempt->authorize('update', $order, targetType: 'order', targetId: $order->id);

        $this->assertEditable($order);

        Validator::make(
            ['order_item_id' => $orderItemId, 'quantity' => $quantity],
            [
                'order_item_id' => $this->orderItemOwnershipRules($order->id),
                'quantity' => $this->orderItemQuantityRules(),
            ]
        )->validate();

        // F-8 (Phase 4 security audit, informational): $lockedOrder/$item below must stay
        // entirely closure-local, re-fetched INSIDE this transaction rather than mutated from an
        // outer-scope instance. RecalculateOrderTotals mutates the Order instance it is given via
        // forceFill()->save() -- if a future edit hoisted either fetch back outside this closure
        // and this transaction ever gained an `attempts: N` retry, a retried attempt could
        // silently skip the write, the exact shape App\Actions\Products\UpdateProduct's own
        // docblock describes (docs/security/derived-column-invariants.md, "What the remediation
        // introduced"). No `attempts:` is used here today -- this is defensive documentation.
        return DB::transaction(function () use ($order, $orderItemId, $quantity, $adjustStock): OrderItem {
            // F-4: re-verify against the order's TRUE current state under lock.
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            $this->assertEditable($lockedOrder);

            // D-7 step 2: plain read through the order's relation (F-5 scoping), no row lock yet.
            $item = $lockedOrder->items()->findOrFail($orderItemId);

            $this->assertQuantityNotBelowRefunded($quantity, $item);

            $difference = $quantity - $item->quantity;
            // D-2: an increase leaves U unchanged when its units are taken out of stock, and adds d
            // (units never taken out) when it is not; D-3: a decrease is max(0, U - d) in both modes.
            $undeducted = $difference >= 0
                ? $item->stock_undeducted_quantity + ($adjustStock ? 0 : $difference)
                : max(0, $item->stock_undeducted_quantity + $difference);
            $stockDelta = $difference >= 0
                ? -$difference
                : max(0, -$difference - $item->stock_undeducted_quantity);

            if ($adjustStock && $stockDelta !== 0) {
                ($this->adjustOrderStock)($lockedOrder, [['order_item' => $item, 'delta' => $stockDelta]]);
            }

            // D-7 step 5: lock the line only after the stock locks and re-verify the step-2 values.
            $lockedItem = $lockedOrder->items()->lockForUpdate()->findOrFail($orderItemId);

            if (
                $lockedItem->quantity !== $item->quantity
                || $lockedItem->refunded_quantity !== $item->refunded_quantity
                || $lockedItem->stock_undeducted_quantity !== $item->stock_undeducted_quantity
            ) {
                throw new LogicException('The order line changed while its stock was being adjusted.');
            }

            $this->assertQuantityNotBelowRefunded($quantity, $lockedItem);
            $item = $lockedItem;

            // D-4/R-1: the EXISTING unit_price column, never the product's live price. No
            // relation on $item is ever touched here -- that is the whole point of this line.
            $lineTotal = bcmul(($this->toNumericString)((string) $item->unit_price), (string) $quantity, 2);

            // F-1: the same decimal(10,2) column-overflow guard CreateOrder already applies.
            ($this->assertWithinColumnCeiling)($lineTotal, 'items');

            $item->forceFill([
                'stock_undeducted_quantity' => $undeducted,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ])->save();

            ($this->recalculateOrderTotals)($lockedOrder);

            return $item;
        });
    }

    /**
     * Story 0055 (D-4 prep): the quantity can never drop below the units already refunded --
     * that would leave `refunded_quantity > quantity` and a negative outstanding balance.
     */
    private function assertQuantityNotBelowRefunded(int $quantity, OrderItem $item): void
    {
        if ($quantity < $item->refunded_quantity) {
            throw ValidationException::withMessages([
                'quantity' => __('orders.errors.quantity_below_refunded', ['refunded' => $item->refunded_quantity]),
            ]);
        }
    }

    /**
     * D-5: a direct throw, never a Gate ability -- duplicated identically
     * across all three of this story's actions rather than extracted; see
     * AddOrderItem::assertEditable()'s own docblock.
     *
     * Story 0055 (D-4): the status set now lives once, in Order::isLineItemEditable(); this
     * method keeps only the refusal logging and the throw.
     */
    private function assertEditable(Order $order): void
    {
        if (! $order->isLineItemEditable()) {
            // F-6 (Phase 4 security audit): logged as a refused privileged attempt, matching
            // this project's story 0015b convention.
            $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'order_not_editable', 'order', $order->id);

            throw new OrderNotEditableException;
        }
    }
}
