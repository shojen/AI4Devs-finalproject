# Derived Column Invariants — The rule and what closes it

> Part of [Derived Column Invariants](../derived-column-invariants.md). **Read this part when:** you add or change a write site for a derived column (hash, SKU, totals) and need the rule, the failure mode and the closing pattern. The other parts are listed in the [hub](../derived-column-invariants.md#table-of-contents).

## The rule

**Every invariant a derived column's *creating* writer enforces must be re-enforced at every
*re-derivation* writer, or the invariant is a property of one code path rather than of the column.**

A derived value has more write sites than an ordinary one, and they are easy to miss because they do
not look like writes to that column at all — they look like edits to the column's *inputs*. In story
0029 there are three writers of `product_variants.sku`:

| Writer | Looks like | Enforces the length cap? | Enforces the empty-segment rule? | Catches `1062`? |
| --- | --- | --- | --- | --- |
| `CreateProductVariant` | creating a variant | ✅ | ✅ | ✅ (and disambiguates *which* unique index) |
| `UpdateProduct::reDeriveVariantSkus()` | renaming a **product** | ❌ | ❌ (n/a — values unchanged) | ⚠️ caught, but attributed to the product's own `sku` |
| `SyncProductAttributeValues::reDeriveVariantSkusForRenamedValues()` | renaming an **attribute value** | ❌ | ❌ | ❌ |

Only the first row *reads* as a SKU writer. The other two are the whole point: an administrator
renaming a colour on a taxonomy screen is, transitively, the author of a new value in a `UNIQUE`,
length-capped column on a different table.

> ✅ **The table above is the Phase 3 state. Every cell in it reads ✅ today** — all three writers now
> call `DeriveVariantSku::checked()` for the first two columns, and both cascades gained a `1062`
> catch for the third. Re-verified at the re-audit by enumerating the writers rather than trusting the
> fix: `grep -rn "product_variants'\|ProductVariant::" app/` returns exactly these three classes as
> writers of `product_variants.sku`, and none of them reaches `DeriveVariantSku::__invoke()` directly.

## Why a derived column concentrates this failure

The creating writer is where the invariants get written, because it is where the design conversation
happens — it is the class the story is named after, the one with the docblock, the one every test
targets. The re-derivation sites are added later in the same story as *retrofits to other stories'
files*, under a different heading ("the cascade"), and they are reviewed against the question **"does
the SKU follow its inputs?"** rather than **"is this a legal SKU?"**.

Both cascades here answer the first question correctly and neither asks the second.

## ❌ The three guards that exist on one write site and not the others

`CreateProductVariant` gets it right, and is the reference:

```php
// app/Actions/Products/CreateProductVariant.php — the creating writer
foreach ($ordered as $value) {
    if ($deriveVariantSku->segment($value->value) === '') {
        throw ValidationException::withMessages([
            'sku' => trans('products.variants.derived_sku_empty_segment', ['value' => $value->value]),
        ]);
    }
}

$sku = $deriveVariantSku($product->sku, $orderedValues);

if (mb_strlen($sku) > DeriveVariantSku::MAX_LENGTH) {
    throw ValidationException::withMessages([
        'sku' => trans('products.variants.derived_sku_too_long', ['max' => DeriveVariantSku::MAX_LENGTH]),
    ]);
}
```

Neither cascade carries either guard. The re-derivation loop is only:

```php
// app/Actions/Products/SyncProductAttributeValues.php — the value-rename cascade (❌ as shipped)
foreach ($variants as $variant) {
    $orderedValues = $variant->values->pluck('value')->all();
    $newSkus[$variant->id] = $deriveVariantSku($variant->product->sku, $orderedValues);
}

// ...a cross-table conflict pre-check per new SKU, then:

foreach ($newSkus as $variantId => $newSku) {
    DB::table('product_variants')->where('id', $variantId)->update([
        'sku' => $newSku,
        'updated_at' => now(),
    ]);
}
```

Three reproduced consequences, all with `sql_mode` including `STRICT_TRANS_TABLES` (**V-A**):

- **The length cap is enforced by MySQL instead of by the application.** `product_attribute_values.value`
  is `max:100` and a combination holds up to ten of them, against a `varchar(128)` `sku` — so renaming
  one value to a long string is enough. Reproduced: `SQLSTATE[22001] … 1406 Data too long for column
  'sku'`, an unhandled `QueryException` reaching the caller, on both cascades. This is precisely the
  outcome the story's own test checklist forbids (*"refused cleanly, not truncated and not a raw
  `1406`/`22001`"*) — that checklist item exists, and is satisfied only on the creating path.
- **The empty-segment rule is not enforced at all on the rename path.** Reproduced: renaming a value to
  `'???'` (which `segment()` reduces to `''`) raises **no exception** and stores the SKU `AA-`. The
  D-4.4 invariant that a value reducing to the empty string is *refused loudly* holds only at creation.
- **The pre-check compares each new SKU against the rows' *pre-rename* values**, so two variants whose
  renamed segments collide with each other both pass the check and the second `update()` hits the
  unique index. Reproduced: an uncaught `UniqueConstraintViolationException` (`1062 … for key
  'product_variants.product_variants_sku_unique'`). The transaction does roll back cleanly — atomicity
  is not the defect — but the administrator gets a 500 rather than the `derived_sku_taken` message that
  exists for exactly this case.

The severity is error-handling and data integrity rather than access control: no guard is *bypassed*,
and the enclosing transaction rolls back in every reproduced case. What escapes is a raw
`QueryException`, whose message carries the SQL statement plus the connection's host, port and database
name into the log and — at `APP_DEBUG=true` — onto the page.

## ✅ What closes it

**Move the invariant into the derivation's own seam, so no writer can call it and skip the check.**
Three writers each remembering three rules is the shape that produced this; the guards belong beside
`DeriveVariantSku`, which is already the single definition every writer shares:

```php
// app/Actions/Products/DeriveVariantSku.php — SHIPPED (2026-09-04). One checked entry
// point, called by all three writers; `__invoke()` remains for the pure derivation.
class DeriveVariantSku
{
    public const MAX_LENGTH = 128;

    /** @throws ValidationException */
    public function checked(string $productSku, array $orderedValues): string
    {
        foreach ($orderedValues as $value) {
            if ($this->segment($value) === '') {
                throw ValidationException::withMessages([
                    'sku' => trans('products.variants.derived_sku_empty_segment', ['value' => $value]),
                ]);
            }
        }

        $sku = $this($productSku, $orderedValues);

        if (mb_strlen($sku) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'sku' => trans('products.variants.derived_sku_too_long', ['max' => self::MAX_LENGTH]),
            ]);
        }

        return $sku;
    }
}
```

Two further rules the cascades need on top of it:

- **A batch pre-check must compare against the batch's own pending values, not only against the
  database.** Collect every new value first, assert the batch is internally unique, *then* check it
  against the rows that are not being rewritten. The existing `->where('id', '!=', $variantId)`
  exclusion is what makes the database half correct and is also what makes the batch half invisible.
- **A re-derivation site needs the same last-word `1062` catch its creating sibling has** — including
  `CreateProductVariant::translateRaceViolation()`'s disambiguation between the two unique indexes on
  `product_variants` (`product_variants_sku_unique` vs.
  `product_variants_product_id_combination_hash_unique`; both names verified against
  `php artisan db:table product_variants`). `UpdateProduct` has a `1062` catch but attributes every one
  of them to the *product's* own `sku`, which is now wrong for the variant rows its own cascade writes.

> ✅ **Both shipped, with one detail worth knowing before extending it.** The batch pre-check is
> `array_diff_key($newSkus, array_unique($newSkus))`, computed **before any database query runs** in
> both cascades. The disambiguation was extracted into
> [`App\Actions\Products\TranslateProductVariantUniqueViolation`](../../../app/Actions/Products/TranslateProductVariantUniqueViolation.php)
> — a stateless translator that reads the violated index's own name out of the exception and returns
> the matching `ValidationException`, **never putting the index name, the SQL or the connection
> details into the message it returns**; an unrecognised index re-throws the original rather than
> guessing. `SyncProductAttributeValues`' write loop uses that translator; `UpdateProduct`'s outer
> `1062` catch does **not** — it re-implements the same index-name test inline. That is a second
> implementation of one rule, and the shape this project's own conventions warn about; it is behaviourally
> correct today only because `UpdateProduct`'s cascade never writes `combination_hash`, so its two
> variant branches collapse to one message.
>
> ✅ **Closed 2026-09-04 (second re-audit, finding R-3).** `UpdateProduct` no longer re-implements the
> index-name test: it constructor-injects `TranslateProductVariantUniqueViolation` and calls it, so
> there is exactly one implementation of *"which index was this"* behind all three writers. The
> translator gained an optional `?string $overrideMessage` for it, because the same two indexes mean a
> different thing to a **parent-SKU-change cascade** than to a newly-created variant
> (`products.variants.parent_sku_change_collides`, always under the `sku` key, rather than
> `derived_sku_taken` / `duplicate_combination` under `sku` / `combination`). Re-checked as new code:
> the override is a `trans()` key resolved at the call site, never anything derived from the exception,
> so it opens **no new path for the index name, the SQL or the connection details to reach an
> actor-facing message** — the translator still returns only `trans()` output on both branches and
> still re-throws the original for an unrecognised index. `tests/Feature/Products/ProductVariantSkuUniquenessTest.php`'s
> F-6 test pins the behaviour through a genuine injected race (`DB::listen` inserts the colliding row
> *after* the cascade's own pre-check has run and found nothing), so it exercises the catch rather than
> the pre-check, and asserts the rollback of both the product's and the variant's SKU.
>
> ⚠️ **Neither cascade's per-row database pre-check excludes the rest of its own batch** — it excludes
> only `$variantId` (`->where('id', '!=', $variantId)`). Every *other* variant in the batch still holds
> its pre-rename SKU at check time, so a rename that merely **rotates** two SKUs between two variants
> of the same batch is refused with a collision message even though the end state is legal. Fail-closed
> and therefore not a security finding, but a real false refusal; the fix is to exclude
> `array_keys($newSkus)` rather than one id, which is safe precisely *because* the batch-internal
> `array_unique` check above already rules out a genuine within-batch duplicate.
>
> ✅ **Closed 2026-09-04 (second re-audit, finding R-4) — with one honest narrowing of what "rotates"
> can mean.** Both cascades now compute `$batchVariantIds = array_keys($newSkus)` and use
> `->whereNotIn('id', $batchVariantIds)` on the `product_variants` half of the pre-check. Re-verified
> as new code, three ways:
>
> 1. **The exclusion covers the whole batch, not part of it.** `$newSkus` is keyed by variant id and is
>    populated for *every* variant the cascade fetched, before any check runs, so `array_keys()` is the
>    complete batch by construction — there is no path that checks a row it did not also exclude.
> 2. **The ordering that makes the widening safe is unchanged and is the part to preserve.** The
>    batch-internal `array_diff_key($newSkus, array_unique($newSkus))` check runs **first**, before the
>    database is consulted at all, in both cascades. Reversed, the batch-wide exclusion would hide a
>    genuine same-batch duplicate from every check — so if a future edit moves the database loop above
>    the `array_unique` one, that is the regression to catch.
> 3. **The `whereNotIn` does not weaken D-4.5's race guard.** The clause is an extra predicate on a
>    locking read whose driving condition is still equality on `product_variants_sku_unique`; when no
>    row holds the value, InnoDB's gap lock is taken at the insertion point regardless of any
>    non-driving filter, which is the case the guard exists for. The two cross-table checks also keep
>    their fixed order (`products`, then `product_variants`), so the deadlock-avoidance argument is
>    untouched. Ids are bound parameters read out of the database, never actor input.
>
> **The narrowing:** a *true* two-element swap (A ↔ B, each taking exactly what the other holds) can
> never succeed through sequential single-row `UPDATE`s against an immediately-enforced `UNIQUE` index —
> MySQL has no deferred constraints, so whichever row writes first collides. What R-4 actually buys is
> the **one-directional chain** (A's new SKU lands on B's old SKU while B's own new SKU is untaken),
> which a real write order *can* complete and which the pre-check previously refused before either
> write was attempted. The test file states this limitation explicitly; the inline code comments say
> only "rotates", which reads as the stronger claim. Two consequences worth knowing before extending
> this: the true swap now fails at the **write**, not the pre-check — still a clean, fully-rolled-back
> `ValidationException` via the `1062` catch, never a raw database error — and which of the two
> outcomes a chain gets depends on the order the batch is written in, which is explicit
> (`Product::variants()`'s `orderBy('position')->orderBy('sku')`) in `UpdateProduct`'s cascade and
> **unordered** in `SyncProductAttributeValues`' own `ProductVariant::query()->whereHas(...)` fetch.
> Fail-closed in every ordering, so this is a UX and test-stability note rather than a finding.

## The review question

> **Who else can change this column's inputs, and does that path enforce everything the column's own
> writer does?**

Ask it of every stored derived value. The answer is never "just the class the story is named after" —
the inputs are on other tables, edited from other screens, by other stories' shipped actions. The
same question applied to a *hash* rather than a SKU is what
[database/schema.md](../../database/schema-products/attribute-types-and-values.md#product_attribute_values) already records as story 0029's
dependency on 0028's id-stability guarantee: a derived column's correctness is a property of the whole
set of paths that can move its inputs.
