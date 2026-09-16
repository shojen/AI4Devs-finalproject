<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Story 0048 -- remove a line item from an open order. Same six-step order
 * as AddOrderItem (see that class's own docblock for the full reasoning),
 * with steps 4-6 differing:
 *
 * 4. Validate: the item exists AND belongs to `$order`
 *    (`orderItemOwnershipRules()`, R-5).
 * 5. No catalog resolution -- nothing is snapshotted on a removal.
 * 6. `DB::transaction()`: re-verify the hard block against the order's TRUE
 *    current state under `lockForUpdate()` (Phase 4 security audit finding
 *    F-4); lock+fetch the item through the order's own relation; lock+count
 *    the order's items and refuse (D-1 -- a ValidationException, never the
 *    409: `OrderNotEditableException` means "this order is closed to
 *    editing", and this order is not -- the specific edit is invalid) if
 *    that would leave zero line items; otherwise delete the row through the
 *    MODEL INSTANCE (`$item->delete()`, never `OrderItem::where(...)->delete()`,
 *    per base-standards.md's "deleting goes through the model" rule), then
 *    recompute and persist the parent's totals.
 *
 * Phase 4 security audit fixes applied to this action:
 * - F-3: D-1's last-item guard now runs INSIDE the transaction, under the
 *   same row lock as the item fetch and the order lock below -- previously
 *   an unlocked `count() <= 1` check run BEFORE the transaction opened,
 *   which left a genuine TOCTOU race: two concurrent removals of the
 *   order's last two items could both read `count() === 2` and both
 *   proceed, leaving zero line items.
 * - F-4: the hard block is re-checked a second time, inside the transaction,
 *   against an order re-read under `lockForUpdate()`.
 * - F-5: the line item is resolved THROUGH `$order->items()` rather than a
 *   global `OrderItem::query()`, a second, structural layer on top of
 *   `orderItemOwnershipRules()`'s own scoped `Rule::exists()`
 *   (docs/security/related-id-pair-resolution.md).
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
    ) {}

    public function __invoke(Order $order, string $orderItemId): void
    {
        $order->refresh();

        $this->logRefusedPrivilegedAttempt->authorize('update', $order);

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
        DB::transaction(function () use ($order, $orderItemId): void {
            // F-4: re-verify against the order's TRUE current state under lock.
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            $this->assertEditable($lockedOrder);

            // F-5: resolve THROUGH the order's own relation, never a global query -- a second,
            // structural layer on top of orderItemOwnershipRules()'s already-scoped
            // Rule::exists()->where('order_id', ...) above, not a replacement for it. F-3:
            // locked, matching the order lock and the count below.
            //
            // Deadlock note (Phase 4 re-audit finding NEW-3): locking one item row and then a
            // whole-range item count, in that order, would be a textbook AB/BA deadlock if two
            // concurrent removals targeted two DIFFERENT items on the same order -- it is
            // unreachable only because the `orders` row lock taken above already serializes any
            // two calls against the same order before either reaches this line. That order-row
            // lock is therefore load-bearing for deadlock-freedom here, not merely for the
            // count's own correctness -- do not drop it or reorder it below this point.
            $item = $lockedOrder->items()->lockForUpdate()->findOrFail($orderItemId);

            // F-3/D-1: moved inside the transaction, under the same lock as the item fetch
            // above -- closes the TOCTOU race the original pre-transaction, unlocked count left
            // open (two concurrent removals of the order's last two items could otherwise both
            // read count() === 2 and both proceed).
            if ($lockedOrder->items()->lockForUpdate()->count() <= 1) {
                $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'last_line_item', 'order', $lockedOrder->id);

                throw ValidationException::withMessages([
                    'order_item_id' => __('orders.errors.last_line_item_cannot_be_removed'),
                ]);
            }

            $item->delete();

            ($this->recalculateOrderTotals)($lockedOrder);
        });
    }

    /**
     * D-5: a direct throw, never a Gate ability -- duplicated identically
     * across all three of this story's actions rather than extracted; see
     * AddOrderItem::assertEditable()'s own docblock.
     */
    private function assertEditable(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
            // F-6 (Phase 4 security audit): logged as a refused privileged attempt, matching
            // this project's story 0015b convention.
            $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'order_not_editable', 'order', $order->id);

            throw new OrderNotEditableException;
        }
    }
}
