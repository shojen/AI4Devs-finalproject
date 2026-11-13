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
 * Story 0048 -- remove a line item from an open order. Same six-step order
 * as AddOrderItem (see that class's own docblock for the full reasoning),
 * with steps 4-6 differing:
 *
 * 4. Validate: the item exists AND belongs to `$order`
 *    (`orderItemOwnershipRules()`, R-5).
 * 5. No catalog resolution -- nothing is snapshotted on a removal.
 * 6. `DB::transaction()` (story 0090, D-7 lock order `orders -> products ->
 *    product_variants -> order_items -> totals`): (1) lock the `orders` row and re-verify the
 *    hard block (F-4); (2) read the line with a PLAIN, non-locking read through the order's own
 *    relation (F-5); (3) run the guards on plain reads, in order: refunded line, then last line
 *    (D-1 -- a ValidationException, never the 409: `OrderNotEditableException` means "this order
 *    is closed to editing", and this order is not -- the specific edit is invalid), plain
 *    `count() <= 1`; (4) when `$adjustStock` is true and the line's returnable units
 *    R = quantity - undeducted are above zero, call AdjustOrderStock (D-4); (5) only then lock the
 *    line and the order's whole item range, and re-verify on the locked reads that Q, F, U and
 *    both guards still hold, throwing a LogicException otherwise (the transaction, stock change
 *    included, rolls back); delete the row through the MODEL INSTANCE (`$item->delete()`, never
 *    `OrderItem::where(...)->delete()`, per base-standards.md's "deleting goes through the
 *    model" rule); (6) recompute and persist the parent's totals.
 *
 * Phase 4 security audit fixes applied to this action:
 * - F-3: D-1's last-item guard runs INSIDE the transaction, under the `orders` row lock. Story
 *   0090: it is now decided on a plain count (step 3) and confirmed on the locked range count
 *   (step 5). The TOCTOU race the original pre-transaction, unlocked count left open (two
 *   concurrent removals of the order's last two items both reading `count() === 2`) stays closed
 *   because every writer of an order's lines locks the `orders` row first, which serializes the
 *   two calls before either reads; the locked count is the confirmation.
 * - F-4: the hard block is re-checked a second time, inside the transaction,
 *   against an order re-read under `lockForUpdate()`.
 * - F-5: the line item is resolved THROUGH `$order->items()` rather than a
 *   global `OrderItem::query()`, a second, structural layer on top of
 *   `orderItemOwnershipRules()`'s own scoped `Rule::exists()`
 *   (docs/security/related-id-pair-resolution.md). Story 0090: the first fetch is unlocked and
 *   the row lock is taken after the stock locks; the locked re-read can only cause a refusal,
 *   never a wrong stock delta, if the plain snapshot was stale.
 * - NEW-3 (deadlock note): the item-row lock followed by a whole-range item count is a textbook
 *   AB/BA shape if two removals of two DIFFERENT items on one order could interleave; it is
 *   unreachable only because the `orders` row lock taken first serializes any two calls against
 *   the same order. That lock stays load-bearing for deadlock-freedom, and both `order_items`
 *   locking reads now sit after the stock locks (appsec L-1, project lock order) -- do not drop
 *   the `orders` lock or move either locking read above AdjustOrderStock.
 *
 * Post-condition (Phase 4 re-audit finding NEW-5): the `$order` PARAMETER is
 * never the row this action's own writes end up reflected on -- see
 * AddOrderItem's own docblock for the full reasoning (Laravel has no
 * identity map; RecalculateOrderTotals mutates a distinct, closure-local
 * `$lockedOrder`). This action returns `void`, so a caller has no handle at
 * all on the updated order after calling it and must re-fetch it.
 */
class RemoveOrderItem
{
    use OrderValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly RecalculateOrderTotals $recalculateOrderTotals,
        private readonly AdjustOrderStock $adjustOrderStock,
    ) {}

    public function __invoke(Order $order, string $orderItemId, bool $adjustStock = false): void
    {
        $order->refresh();

        $this->logRefusedPrivilegedAttempt->authorize('update', $order, targetType: 'order', targetId: $order->id);

        $this->assertEditable($order);

        Validator::make(
            ['order_item_id' => $orderItemId],
            ['order_item_id' => $this->orderItemOwnershipRules($order->id)]
        )->validate();

        // F-8 (Phase 4 security audit, informational): $lockedOrder/$item below must stay
        // entirely closure-local, locked and re-fetched INSIDE this transaction rather than
        // mutated from an outer-scope instance. RecalculateOrderTotals mutates the Order
        // instance it is given via forceFill()->save() -- if a future edit hoisted either fetch
        // back outside this closure and this transaction ever gained an `attempts: N` retry, a
        // retried attempt could silently skip the write, the exact shape
        // App\Actions\Products\UpdateProduct's own docblock describes
        // (docs/security/derived-column-invariants.md, "What the remediation introduced"). No
        // `attempts:` is used here today -- this is defensive documentation.
        DB::transaction(function () use ($order, $orderItemId, $adjustStock): void {
            // F-4: re-verify against the order's TRUE current state under lock.
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            $this->assertEditable($lockedOrder);

            // D-7 step 2: plain read through the order's relation (F-5 scoping), no row lock yet.
            $item = $lockedOrder->items()->findOrFail($orderItemId);

            $this->assertRemovable($item, $lockedOrder, $lockedOrder->items()->count());

            if ($adjustStock) {
                $returnable = $item->quantity - $item->stock_undeducted_quantity;

                if ($returnable > 0) {
                    ($this->adjustOrderStock)($lockedOrder, [['order_item' => $item, 'delta' => $returnable]]);
                }
            }

            // D-7 step 5: lock the line, then the range, only after the stock locks; re-verify.
            $lockedItem = $lockedOrder->items()->lockForUpdate()->findOrFail($orderItemId);
            $lockedCount = $lockedOrder->items()->lockForUpdate()->count();

            if (
                $lockedItem->quantity !== $item->quantity
                || $lockedItem->refunded_quantity !== $item->refunded_quantity
                || $lockedItem->stock_undeducted_quantity !== $item->stock_undeducted_quantity
            ) {
                throw new LogicException('The order line changed while its stock was being adjusted.');
            }

            $this->assertRemovable($lockedItem, $lockedOrder, $lockedCount, reverify: true);
            $item = $lockedItem;

            $item->delete();

            ($this->recalculateOrderTotals)($lockedOrder);
        });
    }

    /**
     * Story 0055 refunded-line rule, then the D-1 last-line rule. On the locked re-verify
     * (`$reverify`) a refusal means the plain read was stale, a programming-error path.
     */
    private function assertRemovable(OrderItem $item, Order $order, int $itemCount, bool $reverify = false): void
    {
        // `refunds.order_item_id` is restrictOnDelete() (0051 OQ-1), so a line with refunded units
        // can never be deleted -- refuse it as an invalid specific edit, never as a 500.
        if ($item->refunded_quantity > 0) {
            if ($reverify) {
                throw new LogicException('The order line changed while its stock was being adjusted.');
            }

            throw ValidationException::withMessages([
                'order_item_id' => __('orders.errors.refunded_line_item_cannot_be_removed'),
            ]);
        }

        if ($itemCount <= 1) {
            if ($reverify) {
                throw new LogicException('The order line count changed while its stock was being adjusted.');
            }

            $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'last_line_item', 'order', $order->id);

            throw ValidationException::withMessages([
                'order_item_id' => __('orders.errors.last_line_item_cannot_be_removed'),
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
