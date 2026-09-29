<?php

namespace App\Actions\Orders;

/**
 * Story 0053a -- the single implementation of `tax_amount = subtotal x (tax_rate / 100)`,
 * shared by ResolveOrderTaxRegion (0053) and RecalculateOrderTotals (0048) so the two can
 * never disagree about the same subtotal and rate, and by story 0054's virtual resolver.
 *
 * `tax_rate` is a PERCENTAGE (`21.000` means 21%), matching `sales_regions.rate`.
 * A null rate means "not configured" and yields `0.00` -- no tax is invented for it.
 * A rate of `0.000` is a real 0% and is computed, not short-circuited; the two share an
 * amount and stay distinguishable by `tax_rate`, never by this value.
 *
 * bcmath truncates, so the exact 5-decimal product is divided by 100 at 7 decimals and
 * nudged by half a cent before truncating to two: rounded half-up.
 *
 * A pure computation collaborator that authorizes nothing, container-resolved and
 * constructor-injected like ToNumericString (docs/conventions/code-style.md).
 */
class CalculateTaxAmount
{
    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string|null  $taxRate
     * @return numeric-string
     */
    public function __invoke(string $subtotal, ?string $taxRate): string
    {
        if ($taxRate === null) {
            return '0.00';
        }

        return bcadd(bcdiv(bcmul($subtotal, $taxRate, 5), '100', 7), '0.005', 2);
    }
}
