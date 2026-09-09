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

    /**
     * Phase 4 security-audit finding F-4: `$weightKg` was malformed --
     * non-numeric, or negative. Named as a THIRD reason on this same
     * unresolved-result shape, matching the two existing reasons above,
     * rather than an `InvalidArgumentException` -- no precedent for that
     * exception exists anywhere under app/Actions/ in this codebase, and
     * every existing caller of this action already pattern-matches on
     * `$resolution->reason`, so a third named reason keeps the one return
     * shape uniform instead of forcing every caller to also catch a second,
     * incompatible exception type for what is still, from the caller's
     * point of view, "no rate could be resolved for this input."
     */
    public const REASON_INVALID_WEIGHT = 'invalid_weight';

    public function __invoke(
        GeographyEntry $destination,
        float|string $weightKg,
        ?string $shippingCarrierId = null,
    ): ShippingRateResolution {
        // Phase 4 security-audit finding F-4: reject a non-numeric, empty, or negative
        // $weightKg as this action's own first statement -- BEFORE it ever reaches
        // scopeCoveringWeight()'s raw DECIMAL comparison. `is_numeric()` runs before any cast:
        // narrowing the parameter type to `float` alone would NOT fix this, since PHP's
        // (float) cast silently coerces a non-numeric string to 0.0 ((float) 'abc' === 0.0),
        // which would then silently match the lightest bracket and return a real (wrong)
        // price instead of refusing. A negative weight must not be misdiagnosed as an
        // ordinary coverage gap (REASON_NO_COVERING_ZONE/REASON_NO_MATCHING_WEIGHT_BRACKET) --
        // it is a caller input error, named as its own reason.
        if (! is_numeric($weightKg) || (float) $weightKg < 0) {
            return ShippingRateResolution::unresolved(self::REASON_INVALID_WEIGHT);
        }

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
            //
            // Phase 4 security-audit finding F-6: the active-carrier and optional
            // carrier-id filters are re-applied HERE too, not merely inherited from
            // $tierRates' own id list above -- a TOCTOU window between the two queries
            // (a carrier disabled between them) would otherwise let this second query
            // resolve a rate whose carrier no longer qualifies, since whereKey() alone
            // re-reads nothing about carrier state. The guarantee now lives in the
            // query that actually produces the answer, not one query away from it.
            $coveringRates = ShippingRate::query()
                ->whereKey($tierRates->pluck('id'))
                ->whereHas('carrier', fn ($carrierQuery) => $carrierQuery->where('is_active', true))
                ->when(
                    $shippingCarrierId !== null,
                    fn ($query) => $query->where('shipping_carrier_id', $shippingCarrierId),
                )
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
