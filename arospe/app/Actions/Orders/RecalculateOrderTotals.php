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
 * D-8: `tax_amount` is recomputed as `subtotal x (tax_rate / 100)` -- `tax_rate` is a
 * percentage -- by the shared CalculateTaxAmount, ONLY when `tax_rate` is already
 * non-null. `NULL` means "not configured" and `0.000` means "a legitimate 0%", so a
 * null `tax_rate` leaves `tax_amount` at `0.00` rather than inventing one. Nothing here
 * resolves a sales region or writes `tax_rate` itself; both stay exactly as they were
 * before this call. (Story 0053a fixed the missing `/ 100` this class originally shipped
 * without, and moved the formula into CalculateTaxAmount so it and ResolveOrderTaxRegion
 * cannot drift.)
 *
 * Phase 4 security audit finding F-1: `subtotal` and `total` are both
 * checked against the same decimal(10,2) column ceiling
 * `App\Actions\Orders\CreateOrder` already enforces at create time, via the
 * shared `AssertWithinColumnCeiling` collaborator -- see that class's own
 * docblock. `tax_amount` is deliberately NOT checked on its own: `total` (which is
 * checked) is `subtotal + tax_amount + shipping_amount` and every term is
 * non-negative, so a `tax_amount` above the ceiling implies a `total` above it. That
 * holds for any rate, including the above-100% ones `decimal(6,3)` can represent.
 * `CreateOrder` itself checks only `line_total` / `subtotal` / `total`, never
 * `tax_amount` in isolation -- this class mirrors exactly which three values that
 * action checks, not a superset.
 */
class RecalculateOrderTotals
{
    public function __construct(
        private readonly ToNumericString $toNumericString,
        private readonly AssertWithinColumnCeiling $assertWithinColumnCeiling,
        private readonly CalculateTaxAmount $calculateTaxAmount,
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

        ($this->assertWithinColumnCeiling)($subtotal, 'items');

        $taxAmount = ($this->calculateTaxAmount)(
            $subtotal,
            $order->tax_rate !== null ? ($this->toNumericString)((string) $order->tax_rate) : null,
        );

        $total = bcadd(bcadd($subtotal, $taxAmount, 2), ($this->toNumericString)((string) $order->shipping_amount), 2);

        ($this->assertWithinColumnCeiling)($total, 'items');

        $order->forceFill([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ])->save();
    }
}
