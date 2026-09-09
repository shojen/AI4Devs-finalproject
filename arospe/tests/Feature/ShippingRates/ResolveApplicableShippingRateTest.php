<?php

use App\Actions\Shipping\ResolveApplicableShippingRate;
use App\Enums\GeographyLevel;
use App\Models\GeographyEntry;
use App\Models\ShippingCarrier;
use App\Models\ShippingRate;
use App\Models\ShippingZone;

// Story 0036, Phase 3 (TDD "red" step): App\Actions\Shipping\ResolveApplicableShippingRate does
// not exist yet, nor does App\Models\ShippingRate/its migration -- every test below is expected
// to fail (class/table not found) until database-expert/backend-expert implement them. That
// failure is the correct, intended "red" outcome.
//
// ================================================================================================
// D-13 RESULT-OBJECT SHAPE THIS FILE IS WRITTEN AGAINST -- backend-expert's implementation must
// match this shape exactly (see this session's own report for the full rationale):
//
//   final readonly class App\Actions\Shipping\ShippingRateResolution
//   {
//       public ?App\Models\ShippingRate $rate;                 // the chosen rate, or null
//       public ?string $reason;                                 // null when resolved
//       public ?App\Models\ShippingZone $decidingZone;          // the zone whose tier decided it
//       public ?App\Enums\GeographyLevel $decidingTier;         // the winning tier's level
//
//       public static function resolved(ShippingRate $rate, ShippingZone $decidingZone, GeographyLevel $decidingTier): self;
//       public static function unresolved(string $reason, ?ShippingZone $decidingZone = null, ?GeographyLevel $decidingTier = null): self;
//       public function isResolved(): bool; // true iff rate !== null
//   }
//
// Two named reason constants on ResolveApplicableShippingRate itself (naming.md's "name it once
// on the class that owns the rule" convention):
//
//   ResolveApplicableShippingRate::REASON_NO_COVERING_ZONE = 'no_covering_zone';
//       -- no zone carrying ANY rate covers the destination at all (D-13's "we do not ship there").
//   ResolveApplicableShippingRate::REASON_NO_MATCHING_WEIGHT_BRACKET = 'no_matching_weight_bracket';
//       -- a winning tier WAS found (D-1 step 2), but none of its rates cover this parcel's weight,
//          and D-1 step 4 forbids falling back to a broader tier.
//
// The two reasons must be distinguishable strings -- see "a destination no zone covers has no
// applicable rate" (asserts REASON_NO_COVERING_ZONE) vs "no fallback across tiers" (asserts
// REASON_NO_MATCHING_WEIGHT_BRACKET) below.
// ================================================================================================

// No custom names passed to create() -- GeographyEntryFactory computes `normalized_name` from
// each state's OWN internally-generated fake name, and overriding `name` afterward without also
// overriding `normalized_name` would leave the latter stale (see docs/errors-log.md's
// 2026-09-07 entry on this exact factory). None of this file's assertions read `->name`, so the
// factory's own unique fake names are used throughout.
function shippingRateFixtureAncestry(): array
{
    $country = GeographyEntry::factory()->create();
    $community = GeographyEntry::factory()->community($country)->create();
    $municipality = GeographyEntry::factory()->municipality($community)->create();

    return [$country, $community, $municipality];
}

