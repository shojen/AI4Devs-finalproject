<?php

namespace App\Actions\Shipping;

use App\Models\ShippingCarrier;
use Illuminate\Database\Eloquent\Collection;

class ListShippingRatesByCarrier
{
    /**
     * List every shipping rate, grouped by its carrier.
     *
     * D-11/D-10: this action gates NOTHING of its own -- it is a plain
     * query, with no route and no Livewire component (this story ships
     * neither); 0037 is its gating consumer.
     *
     * Grouping by EAGER LOAD (ShippingCarrier::with('shippingRates')),
     * never a PHP ->groupBy() over a flat rate list, is deliberate: a
     * carrier with ZERO rates must still appear as an empty group, which a
     * rate-driven grouping silently drops. Each rate additionally eager
     * loads its zone, so 0037's rate table renders with no N+1.
     *
     * Deliberately unfiltered by carrier `is_active` -- the listing is
     * configuration, not resolution (D-6); only
     * App\Actions\Shipping\ResolveApplicableShippingRate filters on the
     * carrier's active state, so a disabled carrier's rates still appear
     * here.
     *
     * @return Collection<int, ShippingCarrier> each with its `shippingRates`
     *                                          relation eager-loaded (and
     *                                          each rate's own `zone`),
     *                                          ordered by carrier name
     */
    public function __invoke(): Collection
    {
        return ShippingCarrier::query()
            ->orderBy('name')
            ->with(['shippingRates.zone'])
            ->get();
    }
}
