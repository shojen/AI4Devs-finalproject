<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Lang;

/**
 * Resolves the copy shown for an order flagged for manual review (story 0055, D-15), shared by the
 * orders list marker and the order-detail callout so the two can never word the same flag differently.
 */
trait ResolvesFlagReasonLabel
{
    /**
     * The recorded reason's label, or the generic "needs attention" copy when `flag_reason` is NULL --
     * which is the MAJORITY of flagged orders today, since 0053 sets the flag without a reason in six
     * cases and only 0054 writes one. An unknown token also falls back to the generic copy rather than
     * rendering a raw lang key.
     */
    protected function flagReasonLabel(?string $reason): string
    {
        if ($reason !== null && Lang::has('orders.flag_reasons.'.$reason)) {
            return __('orders.flag_reasons.'.$reason);
        }

        return __('orders.index.flagged_generic');
    }
}