function shippingRateZoneCovering(array $entryIds, string $name = 'Zona'): ShippingZone
{
    $zone = ShippingZone::factory()->create(['name' => $name]);

    if ($entryIds !== []) {
        $zone->geographyEntries()->attach($entryIds);
    }

    return $zone;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function shippingRateFor(ShippingCarrier $carrier, ShippingZone $zone, array $overrides = []): ShippingRate
{
    return ShippingRate::factory()->create(array_merge([
        'name' => 'Estándar',
        'shipping_carrier_id' => $carrier->id,
        'shipping_zone_id' => $zone->id,
        'min_weight_kg' => '0',
        'max_weight_kg' => '2',
        'price' => '4.95',
        'delivery_estimate' => '24-48h',
    ], $overrides));
}

// =====================================================================
// Dataset: precedence_by_level -- three cases proving the walk order
// (municipio > comunidad > país), plus two proving the chain simply
// starts shorter when the destination itself is a comunidad/país entry.
// =====================================================================

dataset('precedence_by_level', function () {
    return [
        'all three levels have a covering zone -- municipio wins' => [function (): array {
            [$country, $community, $municipality] = shippingRateFixtureAncestry();
            $carrier = ShippingCarrier::factory()->create();

            $countryZone = shippingRateZoneCovering([$country->id], 'País');
            $communityZone = shippingRateZoneCovering([$community->id], 'Comunidad');
            $municipalityZone = shippingRateZoneCovering([$municipality->id], 'Municipio');

            shippingRateFor($carrier, $countryZone);
            shippingRateFor($carrier, $communityZone);
            shippingRateFor($carrier, $municipalityZone);

            return [$municipality, $carrier, $municipalityZone];
        }],
        'only comunidad and país have a covering zone -- comunidad wins' => [function (): array {
            [$country, $community, $municipality] = shippingRateFixtureAncestry();
            $carrier = ShippingCarrier::factory()->create();

            $countryZone = shippingRateZoneCovering([$country->id], 'País');
            $communityZone = shippingRateZoneCovering([$community->id], 'Comunidad');

            shippingRateFor($carrier, $countryZone);
            shippingRateFor($carrier, $communityZone);

            return [$municipality, $carrier, $communityZone];
        }],
        'only país has a covering zone -- país wins' => [function (): array {
            [$country, , $municipality] = shippingRateFixtureAncestry();
            $carrier = ShippingCarrier::factory()->create();

            $countryZone = shippingRateZoneCovering([$country->id], 'País');
            shippingRateFor($carrier, $countryZone);

            return [$municipality, $carrier, $countryZone];
        }],
        'the destination is itself a comunidad entry -- the chain starts shorter' => [function (): array {
            [$country, $community] = shippingRateFixtureAncestry();
            $carrier = ShippingCarrier::factory()->create();

            $countryZone = shippingRateZoneCovering([$country->id], 'País');
            $communityZone = shippingRateZoneCovering([$community->id], 'Comunidad');

            shippingRateFor($carrier, $countryZone);
            shippingRateFor($carrier, $communityZone);

            return [$community, $carrier, $communityZone];
        }],
        'the destination is itself a país entry -- the chain is a single level' => [function (): array {
            [$country] = shippingRateFixtureAncestry();
            $carrier = ShippingCarrier::factory()->create();

            $countryZone = shippingRateZoneCovering([$country->id], 'País');
            shippingRateFor($carrier, $countryZone);

            return [$country, $carrier, $countryZone];
        }],
    ];
});

test('rate precedence by level', function (Closure $build) {
    [$destination, $carrier, $expectedZone] = $build();

    $result = app(ResolveApplicableShippingRate::class)($destination, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->shipping_zone_id)->toBe($expectedZone->id)
        ->and($result->decidingZone->id)->toBe($expectedZone->id);
})->with('precedence_by_level');

// =====================================================================
// Individual tests -- assertions genuinely diverge
// =====================================================================

// PRD's own "An ancestry-only overlap is resolved the same way as a listed overlap" scenario.
// Zone A lists the municipio "Gijón" directly; zone B lists only the country "España" -- no
// pivot row is shared between the two. 0033 D-2's case no schema constraint can see: an
// implementation that reads only the pivot row for Gijón itself (rather than walking the
// ancestry chain per tier) never even considers zone B, but that must not matter here since A
// wins regardless -- the point is that BOTH are correctly evaluated and specificity still governs.
test('an implicit, ancestry-only overlap is resolved the same way as an explicit one -- the narrower zone wins', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $zonaNorte = shippingRateZoneCovering([$municipality->id], 'Zona Norte');
    $nacional = shippingRateZoneCovering([$country->id], 'Nacional');

    shippingRateFor($carrier, $zonaNorte, ['price' => '4.95']);
    shippingRateFor($carrier, $nacional, ['price' => '4.95']);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->decidingZone->id)->toBe($zonaNorte->id);
});

