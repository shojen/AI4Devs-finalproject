<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
 * 6. `DB::transaction()`: re-verify the hard block against the order's TRUE
 *    current state under `lockForUpdate()` (Phase 4 security audit finding
 *    F-4), write `quantity` and `line_total`, then recompute and persist
 *    the parent's totals.
 *
 * Phase 4 security audit fixes applied to this action:
 * - F-1: `$lineTotal` is checked against the same decimal(10,2) column
 *   ceiling CreateOrder already enforces at create time (AssertWithinColumnCeiling).
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
    ) {}

    public function __invoke(Order $order, string $orderItemId, int $quantity): OrderItem
    {
        $order->refresh();

        $this->logRefusedPrivilegedAttempt->authorize('update', $order);

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
        return DB::transaction(function () use ($order, $orderItemId, $quantity): OrderItem {
            // F-4: re-verify against the order's TRUE current state under lock.
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);

            $this->assertEditable($lockedOrder);

            // F-5: resolve THROUGH the order's own relation, never a global query -- a second,
            // structural layer on top of orderItemOwnershipRules()'s already-scoped
            // Rule::exists()->where('order_id', ...) above, not a replacement for it.
            $item = $lockedOrder->items()->findOrFail($orderItemId);

            // D-4/R-1: the EXISTING unit_price column, never the product's live price. No
            // relation on $item is ever touched here -- that is the whole point of this line.
            $lineTotal = bcmul(($this->toNumericString)((string) $item->unit_price), (string) $quantity, 2);

            // F-1: the same decimal(10,2) column-overflow guard CreateOrder already applies.
            ($this->assertWithinColumnCeiling)($lineTotal, 'items');

            $item->forceFill([
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ])->save();

            ($this->recalculateOrderTotals)($lockedOrder);

            return $item;
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
