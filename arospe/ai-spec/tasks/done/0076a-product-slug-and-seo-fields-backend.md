# [0076a] Product slug and SEO fields — per-store-language (backend)

## Description
Adds the product **slug** and **SEO meta fields** (`slug`, `meta_title`, `meta_description`) to the per-store-language `product_translations` table that [0076](../done/0076-translatable-content-retrofit-products-backend.md) creates ([PRD Epic 5, Layer 2](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization); [assumption 14](../../../docs/PRD/sections/foundations.md#assumptions--confirmed-decisions) names *"slug/SEO fields … on products"* as translatable content). It covers the columns, their validation rules, the `CreateProduct`/`UpdateProduct` signature widening, slug canonicalisation in two layers, per-language slug uniqueness, and a 1062 race catch that is disambiguated by index name.

**Origin.** This story was split out of 0076 on 2026-11-06 (project owner's decision after 0076's Phase 2 rejection; see that file's review log). The text below reuses 0076's original Phase 1 debate (2026-08-29) where it is still valid. Its decision numbers keep 0076's original ones (**D-5**, **D-6**, **D-18**) so that [0077](0077-product-editor-language-tabs-ui.md)'s references still resolve. New decisions start at **D-30**.

**Numbering.** This story is `0076a`, not the next free integer (`0088`). The reason is the [task ordering rule](../../../docs/workflow/task-files-links-and-ordering.md#task-ordering-rule): a dependency's number must be lower than its dependents', and [0077](0077-product-editor-language-tabs-ui.md) consumes this story's rule methods and widened signatures. A suffix keeps `0076 < 0076a < 0077` without renumbering 0077, using the same convention as `0024a`/`0024b`.

## Type
backend | includes database-expert: **yes** (one additive migration with a named composite unique index)

## 1. Refined user story

> **As** a catalog administrator running a store that sells in more than one language,
> **I want** each product to carry its own slug, meta title and meta description per store language,
> **so that** each language's storefront can address and describe the product in its own words, and two products can never claim the same address within one language.

**Scope fence.** No screen, route, tab or lang key for a visible control. The editor fields and the slug pre-fill affordance belong to [0077](0077-product-editor-language-tabs-ui.md). Every write in this story goes through `CreateProduct`/`UpdateProduct` and writes the **default store language**, as in 0076. Non-default-language writes are 0077's `SetProductTranslation`.

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and carries exactly one `When` ([gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3). Content in other languages appearing in a `Given` is arranged fixture data.

```gherkin
Feature: Per-store-language product slug and SEO fields

  # --- Canonical form ---

  Scenario: A slug is stored in its canonical form
    Given a catalog administrator holding the products edit permission
    When they update a product's slug to "  Chaussures De Course  "
    Then the stored default-language slug reads "chaussures-de-course"

  Scenario: A slug with no usable characters is stored as no slug
    Given a catalog administrator holding the products edit permission
    When they update a product's slug to "!!!"
    Then the product is saved and its default-language slug is empty

  Scenario: A whitespace-only slug is stored as no slug
    Given a catalog administrator holding the products edit permission
    When they update a product's slug to "   "
    Then the product is saved and its default-language slug is empty

  Scenario: A product may be saved without a slug
    Given a catalog administrator holding the products create permission
    When they create a product leaving its slug empty
    Then the product is created, because no storefront resolves a product by slug yet

  # --- Per-language uniqueness ---

  Scenario: Two products cannot share a slug within one store language
    Given a catalog administrator holding the products edit permission, with another product whose default-language slug is "chaussures-de-course"
    When they update this product's slug to "chaussures-de-course"
    Then the change is refused with a validation message on the slug

  Scenario: A slug colliding only after canonicalisation is still refused
    Given a catalog administrator holding the products edit permission, with another product whose default-language slug is "chaussures-de-course"
    When they update this product's slug to "Chaussures De Course"
    Then the change is refused with a validation message on the slug

  Scenario: The same slug in another store language is permitted
    Given a catalog administrator holding the products edit permission, with another product whose French slug is "chaussures-de-course"
    When they update this product's default-language slug to "chaussures-de-course"
    Then the change is accepted, because slug uniqueness is scoped to one store language

  Scenario: A product keeps its own slug when re-saved
    Given a catalog administrator holding the products edit permission, with a product whose default-language slug is "chaussures-de-course"
    When they update that product leaving its slug unchanged
    Then the change is accepted rather than refused as a duplicate

  Scenario: Two products may both be saved without a slug
    Given a catalog administrator holding the products create permission, with another product holding no default-language slug
    When they create a product leaving its slug empty
    Then the product is created

  Scenario: A concurrent first save of the default-language content asks the administrator to retry
    Given a catalog administrator holding the products edit permission, with a product that has no row in the default store language, and another administrator's save inserting that row at the same moment
    When they save the product
    Then the change is refused with a localised message asking them to reload and try again, rather than a server error or a SKU or slug message

  # --- SEO fields ---

  Scenario: A meta description longer than its limit is refused
    Given a catalog administrator holding the products edit permission
    When they update a product's meta description to 501 characters
    Then the change is refused with a validation message on the meta description

  Scenario: The SEO fields are stored per language without touching other languages
    Given a catalog administrator holding the products edit permission, with a product whose French meta title is "Chaussures"
    When they update the product's default-language meta title to "Zapatillas"
    Then the French meta title still reads "Chaussures"

  # --- Fallback ---

  Scenario: A missing French meta title falls back to the default language's
    Given a catalog administrator, with a product whose French slug is present and whose French meta title is absent
    When the product's French meta title is requested
    Then the store default's meta title is returned
```

## Files to create/modify

### Create
- **`database/migrations/<timestamp>_add_slug_and_seo_columns_to_product_translations_table.php`** (timestamp strictly after 0076's two migrations):

  ```php
  public function up(): void
  {
      Schema::table('product_translations', function (Blueprint $table): void {
          $table->string('slug', 160)->nullable()->after('description');           // D-5, D-6
          $table->string('meta_title', 160)->nullable()->after('slug');            // D-5
          $table->string('meta_description', 500)->nullable()->after('meta_title'); // D-5 (Q-1(a))

          $table->unique(['store_language_id', 'slug'], 'product_translations_language_slug_unique'); // D-6, D-31
      });

      // D-34: on a fresh migrate MySQL has already dropped the FK index it auto-created in 0076,
      // so this is a no-op. After a down(), the explicit index down() added survives the unique
      // above; it is redundant now that the composite supports the FK, so drop it here to keep
      // exactly three indexes. Checked in its own call, after the composite exists.
      if (Schema::hasIndex('product_translations', 'product_translations_store_language_id_foreign')) {
          Schema::table('product_translations', function (Blueprint $table): void {
              $table->dropIndex('product_translations_store_language_id_foreign');
          });
      }
  }

  public function down(): void
  {
      // D-11b: after up(), the composite unique is the only index whose leftmost column is
      // store_language_id, so MySQL uses it to enforce the store_language_id foreign key.
      // Dropping it first fails with error 1553 ("needed in a foreign key constraint").
      // Restore a plain index first, under the name MySQL gave the one it auto-created in 0076.
      Schema::table('product_translations', function (Blueprint $table): void {
          $table->index('store_language_id', 'product_translations_store_language_id_foreign');
      });

      Schema::table('product_translations', function (Blueprint $table): void {
          $table->dropUnique('product_translations_language_slug_unique');
          $table->dropColumn(['slug', 'meta_title', 'meta_description']);
      });
  }
  ```

  There is no backfill: every existing row gets `NULL` in all three columns and **no slug is generated** (**D-9b**). `down()` is an inverse **only because** it restores the FK-supporting index before dropping the composite unique (**D-11b**, **D-34**). The two `Schema::table` calls are deliberate, so the index exists before the unique is dropped. `up()` is exact on a re-run **only because** its guarded `dropIndex` removes that restored index once the composite exists (**D-34**): MySQL silently drops only an index it auto-created for a foreign key, never an explicit one. So fresh migrate and `down()` then `up()` both end with the same three indexes. After `down()`, `product_translations` has the same three indexes as at the end of 0076 (`primary`, `product_translations_product_language_unique`, and the `store_language_id` index). Phase 3 verifies the restored index name against `db:table` output from 0076's schema, and records it if MySQL named it differently.
- **`app/Actions/Products/CanonicalizeProductSlug.php`** is the **single** canonicaliser, used by both layers (**D-6**): `__invoke(?string $slug): ?string`. It runs `trim()`, returns `null` when the result is `''`, and otherwise returns `Str::slug($slug) ?: null`. So `'!!!'` and whitespace-only input both become `null`, never `''`.
- **`app/Actions/Products/TranslateProductUniqueViolation.php`** is the **single** 1062-to-field translator for both product actions (**D-31**). It is modelled on `TranslateProductCategoryNameUniqueViolation`: stateless, container-resolved, constructor-injected into `CreateProduct` and `UpdateProduct`, and it never reads `getMessage()`.

  ```php
  /**
   * @throws QueryException  $e unchanged when it is not a 1062, or names any other index
   */
  public function __invoke(QueryException $e): ValidationException
  ```

  - It considers the exception only when `($e->errorInfo[1] ?? null) === 1062`. It then matches `(string) ($e->errorInfo[2] ?? '')` against `/for key '(?:[^']+\.)?<index>'$/` for each index below.
  - `products_sku_unique` returns `ValidationException::withMessages(['sku' => trans('validation.unique', ['attribute' => 'sku'])])`. This message is byte-identical to today's.
  - `product_translations_language_slug_unique` returns `['slug' => trans('validation.unique', ['attribute' => trans('products.attributes.slug')])]`.
  - `product_translations_product_language_unique` returns `['name' => trans('products.editor.concurrent_save_retry')]` (**R-22**, decided as option (b)). The key is `name`, because the default-language translation row holds the name and the editor already renders errors on `name`.
  - Anything else, including both `product_variants` indexes, a 1452 and a 1213, is **rethrown** with `throw $e`. The variant indexes stay with `TranslateProductVariantUniqueViolation`, and `UpdateProduct` routes to that translator first (see below).

### Modify
- **`app/Models/ProductTranslation.php`**: `#[Fillable]` gains `slug`, `meta_title` and `meta_description`. Inside the existing `booted()` `saving` hook (0076's sanitizer), add **layer 2** of canonicalisation:

  ```php
  if ($translation->isDirty('slug')) {
      $translation->slug = app(CanonicalizeProductSlug::class)($translation->slug);
  }
  ```

  There is deliberately no `filled()` guard in front of the call: blank becomes `null` *inside* the canonicaliser, so a guard would let `'   '` through untouched (**D-6**).

  The same hook also normalises the two meta fields (**D-35**): when `meta_title` or `meta_description` is dirty, it is trimmed, and a blank result becomes `null`. This binds every writer, including bare `SetTranslation`, so `''` is never stored in any of the three columns. The actions do **not** repeat this step.
- **`app/Concerns/ProductValidationRules.php`**, three new methods named in the trait's existing `product*Rules()` style (**D-32**):
  - `productSlugRules(string $storeLanguageId, ?string $productId = null): array` returns `['nullable', 'string', 'max:160', Rule::unique(ProductTranslation::class, 'slug')->where(fn ($q) => $q->where('store_language_id', $storeLanguageId)->when($productId !== null, fn ($q) => $q->where('product_id', '!=', $productId)))]`. The own-row exclusion is by **`product_id`**, never `->ignore()` (**D-6**).
  - `productMetaTitleRules(): array` returns `['nullable', 'string', 'max:160']`.
  - `productMetaDescriptionRules(): array` returns `['nullable', 'string', 'max:500']`.
- **`app/Actions/Products/CreateProduct.php`**: **the signature widens** by appending `?string $slug = null, ?string $metaTitle = null, ?string $metaDescription = null` (**D-18**). On create, leaving them out and passing "none" mean the same thing, which is the same reasoning that keeps `$description`'s default.
  - **Layer 1:** after `authorize()` and before `validate()`, `$slug = ($this->canonicalizeProductSlug)($slug)`, so the uniqueness rule compares the stored form (**D-6**). The meta fields are passed through as given: the `max:` rules apply to the raw value, and the `saving` hook normalises blank to `null` (**D-35**).
  - Validation adds `slug`, `meta_title` and `meta_description` keys using the three methods (`$defaultLanguageId`, no product id).
  - The `SetTranslation` attribute array gains the three keys.
  - The constructor gains `private readonly TranslateProductUniqueViolation $translateProductUniqueViolation`. The blanket "every 1062 is `sku`" branch is replaced by `throw ($this->translateProductUniqueViolation)($e);` (**D-31**). Non-1062 exceptions keep being rethrown, as today.
- **`app/Actions/Products/UpdateProduct.php`**: **the signature widens** by appending `?string $slug, ?string $metaTitle, ?string $metaDescription` **with no defaults**. Leaving them out on update would silently clear stored values, which is the same reasoning as Phase 4 re-audit finding F-6 for `$description` (**D-18**, **Q-3**). The canonicalisation and validation are the same as on create, with `$product->id` as the own-row exclusion. It keeps **no** `attempts:` (0076 **D-15**). The catch is restructured as follows (**D-31**):
  - The constructor gains `private readonly TranslateProductUniqueViolation $translateProductUniqueViolation`, next to the existing variant translator.
  - Inside `if (($e->errorInfo[1] ?? null) === 1062)`, the `product_variants` branch condition moves from `str_contains($e->getMessage(), …)` to the anchored `errorInfo[2]` match: `preg_match("/for key '(?:[^']+\\.)?(?:product_variants_sku_unique|product_variants_product_id_combination_hash_unique)'$/", (string) ($e->errorInfo[2] ?? '')) === 1`. The `instanceof UniqueConstraintViolationException` guard and the call to `TranslateProductVariantUniqueViolation` with `overrideMessage: trans('products.variants.parent_sku_change_collides')` are unchanged.
  - The inline `products_sku_unique` branch is removed. Every other exception, 1062 or not, ends in `throw ($this->translateProductUniqueViolation)($e);`, which maps `sku`, `slug` and the natural-key retry, and rethrows the rest.
  - `TranslateProductVariantUniqueViolation`'s own internal `getMessage()` match is **not** changed (technical task 2).
- **`app/Livewire/Products/Editor.php`**: carries the default-language `slug`, `meta_title` and `meta_description` through `save()` unchanged, so an edit made today does not clear them. It reads them in `mount()` from the raw default-language row into three **`#[Locked]`** public properties (there is no input for them yet) and passes them to `UpdateProduct`. `CreateProduct` receives `null`. 0077 replaces this with real per-language inputs (**D-33**, **Q-3**).
- **`database/factories/ProductTranslationFactory.php`**: no slug by default. It adds a `withSeo()` state for tests.
- **`lang/en/products.php`** / **`lang/es/products.php`** (key-for-key identical):
  - `attributes.slug`, `attributes.meta_title` and `attributes.meta_description` leaves, so validation messages name the field.
  - `editor.concurrent_save_retry` (**R-22**): en `'Someone else saved this product at the same moment. Reload the page and try again.'`, es `'Otra persona ha guardado este producto al mismo tiempo. Recarga la página e inténtalo de nuevo.'`. The wording is negotiable in Phase 2. The key name is not, because the translator and its test bind to it.
  - **File-sharing with [0087](../0087-translation-write-actions-refuse-inactive-store-language.md):** 0087 adds `categories.index.tabs.language_inactive` to the same two files. The arrays do not overlap, but whichever story merges second must rebase onto the other's version of both files and keep both leaves. Never resolve a conflict by taking one side whole.
- **Test files**: every direct caller of `UpdateProduct` passes the three new arguments (mechanical). See the disposition note below.

### Deliberately not touched
- `SetTranslation`, `HasTranslations`, `StoreLanguage`: unmodified.
- `NormalizeForSearch`: not used. A slug is canonicalised in place, never shadowed by a fold column.
- `productNameRules()`, `productDescriptionRules()`, `productSkuRules()`: byte-identical.
- `config/store-languages.php`: the table is already registered by 0076.

## Tests to perform — 3. QA test cases / validation scenarios

### Canonicalisation (D-6)
- [ ] Unit: `CanonicalizeProductSlug` maps `'  Chaussures De Course  '` to `'chaussures-de-course'`, `'!!!'` to `null`, `'   '` to `null`, `''` to `null`, `null` to `null`, and is **idempotent** on an already-canonical slug.
- [ ] Feature (layer 1): through `UpdateProduct`, `'Chaussures De Course'` collides with a stored `'chaussures-de-course'`. This proves the rule ran on the canonical form.
- [ ] Feature (layer 2): a bare `SetTranslation` call (no action in the path) with `'  Chaussures De Course  '` stores `'chaussures-de-course'`, and with `'!!!'` and with `'   '` stores `NULL`.
- [ ] Feature (layer 2, **D-35**): a bare `SetTranslation` call with `meta_title` `''` and `meta_description` `'   '` stores `NULL` in both, and `'  Zapatillas  '` is stored as `'Zapatillas'`.
- [ ] Feature: `'!!!'` and whitespace-only through **each** action save successfully with a `NULL` slug. The `nullable` rule plus the canonical `null` must not produce a spurious `unique` refusal against other `NULL`-slug rows.

### Uniqueness (D-6, D-31)
- [ ] Feature: two products, same canonical slug, **same** language: refused on `slug`.
- [ ] Feature: two products, **byte-identical** slug, **different** languages: accepted. This is the only fixture that proves the scope is per-language.
- [ ] Feature: re-saving a product's own slug is accepted (the no-op succeeds, the row is unchanged, and a genuinely free slug is still accepted as a control).
- [ ] Feature: **the wrong-key catch**. Product A has slug `chaussures` and B has `bottes`. Re-save A unchanged and assert success. A naive `->ignore($productId)` against `product_translations.id` would refuse this.
- [ ] Feature: writing a slug into a language in which the product has **no row yet** (an insert) is accepted.
- [ ] Feature: two products may both hold a `NULL` slug in the same language.
- [ ] Feature, **race test per action** (the technique `ProductSkuUniquenessTest` uses: a one-shot listener inserts the conflicting row after validation): a raced duplicate slug through `CreateProduct` and through `UpdateProduct` surfaces as a `ValidationException` on **`slug`**, never on `sku` and never as a 500.
- [ ] Unit, **`TranslateProductUniqueViolation`** (the [constraint-violation-discrimination](../../../docs/security/constraint-violation-discrimination.md#-the-rule) rule 3), each case on a constructed `QueryException` with a hand-set `errorInfo`:
  - 1062 for `products_sku_unique`, with and without the `products.` table prefix, returns a `ValidationException` on `sku` only.
  - 1062 for `product_translations_language_slug_unique`, with and without the `product_translations.` table prefix, returns one on `slug` only.
  - 1062 for `product_translations_product_language_unique`, with and without the `product_translations.` table prefix, returns one on `name` whose message is `trans('products.editor.concurrent_save_retry')`, never on `sku` or `slug` (**R-22**).
  - **Rethrown unchanged** (the same instance): bindings that contain `product_translations_language_slug_unique` while `errorInfo[2]` names another index; an index name that appears mid-string but not before the closing `'$`; a 1062 on either `product_variants` index; a 1452; a 1213.
- [ ] Feature, `UpdateProduct` (**R-22**): a raced natural-key 1062 (a one-shot listener inserts the default-language row for the product after validation, the same technique as the slug race test) surfaces as a `ValidationException` on `name` with the retry message, never as a 500, `sku` or `slug`.
- [ ] Feature, `UpdateProduct` (**D-31** regression): a parent SKU change whose variant re-derivation collides still reports `products.variants.parent_sku_change_collides` on `sku`, now through the anchored `errorInfo[2]` match.
- [ ] Feature: `lang/en/products.php` and `lang/es/products.php` both define `editor.concurrent_save_retry` and the three `attributes.*` leaves (or the project's existing key-parity test covers them).

### Signatures and SEO fields
- [ ] Feature: `meta_title` at 160 characters is accepted and 161 is refused. `meta_description` at 500 is accepted and 501 is refused.
- [ ] Feature: blank meta fields sent through **each** action are stored as `NULL` (by the `saving` hook, **D-35**).
- [ ] Feature: updating the default language's SEO fields leaves a French row byte-identical.
- [ ] Feature: `Products\Editor` save without touching anything preserves the stored slug and meta fields (**D-33**).
- [ ] Feature: **four-field fallback regression** (0076 **D-3** extended). The default row has `name`, `description`, `slug` and `meta_title`. The French row has `name` and `slug` only. `name` and `slug` resolve French, and `description` and `meta_title` resolve the default's.
- [ ] DoD check: `php artisan db:table product_translations` reports **three** indexes (**D-11b**).
- [ ] Feature, **migration `down()`** (**D-34**), following `ProductTranslationsSchemaTest`'s pattern: `require` the migration file and call `down()`. It must not throw (no 1553). Then the three columns are gone, `product_translations_language_slug_unique` is gone, an index whose leftmost column is `store_language_id` exists, and the table again has exactly three indexes. Then the test calls `up()` and asserts the post-migration shape is back exactly: exactly **three** indexes (`primary`, the natural key and `product_translations_language_slug_unique`), and `product_translations_store_language_id_foreign` is **not** an index (consistent with 0076's `ProductTranslationsSchemaTest` `toHaveCount(3)`). A `finally` block also calls `up()` when `down()` or an assertion failed before the re-run, so the shared test schema is never left rolled back (DDL commits implicitly on MySQL); the guarded `dropIndex` in `up()` is what makes that restore leave three indexes, not four.

### Disposition
`ProductValidationRulesTest` gains the three new methods' cases. A new unit test file covers `TranslateProductUniqueViolation`. Existing tests that assert `CreateProduct` maps a raced 1062 to `sku` keep passing, because the translator returns the same message for `products_sku_unique`. Every test that calls `UpdateProduct` directly gains three trailing arguments, mechanically (for example `ProductAuthorizationTest`, `UpdateProductTest`, `ProductVariantSkuUniquenessTest`, `ProductDescriptionSanitizationTest`, `RefusalLoggingTest`; Phase 3 finds the full list with `grep -rln "UpdateProduct" tests`). `CreateProduct` callers are unchanged, because the new parameters have defaults. `ProductSkuUniquenessTest` must still pass **unmodified** except for the narrowed sku matcher's own tests, if any.

## Expected outcome
Each `product_translations` row can carry a slug, a meta title and a meta description. The slug is always stored canonical or as `NULL`, whatever writer sends it, and is unique **within one store language**, so a refusal is a clean validation message on `slug` even under a race. A concurrent first save of a product's default-language row returns a localised "try again" message instead of a raw 1062, and the migration rolls back cleanly. `CreateProduct` accepts the three fields optionally. `UpdateProduct` requires them explicitly, and the existing editor preserves them until 0077 exposes them.

## Acceptance criteria
- [ ] `product_translations` has nullable `slug VARCHAR(160)`, `meta_title VARCHAR(160)` and `meta_description VARCHAR(500)`, plus the named unique `product_translations_language_slug_unique (store_language_id, slug)`.
- [ ] `php artisan db:table product_translations` reports exactly **three** indexes: `primary`, `UNIQUE(product_id, store_language_id)` and `UNIQUE(store_language_id, slug)` (**D-11b**).
- [ ] The slug is canonicalised by **one** class, `CanonicalizeProductSlug`, called in both actions **before** `validate()` and in the `ProductTranslation` `saving` hook. `'!!!'`, `''` and whitespace-only input all store `NULL`.
- [ ] `productSlugRules()` scopes uniqueness by `store_language_id` and excludes the product's own row by `product_id`, never `->ignore()`.
- [ ] The `CreateProduct` signature gains three trailing optional parameters, and the `UpdateProduct` signature gains three trailing parameters with no defaults (**D-18**).
- [ ] Every 1062 in both actions is attributed by **index name** matched on `errorInfo[2]` with `/for key '(?:[^']+\.)?<index>'$/`, never on `getMessage()`. The product indexes are handled by the one injected `TranslateProductUniqueViolation`: `products_sku_unique` to `sku`, `product_translations_language_slug_unique` to `slug`, `product_translations_product_language_unique` to `name` with `products.editor.concurrent_save_retry` (**R-22**). In `UpdateProduct`, the variant indexes go to `TranslateProductVariantUniqueViolation`, selected by the same anchored match. Anything else is rethrown (**D-31**).
- [ ] The migration's `down()` runs on MySQL without error 1553, restores an index on `store_language_id` before dropping the composite unique, and leaves three indexes. Running `up()` again after `down()` leaves exactly the same three indexes as a fresh migrate (`primary`, `UNIQUE(product_id, store_language_id)`, `UNIQUE(store_language_id, slug)`), never four (**D-34**).
- [ ] `meta_title` and `meta_description` are never stored as `''`: the `saving` hook trims them and stores blank as `NULL`, whichever writer sends them (**D-35**).
- [ ] `lang/{en,es}/products.php` carry `editor.concurrent_save_retry` and the three `attributes.*` leaves, key-for-key identical, with 0087's `categories.index.tabs.language_inactive` kept intact.
- [ ] The existing editor never clears a stored slug or meta field.
- [ ] No new permission, ability, policy or route.

## Definition of Done
- [x] Tests written and green (full suite, unscoped)
- [x] `vendor/bin/pint --format agent` run unscoped; Larastan level 7 run and recorded
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor). Point the audit at **D-31**, the error-to-field attribution in `TranslateProductUniqueViolation` and in `UpdateProduct`'s variant branch
- [x] The migration `down()` test is green, including its `down()` then `up()` exactly-three-indexes assertion (**D-34**), and 0076's `ProductTranslationsSchemaTest` stays green in any test order. No manual `migrate:rollback` is run against any database
- [x] Documentation updated (docs-keeper): `docs/database/schema.md` (the new columns and the named index) and the Epic 5 digest
- [x] Acceptance criteria met

## 4. Documented functional decisions

**D-5 — The SEO field set is `slug`, `meta_title` and `meta_description`, at widths 160/160/500 (Q-1 resolved 2026-08-30 as (a)).** The PRD names them with an "e.g.", and all three amigos proposed exactly this set and no more. Widths are `database-expert`'s: 160 for `slug` and `meta_title`, and a bounded `VARCHAR(500)` rather than `TEXT` for `meta_description`, consistent with `sales_regions.description`.

**D-6 — The slug is administrator-supplied, canonicalised in place in two layers, nullable, and unique per store language (Q-2 resolved 2026-08-30 as (a)).**
- **Why not derived:** a name-derived unique slug is structurally impossible, because `products.name` is deliberately not unique (0076 **D-4**). Two legitimately identically named products would derive the same slug, and the second would fail with an error the administrator cannot resolve. So the slug is a typed identifier, like `sku`, and pre-filling it from the title is a UI affordance (0077), never a model hook.
- **Canonical form:** `Str::slug()` on write, stored already canonical, compared like-for-like. There is no fold column.
- **Layer 1 (actions):** canonicalise **before** `validate()`, so the unique rule checks the stored value (the same trap 0024 **D-13** (b) records for SKU).
- **Layer 2 (`saving` hook):** binds every other writer, including bare `SetTranslation` and 0077's action. This is the same two-layer argument as 0076 **D-8**.
- **Blank handling:** `trim` first, then blank becomes `null`, then `Str::slug($v) ?: null`. Both layers use the same class, so they cannot drift. The "blank to null" step happens **before** any `filled()` check, and `'!!!'` (which `Str::slug()` reduces to `''`) becomes `null`. A stored `''` would otherwise collide with every other `''` under the unique index, which `NULL` never does.
- **Own-row exclusion by `product_id`, never `->ignore()`:** a slug written into a language without a row is an insert, so there is no translation-row id to ignore, and `->ignore($productId)` against `product_translations.id` would silently refuse every re-save.
- Per-language scope matches 0078's decision for `blog_posts`.

*Rejected:* derived + unique (impossible), derived + auto-suffixed (an invented convention), and derived + not unique (a slug that does not identify one thing). `database-expert` flagged the synthesis of 0024's canonicalise-on-write with 0072's hook placement as its own construction; Phase 2 should confirm it.

**D-9b — No slug is generated for existing rows.** A generating backfill would need a dedup-suffix pass by construction, because identically named products slugify identically and would collide against the new unique. That suffix is exactly the convention **D-6** rejects. `NULL` is honest while no storefront resolves by slug (`database-expert`'s dissent recorded in 0076's original provenance).

**D-11b — Three indexes after this migration.** `primary`, `UNIQUE(product_id, store_language_id)`, and the new `UNIQUE(store_language_id, slug)`. The new composite has `store_language_id` as its leftmost column, so MySQL silently drops the FK index it auto-created in 0076. The count stays three, but its composition changes. The same three hold after `down()` then `up()`, because `up()` drops the explicit index `down()` restored (**D-34**). Verify with `db:table`, and investigate any other number.

**D-18 — The action signatures widen.** Unlike the siblings, which reinterpreted existing parameters, these three fields are **new**, so they are added as trailing parameters. On `CreateProduct` they default to `null` (leaving them out means "none" on create). On `UpdateProduct` they have **no** default, because a caller that leaves them out would otherwise wipe stored values, the same reasoning as F-6 for `$description` (see **Q-3**). This is a public-contract change that 0077 binds to.

**D-30 — The canonicaliser is one invokable action**, `App\Actions\Products\CanonicalizeProductSlug`, mirroring `SanitizeProductDescription`: one implementation shared by both layers, unit-testable on its own.

**D-31 — Every 1062 is attributed by index name, in one dedicated translator.**
- **Why:** at `HEAD`, `CreateProduct` maps **any** 1062 to `sku`. Once `product_translations` carries a slug unique, a raced duplicate slug would be misreported as "the SKU is taken". `UpdateProduct` matches its index names with `str_contains($e->getMessage(), …)`, which [constraint-violation-discrimination.md](../../../docs/security/constraint-violation-discrimination.md#-the-rule) forbids.
- **Where:** `App\Actions\Products\TranslateProductUniqueViolation`, `__invoke(QueryException $e): ValidationException`, constructor-injected into both actions. It is modelled on `TranslateProductCategoryNameUniqueViolation` (stateless, never reads `getMessage()`, ends in `throw $e`). The **D-30** argument applies here too: one implementation shared by two call sites cannot drift, and a constructed `QueryException` can unit-test it without a real transaction.
- **Rule:** gate on `($e->errorInfo[1] ?? null) === 1062`, then match `errorInfo[2]` against the doc's literal pattern `/for key '(?:[^']+\.)?<index>'$/`: an escaped dot, any table prefix, anchored to the end.
- **Mapping:** `products_sku_unique` → `sku` (the message is unchanged); `product_translations_language_slug_unique` → `slug` (localised attribute); `product_translations_product_language_unique` → `name` with `products.editor.concurrent_save_retry` (**R-22**, option (b)); anything else → rethrow the same instance.
- **`UpdateProduct`'s variant branch:** the two `product_variants` indexes are not in the new class, because they need `$sku` and the parent-change override message. `UpdateProduct` selects them **first**, and its branch condition moves from `str_contains($e->getMessage(), …)` to the same anchored `errorInfo[2]` match. It then calls the unchanged `TranslateProductVariantUniqueViolation`. Every other exception goes to the new translator.
- **Scope of the change:** moving the sku and variant matchers in both action files to the anchored form is in scope, because the doc asks for it the next time a file is touched and this story touches both. `TranslateProductVariantUniqueViolation`'s own internal `getMessage()` match is **not** changed here, because the doc scopes that to the next edit of *that* file (technical task 2).

**D-34 — `down()` restores the FK-supporting index before dropping the composite unique.** After `up()`, MySQL enforces the `store_language_id` foreign key through `product_translations_language_slug_unique` (**D-11b**). Dropping that unique first fails with error 1553. So `down()` adds a plain index on `store_language_id` in its own `Schema::table` call, then drops the unique and the three columns. Symmetrically, `up()` ends with a second `Schema::table` call that drops `product_translations_store_language_id_foreign` when `Schema::hasIndex()` finds it. On a fresh migrate MySQL has already removed its auto-created FK index, so the guard is a no-op. After a `down()` the index is explicit and survives the new composite, so it is dropped (legal, because the composite now supports the FK). The round trip is therefore exact: three indexes after fresh migrate, after `down()`, and after `down()` then `up()`. It is proved by a feature test that calls `down()` and restores with `up()`, never by a manual rollback (contracts: destructive DB commands).

**D-35 — The `saving` hook normalises the meta fields too (finding 6, resolved as "move it into the hook").** When `meta_title` or `meta_description` is dirty, the hook trims it and stores blank as `NULL`. This is the same two-layer reasoning as **D-6**: every writer is bound, including bare `SetTranslation` and 0077's action, so the actions do not duplicate the step. Reads were already safe (`translated()` treats `''` as absent), but the stored data is now uniform.

**D-32 — Rule method names follow the shipped trait** (`productNameRules()`, `productSkuRules()`, …): `productSlugRules()`, `productMetaTitleRules()` and `productMetaDescriptionRules()`. [0077](0077-product-editor-language-tabs-ui.md)'s draft calls them `slugRules()` / `metaTitleRules()` / `metaDescriptionRules()`, and 0077's Phase 3 must adopt these names. This is recorded here as a hand-off and 0077's file is not edited.

**D-33 — The existing editor preserves the three fields until 0077 exposes them.** Because `UpdateProduct` requires them (**D-18**), `Products\Editor::save()` must pass something. It passes the values it read from the raw default-language row in `mount()`, held in `#[Locked]` properties so a forged update cannot change them through a field the UI does not offer.

## 5. Dependencies, risks, open technical questions

### Dependencies
- **[0076](../done/0076-translatable-content-retrofit-products-backend.md)**: hard. It supplies `product_translations`, `ProductTranslation` and its `saving` hook, the default-language write in both actions, and the editor's raw-row read.
- Shipped: [0024](../done/0024-products-core-crud-backend.md) (the actions and their 1062 catches), [0070](../done/0070-translatable-content-mechanism-product-categories-backend.md) (`SetTranslation`), [0068](../done/0068-store-languages-catalog-backend.md).
- **Dependents:** [0077](0077-product-editor-language-tabs-ui.md) (hard: rule methods, widened signatures).
- **File-sharing:** [0087](../0087-translation-write-actions-refuse-inactive-store-language.md) also edits `lang/{en,es}/products.php`.

### Risks
- **R-5 — The slug's self-exclusion fails silently and permanently** if it is wired as `->ignore()`. The deliberate two-product test keeps it closed.
- **R-14 — The canonicalisation mechanism is a synthesis**, not an inherited decision (**D-6**), so Phase 2 should confirm it.
- **R-20 — The `UpdateProduct` signature change is wide:** every direct caller and test must pass three more arguments. It is mechanical but broad.
- **R-21 — The implicit-FK-index drop (D-11b) is MySQL behaviour, not something Laravel declares.** Assert the count after migrating, never from the migration text. The converse also holds: MySQL never drops an **explicit** index, so the one `down()` restores would survive a re-run of `up()` and leave four indexes; `up()`'s guarded `dropIndex` (**D-34**) prevents that, and the `down()` then `up()` assertion proves it.
- **R-22 — Deferred from 0076 (Phase 4, Low): a raw 1062 on `product_translations_product_language_unique`.** After the default store language switches to one with no rows for a product, two administrators saving that same product concurrently both try to insert the default-language row; `UpdateProduct` has no catch for this and surfaces a raw 1062. `CreateProduct` cannot hit it (fresh product id) but its blanket catch maps **every** 1062 to "sku taken". 0076 deliberately did not fix it. **✅ DECIDED by the project owner, 2026-11-07, as option (b):** `TranslateProductUniqueViolation` maps a 1062 on `product_translations_product_language_unique` to a `ValidationException` on `name` with the localised `products.editor.concurrent_save_retry` message, in both actions (**D-31**). It is never reported as `sku` or `slug` and never surfaces as a 500. It is covered by a translator unit test and an `UpdateProduct` race test. Option (a), rethrow and keep it deferred, was rejected because it leaves the raw 1062 that R-22 describes as the defect.

### Open questions for the product owner

**Q-1 — SEO field set and widths. ✅ RESOLVED 2026-08-30, (a)** (`slug`, `meta_title` 160, `meta_description` 500). Text is carried from 0076.

**Q-2 — Slug uniqueness scope. ✅ RESOLVED 2026-08-30, (a) per-language** `UNIQUE(store_language_id, slug)`, consistent with 0078. The hedged alternatives (global, none) are withdrawn.

**Q-3 — How does `UpdateProduct` treat leaving out the slug/meta fields? ✅ RESOLVED 2026-11-06 as (a): no defaults.**
- **(a) No defaults: callers must pass them, and the existing editor carries the stored values through `#[Locked]` properties until 0077 _(recommended)_.** This matches the F-6 rule for `$description` (leaving a field out must never silently clear it) and keeps "clear the slug" expressible as an explicit `null`.
- **(b) Default `null`, meaning "leave unchanged".** It needs no editor change, but it makes clearing a slug through the action impossible and gives `null` two meanings across the same signature.

## 6. Technical tasks for later backlog creation
1. When 0077 starts Phase 3: adopt the **D-32** method names and replace **D-33**'s `#[Locked]` pass-through with real per-language inputs.
2. Move `TranslateProductVariantUniqueViolation`'s internal `getMessage()` match to the anchored `errorInfo[2]` rule the next time that file is touched (per the security doc).

## Provenance
Split from [0076](../done/0076-translatable-content-retrofit-products-backend.md) on 2026-11-06 by `product-owner`, at the project owner's decision after 0076's Phase 2 rejection (its findings 5, 9, 10 and 12 land here). **D-5**, **D-6**, **D-9b**, **D-18**, **R-5**, **R-14**, **Q-1** and **Q-2** are carried from 0076's Phase 1 debate (2026-08-29: `backend-expert`, `database-expert`, `backend-qa`), and were re-verified against `HEAD` on 2026-11-06 (`CreateProduct`'s blanket 1062 catch, `UpdateProduct`'s `getMessage()` matching, and the trait's `product*Rules()` naming). **D-30** to **D-33**, **R-20**, **R-21** and **Q-3** are new at the split. **D-34** and **D-35**, and the R-22 decision (project owner, 2026-11-07), were added in the Phase 1 revision after the Phase 2 rejection. No Three Amigos re-run was performed for the new decisions, so Phase 2 should weigh them accordingly.

## Review log
- Phase 1 (split), 2026-11-06: created. **Awaiting Phase 2** (not run).
- Phase 2 (INVEST validation), 2026-11-07, `code-reviewer`: **REJECTED**. Return to `product-owner` for rewriting.
  Verified accurate at `HEAD`: `CreateProduct` maps every 1062 to `sku` and keeps `$description = null`; `UpdateProduct` matches index names with `str_contains($e->getMessage(), …)`, has no `$description` default and no `attempts:`; `product_translations` has `name`/`description` and `product_translations_product_language_unique`; the trait's methods are `product*Rules()`; `HasTranslations::translated()` already falls back per field (treating `''` as absent), so the meta-title fallback scenario needs no read-side change; `Products\Editor::mount()` reads the raw default-language row. D-30, D-32, D-33 and R-14 (canonicalise before `validate()` plus an `isDirty('slug')` `saving` hook through one class; `Str::slug()` is idempotent on its own output) are sound and testable. R-21/D-11b is correct (the FK index on `store_language_id` is silently replaced by the new composite; the count stays three).
  Findings:
  1. **`down()` is not a true inverse; it fails on MySQL.** After `up()`, `product_translations_language_slug_unique` is the only index that can enforce the `store_language_id` foreign key (D-11b itself says MySQL dropped the auto-created one). `$table->dropUnique(...)` then fails with error 1553 ("needed in a foreign key constraint"). `down()` must first restore an index on `store_language_id` (e.g. `$table->index('store_language_id')`), then drop the unique, then the columns. Correct the migration snippet and the "true inverse" claim, and add a test or DoD check that `down()` runs.
  2. **D-31 is not unit-testable as specified.** The QA list asks for a unit test with a constructed `QueryException` (rule 3 of constraint-violation-discrimination.md), but the story places the index-name matching inline in two action `catch` blocks, which a unit test cannot reach without driving a real transaction. Every sibling extracts it into a class (`TranslateProductCategoryNameUniqueViolation`, `TranslateBlogTagNameUniqueViolation`, `TranslateProductVariantUniqueViolation`), and D-30's own "one implementation shared by both layers" argument applies equally to the two actions' catches. Name the collaborator (e.g. `App\Actions\Products\TranslateProductUniqueViolation`, constructor-injected into both actions), its signature and return contract (a `ValidationException`, or `null`/rethrow for an unrecognised index), add it to "Files to create", and point the unit test at it.
  3. **R-22 is left undecided.** R-22 says the natural-key 1062 must be "rethrown or mapped to a retry message"; the QA list and AC say rethrown. Rethrowing removes the misattribution but still surfaces the raw 1062 (a 500) that R-22 describes as the defect, so R-22 would stay open. Decide explicitly (ask the project owner, recommend one): (a) rethrow and record R-22 as accepted/still deferred, or (b) map to a localised retry message on a named key, with its lang leaf and test. Then make R-22, D-31's mapping, the AC and the QA test say the same thing.
  4. Minor: D-31's pattern `for key '(?:table.)?<index>'$` should be written as the doc's literal form `/for key '(?:[^']+\.)?<index>'$/` (escaped dot, any table prefix), and D-31 should state explicitly that `UpdateProduct`'s own `product_variants` branch condition (currently `str_contains($e->getMessage(), …)`) also moves to the anchored `errorInfo[2]` match, while the translator's internals stay untouched.
  5. Minor: `productSlugRules()` should use `Rule::unique(ProductTranslation::class, 'slug')`, matching the trait's existing `Rule::unique(Product::class, …)` style, rather than a table string.
  6. Note (no change required): layer 2 canonicalises only the slug; a bare `SetTranslation` can still store `''` in `meta_title`/`meta_description`. This is harmless for reads (`translated()` treats `''` as absent) but should be stated as deliberate, or the blank-to-null step for the meta fields should also move into the `saving` hook.
  INVEST: Independent (0076 is done), Negotiable, Estimable and Small (R-20 is broad but mechanical) pass. Valuable passes as an enabler for 0077. Testable fails on findings 1 to 3.
- Phase 1 revision, 2026-11-07, `product-owner`: findings 1-6 addressed, awaiting Phase 2 re-run.
  1. The migration `down()` now adds `index('store_language_id', 'product_translations_store_language_id_foreign')` in its own `Schema::table` call before `dropUnique`, then drops the columns. The "true inverse" claim is corrected, **D-34** is new, and a `down()` feature test, an AC and a DoD item are added.
  2. **D-31** now names `App\Actions\Products\TranslateProductUniqueViolation` with `__invoke(QueryException $e): ValidationException`, rethrowing anything unrecognised. It is constructor-injected into both actions, listed under Create, and targeted by the unit test.
  3. **R-22** is recorded as decided by the project owner (option (b)): `name` key, new leaf `products.editor.concurrent_save_retry` in `lang/{en,es}/products.php`, with a 0087 file-sharing note. R-22, D-31, the AC and the tests agree.
  4. The literal pattern `/for key '(?:[^']+\.)?<index>'$/` is used throughout. `UpdateProduct`'s `product_variants` branch moves to the anchored `errorInfo[2]` match, and the translator's internals are untouched.
  5. `productSlugRules()` uses `Rule::unique(ProductTranslation::class, 'slug')`.
  6. **D-35** is new: the `saving` hook normalises blank meta fields to `NULL`, and the actions no longer do it. A layer-2 test is added, and the action test is reworded.
  Choices made in this revision for Phase 2 to confirm: the `name` error key for the retry message, the restored index name, and the en/es wording of the retry message.
- Phase 2 re-run (INVEST validation), 2026-11-07, `code-reviewer`: **REJECTED** on one new finding (7). Return to `product-owner` for a narrow fix; findings 1-6 are resolved.
  Verified: findings 1-6 are resolved and consistent across Description, Gherkin, Files, Tests, AC and DoD. `TranslateProductUniqueViolation` matches the shipped `TranslateProductCategoryNameUniqueViolation` contract (returns a `ValidationException`, ends in `throw $e`, reads only `errorInfo`). `products.editor.concurrent_save_retry` is named identically in Files, D-31, R-22, Tests and AC, and `name` is a sound error key (the editor's `flux:input wire:model="name"` renders it). D-35 is consistent in Files, Tests and AC. R-22 option (b) is the project owner's decision and is not reopened. D-34's restored name is correct: 0076's migration (`2026_11_06_100000_create_product_translations_table.php`) declares `foreignUuid('store_language_id')->constrained('store_languages')`, so the FK constraint, and the index MySQL auto-creates for it, are both named `product_translations_store_language_id_foreign`. A probe on scratch tables (MySQL, same shape) confirmed: after 0076 the indexes are `PRIMARY`, the natural key and `..._store_language_id_foreign`; after `up()` the FK index is silently replaced by the composite; a naive `dropUnique` fails with 1553; the D-34 `down()` succeeds and leaves the 0076 shape.
  Findings:
  7. **`up()` after `down()` does not restore the post-migration shape: it leaves four indexes.** The probe showed that re-running `up()` after the D-34 `down()` gives `PRIMARY`, the natural key, `..._language_slug_unique` **and** `..._store_language_id_foreign`. MySQL silently drops only an index it auto-created for a foreign key; the one `down()` adds is explicit, so it survives. Consequences: (a) the planned `down()` test's `finally { up() }` leaves the shared test schema with four indexes (DDL is not rolled back), so the D-11b three-index test and 0076's `ProductTranslationsSchemaTest` (`toHaveCount(3)`) fail depending on test order; (b) a real rollback followed by `migrate` gives a schema that differs from a fresh migrate. Fix, either: **(a) _(recommended)_** in `up()`, after adding the composite unique, drop `product_translations_store_language_id_foreign` as an index when `Schema::hasIndex('product_translations', 'product_translations_store_language_id_foreign')` (it is redundant once the composite exists, and dropping it is then legal). This makes the round trip exact and fixes both consequences. Or (b) keep `up()` as written, have the test's `finally` drop that index after `up()`, and record that a real rollback-then-migrate leaves an extra redundant index. Then correct the sentence "A `finally` block calls `up()` to restore the post-migration shape". Also add to the `down()` test: after `down()` then `up()`, the table has exactly three indexes.
  8. Minor (fix in the same pass): the translator unit test says "1062 for `products_sku_unique`, with and without the `product_translations.` table prefix". `products_sku_unique` lives on `products`, so the realistic prefix is `products.`. Use `products.` for the sku case and `product_translations.` for the two translation indexes.
  INVEST: Independent, Negotiable, Valuable, Estimable and Small pass. Testable fails on finding 7, because the specified `down()` test leaves the shared schema in a shape that breaks other tests.
- Phase 1 revision #2, 2026-11-07, `product-owner`: findings 7-8 addressed, awaiting Phase 2 re-run.
  7. Fix (a) adopted. `up()` now ends with a second `Schema::table` call, guarded by `Schema::hasIndex('product_translations', 'product_translations_store_language_id_foreign')`, that drops that index once the composite exists (a no-op on a fresh migrate). The migration snippet, the paragraph under it, **D-34**, **D-11b** and **R-21** say so. The `down()` test now calls `up()` after `down()` and asserts exactly three indexes with no `..._store_language_id_foreign` index; the `finally` sentence is corrected (it restores only on failure, and leaves three indexes because of the guard). The AC and DoD gain the `down()` then `up()` three-index wording, and the DoD requires 0076's `ProductTranslationsSchemaTest` to stay green in any order.
  8. The translator unit test uses the `products.` prefix for `products_sku_unique` and `product_translations.` for the two translation indexes.
- Phase 2 re-run #2 (INVEST validation), 2026-11-07, `code-reviewer`: **APPROVED**. Ready for Phase 3.
  Verified: finding 7 is resolved. `up()` adds the composite in one `Schema::table` call, then, in a second call, drops `product_translations_store_language_id_foreign` only when `Schema::hasIndex()` finds it. On a fresh migrate MySQL has already replaced its auto-created FK index with the composite, so the guard is a no-op. After `down()` the restored index is explicit and survives, so the guard drops it, which is legal because the composite now supports the FK. Fresh migrate, `down()`, and `down()` then `up()` therefore all leave three indexes, which matches the earlier scratch-table probe. The `down()` test now re-runs `up()` and asserts three indexes with no `..._store_language_id_foreign`. Its `finally` restores only on failure and also leaves three indexes, so 0076's `ProductTranslationsSchemaTest` `toHaveCount(3)` holds in any order. Finding 8 is resolved: `products.` is used for the sku case, and `product_translations.` for the two translation indexes. The migration snippet, the paragraph under it, D-11b, D-34, R-21, the `down()` test, AC and DoD agree, and no other section contradicts them. R-22 option (b) is not reopened.
  Non-blocking note for Phase 3: if `down()` fails partway (after its index call, before `dropColumn`), the `finally` `up()` would fail on columns that already exist. The test should restore conditionally, for example by checking `Schema::hasColumn('product_translations', 'slug')`, so the original failure is not masked.
  INVEST: all six criteria pass.
- Phase 2 third pass APPROVED; Phase 3 step 0: moved to in-progress/, 2026-11-07
- Phase 4 (security audit), 2026-11-07, `appsec-auditor`: **PASS** (no Critical/High/Medium findings), audited at `9df01f9`.
  Verified: `TranslateProductUniqueViolation` gates on `errorInfo[1] === 1062` and matches `errorInfo[2]` with the anchored `/for key '(?:[^']+\.)?<index>'$/` pattern (constraint-violation-discrimination.md); the canonical slug is `[a-z0-9-]` only, so it cannot carry a `'` into the duplicate-entry value; the unit test covers bindings naming an index, a mid-string name, prefix/suffix lookalikes, 1452, 1213 and missing `errorInfo`. `UpdateProduct`'s variant branch moved to the same anchored match; `CreateProduct` no longer maps every 1062 to `sku`. Mass assignment: only `slug`/`meta_*` were added to `#[Fillable]`; `product_id`/`store_language_id` stay guarded and are written only through `SetTranslation`'s `forceFill`. Authorization on both write paths is unchanged (`create`/`update` before any read or validation). The three new Editor properties are `#[Locked]`, set only in `mount()` from the persisted default-language row and null on create, with no setter or `wire:model`. The slug is canonicalised before `validate()`, so the unique rule and `max:160` see the stored form, and the composite unique index is the race backstop, mapped to `slug`. `Str::slug()` produces ASCII output, so there is no homoglyph or unicode-normalisation collision in the stored slug.
  Low / informational, non-blocking:
  1. Low (lost update): every editor save now rewrites `slug`/`meta_*` with mount-time values. Once 0077 (or any second writer) can change them, a stale tab silently reverts them. The same exists for `name`/`description` today. 0077 should bind them as real inputs, or the action should skip unchanged nulls.
  2. Low (UX/error visibility): a `slug` `ValidationException` (race only) has no rendered field in the editor until 0077. 0077 must render `slug` errors.
  3. Informational (output encoding, for 0077 and the storefront): `meta_title`/`meta_description` are stored raw (trimmed only). Render them only with `{{ }}` (in `<title>` and `<meta content>`), and with `@json`/`Js::from` in JSON-LD, never `{!! !!}` (blade-livewire-output-encoding.md). Optional defence in depth: reject C0/C1 control and bidi-override characters (`\p{Cc}`, U+202A-202E, U+2066-2069) in the meta rules.
  4. Informational (non-security): the commit removed the D-4.6 atomicity docblock from `UpdateProduct::reDeriveVariantSkus()` without a code change. Consider restoring it for Phase 5.
- Phase 5 (final code review), 2026-11-07, `code-reviewer`: **REJECTED**, reviewed at `9df01f9`. Return to `backend-expert` (or `backend-qa` for the test) for two narrow fixes; no design change.
  Full suite, unscoped and isolated on `testing_0076a_product_slug_and_seo_fields_backend`, run in four sequential chunks (Unit+Products, Orders+Blog, the remaining Feature directories, Browser): **6522 tests, 6513 passed, 1 failed, 8 skipped**. Pint unscoped: clean, no file changed. Larastan level 7: 0 errors.
  Verified: every acceptance criterion is implemented as specified (columns and widths, three indexes, `CanonicalizeProductSlug` used before `validate()` in both actions and in the `saving` hook, `productSlugRules()` scoped by language with a `product_id` exclusion, signature widening per D-18, `TranslateProductUniqueViolation` with the anchored `errorInfo[2]` match, `UpdateProduct`'s variant branch on the same match, `down()`/`up()` round trip, D-35 meta normalisation, lang leaves key-for-key, `#[Locked]` editor pass-through, no new permission or route), each with a matching test.
  Findings:
  1. **Blocking: the full suite is red.** `tests/Feature/Models/ProductTranslationModelTest.php:88` (0076's test "only name and description are fillable …") asserts `getFillable()` is `['name', 'description']`; this story widened `#[Fillable]` by design, so it now fails deterministically (reproduced alone, so not a shared-database effect). Update that assertion to the five-column list and the test title to match; keep its forged `product_id`/`store_language_id` assertions unchanged.
  2. **Required: restore the deleted `reDeriveVariantSkus()` docblock.** The feat commit removed the D-4.6 PHPDoc block (same-transaction rollback; compute-and-check every variant first, write only after all pass) with no code change. It documents a still-true, non-obvious atomicity invariant, the deletion is outside the story's scope, and the project prefers PHPDoc over inline comments. Restore it byte-identical from `main`.
  3. Minor (non-blocking): in `lang/en/products.php` and `lang/es/products.php` the new `attributes` array was inserted between the `// Story 0027 -- the routed product editor.` / `// Historia 0027 …` comment and `editor`, so that comment now labels `attributes`. Move `attributes` above the comment (or give it its own 0076a comment). `UpdateProduct`'s constructor docblock still says "Five collaborators" (already stale before this story, now eight); optional fix.
  Phase 6 (docs-keeper) items, not checked here: `docs/database/schema.md` (three columns, named index, index set), the ER diagram, the Epic 5 digest, and any lang-key or routes docs the sync pass finds affected.
- Phase 5 second pass (final code review), 2026-11-08, `code-reviewer`: **APPROVED**, reviewed at `4ffb48a`. Ready for Phase 6.
  Full suite, unscoped and isolated on `testing_0076a_product_slug_and_seo_fields_backend` (`php -d memory_limit=-1 vendor/bin/pest`), run as four sequential chunks with no other process on that database: Unit+Feature/Products 1100 passed; Orders+Blog 1937 passed; the remaining Feature directories 3206 tests, 3201 passed, 5 skipped; Browser 279 tests, 276 passed, 3 skipped. **Total 6522 tests, 6514 passed, 0 failed, 8 skipped.** Pint unscoped: passed, no file changed. Larastan level 7: 0 errors.
  Verified fixes: (1) `ProductTranslationModelTest` now expects `['name', 'description', 'slug', 'meta_title', 'meta_description']` with a matching title, and its forged `product_id`/`store_language_id` assertions are unchanged. (2) The `reDeriveVariantSkus()` D-4.6 docblock is restored: `git diff main` on `UpdateProduct.php` has no hunk in that region, so it is byte-identical to `main`. (3) In both `lang/*/products.php` files, `attributes` has its own 0076a comment and the 0027 comment again labels `editor`. The optional "Five collaborators" constructor docblock note stays open and non-blocking.
- Phase 6 docs done; Phase 7: closed and moved to done/, 2026-11-08.
