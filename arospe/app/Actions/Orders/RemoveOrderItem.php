<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Story 0048 -- remove a line item from an open order. Same six-step order
 * as AddOrderItem (see that class's own docblock for the full reasoning),
 * with steps 4-6 differing:
 *
 * 4. Validate: the item exists AND belongs to `$order`
 *    (`orderItemOwnershipRules()`, R-5), AND the order would not be left
 *    with zero line items (D-1 -- a ValidationException, never the 409:
 *    `OrderNotEditableException` means "this order is closed to editing",
 *    and this order is not -- the specific edit is invalid).
 * 5. No catalog resolution -- nothing is snapshotted on a removal.
 * 6. `DB::transaction()`: delete the row through the MODEL INSTANCE
 *    (`$item->delete()`, never `OrderItem::where(...)->delete()`, per
 *    base-standards.md's "deleting goes through the model" rule), then
 *    recompute and persist the parent's totals.
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

        // D-1: symmetry with 0045's own zero-line-item creation guard -- enforced here, before the
        // transaction opens, rather than as a database constraint (no engine expresses "a parent
        // must have at least one child" without a trigger).
        if ($order->items()->count() <= 1) {
            throw ValidationException::withMessages([
                'order_item_id' => __('orders.errors.last_line_item_cannot_be_removed'),
            ]);
        }

        DB::transaction(function () use ($order, $orderItemId): void {
            $item = OrderItem::query()->findOrFail($orderItemId);
            $item->delete();

            ($this->recalculateOrderTotals)($order);
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
            throw new OrderNotEditableException;
        }
    }
}