// The single test that separates the specified algorithm from "sort every qualifying rate by
// price and take the first" -- which passes every other case in this file. The BROADER tier's
// rate is CHEAPER; the narrower, winning tier's rate is DEARER and must still be chosen.
test('specificity beats price across tiers -- a cheaper broader-tier rate never wins over a dearer, more specific one', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $countryZone = shippingRateZoneCovering([$country->id], 'País');
    $municipalityZone = shippingRateZoneCovering([$municipality->id], 'Municipio');

    $cheapCountryRate = shippingRateFor($carrier, $countryZone, ['price' => '1.00']);
    $expensiveMunicipalityRate = shippingRateFor($carrier, $municipalityZone, ['price' => '99.00']);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($expensiveMunicipalityRate->id)
        ->and($result->rate->id)->not->toBe($cheapCountryRate->id);
});

// Explicit overlap: two zones at the SAME level, both directly listing "Gijón" -- the cheaper
// rate wins.
test('two zones at the same level explicitly overlapping are separated by price -- the cheaper wins', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $zoneA = shippingRateZoneCovering([$municipality->id], 'Zona A');
    $zoneB = shippingRateZoneCovering([$municipality->id], 'Zona B');

    shippingRateFor($carrier, $zoneA, ['price' => '9.00']);
    $cheaperRate = shippingRateFor($carrier, $zoneB, ['price' => '5.00']);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($cheaperRate->id);
});

// Same price, different created_at -- the older rate wins (D-1's second tiebreak key).
test('two same-level rates at the same price are separated by created_at -- the older wins', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $zoneA = shippingRateZoneCovering([$municipality->id], 'Zona A');
    $zoneB = shippingRateZoneCovering([$municipality->id], 'Zona B');

    $olderRate = shippingRateFor($carrier, $zoneA, [
        'price' => '5.00',
        'created_at' => now()->subMinutes(10),
    ]);
    shippingRateFor($carrier, $zoneB, [
        'price' => '5.00',
        'created_at' => now(),
    ]);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($olderRate->id);
});

// Full tiebreak determinism: two same-level rates at the SAME price, created in the SAME second.
// `id` is the final key, not row order -- resolution must be stable across repeated calls.
test('two rates identical on price and created_at resolve to the same id on every call -- id is the final tiebreak', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $zoneA = shippingRateZoneCovering([$municipality->id], 'Zona A');
    $zoneB = shippingRateZoneCovering([$municipality->id], 'Zona B');

    $sameInstant = now();

    shippingRateFor($carrier, $zoneA, ['price' => '5.00', 'created_at' => $sameInstant]);
    shippingRateFor($carrier, $zoneB, ['price' => '5.00', 'created_at' => $sameInstant]);

    $first = app(ResolveApplicableShippingRate::class)($municipality, '1.0');
    $second = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($first->isResolved())->toBeTrue()
        ->and($second->isResolved())->toBeTrue()
        ->and($first->rate->id)->toBe($second->rate->id);
});

// D-3 boundary, asserted from both sides in one body: storage stays literal (0033 D-3) while
// resolution walks ancestry. A zone holding ONLY the país "España" pivot row does NOT have a
// Gijón row in its pivot table -- but resolving a Gijón destination against that zone's rate DOES
// match at the país tier. Pins that the ancestry walk lives exclusively in the resolver.
test('storage stays literal while resolution walks ancestry -- the D-3 boundary, both sides', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $nacional = shippingRateZoneCovering([$country->id], 'Nacional');
    $rate = shippingRateFor($carrier, $nacional);

    // Storage side: the pivot has no row for Gijón in this zone.
    expect($nacional->geographyEntries()->whereKey($municipality->id)->exists())->toBeFalse();

    // Resolution side: resolving Gijón still matches, via the país tier.
    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($rate->id)
        ->and($result->decidingZone->id)->toBe($nacional->id);
});

