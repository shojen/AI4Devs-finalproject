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
 * 6. `DB::transaction()`: write `quantity` and `line_total`, then
 *    recompute and persist the parent's totals.
 */
class UpdateOrderItemQuantity
{
    use OrderValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly RecalculateOrderTotals $recalculateOrderTotals,
        private readonly ToNumericString $toNumericString,
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

        return DB::transaction(function () use ($order, $orderItemId, $quantity): OrderItem {
            $item = OrderItem::query()->findOrFail($orderItemId);

            // D-4/R-1: the EXISTING unit_price column, never the product's live price. No
            // relation on $item is ever touched here -- that is the whole point of this line.
            $lineTotal = bcmul(($this->toNumericString)((string) $item->unit_price), (string) $quantity, 2);

            $item->forceFill([
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ])->save();

            ($this->recalculateOrderTotals)($order);

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
            throw new OrderNotEditableException;
        }
    }
}
