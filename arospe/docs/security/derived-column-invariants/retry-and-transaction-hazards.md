# Derived Column Invariants — Relation re-reads and retried-transaction hazards

> Part of [Derived Column Invariants](../derived-column-invariants.md). **Read this part when:** you add `attempts:` to `DB::transaction()`, nest transactions, or rely on a reloaded relation or `causedByConcurrencyError()`. The other parts are listed in the [hub](../derived-column-invariants.md#table-of-contents).

## Related: re-loading a relation does not re-read the key it resolves through

A second, smaller finding from the same audit, recorded here because it is the derived-value idea
applied to an **authorization target**. `UpdateProductVariant` and `DeleteProductVariant` authorize
against the variant's parent product, and correctly reload the relation first, per
[model-instance-trust.md](../model-instance-trust.md):

```php
// app/Actions/Products/DeleteProductVariant.php (❌ as shipped)
$variant->load('product');

$this->logRefusedPrivilegedAttempt->authorize(
    'update',
    $variant->product,
    targetType: 'product',
    targetId: $variant->product->id,
);

return (bool) $variant->delete();
```

`load('product')` re-reads the **product**, but it resolves *which* product from the caller's
in-memory `$variant->product_id` — which is mass-assignable on `ProductVariant`
(`#[Fillable(['product_id', …])]`) and is simply a public attribute besides. `delete()` then acts on
`$variant->getKey()`. So the row acted on and the row authorized against come from two different
sources, and a caller that stages the instance decides which product the gate sees. Reproduced: a
variant belonging to product `VICTIM` was deleted while the gate evaluated `update` against product
`DECOY`.

**No shipped caller does this** — story 0029 ships no Livewire component or route at all, and story
0031's editor will resolve variants with `findOrFail()`. It is a latent shape, not a live bypass. The
rule it illustrates is worth keeping regardless:

> **Re-read the *subject* of the operation, not only the relation you authorize through.** A relation
> refreshed from an in-memory foreign key is exactly as trustworthy as that foreign key.

The concrete fix is one line — resolve the variant from the database inside the action
(`$variant = ProductVariant::query()->with('product')->findOrFail($variant->getKey())`) and act only
through that instance, the same "re-fetch the row and read/write only through that instance" remedy
[model-instance-trust.md](../model-instance-trust.md) already prescribes for `SalesRegion`. Removing
`product_id` from `ProductVariant`'s `#[Fillable]` is worth doing beside it (a variant's parent is
fixed at creation and `CreateProductVariant` writes it through `forceCreate()` anyway), but it is
defence in depth, not the fix — `save()` writes the whole dirty set, so the omission is a
mass-assignment guard and not an integrity one.

> ✅ **Both shipped, and the ordering is the part to preserve.** `UpdateProductVariant` and
> `DeleteProductVariant` open with
> `ProductVariant::query()->with('product')->whereKey($variant->getKey())->firstOrFail()`, and that
> re-fetch is the method's **first statement** — above the `Gate` call, above validation, above any
> transaction, per D-12.1. The caller-supplied `$variant` is shadowed immediately and nothing reads it
> in between, so there is no window in which validation ran against one instance and the gate against
> another. `#[Fillable]` is now `['price', 'stock', 'featured_media_id', 'position']`. The
> `#[Fillable]` change is invisible to `ProductVariantFactory`, which is not a counter-example: Laravel
> factories build models inside `Model::unguarded()`, so mass-assignment rules never applied there in
> the first place — and the factory assigns `product_id` explicitly in its own `afterMaking()` anyway.
>
> One consequence to know rather than to fix: `firstOrFail()` on a variant that no longer exists throws
> `ModelNotFoundException` (404) **before** the gate runs, so a missing variant and an unauthorised one
> are distinguishable. That is the same ordering every route-model-bound screen in this app already
> has, and `product_variants.id` is a UUIDv7 with no enumerable surface, so it discloses nothing an
> actor could act on.

## What the remediation introduced: a retried transaction is a retry-safe unit, or it is a lost update

