# Shipping: zones, carriers, rates, and rate resolution

## Table of Contents

- [Overview](#overview)
- [The three tables, briefly](#the-three-tables-briefly)
- [The rate-precedence resolution algorithm](#the-rate-precedence-resolution-algorithm)
- [The result object: never a bare `null`](#the-result-object-never-a-bare-null)
- [The zone-delete guard](#the-zone-delete-guard)
- [Disabling a carrier never touches its rates](#disabling-a-carrier-never-touches-its-rates)
- [Hand-off: this domain has no consumer yet](#hand-off-this-domain-has-no-consumer-yet)

## Overview

This page documents the Shipping domain's **business logic** — the rate-precedence resolution rule in particular — as opposed to its schema, which lives in [database/schema.md](../database/schema-shipping.md#shipping_zones) (`shipping_zones`, `shipping_zone_geography_entry`, `shipping_carriers`, `shipping_rates`). The distinction matters because [PRD Epic 3 (Orders)](../PRD/PRD.md) will be this domain's real consumer, and it must **reuse** the algorithm below rather than re-derive it — the same "don't rebuild what already has a documented, tested resolver" reasoning [database/schema.md](../database/schema-products.md#what-the-pivot-enables-appactionsproductsresolveproducttaxrate) already applies to `App\Actions\Products\ResolveProductTaxRate` for tax-rate resolution.

Built across three stories: [0033](../../ai-spec/tasks/done/0033-shipping-zones-backend.md) (zones — an admin-created, admin-editable named group of [`GeographyEntry`](../database/schema-shipping.md#geography_entries) rows at any level), [0035](../../ai-spec/tasks/done/0035-shipping-carriers-backend.md) (carriers — a seeded catalog of four prototype-integrated carriers), and 0036 (rate rules — a carrier + zone + weight bracket + price + delivery estimate, and the resolution rule that picks one when zones overlap).

## The three tables, briefly

See [database/schema.md](../database/schema-shipping.md#shipping_zones) for full column-level detail; only what this page's algorithm needs is repeated here.

- **`shipping_zones`** bundles one or more [`geography_entries`](../database/schema-shipping.md#geography_entries) rows (country, comunidad autónoma, or municipio) under a name. Membership is **literal, never transitive** — assigning the country "España" to a zone creates exactly one pivot row, never an implicit expansion into its comunidades or municipios. **Zones may overlap freely** — two zones may both cover "Gijón" — which is what makes a precedence rule necessary at all, and a zone may legally carry **no rate rule** at all (an empty zone, [database/schema.md](../database/schema-shipping.md#shipping_zones)'s own D-5).
- **`shipping_carriers`** is a seeded, non-deletable catalog (SEUR, Correos, MRW, DHL Express) with one administrator-editable flag, `is_active`.
- **`shipping_rates`** is the row that actually carries a price: a `shipping_carrier_id`, a `shipping_zone_id`, a weight bracket (`min_weight_kg`/`max_weight_kg`, the latter nullable meaning "and above"), a `price`, and a free-text `delivery_estimate`. Two differently-named rates may share an identical bracket on the same carrier and zone (e.g. "Estándar" and "Frágil" both covering 0–2 kg at different prices) — see [database/schema.md](../database/schema-shipping.md#shipping_rates) for why no uniqueness constraint exists on this table.

## The rate-precedence resolution algorithm

[`App\Actions\Shipping\ResolveApplicableShippingRate`](../../app/Actions/Shipping/ResolveApplicableShippingRate.php) answers one question: **given a destination and a parcel weight (and optionally a specific carrier), which single rate rule applies?** Overlapping zones make this genuinely ambiguous — "most specific zone wins" is not a complete rule until "most specific" and "wins when there's a tie" are both defined precisely, which is what this algorithm is for.

```
1. Build the destination's ANCESTRY CHAIN from the GeographyEntry it resolves to,
   most specific first: [municipio, comunidad autónoma, país]. Bounded at THREE
   entries because the geography catalog (0032) is exactly three levels deep; if
   the destination is itself a comunidad or a country, the chain simply starts
   shorter.

2. Walk the chain from most specific to least. At each level, find every rate
   rule whose zone LISTS that exact entry, whose carrier is ACTIVE, and which
   matches the CARRIER FILTER if one was given (never weight-filtered yet). The
   first level with a non-empty set is the WINNING TIER -- stop walking.

3. Within the winning tier -- and ONLY within it -- keep the rates whose weight
   bracket covers the parcel.

4. If that leaves nothing, RETURN NO RATE. Do not continue to a broader tier.

5. Otherwise order by price ASC, then created_at ASC, then id ASC, and take the
   first.
```

Four properties of this algorithm are easy to get backwards, and each is the reason a specific test exists in `tests/Feature/ShippingRates/ResolveApplicableShippingRateTest.php` — a future consumer changing this logic should re-run that file, not merely add a new one.

**The tier is selected by the *presence* of qualifying rate rules, never by zone coverage alone.** A zone that covers the destination but carries **no rate rule at all** expresses no pricing intent, so it must not suppress a broader zone that does — otherwise a legally empty zone ([database/schema.md](../database/schema-shipping.md#shipping_zones)'s own D-5) becomes a silent shipping outage the moment an administrator creates one for, say, a future promotional campaign. A zone whose rates merely fail to cover *this parcel's* weight is the opposite case: it has expressed a ladder, and step 4 respects that by refusing to fall through.

**The carrier filter is applied while choosing the tier, not after.** Resolving "the best MRW rate for this address" computes the winning tier *with* the MRW filter already applied. Computing the tier globally and filtering afterwards would let a SEUR municipio-level rate suppress an MRW país-level rate, silently returning nothing for MRW even though a perfectly good country-wide MRW rate exists — a bug invisible in a single-carrier fixture.

**Once a tier is chosen, it does not reopen — this is the highest-stakes rule in the domain, decided deliberately.** If the winning tier's own rates do not cover the parcel's weight, the resolver returns **no rate**, never a broader tier's rate. The alternative (filter by weight first, then pick the most specific tier among what survives) was argued seriously and rejected: it never blocks, but its failure mode is *undercharging* — a country-wide "España" catch-all silently quoting the mainland price for a heavy parcel to the Canaries, on every such order, until someone audits it. No-fallback's failure mode is a *visible refusal to quote* instead, which is recoverable. This is the same principle [database/schema.md](../database/schema-products.md#sales_regions) already applies to `sales_regions.parent_id`'s `restrictOnDelete()` choice: an error is preferable to silent wrong money.

**The tiebreak is a total order, not merely "cheapest wins."** `price` ASC first (the customer never pays more than necessary among equally-specific options), then `created_at` ASC (older configuration wins a tie), then `id` ASC as the final, deterministic key — timestamps collide within a second routinely (factories, seeders, bulk imports), so `id` is what makes "run the resolver twice, get the same answer" a guarantee rather than a coin flip.

Implementation notes, for a future reader of the action itself: the ancestry chain is loaded with **one** eager load (`$destination->load('parent.parent')`), not three lazy hops; the winning-tier query and the weight-covering query are two separate queries, each re-applying the active-carrier and carrier-filter conditions independently — a Phase 4 security-audit finding (F-6) closed a TOCTOU window where a carrier disabled between the two queries could otherwise let the second query resolve a rate whose carrier no longer qualifies; and the weight-bracket comparison always runs through `ShippingRate::scopeCoveringWeight()` (see [database/schema.md](../database/schema-shipping.md#max_weight_kg-is-nullable-and-the-bracket-comparison-must-be-null-aware)) so the null-aware "and above" logic exists in exactly one place.

**A fourth guard, not decided in the domain design but added at Phase 4 security audit, rejects a malformed weight before any of the above runs.** `$weightKg` is validated as its own first statement — numeric, finite (rejecting `INF`/`-INF`/`NAN`, which each pass a bare `is_numeric()` check but cannot be compared or bound to a `DECIMAL` column safely), and non-negative — because PHP's `(float)` cast silently coerces a non-numeric string to `0.0`, which would otherwise match the lightest bracket and return a real, wrong price instead of refusing. This is why the result carries a **third** reason beyond the two the domain decision names (see below).

## The result object: never a bare `null`

"No applicable rate" is deliberately never expressed as `null`. A bare `null` at checkout cannot distinguish "we do not ship there at all" from "we ship there, but your parcel is heavier than this narrow zone's own ladder" — and the second is a misconfiguration an administrator can fix in thirty seconds *if they are told which zone decided it*. `ResolveApplicableShippingRate` therefore always returns a small, `final readonly` [`App\Actions\Shipping\ShippingRateResolution`](../../app/Actions/Shipping/ShippingRateResolution.php):

```php
// app/Actions/Shipping/ShippingRateResolution.php
final readonly class ShippingRateResolution
{
    private function __construct(
        public ?ShippingRate $rate,
        public ?string $reason,
        public ?ShippingZone $decidingZone,
        public ?GeographyLevel $decidingTier,
    ) {}

    public static function resolved(ShippingRate $rate, ShippingZone $decidingZone, GeographyLevel $decidingTier): self { /* ... */ }

    public static function unresolved(string $reason, ?ShippingZone $decidingZone = null, ?GeographyLevel $decidingTier = null): self { /* ... */ }

    public function isResolved(): bool { /* ... */ }
}
```

`$decidingZone`/`$decidingTier` are populated whenever a winning tier **was** found but its rates did not cover the weight (a diagnosable misconfiguration), and left `null` when no zone covers the destination at all (there is no tier to name). Three named reason constants on `ResolveApplicableShippingRate` itself, and a caller must branch on exactly these strings rather than inventing its own:

| Constant | Value | Meaning |
| --- | --- | --- |
| `REASON_NO_COVERING_ZONE` | `no_covering_zone` | No zone carrying any rate rule covers the destination at all. |
| `REASON_NO_MATCHING_WEIGHT_BRACKET` | `no_matching_weight_bracket` | A winning tier was found, but none of its rates cover this parcel's weight, and the no-fallback rule above forbids trying a broader tier. |
| `REASON_INVALID_WEIGHT` | `invalid_weight` | `$weightKg` was malformed (non-numeric, non-finite, or negative) — a caller input error, distinct from either coverage reason above. |

`REASON_INVALID_WEIGHT` is a Phase 4 security-audit addition, not part of the original domain decision — it exists so a bad caller input reads as "you asked something invalid" rather than being silently misdiagnosed as an ordinary coverage gap. Every existing caller already pattern-matches on `$resolution->reason`, so adding it as a third named reason on the same result shape kept every call site's contract uniform instead of introducing a second, incompatible exception type.

## The zone-delete guard

Deleting a shipping zone still referenced by any rate rule is hard-blocked, with an accurate count, at both the application and database layer — full mechanism, the two-layer design, and the `1451`/`1452` FK-race handling are documented in [database/schema.md](../database/schema-shipping.md#deleting-a-shipping-zone-still-referenced-by-a-rate-rule-is-hard-blocked); this page states only the one fact the resolution algorithm above depends on knowing: **the guard's count is unfiltered by carrier and by carrier state**, which is the same design point the next section explains from the other direction.

## Disabling a carrier never touches its rates

Disabling a carrier that still has rate rules is allowed, unconditionally — no block, no warning, no data change. The rates survive untouched, simply stop being selected by the resolver (step 2 of the algorithm above filters on `carrier.is_active`) while the carrier is inactive, and become selectable again the instant it is re-enabled.

This does not contradict the zone-delete guard being a hard block: the two operations differ on the axis that matters. **A toggle is reversible and destroys nothing; a delete is irreversible and orphans rows.** Blocking a disable would make the toggle un-flippable for any carrier that had ever been configured — which is every carrier that matters — and the only escape would be deleting the very rate rules the block exists to protect. This is why the zone-delete guard's own count (above) is **unfiltered** by carrier state: a disabled carrier's rates still count toward "this zone is in use," because deleting the zone out from under them would destroy configuration that returns the moment the carrier is re-enabled.

**Corollary: a rate rule may be created for a carrier that is currently disabled.** Refusing would be incoherent in both directions — it would create a chicken-and-egg problem for onboarding a new carrier (configure its rates, *then* switch it on, is the natural order), and it would mean a rate that may legally *survive* a disable could not legally be *created* during one. Rates are configuration; `is_active` governs **resolution** only, never authoring.

## Hand-off: this domain has no consumer yet

Story 0036 ships **no route, no Livewire component and no Blade view** — the sibling story [0037](../../ai-spec/tasks/done/0037-shipping-carriers-and-rates-ui.md) owns the Shipping screen's rate table and rate modal, and [PRD Epic 3 (Orders)](../PRD/PRD.md) is the resolver's real consumer at checkout. Two read-only actions exist with **no caller today**, and their own self-authorization posture is deliberate rather than an oversight:

- **`App\Actions\Shipping\ResolveApplicableShippingRate`** self-authorizes nothing. It is a pure read of pricing data that, like `App\Actions\Products\ResolveProductTaxRate` before it, may run from a queued job with no acting user at all (an order-total recalculation, a scheduled catalog audit) — the identical reasoning [database/schema.md](../database/schema-products.md#what-the-pivot-enables-appactionsproductsresolveproducttaxrate) already records for that action. Story 0037's own screen, and Epic 3's checkout flow, are each responsible for authorizing *their own* surrounding operation before calling it — this action is a calculator, not a decision point.
- **`App\Actions\Shipping\ListShippingRatesByCarrier`** likewise gates nothing of its own; it is a plain grouped query (carriers ordered by name, each with its rates eager-loaded including a carrier with **zero** rates still appearing as an empty group). [`App\Policies\ShippingRatePolicy::viewAny`](../../app/Policies/ShippingRatePolicy.php) exists specifically so story 0037 has an ability to `Gate::authorize()` against before rendering the list — the same "ship the policy so a later consumer has something to gate against" reasoning `App\Policies\ShippingZonePolicy` already established for story 0033.

**This is a binding hand-off, not a suggestion.** [`App\Policies\ShippingRatePolicy`](../../app/Policies/ShippingRatePolicy.php) already has real call sites for `create`/`update`/`delete` — all three write actions (`CreateShippingRate`, `UpdateShippingRate`, `DeleteShippingRate`) self-authorize against it as their own first statement, since this story ships no route and each action is therefore the *only* reachable enforcement point. `viewAny` is the one ability with no caller yet, pinned directly via `Gate::forUser()` in `tests/Feature/Policies/ShippingRatePolicyTest.php` rather than through any HTTP or component test. Story 0037 must `Gate::authorize('viewAny', ShippingRate::class)` before rendering the rate table — **defence in depth on top of the write actions' own gates, never the only place any rule exists** — matching how `App\Livewire\ProductCategories\Index` re-checks abilities its own actions already enforce.

_Last updated: 2026-09-10 — Story 0036 (Shipping rate rules — backend), the story that created this page. First architecture doc for the Shipping domain: documents the ancestry-walk rate-precedence resolution algorithm (`App\Actions\Shipping\ResolveApplicableShippingRate`) as domain behaviour Epic 3's checkout flow must consume and never re-derive, the `ShippingRateResolution` result-object shape and its three reason constants (including `REASON_INVALID_WEIGHT`, a Phase 4 security-audit addition beyond the original domain decision), the zone-delete guard's cross-reference to `database/schema.md`, why disabling a carrier never touches its rates, and the binding hand-off to story 0037 for `ShippingRatePolicy::viewAny`'s still-callerless ability._
