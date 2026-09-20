<?php

namespace App\Concerns;

use App\Models\SalesRegion;
use Illuminate\Support\Str;

/**
 * Story 0053 (D-4/D-5) -- the country -> Sales Region and Spain postal-prefix mapping, shared
 * by the physical (shipping address) and virtual (billing address, story 0054) tax-region
 * resolvers so it exists in exactly one place.
 *
 * It MAPS and nothing more: no `is_active` filter, no default fallback and no review flag.
 * All three stay in the calling action because the two resolvers' fallbacks genuinely differ;
 * a shared mapping is not a shared fallback.
 */
trait ResolvesSalesRegionFromAddress
{
    /**
     * Spain's non-Peninsula fiscal territories keyed by the integer value of the first two
     * characters of the postal code -- the Spanish province code (D-5). Integer keys because PHP
     * would silently coerce '35' to an int but not '07'. A PHP constant rather than a table, a config file or `sales_regions.code`: it
     * is a closed set fixed by Spanish law, never administrator-edited. Both Canary prefixes
     * (35 and 38) are deliberately separate entries.
     *
     * @var array<int, string>
     */
    public const SPAIN_POSTAL_PREFIX_TERRITORIES = [
        7 => 'es-baleares',
        35 => 'es-canarias',
        38 => 'es-canarias',
        51 => 'es-ceuta',
        52 => 'es-melilla',
    ];

    /**
     * Any other real Spanish province prefix (`01`-`52`) is mainland Spain.
     */
    public const SPAIN_MAINLAND_TERRITORY_SLUG = 'es-peninsula';

    /**
     * Map an address to the catalog row it belongs to, or null when it maps to none.
     * The returned row may be inactive or a heading; the caller decides what that means.
     */
    protected function resolveSalesRegionFromAddress(?string $countryCode, ?string $postalCode): ?SalesRegion
    {
        $countryCode = trim((string) $countryCode);

        if ($countryCode === '') {
            return null;
        }

        // Lower-cased at the query, not assumed of the stored value: `*_country` is only
        // regex-validated as two letters and never normalised on write (D-4, R-4).
        $slug = Str::lower($countryCode);

        if ($slug === 'es') {
            $slug = $this->spainTerritorySlug($postalCode);

            if ($slug === null) {
                return null;
            }
        }

        return SalesRegion::query()->where('slug', $slug)->first();
    }

    private function spainTerritorySlug(?string $postalCode): ?string
    {
        $prefix = substr(trim((string) $postalCode), 0, 2);

        // Bounds check, not a bare array miss: only a real province code (01-52) may default to
        // the Peninsula, so '99999', '00' or a truncated '7001' ('70') fall back instead.
        if (preg_match('/^\d{2}$/', $prefix) !== 1 || (int) $prefix < 1 || (int) $prefix > 52) {
            return null;
        }

        return self::SPAIN_POSTAL_PREFIX_TERRITORIES[(int) $prefix] ?? self::SPAIN_MAINLAND_TERRITORY_SLUG;
    }
}
