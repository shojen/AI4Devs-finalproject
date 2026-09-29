<?php

namespace App\Actions\Shipping;

use App\Enums\GeographyLevel;
use App\Models\ShippingRate;
use App\Models\ShippingZone;

/**
 * The result of App\Actions\Shipping\ResolveApplicableShippingRate --
 * carrying either the chosen rate, or the reason none was chosen, plus the
 * zone and tier level that decided the outcome either way (D-13).
 *
 * "No applicable rate" is deliberately NEVER expressed as a bare `null`
 * (D-1 step 4's no-fallback rule refuses to quote in a case an
 * administrator did not intend, so the refusal must be diagnosable): a
 * bare null at a checkout is indistinguishable between "we do not ship
 * there at all" and "we ship there, but your parcel is heavier than the
 * narrow zone's own ladder" -- and the second is a misconfiguration
 * somebody can fix in thirty seconds if they are told.
 */
final readonly class ShippingRateResolution
{
    private function __construct(
        public ?ShippingRate $rate,
        public ?string $reason,
        public ?ShippingZone $decidingZone,
        public ?GeographyLevel $decidingTier,
    ) {}

    /**
     * Build a resolved result: a rate was chosen.
     */
    public static function resolved(ShippingRate $rate, ShippingZone $decidingZone, GeographyLevel $decidingTier): self
    {
        return new self($rate, null, $decidingZone, $decidingTier);
    }

    /**
     * Build an unresolved result: no rate applies, for the given reason.
     *
     * $decidingZone/$decidingTier are provided whenever a winning tier WAS
     * found but its rates did not cover the parcel's weight
     * (ResolveApplicableShippingRate::REASON_NO_MATCHING_WEIGHT_BRACKET) --
     * omitted (both null) when no zone covers the destination at all
     * (REASON_NO_COVERING_ZONE), since there is no tier to name.
     */
    public static function unresolved(string $reason, ?ShippingZone $decidingZone = null, ?GeographyLevel $decidingTier = null): self
    {
        return new self(null, $reason, $decidingZone, $decidingTier);
    }

    /**
     * Whether a rate was chosen.
     */
    public function isResolved(): bool
    {
        return $this->rate !== null;
    }
}
