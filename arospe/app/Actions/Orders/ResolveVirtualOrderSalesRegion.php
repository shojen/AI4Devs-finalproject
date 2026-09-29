<?php

namespace App\Actions\Orders;

use App\Concerns\ResolvesSalesRegionFromAddress;
use App\Enums\ProductType;
use App\Exceptions\NoDefaultSalesRegionException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesRegion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Story 0054 -- resolves the tax Sales Region of an order whose line items are all virtual from
 * its OWN frozen billing address, after the geo/fraud check PRD §3.2 describes, and derives
 * `tax_amount` / `total` in the same write. The virtual sibling of ResolveOrderTaxRegion (0053).
 * Invoked explicitly after `CreateOrder` returns (D-8); returns void, so a caller re-reads
 * `$order->fresh()`.
 *
 * Geo check (owner decision overriding the spec's interim D-1): the IP-derived country is
 * MANDATORY. An order with none is flagged (REASON_IP_COUNTRY_MISSING) rather than resolved, and
 * so is one whose IP country differs from `billing_country`. A flagged order is NEVER also
 * resolved (D-5): the flag paths write only the flag. An absent `billing_country` is a data gap,
 * not a mismatch (D-3). Until a channel captures real IPs, OrderFactory/seeders supply a test one.
 *
 * The rate is the resolved region's own `rate`, once per order -- not ResolveProductTaxRate's
 * per-product answer -- exactly as 0053's D-1 decides; null and '0.000' stay distinct.
 *
 * Authorizes nothing (D-6): a pipeline step that may run with no acting user. Idempotent by
 * recomputation: `total` is rebuilt from `subtotal`, never accumulated.
 */
class ResolveVirtualOrderSalesRegion
{
    use ResolvesSalesRegionFromAddress;

    public const REASON_BILLING_IP_COUNTRY_MISMATCH = 'billing_ip_country_mismatch';

    public const REASON_IP_COUNTRY_MISSING = 'ip_country_missing';

    public const REASON_MIXED_BASKET = 'mixed_basket';

    public function __construct(
        private readonly ToNumericString $toNumericString,
        private readonly CalculateTaxAmount $calculateTaxAmount,
        private readonly AssertWithinColumnCeiling $assertWithinColumnCeiling,
    ) {}

    /**
     * @throws InvalidArgumentException when the order is not virtual or mixed (0053's, or empty)
     * @throws NoDefaultSalesRegionException when the fallback needs a catalog default that is absent
     */
    public function __invoke(Order $order): void
    {
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

        if ($virtual === 0 && ! $unresolvable) {
            throw new InvalidArgumentException('Only orders containing virtual products are resolved by this action.');
        }

        // Mixed, or a line whose product is gone: resolved by neither story, flagged by either (D-2).
        if ($physical > 0 || $unresolvable) {
            $this->flag($order, self::REASON_MIXED_BASKET);

            return;
        }

        if ($order->ip_derived_country === null || trim($order->ip_derived_country) === '') {
            $this->flag($order, self::REASON_IP_COUNTRY_MISSING);

            return;
        }

        $billingCountry = trim((string) $order->billing_country);

        if ($billingCountry !== '' && strcasecmp($billingCountry, trim($order->ip_derived_country)) !== 0) {
            $this->flag($order, self::REASON_BILLING_IP_COUNTRY_MISMATCH);

            return;
        }

        $region = $this->resolveSalesRegionFromAddress($order->billing_country, $order->billing_postal_code);

        // Unmapped, inactive or a heading row: the catalog default, without a flag -- an
        // address-less customer is legitimate (D-3), and 0053's fallback shape otherwise.
        if ($region === null || ! $region->is_active || $region->children()->exists()) {
            $region = $this->defaultRegion();
        }

        $taxRate = $region->rate;
        $subtotal = ($this->toNumericString)((string) $order->subtotal);
        $taxAmount = ($this->calculateTaxAmount)(
            $subtotal,
            $taxRate !== null ? ($this->toNumericString)($taxRate) : null,
        );
        $total = bcadd(
            bcadd($subtotal, $taxAmount, 2),
            ($this->toNumericString)((string) $order->shipping_amount),
            2
        );

        ($this->assertWithinColumnCeiling)($total, 'items');

        DB::transaction(function () use ($order, $region, $taxRate, $taxAmount, $total): void {
            $order->forceFill([
                'sales_region_id' => $region->id,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ])->save();
        });
    }

    private function flag(Order $order, string $reason): void
    {
        $order->forceFill(['flagged_for_review' => true, 'flag_reason' => $reason])->save();
    }

    /**
     * Resolved by `is_default`, never by slug: an administrator may move the default.
     */
    private function defaultRegion(): SalesRegion
    {
        return SalesRegion::query()->where('is_default', true)->first()
            ?? throw new NoDefaultSalesRegionException('The Sales Region catalog has no default entry; cannot resolve an order\'s tax region.');
    }
}
