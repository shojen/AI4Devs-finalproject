# Array Validation Bounds — The rule, call sites and shapes that bound it

> Part of [Array Validation Bounds](../array-validation-bounds.md). **Read this part when:** you validate a submitted array of ids: why `max:` does not gate `.*`, and the two-pass shape. The other parts are listed in the [hub](../array-validation-bounds.md#table-of-contents).

## A `max:` rule on an array does not gate that array's `.*` rules

**Rule. `max:N` on an array attribute bounds what is allowed to *succeed*; it does not bound what
the request *costs*.** Laravel expands `field.*` against the data it was given, and runs every
expanded rule regardless of whether the parent attribute's own rules already failed. So a submission
carrying 4,000 ids still pays 4,000 `Rule::exists()` queries before the `max:254` message is
returned. If the per-element rule set touches the database, the array-level `max:` is not a
denial-of-service control and must not be documented as one.

This is the specific trap: `max:N` *looks* like a bound on the work, because the number it names is
the number of elements. It is a bound on the accepted result only.

Measured against this repo's own rules (`ProductValidationRules::salesRegionIdsRules()` +
`salesRegionIdRules([])`, executed on this worktree's MySQL, one `DB::listen()` counter registered
once):

| submitted ids | queries issued | wall time | outcome |
| --- | --- | --- | --- |
| 254 (the legal maximum) | 254 | 0.35 s | `ValidationException` |
| 1,000 | 1,000 | 1.40 s | `ValidationException` (`max:254`) |
| 4,000 | 4,000 | 6.60 s | `ValidationException` (`max:254`) |

Exactly one query per submitted element, linear, with the `max:254` verdict arriving only after all
of them have run. Extrapolating is the point: the cost is set by what the client sends, not by what
the rule permits.

❌ **As found (story 0026, and unchanged in shipped story 0024 code).** The array-level bound and the
per-element database rule are declared in the same rule set, so they are evaluated in the same pass:

```php
// app/Concerns/ProductValidationRules.php — the rules, correct in isolation
protected function salesRegionIdsRules(): array
{
    return ['array', 'list', 'max:254'];
}

protected function salesRegionIdRules(array $preservedSalesRegionIds = []): array
{
    return ['required', 'string', 'distinct', Rule::exists('sales_regions', 'id')->where(/* … */)];
}
```

```php
// the composition a consumer is expected to write — this is where the cost is unbounded
Validator::make($input, [
    'salesRegionIds' => $this->salesRegionIdsRules(),
    'salesRegionIds.*' => $this->salesRegionIdRules($preserved),   // runs for EVERY element
])->validate();
```

Note the rules themselves are not wrong, and `max:254` is a well-chosen number — 249 ISO countries
(`database/data/iso-3166-countries.json`) plus `SalesRegionSeeder::SPAIN_TERRITORIES`' five, and the
catalog has no create path, so 254 is a hard ceiling rather than a guess. What is wrong is only the
claim that declaring it bounds the per-request query cost.

## No array-level rule gates them — `list` does not either

The rule above is stated in terms of `max:N` because that is the rule whose *number* invites the
misreading. It is not a property of `max:` — **no rule on the parent attribute gates the `.*` rules**,
because `field` and `field.*` are different attributes and the wildcard is expanded against the data
the request supplied, not against the data that passed. `array`, `list`, `size:`, `between:` and a
custom array-level closure all behave identically.

`list` is worth calling out by name, because it is the *other* rule in
`ProductValidationRules::salesRegionIdsRules()` and its own docblock made the identical claim
`max:254`'s did — that it "refuses a sparse/associative array **before either per-element rule
runs**". It does not. Measured on this worktree, 30 ids submitted as an associative array against
`['ids' => ['array', 'list', 'max:254'], 'ids.*' => ['required', 'string', 'distinct', Rule::exists(…)]]`:

| submitted | `list` verdict | queries issued | error keys returned |
| --- | --- | --- | --- |
| 30 ids, associative (`k0` … `k29`) | fails | **30** | `ids`, `ids.k0` … `ids.k29` |

The wildcard expanded against the *associative* keys and ran the `exists` rule for every one of them.
So an associative array is exactly as expensive as a list one, and `list` bounds the accepted shape
rather than the work — the same sentence, about a different rule, on the same rule set.

The mitigation is the same and needs no second mechanism: in the two-pass shape below, `list` and
`max:254` both live in pass 1, so either one failing throws before pass 2 is ever composed. Measured
at **0 queries** for a 2,000-id submission in both the oversized-list and the associative case.

## The call sites in this repo today

Two are **unreachable in production as of 2026-09-03**, because neither has a Livewire component or
route in front of it yet (the products editor is story 0027). Recorded so the next audit treats them
as known rather than new, and so 0027 does not wire either one as-is:

