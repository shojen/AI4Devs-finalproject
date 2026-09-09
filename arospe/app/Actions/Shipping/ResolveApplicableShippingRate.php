<?php

namespace App\Actions\Shipping;

use App\Models\GeographyEntry;
use App\Models\ShippingRate;
use Illuminate\Support\Collection;

/**
 * Choose the rate rule that applies to a destination and parcel weight
 * (D-1).
 *
 * Walks the destination's ancestry chain (municipio -> comunidad autónoma ->
 * país) most-specific-first. The chain is bounded at THREE by 0032's schema,
 * which is why this is a fixed walk and not a WITH RECURSIVE query (D-1,
 * R-5) -- if 0032 ever gains a fourth level this decision is revisited.
 *
 * The winning tier is the most specific level carrying ANY rate rule that
 * passes the active-carrier and carrier-filter conditions -- NOT merely the
 * most specific level a zone covers, so a zone with no rate rules never
 * suppresses a broader one (0033 D-5's legal empty zone would otherwise
 * become a silent shipping outage). Once a tier is chosen it does NOT
 * reopen: if none of its rates cover the parcel's weight, this returns "no
 * rate" rather than falling back to a broader tier (D-1 step 4, R-2), and
 * the result names the zone whose ladder decided it (D-13) so the gap is
 * diagnosable.
 */
class ResolveApplicableShippingRate
{
    /**
     * No zone carrying ANY rate covers the destination at all.
     */
    public const REASON_NO_COVERING_ZONE = 'no_covering_zone';

    /**
     * A winning tier WAS found (D-1 step 2), but none of its rates cover
     * this parcel's weight, and D-1 step 4 forbids falling back to a
     * broader tier.
     */
    public const REASON_NO_MATCHING_WEIGHT_BRACKET = 'no_matching_weight_bracket';

    public function __invoke(
        GeographyEntry $destination,
        float|string $weightKg,
        ?string $shippingCarrierId = null,
    ): ShippingRateResolution {
        // ONE eager load for the whole chain, not three lazy hops.
        $destination->load('parent.parent');

        $ancestryChain = Collection::make([
            $destination,
            $destination->parent,
            $destination->parent?->parent,
        ])->filter()->values();

        /** @var GeographyEntry $entry */
        foreach ($ancestryChain as $entry) {
            // Step 2: does ANY rate rule qualify at this tier -- zone covers
            // this exact entry, carrier active, carrier filter applied?
            // Deliberately NOT weight-filtered yet (R-2): the tier is
            // selected by the PRESENCE of qualifying rate rules, never by
            // whether they happen to cover this specific parcel.
            $tierRates = ShippingRate::query()
                ->whereHas(
                    'zone',
                    fn ($zoneQuery) => $zoneQuery->whereHas(
                        'geographyEntries',
                        fn ($entriesQuery) => $entriesQuery->whereKey($entry->id),
                    ),
                )
                ->whereHas('carrier', fn ($carrierQuery) => $carrierQuery->where('is_active', true))
                ->when(
                    $shippingCarrierId !== null,
                    fn ($query) => $query->where('shipping_carrier_id', $shippingCarrierId),
                )
                ->with(['zone', 'carrier'])
                ->orderBy('price')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($tierRates->isEmpty()) {
                // No qualifying rate rule at this level at all -- this
                // level expresses no pricing intent (D-1 step 2's
                // presence-of-rules predicate), so keep walking to a
                // broader tier.
                continue;
            }

            // Winning tier found -- stop walking regardless of what
            // happens next (D-1 step 4).
            $coveringRates = ShippingRate::query()
                ->whereKey($tierRates->pluck('id'))
                ->coveringWeight($weightKg)
                ->with(['zone', 'carrier'])
                ->orderBy('price')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($coveringRates->isEmpty()) {
                // The winning tier's own ladder does not cover this
                // weight -- do NOT fall back to a broader tier (D-1 step
                // 4). Name the zone that decided it (D-13) so the gap is
                // diagnosable: the first rate in the tier's own
                // deterministic order, since multiple zones may share one
                // tier.
                $decidingRate = $tierRates->first();

                return ShippingRateResolution::unresolved(
                    self::REASON_NO_MATCHING_WEIGHT_BRACKET,
                    $decidingRate->zone,
                    $entry->level,
                );
            }

            $chosen = $coveringRates->first();

            return ShippingRateResolution::resolved($chosen, $chosen->zone, $entry->level);
        }

        // No level of the ancestry chain had ANY qualifying rate rule at
        // all -- nothing covers this destination.
        return ShippingRateResolution::unresolved(self::REASON_NO_COVERING_ZONE);
    }
}
