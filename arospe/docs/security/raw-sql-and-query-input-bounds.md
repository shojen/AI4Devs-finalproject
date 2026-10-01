# Raw SQL interpolation and query-input bounds

Established by story 0082's Phase 4 audit (the dashboard read-side actions) and its F1/F2 fixes. Three rules for code that builds an aggregate query from caller-influenced arguments, plus one availability note.

## Raw SQL may interpolate only from a closed enum

Eloquent binds values, but an aggregate has fragments that cannot be bound: a `DATE_FORMAT` pattern, or an `IN (...)` list inside a `CASE`. Those are interpolated into `DB::raw()`, which is safe **only** when every interpolated byte comes from a closed enum case — never from request input, a Livewire property, or a database column.

```php
// app/Actions/Dashboard/GetSalesSeries.php — __invoke()
$format = $granularity->sqlFormat();               // closed constant from App\Enums\SalesGranularity
$paid = PaymentStatus::Paid->value;                // enum case values, not input
// ...
DB::raw("DATE_FORMAT(created_at, '{$format}') AS bucket"),
```

- ✅ The interpolated value is `SalesGranularity::sqlFormat()` (a `match` over three literals, typed `literal-string`) or an `OrderStatus` / `PaymentStatus` `->value`. The caller can only choose *which case*, and a typed enum parameter rejects anything else.
- ❌ `DB::raw("DATE_FORMAT(created_at, '{$request->format}')")`, or interpolating a status string that arrived as a plain `string`. Bind it, or map it through `Enum::from()` first. (Adapted from the real snippet above to show the violation; no such code exists in the repo.)
- The review question: *can any interpolated byte be chosen by a caller who is not a developer?* If yes, it is a bound parameter or it is not allowed.

## An array of enums from a hydrated caller is normalized in the shared collaborator

A Livewire public property is client-controlled: a list typed `list<OrderStatus>` in PHPDoc can arrive with any keys, non-enum entries, duplicates, or 200 000 elements. The bound is applied **where the work happens, once**, not trusted from the caller's type hint.

```php
// app/Actions/Dashboard/ResolveSalesBuckets.php — normalizeStatuses()
foreach ($statuses as $status) {
    if ($status instanceof OrderStatus && ! isset($seen[$status->value])) {
        $seen[$status->value] = $status;
    }
}

return array_values($seen);
```

Type-filter, de-duplicate keeping the first, re-index; the result is bounded by the enum's case count, and an empty result is refused (`statuses_required`). This is the enum-array counterpart of [Array validation bounds](array-validation-bounds.md), which covers arrays of ids.

## Date ranges are bounded to MySQL DATETIME years

A range whose end passes year 9999 makes `endExclusive` an invalid `DATETIME` and the query fail; an absurd span also builds a huge `CarbonPeriod`. `ResolveSalesBuckets` refuses (`range_invalid`) any range whose first or last day is outside years 1000..9998, and checks the per-granularity bucket cap on the bucket **count** in PHP, before any query.

## Per-module counters never throw

`GetDashboardCounters` returns `null` for a counter the actor may not view and runs no query for it; it neither throws nor logs. Partial visibility is a normal state of a landing page, not a privileged attempt — see [the authorization note](../architecture/authorization/step-up-and-refusal-logging.md#the-dashboard-read-side-actions--one-no-throw-exception-five-refusal-logging-actions).

_Last updated: 2026-10-01 — Story 0082 Phase 4 (findings F1/F2 and the auditor's knowledge-base entries): new page; raw SQL interpolation only from closed enums, enum-array normalization and DATETIME-year bounds in the shared collaborator, and the no-throw counters note._
