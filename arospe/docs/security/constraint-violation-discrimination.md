# Discriminating a constraint violation: match the driver's error, never `getMessage()`

Established by story 0070's Phase 4 audit (translatable-content mechanism, piloted on Product
Categories), finding **F-1**.

A write action that catches a `QueryException` and decides *which* constraint fired (so one specific
unique index becomes a clean `ValidationException` while every other violation is rethrown) must
decide from the **driver's own error**, not from `QueryException::getMessage()`.

**The review question this page adds: can any byte of the string being matched come from the
request?**

## Why `getMessage()` is the wrong input

`Illuminate\Database\QueryException::formatMessage()` builds its message as

```php
$previous->getMessage().' (Connection: '.$connectionName.$details.', SQL: '.Str::replaceArray('?', $bindings, $sql).')'
```

so the message contains **every bound value of the failing statement, interpolated**. On a write, the
bindings are the submitted field values. A `str_contains($e->getMessage(), '<index name>')` check is
therefore satisfied by *any* failing statement whose submitted text contains the index name, whatever
the database actually reported.

It is not SQL injection (nothing is built from the match) and it does not leak data. The effect is a
**misclassified error**: a foreign-key violation, the *other* unique index, a deadlock or a lock-wait
timeout is reported to the user as "the name has already been taken", and the real exception is
swallowed instead of reaching the log. That is why the rule is Low severity but still binding: the
translator is the recipe every translatable entity copies (0071-0078), and its own contract says
"only 1062 on this index".

## ❌ The shape story 0070 first shipped

`app/Actions/ProductCategories/TranslateProductCategoryNameUniqueViolation.php`:

```php
if (str_contains($e->getMessage(), 'product_category_translations_store_language_id_name_unique')) {
    return ValidationException::withMessages([
        $errorKey => trans('validation.unique', ['attribute' => $errorKey]),
    ]);
}

throw $e;
```

A category renamed to `x product_category_translations_store_language_id_name_unique` passes
validation (61 characters, well under `max:255`). Any non-name failure of that insert or update is then
reported as a name collision. The error code is never checked, although the class's own contract says
"only 1062".

## ✅ The rule

1. Gate on the **driver error code** first: `($e->errorInfo[1] ?? null) === 1062`. A `QueryException`
   with no `errorInfo` is never a match.
2. Match the index name against the **driver message** (`$e->errorInfo[2]`), not the formatted one,
   **anchored to the `for key '…'` suffix**. MySQL's 1062 text is
   `Duplicate entry '<value>' for key '<table>.<index>'`. The table prefix appears from MySQL 8.0.19,
   so allow it as optional. `<value>` is user text too, so only the anchored suffix is trustworthy.

```php
$isNameCollision = ($e->errorInfo[1] ?? null) === 1062
    && preg_match(
        "/for key '(?:[^']+\\.)?product_category_translations_store_language_id_name_unique'$/",
        (string) ($e->errorInfo[2] ?? ''),
    ) === 1;
```

3. Unit-test it with a constructed exception whose **bindings** contain the index name while
   `errorInfo` reports another constraint (1452, or 1062 on the other index), and assert the same
   instance is rethrown. A test whose constructed SQL has no bindings (`[]`) cannot catch this bug.

## Scope and related pages

- The shipped precedent `App\Actions\Products\TranslateProductVariantUniqueViolation` uses the same
  `getMessage()` match between its two `product_variants` indexes. There the effect is limited to
  choosing between two `ValidationException` messages, because the parameter type already guarantees a
  1062. It should be moved to the rule above when that file is next touched.
- [derived-column-invariants/retry-and-transaction-hazards.md](derived-column-invariants/retry-and-transaction-hazards.md#confirmed-safe-causedbyconcurrencyerror-matches-a-message-not-a-class)
  records the framework-side form of the same hazard: `causedByConcurrencyError()` matches message
  text, and a validation message can contain that text.

_Last updated: 2026-09-28 — created by story 0070's Phase 4 audit (finding F-1)._
