<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Story 0048 -- the single implementation of D-7's recomputation rule
 * (`subtotal` = sum of `order_items.line_total`, `tax_amount` recomputed
 * only when `tax_rate` is already resolved, `shipping_amount` read and
 * never computed, `total` the sum of all three), shared by
 * AddOrderItem / RemoveOrderItem / UpdateOrderItemQuantity so this
 * story's own reason for being one story rather than three -- one
 * invariant, specified and tested once -- also applies to its own
 * recalculation logic rather than being duplicated three times.
 *
 * Deliberately authorizes NOTHING of its own: a collaborator invoked only
 * by an already-authorized action, from inside that action's own
 * transaction, after the line-item write -- the same structural shape
 * App\Actions\Products\SyncProductGallery / SyncProductSalesRegions
 * already establish (docs/conventions/directory-structure.md). Never
 * independently reachable.
 *
 * D-8: `tax_amount` is recomputed as `subtotal x tax_rate` ONLY when
 * `tax_rate` is already non-null -- `NULL` means "not configured" and
 * `0.000` means "a legitimate 0%", so a null `tax_rate` leaves
 * `tax_amount` at `0.00` rather than inventing one. Nothing here resolves
 * a sales region or writes `tax_rate` itself; both stay exactly as they
 * were before this call.
 */
class RecalculateOrderTotals
{
    public function __construct(
        private readonly ToNumericString $toNumericString,
    ) {}

    public function __invoke(Order $order): void
    {
        // A plain foreach accumulator, matching App\Actions\Orders\CreateOrder's own $subtotal
        // loop shape -- not Collection::reduce(), whose generic return type loses the
        // numeric-string narrowing reduce()'s own callback return type would otherwise carry,
        // which would force a suppressive @var override rather than a genuine runtime check.
        $subtotal = '0.00';

        /** @var OrderItem $item */
        foreach ($order->items()->get() as $item) {
            $subtotal = bcadd($subtotal, ($this->toNumericString)((string) $item->line_total), 2);
        }

        $taxAmount = $order->tax_rate !== null
            ? bcmul($subtotal, ($this->toNumericString)((string) $order->tax_rate), 2)
            : '0.00';

        $total = bcadd(bcadd($subtotal, $taxAmount, 2), ($this->toNumericString)((string) $order->shipping_amount), 2);

        $order->forceFill([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ])->save();
    }
}