// The story's highest-stakes assertion (D-1 step 4). The narrower, winning tier's own ladder
// does not cover this weight -- the resolver must NOT fall back to the broader tier's rate, and
// the result must name the deciding (municipio) zone so the refusal is diagnosable (D-13).
test('a narrower zones incomplete weight ladder does not fall back to a broader zone', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $municipalityZone = shippingRateZoneCovering([$municipality->id], 'Gijón Centro');
    $countryZone = shippingRateZoneCovering([$country->id], 'España');

    // Municipio zone's own ladder tops out at 2kg.
    shippingRateFor($carrier, $municipalityZone, ['min_weight_kg' => '0', 'max_weight_kg' => '2']);
    // Country zone genuinely covers 3kg -- must NOT be used as a fallback.
    shippingRateFor($carrier, $countryZone, ['min_weight_kg' => '0', 'max_weight_kg' => '10']);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '3.0');

    expect($result->isResolved())->toBeFalse()
        ->and($result->rate)->toBeNull()
        ->and($result->reason)->toBe(ResolveApplicableShippingRate::REASON_NO_MATCHING_WEIGHT_BRACKET)
        ->and($result->decidingZone->id)->toBe($municipalityZone->id);
});

// The exact converse of the previous test: a zone COVERING the address but carrying ZERO rate
// rules expresses no pricing intent, so it must not suppress a broader zone that does.
test('a zone carrying no rate rule at all does not suppress a broader zone', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $municipalityZone = shippingRateZoneCovering([$municipality->id], 'Gijón Centro');
    // No rate created for $municipalityZone at all.
    $countryZone = shippingRateZoneCovering([$country->id], 'España');
    $countryRate = shippingRateFor($carrier, $countryZone);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($countryRate->id)
        ->and($result->decidingZone->id)->toBe($countryZone->id);
});

// 0033 D-5's legal empty zone (zero geography entries) never matches any destination.
test('a zone with zero geography entries never matches any destination', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();

    $emptyZone = shippingRateZoneCovering([], 'Zona Vacía');
    shippingRateFor($carrier, $emptyZone);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeFalse()
        ->and($result->reason)->toBe(ResolveApplicableShippingRate::REASON_NO_COVERING_ZONE);
});

// =====================================================================
// Dataset: weight_bracket_boundaries -- D-3 inclusive-both-ends over a 0-2 bracket, plus a
// min==max==2 single-weight rate.
// =====================================================================

dataset('weight_bracket_boundaries', function () {
    return [
        '1.999kg matches a 0-2 bracket' => ['0', '2', 1.999, true],
        '2kg matches a 0-2 bracket (inclusive upper)' => ['0', '2', 2.0, true],
        '2.001kg does not match a 0-2 bracket' => ['0', '2', 2.001, false],
        '0kg matches a 0-2 bracket (inclusive lower)' => ['0', '2', 0.0, true],
        '2kg matches a min==max==2 single-weight bracket' => ['2', '2', 2.0, true],
    ];
});

test('weight bracket boundaries', function (string $min, string $max, float $weight, bool $shouldMatch) {
    [, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();
    $zone = shippingRateZoneCovering([$municipality->id], 'Zona');

    shippingRateFor($carrier, $zone, ['min_weight_kg' => $min, 'max_weight_kg' => $max]);

    $result = app(ResolveApplicableShippingRate::class)($municipality, (string) $weight);

    expect($result->isResolved())->toBe($shouldMatch);
})->with('weight_bracket_boundaries');

// D-4/R-1: the null-aware bracket. A single `where('max_weight_kg', '>=', $w)` fails ALL THREE of
// these because `NULL >= 5` is NULL, not true -- and it passes every closed-bracket case above,
// so this cannot be folded into the dataset.
test('an open-ended rate (null max_weight_kg) matches 5kg, 50kg and 5000kg alike', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    $carrier = ShippingCarrier::factory()->create();
    $zone = shippingRateZoneCovering([$municipality->id], 'Zona');

    shippingRateFor($carrier, $zone, ['min_weight_kg' => '5', 'max_weight_kg' => null]);

    foreach (['5', '50', '5000'] as $weight) {
        $result = app(ResolveApplicableShippingRate::class)($municipality, $weight);

        expect($result->isResolved())->toBeTrue("expected {$weight}kg to match the open-ended bracket");
    }
});