❌ **As found (first re-audit, 2026-09-04) — ✅ closed the same day by the second re-audit; see the
block at the end of this section.** Found by re-auditing the fix as new code, per
[errors-log-archive.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#two-of-the-three-security-audit-rounds-found-the-flaw-in-the-previous-rounds-fix--2026-08-19).
The ❌ text below is kept verbatim as the record of what shipped, per the audit-authored-page rule.

The same remediation added `attempts: 3` to three `DB::transaction()` calls, so that the fixed lock
order those transactions rely on converges under a deadlock instead of 500-ing. `attempts: N` changes
one thing about a closure that is easy to miss: **the closure can now run more than once, and a
rollback restores the database but not the PHP objects the closure mutated.**

```php
// app/Actions/Products/UpdateProduct.php — ❌ as shipped: $product is created OUTSIDE the closure
return DB::transaction(function () use ($product, $sku, /* … */): Product {
    $skuChanged = $product->sku !== $sku;

    $product->update(['sku' => $sku, /* … */]);   // attempt 1: syncOriginal() runs

    if ($skuChanged) {
        $this->reDeriveVariantSkus($product, $sku);   // ← a plausible deadlock site
    }

    return $product;
}, attempts: 3);
```

Executed rather than reasoned about — the Eloquent half, with no database involved:

| | attempt 1 | attempt 2 (after the rollback) |
| --- | --- | --- |
| `$product->sku !== $sku` | `true` | **`false`** |
| `isDirty()` after `fill()`, i.e. does `update()` issue SQL | `true` | **`false`** |

So a concurrency error anywhere in that closure — a genuine 1213, or the far likelier
`Lock wait timeout exceeded`, both of which `causedByConcurrencyError()` matches — makes attempt 2
**commit having written nothing**: the product's own column changes were rolled back and are never
re-applied, `$skuChanged` is now false so the variant cascade is skipped entirely, and the action
returns a `Product` whose in-memory attributes show the new values, so the caller reports success. A
silent lost update, reported as saved. `SyncProductGallery` shares the shape (`forceFill(...)->save()`
on the same outside-the-closure instance), so the featured-image write is lost with it.

✅ **The pattern this repo already had, one folder away** — pass **keys** into the closure and re-read
the rows inside it, so every attempt starts from the database:

```php
// app/Actions/SalesRegions/SetSalesRegionActive.php — the shape to copy
$regionKey = $region->getKey();
$replacementKey = $replacementDefault?->getKey();

return DB::transaction(function () use ($regionKey, $replacementKey, $active): SalesRegion {
    $rows = SalesRegion::query()->whereIn('id', [$regionKey, $replacementKey])
        ->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($r) => $r->getKey());
    // …every model this closure writes was fetched by THIS attempt.
}, attempts: 3);
```

**The rule: adding `attempts: N` is a change to the closure's contract, not a flag.** Before adding it,
check that every model, collection or accumulator the closure writes to was created *inside* it — an
Eloquent model created outside is the common case, and it fails silently rather than loudly, because
`save()` on a non-dirty model is a successful no-op rather than an error.

⚠️ **A second, coupled fact: `attempts` on a nested transaction is inert, and all three of this
story's are nested on the only shipped path.** `Connection::handleTransactionException()` refuses to
retry while `$this->transactions > 1` — it converts the error to a `DeadlockException` and rethrows,
so only the **outermost** transaction's `attempts` ever fires. `App\Livewire\Products\Editor::save()`
opens its own `DB::transaction()` around `CreateProduct`/`UpdateProduct`, and that outer one takes no
`attempts` — so the retry never runs there, and the window the fix was aimed at is still open on the
screen that matters. **Do not close that half first.** Moving `attempts: 3` up to `Editor::save()`
without fixing the shape above converts a rare, loud 500 into a silent lost update on every retry.
`SetSalesRegionActive`'s own docblock already records the outermost-transaction rule; it is restated
here because this story is where the two halves first pull against each other.

> ✅ **Closed 2026-09-04 (second re-audit, finding R-1) — by removal, not by rewriting the closure.**
> `UpdateProduct`'s `DB::transaction()` now takes **no** retry parameter at all, with a comment above it
> stating why and naming this section. The key-passing shape above was considered and *not* adopted
> here: `UpdateProduct` returns the caller's own `$product` instance and its cascade reads
> `$skuChanged` from the pre-mutation attribute, so re-reading inside the closure would have meant
> re-shaping the action's return contract to close a window that removal closes outright. Verified as
> new code rather than taken on the diff's word:
>
> - **`attempts` is genuinely absent, and nothing reintroduces it one layer out.**
>   `grep -rn "attempts" app/` returns exactly two `}, attempts: 3` call sites in this domain —
>   `CreateProduct` and `CreateProductVariant` — plus the two pre-existing `SalesRegions` ones and
>   `UpdateProduct`'s own explanatory comment. `App\Livewire\Products\Editor::save()`, the only shipped
>   caller, still opens a plain `DB::transaction()` with no `attempts`, so no outer retry can resurrect
>   the mutating-closure problem from above. `tests/Feature/Products/ProductVariantSkuUniquenessTest.php`
>   pins this with a source-level assertion that also proves it can fail — it asserts the literal
>   `}, attempts:` **is** present in both siblings before asserting its absence in `UpdateProduct`,
>   rather than trusting a negative on its own (the vacuous-assertion trap
>   [errors-log-archive.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-pest-arch-rule-over-an-array-of-namespaces-shipped-green-while-proving-nothing--2026-08-18) records).
> - **The two retained `attempts: 3` are still retry-safe.** Both build the row they mutate *inside*
>   the closure with `forceCreate()`; a second attempt re-does real work rather than skipping it. Every
>   model either closure writes to is fetched or created by that attempt — `CreateProductVariant` reads
>   `$product` from outside but only reads it (`->id`, `->sku`, `->variants()`), never mutates it.
> - **The nested-inertness note above still stands and is now the *only* residual.** `Editor::save()`'s
>   outer transaction makes both retained `attempts: 3` inert on the shipped path, so the deadlock
>   window they were added for is still open on the screen. That is unchanged by R-1 and remains
>   correct as written — and R-1 is exactly why "move `attempts` up to `Editor::save()`" is not the fix:
>   an outer retry would wrap `UpdateProduct`'s mutating closure again, one level higher, and
>   reintroduce the silent lost update this section documents. Closing that half needs the
>   key-passing shape at `Editor::save()`, not a flag.

## Second confirming instance: a generator's own outer transaction — `attempts` fires only at nesting level 1

**Corrected 2026-09-05 (story 0029b, `appsec-auditor`'s Phase 4 pass).** The [What the remediation
introduced](#what-the-remediation-introduced-a-retried-transaction-is-a-retry-safe-unit-or-it-is-a-lost-update)
section above states — and the R-1 closure block repeats — that `App\Livewire\Products\Editor::save()`
is *"the only shipped caller"* opening an outer `DB::transaction()` around these actions, and that
*"`Editor::save()`'s outer transaction makes both retained `attempts: 3` inert on the shipped path."*
Read together, both sentences imply `Editor::save()` is this domain's **only** outer transaction. That
stopped being true the moment `App\Actions\Products\GenerateProductVariantCombinations` shipped: it
opens its **own** outer `DB::transaction(attempts: 3)`, entirely independent of `Editor::save()`,
wrapping a loop of `CreateProductVariant` calls whose own `DB::transaction(attempts: 3)` becomes a
savepoint under it — the identical nesting shape `Editor::save()` already produces over
`CreateProduct`/`UpdateProduct`. Both sentences are kept above as the record of what this page
asserted, per this project's audit-authored-page convention, rather than silently rewritten.

Two things follow, and neither is a new *mechanism* — both are the already-documented
nested-`attempts`-is-inert rule confirmed on a second, independent call path, which is why this fact
belongs on this page rather than in [errors-log.md](../../errors-log.md) (whose own 2026-08-19 entry
records the rule that a repeated instance of an already-documented mistake stays off the log and on the
relevant page instead):

1. **The generator's own transaction is retry-safe by the rule this page already states above.** Every
   mutable accumulator it writes — the `created`/`skipped`/`refused` summary, the pre-read
   `combination_hash` set, the per-combination loop state — is created **fresh inside the closure** on
   every attempt. Unlike the `UpdateProduct` hazard documented above, there is no Eloquent model
   mutated *outside* the closure for a retry to silently no-op against.
2. **`CreateProductVariant`'s own `attempts: 3` is silently inert when invoked from inside the
   generator's transaction**, for the identical reason it is already inert when `CreateProduct`/
   `UpdateProduct` run inside `Editor::save()`'s: `Illuminate\Database\Concerns\ManagesTransactions
   ::transaction()` only enters its retry loop when `$this->transactions === 1` on the connection — at
   any deeper nesting level, `handleTransactionException()` converts a concurrency exception straight
   to a rethrow, never retrying. Verified against the trait itself rather than assumed from the
   `Editor::save()` case alone.

**The general rule this page now states outright, generalized past both instances**: `DB::transaction($fn,
attempts: N)`'s retry only ever fires for the **outermost** transaction on the connection — a callee's
own `attempts` value is inert the moment *anything* wraps it, regardless of which class opened the
outer transaction, how many call frames sit in between, or whether the outer transaction itself
requested a retry.

⚠️ **For a future caller.** Before adding `attempts: N` to any `DB::transaction()` in this domain,
first determine whether it can ever run nested under another one already carrying `attempts` (or ever
will) — an inert `attempts` is not itself a bug, but a `DB::transaction()` that *assumes* its own retry
will fire when an enclosing one already suppresses it is the same lost-update shape documented above,
arriving through nesting rather than through a badly-scoped closure. **Story 0031's future integration
of the generator into `Editor::save()` must not add its own `attempts:` to that outer transaction
without first re-deriving this analysis** — see the epic-2 decision digest's Story 0029b entry.

## Confirmed safe: `causedByConcurrencyError()` matches a message, not a class

Worth recording because the obvious mental model is wrong and the safety here is contingent rather
than structural. `Illuminate\Database\ConcurrencyErrorDetector::causedByConcurrencyError()` does **not**
test that the exception is a `QueryException`. It tests `$e instanceof PDOException` with SQLSTATE
`40001`, and otherwise falls through to `Str::contains($e->getMessage(), [...])` against ten literal
strings, **for any `Throwable`**. Verified by execution:

```php
$d = new Illuminate\Database\ConcurrencyErrorDetector();
$d->causedByConcurrencyError(ValidationException::withMessages(
    ['sku' => 'Deadlock found when trying to get lock']));   // true
$d->causedByConcurrencyError(ValidationException::withMessages(
    ['sku' => 'The derived SKU AA-X is already in use.']));   // false
```

`ValidationException`'s own message is `static::summarize($validator)` — **the first validation error
message**, interpolations included. So a `ValidationException` thrown inside a retried transaction is
retried whenever its rendered message happens to contain one of those literals, and every one of them
contains spaces.

This is inert here, and each step of that was checked rather than assumed. Only five messages can be
thrown inside these transactions (`duplicate_combination`, `derived_sku_taken`,
`derived_sku_empty_segment`, `derived_sku_too_long`, `parent_sku_change_collides`, plus
`validation.unique`/`validation.exists`), and only two interpolate anything: `:sku`, which
`DeriveVariantSku::segment()` restricts to `[A-Za-z0-9._/-]` and therefore cannot contain a space; and
`:value`, the raw attribute value — which reaches that message **only** when `segment($value) === ''`,
i.e. only when the value contains no letters or digits at all, so it cannot spell any of the literals
either. **The rule for the next story: a transaction with `attempts: N` must not throw an exception
whose message can carry user-controlled text**, or an ordinary validation refusal silently becomes
three times the work — and, with a mutating closure like the one above, three times the damage.

_Last updated: 2026-09-05 — Story 0029b (Product variant combination generator — backend). **Corrected
this page's own stale claim, in place, per the audit-authored-page convention**: the
[What the remediation introduced](#what-the-remediation-introduced-a-retried-transaction-is-a-retry-safe-unit-or-it-is-a-lost-update)
section's "`Editor::save()`, the only shipped caller" framing implied `Editor::save()` was this
domain's *only* outer transaction — false as of this story, which ships
`App\Actions\Products\GenerateProductVariantCombinations` as a second, independent outer
`DB::transaction(attempts: 3)`, with `CreateProductVariant`'s own transaction nesting under it as a
savepoint exactly as it already does under `Editor::save()`. Added
[Second confirming instance: a generator's own outer transaction — `attempts` fires only at nesting level 1](#second-confirming-instance-a-generators-own-outer-transaction--attempts-fires-only-at-nesting-level-1),
generalizing the mechanism this page already carried (verified against
`Illuminate\Database\Concerns\ManagesTransactions`: the retry loop only fires when
`$this->transactions === 1`) into a rule this domain's next transaction author must check before
adding `attempts:` to anything — explicitly including story 0031's future `Editor::save()` integration
of this generator. Deliberately **not** a new `errors-log.md` entry, per this project's own precedent
(a repeated instance of an already-documented mistake stays on the relevant page, not the log) and per
this story's own scope fence against duplicating findings already closed by story 0029. **Verified as
unchanged rather than assumed:** every other section on this page — the derived-`sku`/`combination_hash`
sections, the relation-reload finding and the `causedByConcurrencyError()` confirmed-safe note are all
untouched by this story's diff._

_Earlier revision notes: [security--derived-column-invariants--retry-and-transaction-hazards.md](../../history/security--derived-column-invariants--retry-and-transaction-hazards.md)._