- `ProductValidationRules::salesRegionIdsRules()` / `salesRegionIdRules()` (story 0026) —
  `max:254` + a per-element `Rule::exists('sales_regions', 'id')`. Story 0026's Definition-of-Done
  hand-off item 3 makes calling this rule **mandatory** for 0027, so 0027 inherits the shape. Its
  hand-off item **5** now also dictates *how* to call it — see
  [Status in story 0026](story-history.md#status-in-story-0026-bounded-by-a-written-hand-off-not-by-code) below.
- `ProductValidationRules::productGalleryMediaIdsRules()` (story 0024, shipped) — `max:20` +
  `'gallery_media_ids.*' => ['string', 'distinct', Rule::exists('media', 'id')]`, composed in
  `productRules()` and validated by both `CreateProduct` and `UpdateProduct`. Measured on the same
  worktree: 1,000 submitted ids issue **1,000 queries** in 1.16 s despite `max:20`.

The gallery case is the more instructive of the two unreachable sites, because its bound is `20` —
two orders of magnitude below the array a client may send, and still no protection at all.

A **third** site is real, shipped and **closed** rather than unreachable:

- `ProductAttributeValidationRules::attributeValueListRules()` / `attributeValueRules()` (story
  0028) — `max:100` on the `values` array, `distinct:ignore_case` on `values.*.value`. Unlike both
  sites above, `App\Livewire\Products\AttributeTypes\Index::save()` is a real, mounted, permission-
  gated Livewire method that calls it today. See
  [Status in story 0028](story-history.md#status-in-story-0028-the-rules-first-real-shipped-closed-call-site) below.

## Confirmed: neither `bail` form helps

Verified by execution rather than reasoned about, because `bail` is the reflexive reach here:

| rule set | queries for 300 submitted ids |
| --- | --- |
| `'ids' => ['bail', 'array', 'list', 'max:254']` | 300 |
| `'ids.*' => ['bail', 'required', 'string', 'distinct', Rule::exists(...)]` | 300 |

`bail` stops the remaining rules **for the attribute it is on**, and `ids` and `ids.*` are different
attributes. `bail` on the element rules stops at the first failing rule *per element*, which is no
help when every element reaches the `exists` rule (a well-formed UUID string passes `required`,
`string` and `distinct`).

## The shapes that do bound it

Two, both verified. Prefer the second where the per-element rule is a plain existence check.

✅ **Two passes: validate the array's shape first, and let it throw before the element rules are ever
composed.** Measured at **0 queries, 0.00 s** for a 4,000-id submission:

```php
// Pass 1 — shape only. Throws before anything touches the database.
Validator::make($input, [
    'salesRegionIds' => $this->salesRegionIdsRules(),
])->validate();

// Pass 2 — per-element rules, now provably running against at most 254 elements.
Validator::make($input, [
    'salesRegionIds.*' => $this->salesRegionIdRules($preserved),
])->validate();
```

The cost of this shape is that the two passes produce two `ValidationException`s rather than one
merged error bag, so a caller displaying field-level errors sees the size error alone on an
oversized submission. That is the correct trade: an oversized array has no per-element errors worth
showing.

✅ **One batch query instead of N.** Where the per-element rule is only "does this id exist, subject
to a condition", a single array-level closure rule collapses N queries into one and removes the
unbounded cost by construction rather than by ordering:

```php
// One query for the whole array, whatever its size.
'salesRegionIds' => ['array', 'list', 'max:254', function (string $attribute, mixed $value, Closure $fail): void {
    $submitted = array_values(array_unique((array) $value));

    $valid = SalesRegion::query()
        ->whereIn('id', array_slice($submitted, 0, 254))
        ->where(/* the same assignable-or-preserved condition */)
        ->pluck('id')
        ->all();

    if (array_diff($submitted, $valid) !== []) {
        $fail(/* … */);
    }
}],
```

⚠️ Note the `array_slice()` inside it: a closure rule is itself an array-level rule, so it runs even
when `max:254` has already failed — the same mechanism this whole page is about. Slice inside the
closure, or the batch query inherits an unbounded `IN (…)` list. This is the identical shape story
0022 already ships in `SearchableMultiSelect::resolveIdsAllowingPartialFailure()`
(`array_slice($ids, 0, self::MAX_RESOLVABLE_SELECTED)` before either resolver call) — that component
is this repo's existing worked example of bounding a client-supplied id array before it reaches a
query, and it bounds it in PHP, not in a validation rule, for exactly this reason.

**The review question that catches this class**: for every rule set containing a `.*` wildcard, ask
*what does one element cost, and who chose the element count?* If the answer to the first is "a
query" and to the second is "the client", the array-level `max:` is not the control.