// =====================================================================
// Carrier interaction
// =====================================================================

// D-6 read from the resolution side: a disabled carrier's rate is NEVER chosen, even when it is
// the most specific match -- the broader ACTIVE carrier's rate wins instead.
test('a disabled carriers rate is never chosen, even when it is the most specific match', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $mrw = ShippingCarrier::factory()->inactive()->create(['name' => 'MRW']);
    $seur = ShippingCarrier::factory()->create(['name' => 'SEUR']);

    $municipalityZone = shippingRateZoneCovering([$municipality->id], 'Municipio');
    $countryZone = shippingRateZoneCovering([$country->id], 'País');

    shippingRateFor($mrw, $municipalityZone);
    $seurRate = shippingRateFor($seur, $countryZone);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($seurRate->id)
        ->and($result->rate->shipping_carrier_id)->toBe($seur->id);
});

test('re-enabling a disabled carrier makes its rate win again, proving disabling only hid it', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    $mrw = ShippingCarrier::factory()->inactive()->create(['name' => 'MRW']);
    $zone = shippingRateZoneCovering([$municipality->id], 'Municipio');
    $mrwRate = shippingRateFor($mrw, $zone);

    $unresolvedWhileDisabled = app(ResolveApplicableShippingRate::class)($municipality, '1.0');
    expect($unresolvedWhileDisabled->isResolved())->toBeFalse();

    $mrw->update(['is_active' => true]);

    $resolvedAfterReenable = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($resolvedAfterReenable->isResolved())->toBeTrue()
        ->and($resolvedAfterReenable->rate->id)->toBe($mrwRate->id);
});

// R-2/step 2's carrier filter: the tier must be computed UNDER the carrier filter, not globally
// and filtered afterwards. A municipio-level SEUR rate must not suppress a país-level MRW rate
// when resolving for MRW specifically.
test('resolving for one carrier computes the winning tier under that carriers filter, ignoring another carriers narrower rate', function () {
    [$country, , $municipality] = shippingRateFixtureAncestry();
    $seur = ShippingCarrier::factory()->create(['name' => 'SEUR']);
    $mrw = ShippingCarrier::factory()->create(['name' => 'MRW']);

    $municipalityZone = shippingRateZoneCovering([$municipality->id], 'Municipio');
    $countryZone = shippingRateZoneCovering([$country->id], 'País');

    shippingRateFor($seur, $municipalityZone);
    $mrwCountryRate = shippingRateFor($mrw, $countryZone);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0', $mrw->id);

    expect($result->isResolved())->toBeTrue()
        ->and($result->rate->id)->toBe($mrwCountryRate->id)
        ->and($result->rate->shipping_carrier_id)->toBe($mrw->id);
});

// A destination no zone covers at all -- distinguishable (a different reason string) from "a
// zone covers it but no bracket matches" (asserted by the no-fallback test above).
test('a destination no zone covers has no applicable rate, and the reason is distinguishable from a coverage-gap-by-weight', function () {
    [, , $municipality] = shippingRateFixtureAncestry();
    // A completely unrelated zone/carrier/rate exist, but cover nothing this destination's
    // ancestry chain touches -- a second, independent ancestry.
    $carrier = ShippingCarrier::factory()->create();
    [$otherCountry] = shippingRateFixtureAncestry();
    $unrelatedZone = shippingRateZoneCovering([$otherCountry->id], 'Zona sin relación');
    shippingRateFor($carrier, $unrelatedZone);

    $result = app(ResolveApplicableShippingRate::class)($municipality, '1.0');

    expect($result->isResolved())->toBeFalse()
        ->and($result->rate)->toBeNull()
        ->and($result->reason)->toBe(ResolveApplicableShippingRate::REASON_NO_COVERING_ZONE)
        ->and($result->reason)->not->toBe(ResolveApplicableShippingRate::REASON_NO_MATCHING_WEIGHT_BRACKET);
});
