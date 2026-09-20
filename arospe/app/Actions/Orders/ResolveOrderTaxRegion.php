<?php

namespace App\Actions\Orders;

use App\Concerns\ResolvesSalesRegionFromAddress;
use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesRegion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Story 0053 -- resolves an order's tax Sales Region from its OWN frozen shipping address, for
 * orders whose line items are all physical (PRD §3.2), snapshots `tax_rate`, and derives
 * `tax_amount` / `total` in the same write (D-13). Invoked explicitly by the caller after
 * `CreateOrder` returns (D-8); returns void, so a caller re-reads `$order->fresh()`.
 *
 * Deliberately authorizes NOTHING (D-11): system-triggered resolution on the already-authorized
 * success path of `CreateOrder`, callable from a queued job with no acting user. Deliberately
 * does NOT call `ResolveProductTaxRate` (D-1): that resolver answers a per-product display
 * question; an order's tax is destination-based and resolved once per order.
 *
 * Idempotent (D-10): an order that already has a `sales_region_id` is left alone, so a re-run
 * against a changed catalog never re-snapshots a new rate onto an old order.
 *
 * A caller resolving many orders should eager-load `Order::with('items.product')` up front;
 * this action uses `loadMissing()` so it then pays no second query.
 */
class ResolveOrderTaxRegion
{
    use ResolvesSalesRegionFromAddress;

    public function __construct(
        private readonly ToNumericString $toNumericString,
        private readonly AssertWithinColumnCeiling $assertWithinColumnCeiling,
    ) {}

    public function __invoke(Order $order): void
    {
        if ($order->sales_region_id !== null) {
            return;
        }

        $order->loadMissing('items.product');

        $physical = 0;
        $virtual = 0;
        $unresolvable = false;

        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            match ($item->product?->type) {
                ProductType::Physical => $physical++,
                ProductType::Virtual => $virtual++,
                null => $unresolvable = true,
            };
        }

        // An order with no line items has no product type to classify; it is not this story's.
        if ($physical + $virtual === 0 && ! $unresolvable) {
            return;
        }

        // All virtual is story 0054's: write nothing, flag included, so a defer stays
        // distinguishable from a flagged ambiguity (D-2).
        if ($virtual > 0 && $physical === 0 && ! $unresolvable) {
            return;
        }

        // Mixed, or a line item whose product row is gone (D-2, D-3): resolved by neither
        // story, so record it for a human rather than guess.
        if ($virtual > 0 || $unresolvable) {
            $order->forceFill(['flagged_for_review' => true])->save();

            return;
        }

        $region = $this->resolveSalesRegionFromAddress($order->shipping_country, $order->shipping_postal_code);
        $flag = false;

        if ($region === null || ! $region->is_active || $region->children()->exists()) {
            $region = $this->defaultRegion();
            $flag = true;
        }

        $taxRate = $region->rate;

        // The region is known and its rate is not (D-6 case 5): record the region, keep the
        // rate null and ask for review.
        if ($taxRate === null) {
            $flag = true;
        }

        $subtotal = ($this->toNumericString)((string) $order->subtotal);
        $taxAmount = $taxRate !== null
            ? $this->percentageOf($subtotal, ($this->toNumericString)($taxRate))
            : '0.00';
        $total = bcadd(
            bcadd($subtotal, $taxAmount, 2),
            ($this->toNumericString)((string) $order->shipping_amount),
            2
        );

        ($this->assertWithinColumnCeiling)($total, 'items');

        DB::transaction(function () use ($order, $region, $taxRate, $taxAmount, $total, $flag): void {
            $attributes = [
                'sales_region_id' => $region->id,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ];

            if ($flag) {
                $attributes['flagged_for_review'] = true;
            }

            $order->forceFill($attributes)->save();
        });
    }

    /**
     * The row carrying `is_default`, resolved by that column and never by
     * `SalesRegionSeeder::DEFAULT_SLUG`: an administrator may move the default (D-6).
     */
    private function defaultRegion(): SalesRegion
    {
        return SalesRegion::query()->where('is_default', true)->first()
            ?? throw new RuntimeException('The Sales Region catalog has no default entry; cannot resolve an order\'s tax region.');
    }

    /**
     * `$amount x ($percentage / 100)`, rounded half-up to two decimals. `$percentage` is a
     * percentage (`21.000` means 21%), matching `sales_regions.rate`. bcmath truncates, so the
     * exact 5-decimal product is scaled and nudged by half a cent before truncating.
     *
     * @param  numeric-string  $amount
     * @param  numeric-string  $percentage
     * @return numeric-string
     */
    private function percentageOf(string $amount, string $percentage): string
    {
        $exact = bcdiv(bcmul($amount, $percentage, 5), '100', 7);

        return bcadd($exact, '0.005', 2);
    }
}
