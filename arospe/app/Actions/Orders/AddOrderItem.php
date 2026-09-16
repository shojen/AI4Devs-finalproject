<?php

namespace App\Actions\Orders;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\OrderValidationRules;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Story 0048 -- add a line item to an open order. `__invoke()`'s parameter
 * list is a public contract every direct-call test matches verbatim
 * (docs/conventions/code-style.md), so `LogRefusedPrivilegedAttempt` and
 * `RecalculateOrderTotals` are constructor-injected rather than added to
 * the signature.
 *
 * Performs, in this exact order (the ordering is part of the guard, not an
 * implementation detail -- errors-log-archive.md's "two of three security
 * audit rounds found the flaw in the previous round's fix" entry):
 *
 * 1. Reload the order's own state from the database, before anything reads
 *    it -- a caller may hand over a stale instance.
 * 2. `Gate::authorize('update', $order)` (via the refusal-logging wrapper),
 *    against `App\Policies\OrderPolicy::update()`, which gates on
 *    `orders.edit` alone (D-2) -- the permission refusal always wins over
 *    the state refusal (D-6), so this runs strictly before the hard block.
 * 3. The hard-block guard (D-5): a direct `throw`, never a Gate ability
 *    and never an OrderPolicy method, so it binds a Super Admin exactly as
 *    it binds anyone else.
 * 4. Validate: the product exists, the optional variant exists and belongs
 *    to THIS product, the quantity is `>= 1`.
 * 5. Resolve the catalog row from the database and derive `unit_price`,
 *    `product_name`, `product_sku` from it -- the caller supplies only an
 *    id and a quantity, never a price, a name or a SKU (D-4). When a
 *    variant is named, its own price/SKU are used, never the parent
 *    product's -- matching CreateOrder's identical D-15 rule.
 * 6. `DB::transaction()`: insert the `order_items` row, then recompute and
 *    persist the parent's totals (D-7/D-8) via RecalculateOrderTotals.
 */
class AddOrderItem
{
    use OrderValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly RecalculateOrderTotals $recalculateOrderTotals,
        private readonly ToNumericString $toNumericString,
    ) {}

    public function __invoke(Order $order, string $productId, ?string $productVariantId, int $quantity): OrderItem
    {
        $order->refresh();

        $this->logRefusedPrivilegedAttempt->authorize('update', $order);

        $this->assertEditable($order);

        Validator::make(
            [
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
            ],
            [
                ...$this->orderItemProductRules($productId),
                'quantity' => $this->orderItemQuantityRules(),
            ]
        )->validate();

        $product = Product::query()->findOrFail($productId);
        $variant = $productVariantId !== null
            ? ProductVariant::query()->findOrFail($productVariantId)
            : null;

        // A plain `->` (not `?->`) on the left of `??` is intentional, not an oversight --
        // matching App\Actions\Orders\CreateOrder's own identical pattern: PHP's `??` uses
        // isset()-like semantics for a bare property-access chain, so it never throws even when
        // $variant is null (Larastan's own nullsafe.neverNull rule would flag a redundant `?->`).
        $unitPrice = ($this->toNumericString)((string) ($variant->price ?? $product->price));
        $productSku = (string) ($variant->sku ?? $product->sku);
        $lineTotal = bcmul($unitPrice, (string) $quantity, 2);

        return DB::transaction(function () use ($order, $product, $variant, $quantity, $unitPrice, $productSku, $lineTotal): OrderItem {
            $item = OrderItem::forceCreate([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'product_sku' => $productSku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            ($this->recalculateOrderTotals)($order);

            return $item;
        });
    }

    /**
     * D-5: a direct throw, never a Gate ability, duplicated identically
     * across all three of this story's actions rather than extracted --
     * three call sites in one folder do not yet justify the indirection
     * (the same threshold D-5 states explicitly; extract once a fourth
     * appears).
     */
    private function assertEditable(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
            throw new OrderNotEditableException;
        }
    }
}
