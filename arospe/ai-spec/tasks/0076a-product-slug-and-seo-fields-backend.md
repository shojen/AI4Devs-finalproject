# [0076a] Product slug and SEO fields — per-store-language (backend)

## Description
Adds the product **slug** and **SEO meta fields** (`slug`, `meta_title`, `meta_description`) to the per-store-language `product_translations` table that [0076](done/0076-translatable-content-retrofit-products-backend.md) creates ([PRD Epic 5, Layer 2](../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization); [assumption 14](../../docs/PRD/sections/foundations.md#assumptions--confirmed-decisions) names *"slug/SEO fields … on products"* as translatable content). It covers the columns, their validation rules, the `CreateProduct`/`UpdateProduct` signature widening, slug canonicalisation in two layers, per-language slug uniqueness, and a 1062 race catch that is disambiguated by index name.

**Origin.** This story was split out of 0076 on 2026-11-06 (project owner's decision after 0076's Phase 2 rejection; see that file's review log). The text below reuses 0076's original Phase 1 debate (2026-08-29) where it is still valid. Its decision numbers keep 0076's original ones (**D-5**, **D-6**, **D-18**) so that [0077](0077-product-editor-language-tabs-ui.md)'s references still resolve. New decisions start at **D-30**.

**Numbering.** This story is `0076a`, not the next free integer (`0088`). The reason is the [task ordering rule](../../docs/workflow/task-files-links-and-ordering.md#task-ordering-rule): a dependency's number must be lower than its dependents', and [0077](0077-product-editor-language-tabs-ui.md) consumes this story's rule methods and widened signatures. A suffix keeps `0076 < 0076a < 0077` without renumbering 0077, using the same convention as `0024a`/`0024b`.

## Type
backend | includes database-expert: **yes** (one additive migration with a named composite unique index)

## 1. Refined user story

> **As** a catalog administrator running a store that sells in more than one language,
> **I want** each product to carry its own slug, meta title and meta description per store language,
> **so that** each language's storefront can address and describe the product in its own words, and two products can never claim the same address within one language.

**Scope fence.** No screen, route, tab or lang key for a visible control. The editor fields and the slug pre-fill affordance belong to [0077](0077-product-editor-language-tabs-ui.md). Every write in this story goes through `CreateProduct`/`UpdateProduct` and writes the **default store language**, as in 0076. Non-default-language writes are 0077's `SetProductTranslation`.

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and carries exactly one `When` ([gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3). Content in other languages appearing in a `Given` is arranged fixture data.

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
  }

  public function down(): void
  {
      Schema::table('product_translations', function (Blueprint $table): void {
          $table->dropUnique('product_translations_language_slug_unique');
          $table->dropColumn(['slug', 'meta_title', 'meta_description']);
      });
  }
  ```

  There is no backfill: every existing row gets `NULL` in all three columns and **no slug is generated** (**D-9b**). `down()` is a true inverse for this additive change.
- **`app/Actions/Products/CanonicalizeProductSlug.php`** is the **single** canonicaliser, used by both layers (**D-6**): `__invoke(?string $slug): ?string`. It runs `trim()`, returns `null` when the result is `''`, and otherwise returns `Str::slug($slug) ?: null`. So `'!!!'` and whitespace-only input both become `null`, never `''`.

### Modify
- **`app/Models/ProductTranslation.php`**: `#[Fillable]` gains `slug`, `meta_title` and `meta_description`. Inside the existing `booted()` `saving` hook (0076's sanitizer), add **layer 2** of canonicalisation:

  ```php
  if ($translation->isDirty('slug')) {
      $translation->slug = app(CanonicalizeProductSlug::class)($translation->slug);
  }
  ```

  There is deliberately no `filled()` guard in front of the call: blank becomes `null` *inside* the canonicaliser, so a guard would let `'   '` through untouched (**D-6**).
- **`app/Concerns/ProductValidationRules.php`**, three new methods named in the trait's existing `product*Rules()` style (**D-32**):
  - `productSlugRules(string $storeLanguageId, ?string $productId = null): array` returns `['nullable', 'string', 'max:160', Rule::unique('product_translations', 'slug')->where(fn ($q) => $q->where('store_language_id', $storeLanguageId)->when($productId !== null, fn ($q) => $q->where('product_id', '!=', $productId)))]`. The own-row exclusion is by **`product_id`**, never `->ignore()` (**D-6**).
  - `productMetaTitleRules(): array` returns `['nullable', 'string', 'max:160']`.
  - `productMetaDescriptionRules(): array` returns `['nullable', 'string', 'max:500']`.
- **`app/Actions/Products/CreateProduct.php`**: **the signature widens** by appending `?string $slug = null, ?string $metaTitle = null, ?string $metaDescription = null` (**D-18**). On create, leaving them out and passing "none" mean the same thing, which is the same reasoning that keeps `$description`'s default.
  - **Layer 1:** after `authorize()` and before `validate()`, `$slug = ($this->canonicalizeProductSlug)($slug)`, so the uniqueness rule compares the stored form (**D-6**). `$metaTitle` and `$metaDescription` are trimmed, and blank becomes `null`.
  - Validation adds `slug`, `meta_title` and `meta_description` keys using the three methods (`$defaultLanguageId`, no product id).
  - The `SetTranslation` attribute array gains the three keys.
  - The 1062 catch is narrowed by index name (**D-31**).
- **`app/Actions/Products/UpdateProduct.php`**: **the signature widens** by appending `?string $slug, ?string $metaTitle, ?string $metaDescription` **with no defaults**. Leaving them out on update would silently clear stored values, which is the same reasoning as Phase 4 re-audit finding F-6 for `$description` (**D-18**, **Q-3**). The canonicalisation and validation are the same as on create, with `$product->id` as the own-row exclusion. The 1062 catch gains the slug branch (**D-31**). It keeps **no** `attempts:` (0076 **D-15**).
- **`app/Livewire/Products/Editor.php`**: carries the default-language `slug`, `meta_title` and `meta_description` through `save()` unchanged, so an edit made today does not clear them. It reads them in `mount()` from the raw default-language row into three **`#[Locked]`** public properties (there is no input for them yet) and passes them to `UpdateProduct`. `CreateProduct` receives `null`. 0077 replaces this with real per-language inputs (**D-33**, **Q-3**).
- **`database/factories/ProductTranslationFactory.php`**: no slug by default. It adds a `withSeo()` state for tests.
- **`lang/en/products.php`** / **`lang/es/products.php`**: `attributes.slug`, `attributes.meta_title` and `attributes.meta_description` leaves (key-for-key identical), so validation messages name the field.
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
- [ ] Feature: `'!!!'` and whitespace-only through **each** action save successfully with a `NULL` slug. The `nullable` rule plus the canonical `null` must not produce a spurious `unique` refusal against other `NULL`-slug rows.

### Uniqueness (D-6, D-31)
- [ ] Feature: two products, same canonical slug, **same** language: refused on `slug`.
- [ ] Feature: two products, **byte-identical** slug, **different** languages: accepted. This is the only fixture that proves the scope is per-language.
- [ ] Feature: re-saving a product's own slug is accepted (the no-op succeeds, the row is unchanged, and a genuinely free slug is still accepted as a control).
- [ ] Feature: **the wrong-key catch**. Product A has slug `chaussures` and B has `bottes`. Re-save A unchanged and assert success. A naive `->ignore($productId)` against `product_translations.id` would refuse this.
- [ ] Feature: writing a slug into a language in which the product has **no row yet** (an insert) is accepted.
- [ ] Feature: two products may both hold a `NULL` slug in the same language.
- [ ] Feature, **race test per action** (the technique `ProductSkuUniquenessTest` uses: a one-shot listener inserts the conflicting row after validation): a raced duplicate slug through `CreateProduct` and through `UpdateProduct` surfaces as a `ValidationException` on **`slug`**, never on `sku` and never as a 500.
- [ ] Unit (the [constraint-violation-discrimination](../../docs/security/constraint-violation-discrimination.md#-the-rule) rule 3): a constructed `QueryException` whose **bindings** contain `product_translations_language_slug_unique` while `errorInfo` reports a different index, or a 1452, is **rethrown** unchanged and never converted to a slug error.
- [ ] Feature: a natural-key 1062 (`product_id, store_language_id`) is rethrown, not reported as `slug` or `sku`.

### Signatures and SEO fields
- [ ] Feature: `meta_title` at 160 characters is accepted and 161 is refused. `meta_description` at 500 is accepted and 501 is refused.
- [ ] Feature: blank meta fields are stored as `NULL`.
- [ ] Feature: updating the default language's SEO fields leaves a French row byte-identical.
- [ ] Feature: `Products\Editor` save without touching anything preserves the stored slug and meta fields (**D-33**).
- [ ] Feature: **four-field fallback regression** (0076 **D-3** extended). The default row has `name`, `description`, `slug` and `meta_title`. The French row has `name` and `slug` only. `name` and `slug` resolve French, and `description` and `meta_title` resolve the default's.
- [ ] DoD check: `php artisan db:table product_translations` reports **three** indexes (**D-11b**).

### Disposition
`ProductValidationRulesTest` gains the three new methods' cases. Every test that calls `UpdateProduct` directly gains three trailing arguments, mechanically (for example `ProductAuthorizationTest`, `UpdateProductTest`, `ProductVariantSkuUniquenessTest`, `ProductDescriptionSanitizationTest`, `RefusalLoggingTest`; Phase 3 finds the full list with `grep -rln "UpdateProduct" tests`). `CreateProduct` callers are unchanged, because the new parameters have defaults. `ProductSkuUniquenessTest` must still pass **unmodified** except for the narrowed sku matcher's own tests, if any.

## Expected outcome
Each `product_translations` row can carry a slug, a meta title and a meta description. The slug is always stored canonical or as `NULL`, whatever writer sends it, and is unique **within one store language**, so a refusal is a clean validation message on `slug` even under a race. `CreateProduct` accepts the three fields optionally. `UpdateProduct` requires them explicitly, and the existing editor preserves them until 0077 exposes them.

## Acceptance criteria
- [ ] `product_translations` has nullable `slug VARCHAR(160)`, `meta_title VARCHAR(160)` and `meta_description VARCHAR(500)`, plus the named unique `product_translations_language_slug_unique (store_language_id, slug)`.
- [ ] `php artisan db:table product_translations` reports exactly **three** indexes: `primary`, `UNIQUE(product_id, store_language_id)` and `UNIQUE(store_language_id, slug)` (**D-11b**).
- [ ] The slug is canonicalised by **one** class, `CanonicalizeProductSlug`, called in both actions **before** `validate()` and in the `ProductTranslation` `saving` hook. `'!!!'`, `''` and whitespace-only input all store `NULL`.
- [ ] `productSlugRules()` scopes uniqueness by `store_language_id` and excludes the product's own row by `product_id`, never `->ignore()`.
- [ ] The `CreateProduct` signature gains three trailing optional parameters, and the `UpdateProduct` signature gains three trailing parameters with no defaults (**D-18**).
- [ ] Every 1062 in both actions is attributed by **index name** matched on `errorInfo[2]`, anchored to the `for key '…'` suffix: `products_sku_unique` to `sku`, `product_translations_language_slug_unique` to `slug`, the existing variant indexes to their translator, and anything else rethrown (**D-31**).
- [ ] The existing editor never clears a stored slug or meta field.
- [ ] No new permission, ability, policy or route.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] `vendor/bin/pint --format agent` run unscoped; Larastan level 7 run and recorded
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor). Point the audit at **D-31**, the error-to-field attribution in two actions
- [ ] Documentation updated (docs-keeper): `docs/database/schema.md` (the new columns and the named index) and the Epic 5 digest
- [ ] Acceptance criteria met

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

**D-11b — Three indexes after this migration.** `primary`, `UNIQUE(product_id, store_language_id)`, and the new `UNIQUE(store_language_id, slug)`. The new composite has `store_language_id` as its leftmost column, so MySQL silently drops the FK index it auto-created in 0076. The count stays three, but its composition changes. Verify with `db:table`, and investigate any other number.

**D-18 — The action signatures widen.** Unlike the siblings, which reinterpreted existing parameters, these three fields are **new**, so they are added as trailing parameters. On `CreateProduct` they default to `null` (leaving them out means "none" on create). On `UpdateProduct` they have **no** default, because a caller that leaves them out would otherwise wipe stored values, the same reasoning as F-6 for `$description` (see **Q-3**). This is a public-contract change that 0077 binds to.

**D-30 — The canonicaliser is one invokable action**, `App\Actions\Products\CanonicalizeProductSlug`, mirroring `SanitizeProductDescription`: one implementation shared by both layers, unit-testable on its own.

**D-31 — Every 1062 is attributed by index name.**
- **Why:** at `HEAD`, `CreateProduct` maps **any** 1062 to `sku`. Once `product_translations` carries a slug unique, a raced duplicate slug would be misreported as "the SKU is taken". `UpdateProduct` matches its index names with `str_contains($e->getMessage(), …)`, which [constraint-violation-discrimination.md](../../docs/security/constraint-violation-discrimination.md#-the-rule) forbids.
- **Rule:** both actions gate on `errorInfo[1] === 1062`, then match the index name in `errorInfo[2]`, anchored to `for key '(?:table.)?<index>'$`.
- **Mapping:** `products_sku_unique` → `sku`; `product_translations_language_slug_unique` → `slug` (localised attribute); the two `product_variants` indexes → the existing `TranslateProductVariantUniqueViolation` (`UpdateProduct` only); anything else → rethrow.
- **Scope of the change:** moving the existing sku matcher to the anchored form is in scope, because the doc asks for it the next time the file is touched and this story touches it. `TranslateProductVariantUniqueViolation`'s own internal `getMessage()` match is **not** changed here; the doc scopes that to the next edit of *that* file.

**D-32 — Rule method names follow the shipped trait** (`productNameRules()`, `productSkuRules()`, …): `productSlugRules()`, `productMetaTitleRules()` and `productMetaDescriptionRules()`. [0077](0077-product-editor-language-tabs-ui.md)'s draft calls them `slugRules()` / `metaTitleRules()` / `metaDescriptionRules()`, and 0077's Phase 3 must adopt these names. This is recorded here as a hand-off and 0077's file is not edited.

**D-33 — The existing editor preserves the three fields until 0077 exposes them.** Because `UpdateProduct` requires them (**D-18**), `Products\Editor::save()` must pass something. It passes the values it read from the raw default-language row in `mount()`, held in `#[Locked]` properties so a forged update cannot change them through a field the UI does not offer.

## 5. Dependencies, risks, open technical questions

### Dependencies
- **[0076](done/0076-translatable-content-retrofit-products-backend.md)**: hard. It supplies `product_translations`, `ProductTranslation` and its `saving` hook, the default-language write in both actions, and the editor's raw-row read.
- Shipped: [0024](done/0024-products-core-crud-backend.md) (the actions and their 1062 catches), [0070](done/0070-translatable-content-mechanism-product-categories-backend.md) (`SetTranslation`), [0068](done/0068-store-languages-catalog-backend.md).
- **Dependents:** [0077](0077-product-editor-language-tabs-ui.md) (hard: rule methods, widened signatures).
- **File-sharing:** [0087](0087-translation-write-actions-refuse-inactive-store-language.md) also edits `lang/{en,es}/products.php`.

### Risks
- **R-5 — The slug's self-exclusion fails silently and permanently** if it is wired as `->ignore()`. The deliberate two-product test keeps it closed.
- **R-14 — The canonicalisation mechanism is a synthesis**, not an inherited decision (**D-6**), so Phase 2 should confirm it.
- **R-20 — The `UpdateProduct` signature change is wide:** every direct caller and test must pass three more arguments. It is mechanical but broad.
- **R-21 — The implicit-FK-index drop (D-11b) is MySQL behaviour, not something Laravel declares.** Assert the count after migrating, never from the migration text.
- **R-22 — Deferred from 0076 (Phase 4, Low): a raw 1062 on `product_translations_product_language_unique`.** After the default store language switches to one with no rows for a product, two administrators saving that same product concurrently both try to insert the default-language row; `UpdateProduct` has no catch for this and surfaces a raw 1062. `CreateProduct` cannot hit it (fresh product id) but its blanket catch maps **every** 1062 to "sku taken". **D-31's index-name attribution must include `product_translations_product_language_unique`** (rethrown or mapped to a retry message, not reported as `sku` or `slug`). 0076 deliberately did not fix it.

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
Split from [0076](done/0076-translatable-content-retrofit-products-backend.md) on 2026-11-06 by `product-owner`, at the project owner's decision after 0076's Phase 2 rejection (its findings 5, 9, 10 and 12 land here). **D-5**, **D-6**, **D-9b**, **D-18**, **R-5**, **R-14**, **Q-1** and **Q-2** are carried from 0076's Phase 1 debate (2026-08-29: `backend-expert`, `database-expert`, `backend-qa`), and were re-verified against `HEAD` on 2026-11-06 (`CreateProduct`'s blanket 1062 catch, `UpdateProduct`'s `getMessage()` matching, and the trait's `product*Rules()` naming). **D-30** to **D-33**, **R-20**, **R-21** and **Q-3** are new at the split. No Three Amigos re-run was performed for the new decisions, so Phase 2 should weigh them accordingly.

## Review log
- Phase 1 (split), 2026-11-06: created. **Awaiting Phase 2** (not run).
