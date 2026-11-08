# [0076] Translatable content retrofit — Products backend

## Description
Applies story [0070](../done/0070-translatable-content-mechanism-product-categories-backend.md)'s per-store-language translatable-content mechanism to **Products** ([PRD Epic 5, Layer 2](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization); [assumption 14](../../../docs/PRD/sections/foundations.md#assumptions--confirmed-decisions) names *"product title/description"* as translatable content). Story [0024](../done/0024-products-core-crud-backend.md)'s `products.name` and `products.description` move to a new `product_translations` child table, one row per `(product, store language)`; existing rows are backfilled into the store default language; and **every live reader of the two dropped columns is migrated in this same story**, following the precedent of story [0074](../done/0074-translatable-content-retrofit-blog-tags-backend.md) (commit `d8a6be2`, which migrated its Livewire readers alongside the retrofit).

**Split on 2026-11-06 (project owner's decision after the Phase 2 rejection recorded in the review log below).** The slug and SEO fields (`slug`, `meta_title`, `meta_description`), their validation rules, the `CreateProduct`/`UpdateProduct` signature widening, slug canonicalisation and the 1062 disambiguation by index name moved to the follow-up story [0076a](../0076a-product-slug-and-seo-fields-backend.md). This story adds **no** slug or meta column and **does not change any action signature**.

**This story consumes a recipe; it does not write one.** `App\Concerns\HasTranslations`, `App\Actions\Translations\SetTranslation` and `StoreLanguage::defaultStoreLanguage()` are 0070's, shipped, and used **unmodified**. Every upstream story this one builds on — [0024](../done/0024-products-core-crud-backend.md), [0024a](../done/0024a-product-description-html-sanitization.md), [0068](../done/0068-store-languages-catalog-backend.md), [0070](../done/0070-translatable-content-mechanism-product-categories-backend.md), [0072](../done/0072-translatable-content-retrofit-blog-categories-backend.md), [0074](../done/0074-translatable-content-retrofit-blog-tags-backend.md) and [0082](../done/0082-dashboard-home-overview-backend.md) — is **shipped** (`done/`). Every signature named below was re-verified against `HEAD` on 2026-11-06.

> **What genuinely does not port from the single-field siblings** (each is a decision below rather than a silent carry-forward):
>
> 1. **There is no uniqueness to re-scope.** `products.name` has never carried a unique index or a `Rule::unique()` (`productNameRules()` is `['required', 'string', 'max:255']`), so 0070's recipe step 4 is a no-op and none is invented (**D-4**).
> 2. **This is the first translation table with two translatable columns**, so 0070's **D-5** (fallback resolves per *field*, not per row) gets its first real-field wiring test here (**D-3**).
> 3. **`description` carries sanitized HTML**, and the retrofit puts the sanitizer-free `SetTranslation` in front of the column 0024a protects — closed by a two-layer sanitizer (**D-8**, confirmed in Phase 2).
> 4. **Products has many more live readers than any taxonomy** — two Livewire screens, the order detail picker, two order-writing actions, the order-item factory and the dashboard — and `order_items.product_name` freezes a name into a financial record (**D-20**, **D-21**).
> 5. **The parent keeps six fillable columns** rather than reaching `#[Fillable([])]` (**D-10**).

## Type
backend | includes database-expert: **yes** (one new table with an idempotent data backfill, one column-drop migration with a guard, one read-side query scope)

## 1. Refined user story

> **As** a catalog administrator running a store that sells in more than one language,
> **I want** each product's title and description to be stored and resolved per store language, falling back to the store default when a translation is missing,
> **so that** the catalog reads correctly in every language the store authors in, and a partially translated catalog degrades gracefully instead of rendering blank or failing.

> **As** the engineer applying story 0070's recipe to the first entity with two translatable fields,
> **I want** per-field fallback proven against real fields, the description sanitizer bound to every writer, and every reader of the moved columns migrated in the same change,
> **so that** dropping the columns breaks nothing that ships today and the language-tab UI story ([0077](../0077-product-editor-language-tabs-ui.md)) builds on a safe mechanism.

**Scope fence.** This story ships **no new screen, route, language tab or `config/modules.php` entry**. It **does** edit the existing Livewire readers of the dropped columns (listed under *Modify*), minimally, so they keep their current behaviour against the new storage. Non-default-language authoring (the tabs, and the per-entity `SetProductTranslation` action that validates and authorizes such a write) belongs to [0077](../0077-product-editor-language-tabs-ui.md); slug/SEO belong to [0076a](../0076a-product-slug-and-seo-fields-backend.md).

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and carries exactly one `When`, per [gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3. Every **write** below goes through `CreateProduct`/`UpdateProduct`, which write the **default store language only** (0072 **D-7** / 0074 precedent); French content in a `Given` is arranged fixture data, not a write path this story ships.

```gherkin
Feature: Per-store-language product content

  # --- Resolution and fallback ---

  Scenario: A catalog administrator reads a product translated into the requested language
    Given a catalog administrator, with a product titled "Zapatillas Running" in Spanish and "Chaussures de course" in French
    When the product's French title is requested
    Then "Chaussures de course" is returned

  Scenario: A missing translation falls back to the default store language
    Given a catalog administrator, with a product titled "Zapatillas Running" in the default store language and no French translation
    When the product's French title is requested
    Then "Zapatillas Running" is returned, because the store default supplies the fallback

  Scenario: A product translated in neither the requested nor the default language resolves to nothing
    Given a catalog administrator, with a product holding no translation in any store language
    When the product's French title is requested
    Then no title is returned and no error is raised

  # --- Per-field fallback ---

  Scenario: A half-translated product keeps its translated title and falls back only on the description
    Given a catalog administrator, with a product whose French title is present and whose French description is absent
    When the product's French description is requested
    Then the store default's description is returned
    And the French title is still the one returned for the title

  Scenario: A product's title falls back without dragging its description with it
    Given a catalog administrator, with a product whose French description is present and whose French title is absent
    When the product's French title is requested
    Then the store default's title is returned
    And the French description is still the one returned for the description

  Scenario: A description stored as an empty value still falls back
    Given a catalog administrator, with a product whose French description is stored as an empty value
    When the product's French description is requested
    Then the store default's description is returned

  # --- Store-language lifecycle ---

  Scenario: A translation authored in a removed store language is still readable
    Given a catalog administrator, with a product translated into French and French since removed as a store language
    When the product's French title is requested
    Then "Chaussures de course" is returned, because removal preserves stored content

  Scenario: A catalog translated only into the previous default renders no title after a default change
    Given a catalog administrator, with a product titled only in Spanish and Spanish as the store default
    When a store administrator makes French the store default
    Then the product's French title resolves to nothing and no error is raised

  # --- Writing through the product actions (default language only) ---

  Scenario: Creating a product stores its title in the default store language
    Given a catalog administrator holding the products create permission
    When they create a product titled "Zapatillas Running"
    Then the product holds exactly one translation, in the default store language, titled "Zapatillas Running"

  Scenario: Updating a product replaces its default-language title without adding a row
    Given a catalog administrator holding the products edit permission, with a product titled "Zapatillas Running" in the default store language
    When they update the product's title to "Zapatillas Trail"
    Then the default-language translation reads "Zapatillas Trail" and the product still holds exactly one translation

  Scenario: Updating the default-language content leaves other languages untouched
    Given a catalog administrator holding the products edit permission, with a product titled "Chaussures de course" in French
    When they update the product's default-language title
    Then the French translation still reads "Chaussures de course"

  Scenario: A blank title is refused
    Given a catalog administrator holding the products edit permission
    When they update a product with a blank title
    Then the change is refused with a validation message and no translation row is written or changed

  Scenario: Two products may share a title within one store language
    Given a catalog administrator holding the products create permission, with a product titled "Camiseta básica" in the default store language
    When they create another product titled "Camiseta básica"
    Then the product is created, because a product title carries no uniqueness rule

  # --- Description HTML stays sanitized on every write path ---

  Scenario: A description saved through the product actions is stored sanitized
    Given a catalog administrator holding the products edit permission
    When they update a product's description to text containing a script tag
    Then the stored default-language description holds no script tag

  Scenario: A product without a description translates without one
    Given a catalog administrator, with a product carrying no description
    When the product's French description is requested
    Then no description is returned and no error is raised

  # --- Authorization ---

  Scenario: An administrator without the products edit permission cannot change a product's title
    Given a signed-in administrator who does not hold the products edit permission
    When they update a product's title
    Then the change is refused and the stored translation is unchanged

  Scenario: A catalog administrator needs no store-language permission to change a product's title
    Given a catalog administrator holding the products edit permission and no store-language permission
    When they update a product's title
    Then the default-language translation is updated, because authoring content is not managing the language catalog

  # --- Deletion ---

  Scenario: Deleting a product removes every language's content with it
    Given a catalog administrator holding the products delete permission, with a product translated into three store languages
    When they delete that product
    Then all three of its stored translations are removed with it

  # --- Existing screens keep working ---

  Scenario: The products list shows and orders products by their default-language title
    Given a catalog administrator, with products titled "Botas" and "Abrigo" in the default store language
    When they open the products list
    Then "Abrigo" is listed before "Botas"

  Scenario: The products list shows a placeholder for a product with no default-language title
    Given a catalog administrator, with a product titled only in a language that is no longer the store default
    When they open the products list
    Then that product is listed with a placeholder instead of a title, and no error is raised

  Scenario: An order line freezes the product title in the default store language
    Given an order administrator, with a product titled "Zapatillas Running" in the default store language and "Chaussures de course" in French
    When they add that product to an order
    Then the order line's product name reads "Zapatillas Running"

  Scenario: An order line keeps its frozen title when the product is later renamed
    Given an order administrator, with an order line for a product titled "Zapatillas Running"
    When a catalog administrator renames the product to "Zapatillas Trail"
    Then the order line's product name still reads "Zapatillas Running"

  # --- The removal warning this story extends ---

  Scenario: Removing a language in use reports the product content it affects
    Given a store administrator, with French active and holding product translations
    When the usage count for French is requested
    Then the count includes the French product translations
```

**Moved out of this story at the split (not deleted):** writing, re-translating, refusing a blank title in, or sanitizing a description in a **non-default** language, and the store-language-permission scenario for a French write, all need a validating and authorizing non-default write path, which only [0077](../0077-product-editor-language-tabs-ui.md) ships (its scenarios *"A catalog administrator translates a product into an additional language"*, *"A blank title in a language being authored is refused"*, *"A description written in a non-default language is stored safely"* and *"Translating a product is refused without the editor, not only within it"* already cover them). The slug scenarios moved to [0076a](../0076a-product-slug-and-seo-fields-backend.md). The bare `SetTranslation` sanitization proof is a technical test, not a business scenario, and stays below under *Tests to perform*.

## Files to create/modify

### Create

- **`database/migrations/<timestamp>_create_product_translations_table.php`**, the child table plus its backfill. It copies the **shipped 0074 shape** (`2026_10_15_100000_create_blog_tag_translations_table.php`): `assertCanBackfill()` runs **before** any DDL, the table is created, then the backfill runs. Its timestamp must be later than `2026_09_01_142007_create_products_table` and `2026_09_27_122924_create_store_languages_table`.

  ```php
  public function up(): void
  {
      app(BackfillProductTranslations::class)->assertCanBackfill();

      Schema::create('product_translations', function (Blueprint $table): void {
          $table->uuid('id')->primary();                                                                  // D-17
          $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();                  // live cascade, D-19
          $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();  // defensive only, D-19
          $table->string('name', 255);                    // NOT NULL, tracks products.name (D-2)
          $table->mediumText('description')->nullable();  // tracks products.description exactly (D-2)
          $table->timestamps();

          // The natural key, named explicitly (precedent: blog_tag_translations_tag_language_unique) so
          // 0076a's index-name 1062 attribution can match it.
          $table->unique(['product_id', 'store_language_id'], 'product_translations_product_language_unique');
      });

      app(BackfillProductTranslations::class)();
  }

  public function down(): void
  {
      Schema::dropIfExists('product_translations');
  }
  ```

  There is **no** unique on `name` (**D-4**) and **no** fold column (Products uses no `NormalizeForSearch`). Expected indexes: **three** (**D-11**), verified with `php artisan db:table product_translations`, never by reading the migration.

- **`database/migrations/<timestamp>_drop_translatable_columns_from_products_table.php`**, a separate migration ordered strictly after the first, with **0074's guard** copied from `2026_10_15_100001_drop_name_columns_from_blog_tags_table.php`:

  ```php
  public function up(): void
  {
      // A product written by old code between the two migrations would lose its only copy of
      // the name and description.
      $hasUntranslatedProduct = DB::table('products')
          ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
              ->from('product_translations')
              ->whereColumn('product_translations.product_id', 'products.id'))
          ->exists();

      throw_if(
          $hasUntranslatedProduct,
          RuntimeException::class,
          'Cannot drop products.name/description: some products have no product_translations row. Re-run the backfill first.',
      );

      // No dropUnique(): products.name never had a unique index (D-4).
      Schema::table('products', function (Blueprint $table): void {
          $table->dropColumn(['name', 'description']);
      });
  }

  public function down(): void
  {
      // KNOWINGLY NOT AN INVERSE (D-12): both columns come back nullable, with no data.
      Schema::table('products', function (Blueprint $table): void {
          $table->string('name', 255)->nullable()->after('product_category_id');
          $table->mediumText('description')->nullable()->after('stock');
      });
  }
  ```

  The missing `dropUnique()` matters: copying 0072's or 0074's migration verbatim would call `dropUnique()` on an index that never existed and fail at runtime.

- **`app/Actions/Products/BackfillProductTranslations.php`**, the **shipped 0074 shape** (`App\Actions\Blog\BackfillBlogTagTranslations`) copied, not redesigned (**D-13**):
  - `assertCanBackfill(): void` refuses **only** when `products` has rows **and** no default store language exists. With zero products it returns, so a fresh install and every `RefreshDatabase` run (where `store_languages` is empty at migration time) migrate cleanly.
  - `translationRowsFor(iterable $products, string $storeLanguageId, CarbonInterface $now): array` is pure. It maps `{id, name, description}` rows to insert rows (`id` = `Str::uuid7()`, `product_id`, `store_language_id`, `name`, `description` byte-for-byte, timestamps).
  - `__invoke(): int` calls `assertCanBackfill()`, returns `0` when there are no products, then reads `products` through the **query builder** (never the model) with `whereNotExists` against an existing default-language row (idempotent re-run), `chunkById(500, …, 'products.id', 'id')`, and inserts each chunk. It returns the number of rows written.

  Because it uses the query builder, **no model event fires**, so `description` is copied byte-for-byte and never re-sanitized (**D-9**).

- **`app/Models/ProductTranslation.php`**: `use HasFactory, HasUuids;`, `#[Fillable(['name', 'description'])]` (`product_id` and `store_language_id` are omitted, so only `SetTranslation`'s `forceFill()` writes them), no `SoftDeletes`, and `belongsTo` both parents. One `saving` hook, the second sanitizer layer (**D-8**), placed in `booted()` as in `BlogTagTranslation`:

  ```php
  protected static function booted(): void
  {
      static::saving(function (self $translation): void {
          if ($translation->isDirty('description')) {
              $translation->description = app(SanitizeProductDescription::class)($translation->description);
          }
      });
  }
  ```

- **`database/factories/ProductTranslationFactory.php`**, with a `forLanguage(StoreLanguage $language)` state, mirroring `BlogTagTranslationFactory`. Its default `description` is plain text (no markup that needs sanitizing).

### Modify

- **`app/Models/Product.php`**: `class Product extends Model implements Translatable` (`App\Concerns\Translatable`), `use HasTranslations;`, plus `translationModel(): string { return ProductTranslation::class; }`. The interface is required, not optional: `SetTranslation::__invoke()` type-hints `Model&Translatable` (`SetTranslation.php:34`), and `ProductCategory`, `BlogCategory` and `BlogTag` all implement it. Without it, both actions fail Larastan level 7, which is a DoD gate.
  - `#[Fillable]` goes from `['name', 'sku', 'product_category_id', 'type', 'status', 'price', 'stock', 'description']` to **`['sku', 'product_category_id', 'type', 'status', 'price', 'stock']`**, which is **six** columns (**D-10**). `featured_media_id` was never fillable and stays that way.
  - The `@property` block loses `$name` and `$description` and gains `@property-read Collection<int, ProductTranslation> $translations`.
  - `casts()`, `isOutOfStock()`, `displayStatus()` and the **five** existing relations (`category`, `featuredImage`, `gallery`, `salesRegions`, `variants`) are unchanged. The trait adds a sixth, `translations()`.
  - Gains `scopeOrderByTranslatedName(Builder $query, ?string $storeLanguageId = null): void` (**D-14**) and one snapshot seam, `snapshotName(): string` (**D-21**).
- **`app/Actions/Products/CreateProduct.php`**: **the signature is unchanged.** `$name` and `$description` now mean "the default store language's". Constructor-injects `SetTranslation`.
  - Right after the existing `authorize('create', …)` first statement, it resolves `StoreLanguage::defaultStoreLanguage()` and `throw_if`s a `RuntimeException` when there is none (`CreateBlogTag` precedent).
  - It keeps trim, then sanitize, then `validate()` (layer 1 of **D-8**).
  - `forceCreate()` loses `name` and `description`. Inside the **existing** `DB::transaction(…, attempts: 3)` closure, after `forceCreate()` and before `SyncProductGallery`, it calls `SetTranslation($product, $default, ['name' => $name, 'description' => $description])` (**D-15**).
  - The existing blanket `1062 → sku` catch stays correct, because the only new unique (the natural key) cannot collide on a freshly minted product id. Narrowing that catch by index name is [0076a](../0076a-product-slug-and-seo-fields-backend.md)'s job, once a second translation unique exists.
- **`app/Actions/Products/UpdateProduct.php`**: **the signature is unchanged.**
  - It keeps the same authorize-first, default-language guard and sanitize-before-validate order.
  - `$product->update([...])` loses `name` and `description`. The `SetTranslation` call goes **inside the existing `DB::transaction()`** (which deliberately has **no** `attempts:`; see the in-code R-1 comment and `docs/security/derived-column-invariants.md`), next to the parent update and before `SyncProductGallery` / `reDeriveVariantSkus()`.
  - The index-name-disambiguated 1062 catch is unchanged. A natural-key 1062 matches neither branch and is rethrown, which is correct: it is a race, not a field error.
- **`app/Livewire/Products/Index.php`**:
  - `products()` drops `name` from its explicit `select`, qualifies the remaining columns with `products.`, replaces `->orderBy('name')->orderBy('id')` with `->orderByTranslatedName()->orderBy('products.id')`, and maps `'name' => $product->getAttribute('translated_name') ?? '—'`. It reads the column through `getAttribute()` (a dynamic select alias, for Larastan), as `GetLowStockProducts` already does for `effective_stock`. It does **not** eager-load whole translation rows (**R-7**).
  - `confirmDelete()` sets `$deletingProductName` from the default-language name (or `'—'`).
  - The pinned query shape in `IndexQueryTest.php` is updated deliberately.
- **`app/Livewire/Products/Editor.php`**: `mount()` reads `name` and `description` from the **raw default-language translation row** (`$product->translations->firstWhere('store_language_id', $defaultId)`), never `translated()`, so a fallback is never silently saved back as a translation (0073 **D-6** precedent). If there is no such row, both fields start empty. `save()` is unchanged, because the action signatures are unchanged. The class docblock at lines 41–42 (which says `$name`/`$description` match the "still-scalar `products.name` / `products.description` columns") is refreshed to describe the default-language translation row.
- **`app/Livewire/Orders/Show.php`**: `productOptions()` replaces `->orderBy('name')->orderBy('id')->…->get(['id', 'name', 'sku'])` with `->select(['products.id', 'products.sku'])->orderByTranslatedName()->orderBy('products.id')->…->get()`, and reads `getAttribute('translated_name') ?? '—'`. Both the `select` and the `orderBy` must be qualified: after the scope's join, a bare `id` is ambiguous, and Laravel ignores `get([...])`'s column list once columns are already set. The ordering must happen in SQL, not in PHP after the fetch, because the query is capped at `PRODUCT_PICKER_LIMIT` and sorting after the limit would change *which* products are offered.
- **`app/Actions/Orders/CreateOrder.php`** (line ~245) and **`app/Actions/Orders/AddOrderItem.php`** (line ~173): `'product_name' => $product->snapshotName()` (**D-21**). `CreateOrder` resolves its products in one bulk `Product::query()->findOrFail($productIds)` (line ~176), and that query gains `->with('translations')` (the **full** relation) so `snapshotName()` causes no N+1. `AddOrderItem` loads one product, so `snapshotName()`'s lazy load is a single query.
- **`database/factories/OrderItemFactory.php`** (line ~85): `$orderItem->product_name = $product->snapshotName();`.
- **`app/Actions/Dashboard/GetLowStockProducts.php`**: the private `resolveName()` seam (line ~107) returns `$product->translated('name')`, and its return type changes from `string` to **`?string`**; the query eager-loads through `withTranslationsFor()` (the result set is bounded by the action's own limit), and the row's `name` becomes nullable as its docblock already anticipates. It resolves the **store default language** (**Q-4** resolved as (a) on 2026-11-06).
- **`database/factories/ProductFactory.php`**: `definition()` loses `name` (line 39) and `description` (line 45). Following the **shipped `BlogTagFactory` shape**, `configure()` adds an `afterCreating` that writes the default-language translation (creating a default `StoreLanguage` if none exists). New states: `named(string $name, ?string $description = null)` for tests that need specific content, and `withoutTranslations()`. Both change **only the translation step**, never the other `afterCreating` callbacks, so `withGallery()` (line ~121, itself an `afterCreating` callback) survives in any order (**D-22**).
- **`config/store-languages.php`**: append `['table' => 'product_translations', 'column' => 'store_language_id'],`. That is the entire diff (0068 **D8**).
- **The test files in the disposition table below.**

### Deliberately not touched

- `app/Concerns/HasTranslations.php`, `app/Actions/Translations/SetTranslation.php`, `App\Models\StoreLanguage` and `App\Actions\Translations\CompareTranslatedNames`: consumed unmodified.
- `app/Concerns/ProductValidationRules.php`: `productNameRules()`, `productDescriptionRules()` and `productSkuRules()` are byte-identical (**D-4**, **D-7**). The slug/meta rule methods are [0076a](../0076a-product-slug-and-seo-fields-backend.md)'s.
- `app/Actions/Products/SanitizeProductDescription.php` and `config/html-sanitizer.php`: this story adds a second **call site**, never a second allow-list.
- `app/Actions/Products/DeleteProduct.php`, `SyncProductGallery.php`, `SyncProductSalesRegions.php` and the variant actions: none reads or writes `name`/`description` (verified by grep at `HEAD`).
- `database/seeders/RolePermissionSeeder.php` and `app/Policies/ProductPolicy.php`: no new permission, ability or policy (**D-16**).
- `lang/**`: no new key. The list placeholder is the literal `'—'`, as in the Blog Tags list (0075).
- `routes/**`, `config/modules.php` and every Blade view: unchanged. Each view already renders the `name` key of the row array that its component builds.

## Applying 0070's recipe: what transferred, and what did not

| Step | Outcome here |
| --- | --- |
| 1. Create `<entity>_translations` with the FK pair, the natural-key unique, and optionally a unique on the formerly-unique field | FK pair and natural key applied. There is **no candidate** for the optional unique (**D-4**). |
| 2. Create `<Entity>Translation` with `HasFactory, HasUuids` and a translatable-fields-only `#[Fillable]` | Applied. It has one `saving` hook (the sanitizer, **D-8**), which is not a fold. |
| 3. On the parent: `use HasTranslations;` + `translationModel()`; drop translated columns from `#[Fillable]` | Applied, plus `implements Translatable`, which `SetTranslation`'s `Model&Translatable` type-hint requires. The parent keeps **six** fillable columns (**D-10**). |
| 4. Re-scope `<Noun>ValidationRules` uniqueness by `store_language_id` | **Not applied, deliberately**: there is no uniqueness on `name` (**D-4**). |
| 5. Reuse `SetTranslation` unmodified | Applied. |
| 6. Append exactly one `{table, column}` literal to `config/store-languages.php` | Applied. 0070's drift guard picks up `product_translations` through the `*_translations` suffix, so **do not rename the table**. |

## Tests to perform — 3. QA test cases / validation scenarios

Feature and Unit tests. The existing browser tests that seed product names are **migrated** (see the disposition table), and no new browser test is added.

### Per-field fallback (wiring test, D-3)
- [ ] Feature: **one fixture with one field present and one absent.** The default row has `name` and `description`. The French row has `name` only (`description` `NULL`). Assert `translated('name', fr)` is French **and** `translated('description', fr)` is the default's. Both wrong shapes fail this: a per-row resolver that picks the French row returns `null` for the description, and one that picks the default row returns the default name.
- [ ] Feature: the mirror, where French `description` is present and French `name` is absent. Because `name` is `NOT NULL`, "absent" is arranged as French `name = ''`, which `translated()` treats as absent. The title falls back to the default's, and the description stays French. This doubles as the `''`-is-absent regression for `name`.
- [ ] Feature: a French row whose `description` is `''` falls back to the default's.
- [ ] Feature: missing in both languages returns `null` per field and **does not throw**.
- [ ] Feature: an **inactive** requested language still resolves (0068 **D5**).
- [ ] Feature: after the store default **changes**, resolution re-points, and a product translated only into the old default resolves to `null` without error (reset the memo with `StoreLanguage::flushDefaultStoreLanguage()`, **R-8**).

### The description sanitizer (D-8)
- [ ] Feature: **the bypass test.** Call `SetTranslation` **directly**, with no `CreateProduct`/`UpdateProduct` in the path, passing `<script>alert(1)</script>` in `description`, and assert the **persisted** value is sanitized. Without the hook this fails, which is the whole argument for **D-8**.
- [ ] Feature: sanitize-before-length ordering survives on the action path. A description whose **pre**-sanitization length exceeds `max:65535` but whose sanitized form does not is **accepted**, asserted separately on create and on update (0024a **D-16** constraint 1).
- [ ] Feature: idempotence on a value that passes through both layers: `sanitize(sanitize($x)) === sanitize($x)`.
- [ ] Feature: a save that does not touch `description` does not rewrite it (the `isDirty('description')` guard).
- [ ] Feature: every existing `ProductDescriptionSanitizationTest` case is **retargeted** to the translation row. The logic is unchanged.

### Writing through the actions
- [ ] Feature: `CreateProduct` leaves **one** `products` row and **one** `product_translations` row in the default language, with the trimmed name and the sanitized description.
- [ ] Feature: `UpdateProduct` rewrites the default-language row in place (row count unchanged, same row id) and leaves a French row byte-identical.
- [ ] Feature: `UpdateProduct` on a product that has **no** default-language row (after a default change) **inserts** one.
- [ ] Feature: a whitespace-only name is refused before any write (`products` and `product_translations` unchanged).
- [ ] Feature: with **no default store language**, both actions throw `RuntimeException` **after** authorization and write nothing.
- [ ] Feature: two products with byte-identical names in the same language both save (**D-4** tripwire).
- [ ] Feature: a catalog administrator holding `products.edit` and **no** `store-languages.*` permission updates a product's title through `UpdateProduct`, and the default-language translation is updated (Gherkin: *"A catalog administrator needs no store-language permission to change a product's title"*; **D-16**).
- [ ] **Triage note for Phase 3.** The default-language guard in `CreateProduct`/`UpdateProduct` runs **before** `validate()`. Most existing action tests pass only because their payload helpers call `ProductCategory::factory()->create()`, which creates a default `StoreLanguage` (`ProductCategoryFactory.php:78`). A test that builds its fixtures through `withoutTranslations()` (or otherwise without a default language) and expects a `ValidationException` will now get a `RuntimeException`. Fix the fixture, not the order of the checks.
- [ ] Unit: `productNameRules()` still carries no uniqueness rule.

### Atomicity (D-15), rescoped to the existing transactions
- [ ] Feature (create): a test-registered `ProductTranslation::saving` listener that throws makes `CreateProduct` leave **zero** rows in `products`, `product_translations` **and** `product_media`, all asserted in one block.
- [ ] Feature (create): a failure inside `SyncProductGallery` (a media row deleted between validation and the pivot insert) leaves **zero** `product_translations` rows as well as zero products.
- [ ] Feature (update): a failing translation write rolls back the parent `update()` (the `sku`/`price` change is not persisted).
- [ ] Feature (update): a `reDeriveVariantSkus()` failure rolls back the translation row too. Because `SetTranslation` runs *before* the re-derivation, only a failure that comes later proves the translation write is not committed early.
  - **Setup:** reuse the setup of `ProductVariantSkuUniquenessTest`'s *"F-6: a variant-cascade collision surfaces via the parent_sku_change_collides message…"* test (lines ~662–716, whose line ~716 asserts that the whole update rolled back). Change the product's SKU **and** its name in one `UpdateProduct` call, arranged so a re-derived variant SKU collides with another product's SKU. `reDeriveVariantSkus()` then throws a `ValidationException` after `SetTranslation` has already run.
  - **Assert:** the default-language translation row (name and description) is unchanged, and so are the parent's `sku` and `price`.
  - **Why this route:** a model-event listener cannot be used. `reDeriveVariantSkus()` writes through `DB::table('product_variants')->update()` (`UpdateProduct.php:320`), which fires no model event, and it runs only when the SKU changes.
- [ ] Feature: a `ValidationException` is raised before the transaction opens (no rows, no savepoint).
- [ ] **Not added:** a test that `attempts: 3` retries the translation write. That retry behaviour is shipped and tested by 0024/0029. The only new obligation is that the translation write sits **inside** that closure, and the rollback tests above prove it.

### The backfill (D-13)
- [ ] Unit: `translationRowsFor()` over fabricated rows returns exactly one row per input, in the given language, with `name` **and** `description` byte-identical. Assert **per row, never as a count**.
- [ ] Unit: a `null` description stays `null`, not `''`.
- [ ] Unit: a description containing every tag in 0024a's allow-list comes through **byte-identical** (equality, not "contains no script").
- [ ] Feature: `assertCanBackfill()` with **zero products and no default language** does **not** throw. This is the property whose absence failed the whole suite under `RefreshDatabase` (Phase 2 finding 1).
- [ ] Feature: `assertCanBackfill()` with products present and no default language throws `RuntimeException`.
- [ ] Feature: `__invoke()` is idempotent. A second run writes `0` rows and leaves existing rows untouched.
- [ ] Feature: the drop migration's guard throws when a `products` row has no translation row. Arrange this by `require`-ing the drop migration file and calling its `up()` against a product created through `withoutTranslations()`. Assert the `RuntimeException`, and assert that both columns are still absent, because they were already dropped by `RefreshDatabase` and the guard must throw before any DDL. Do **not** run a partial `migrate --path=`.
- [ ] The migrations themselves are covered by `RefreshDatabase` running them.

### Model, wiring and query shape
- [ ] Feature: `(new Product)->getFillable()` is exactly `['sku', 'product_category_id', 'type', 'status', 'price', 'stock']` (six columns), and `featured_media_id` is absent (**D-10**).
- [ ] Feature: a forged `Product::create(['name' => …, 'sku' => …, …])` silently drops `name`, which is no longer fillable and no longer a column, so no `QueryException` is raised.
- [ ] Feature: a forged mass-assignment of `product_id` or `store_language_id` on `ProductTranslation` is ignored.
- [ ] Feature: `scopeOrderByTranslatedName()` orders by the requested language, falls back to the default for a product untranslated in it, and breaks ties by `products.id`. It is also tested through `Products\Index` pagination: two products with the same name never reshuffle between pages.
- [ ] Feature: `Products\Index::products()` issues a bounded number of queries for N products and never selects `description` (an extension of `IndexQueryTest.php`).
- [ ] Feature: `Orders\Show::productOptions()` returns the first `PRODUCT_PICKER_LIMIT` products in **translated-name** order (arranged so a PHP-side sort after the limit would return a different set).
- [ ] Feature: `Products\Editor` mount shows the raw default-language row. A product whose only translation is French opens with an **empty** name, not the French one.

### Orders snapshot (D-21)
- [ ] Feature: `CreateOrder` and `AddOrderItem` freeze the **default-language** name even when the product has a French row.
- [ ] Feature: renaming the product afterwards leaves `order_items.product_name` unchanged (existing `PriceSnapshotTest` intent, migrated).
- [ ] Feature: the **Q-5** edge (no default-language row; Q-5 resolved as (a) on 2026-11-06). The oldest stored translation's name (by `created_at`, then `id`) is frozen, and no error is raised.
- [ ] Feature: `snapshotName()` reads the **full** `translations` relation. A product loaded through `withTranslationsFor($someOtherLanguage)` (a filtered relation) still freezes the correct default-language name, or the oldest-translation fallback. It never returns a wrong name because the relation was filtered. Test: a product with only an FR (older) and a DE row and no default row, loaded through `withTranslationsFor(DE)`, must freeze FR.
- [ ] Feature: a product with **no usable name** (zero translations, or only blank names; e.g. `Product::factory()->withoutTranslations()`) makes `snapshotName()` throw `LogicException`, and no order line is written.
- [ ] Feature: `CreateOrder` with N lines issues a bounded number of translation queries (the bulk `findOrFail()` eager-loads `translations`).

### Cascade, registry and authorization
- [ ] Feature: deleting a product translated into three languages leaves **zero** of its `product_translations` rows, asserted by a direct query (**D-19**).
- [ ] Feature: `StoreLanguage::translationUsageCount()` includes `product_translations` rows.
- [ ] Feature: `php artisan config:cache` succeeds with the appended entry.
- [ ] Feature: **through the action.** A user without `products.edit` calling `UpdateProduct` is refused by `LogRefusedPrivilegedAttempt::authorize()` (a refusal is logged) and `product_translations` is unchanged. This is the one Gate test this story adds (**D-16**). Asserting `ProductPolicy` directly is **not** added, because `ProductPolicyTest` already covers it.
- [ ] Feature (architecture): `arch()->expect('App\Livewire\Products')->not->toUse(SetTranslation::class)` and the same for `App\Livewire\Orders`. Only the actions may call the primitive (**D-16**). No global guard exists today: the existing ones are per-component, in `ProductCategories/LanguageTabsTest.php`, `Blog/BlogCategoryLanguageTabsTest.php` and `Blog/BlogTagsIndexTest.php`.
- [ ] Regression only: `ProductPolicyTest` and 0070's drift guard, unmodified. `ProductSkuUniquenessTest` is also regression only: only its raw-insert column list loses `name`, and no assertion changes.

### Deliberately NOT tested here
- SKU canonicalisation, uniqueness and its race catch (**D-7**). `ProductSkuUniquenessTest.php` is the regression check: only its raw-insert column list loses `name`, and no assertion changes.
- `HasTranslations`' generic mechanics, the default-language memo and `SetTranslation` in isolation (0070), and `StoreLanguage` CRUD (0068).
- Slug/SEO behaviour ([0076a](../0076a-product-slug-and-seo-fields-backend.md)) and non-default-language authoring ([0077](../0077-product-editor-language-tabs-ui.md)).

### Disposition of existing test files (re-derived against `HEAD`, 2026-11-06)

**Size.** Phase 2 counted **93** test files that reference products and **56** that touch `name`/`description`, using a broad pattern. A narrower pass for this table (files that import `App\Models\Product` or call its factory or actions) found 84 files. Re-checked line by line in the second Phase 2 pass, **30 distinct files break with certainty**, for one or more of the five mechanisms below: 20 under (1), 6 more that appear only under (2), 1 under (3), and 3 more that appear only under (5). Eight files the first draft listed under (1) use `'name'` only for `ProductAttributeType`, so they are not affected. `OrderModelTest` was verified to need no change, because its `product_name` comes from `OrderItemFactory`, which is migrated. Phase 3 should treat the table as a **floor**. Its first step after writing the migrations is a full-suite run that produces the authoritative list. Any file failing for a reason not listed here is a finding to record, not to patch silently.

| Mechanism | Files | Disposition |
| --- | --- | --- |
| **(1) `Product::factory()->create(['name' => …])` / `'description' => …`** (the column no longer exists, so a `QueryException` is raised) | Feature/Products: `IndexTest`, `IndexRenderingTest`, `IndexQueryTest`, `EditorTest`, `ScreenAuthorizationTest`, `RefusalLoggingTest`; Feature/Orders: `ShowLineItemsTest`, `PriceSnapshotTest`, `AddOrderItemTest`, `UpdateOrderItemQuantityTest`, `ShowRenderingTest`; Feature/Dashboard: `OverviewRenderingTest`, `OverviewSnapshotSecurityTest`, `OverviewPermissionTest`, `GetLowStockProductsTest` (its `lowStockProduct()` helper, line ~72, passes `['name' => 'Widget']`); Browser: `Products/IndexTest`, `Dashboard/DashboardJourneyTest`, `Orders/OrderDetailLineItemsTest`, `Components/WysiwygEditorTest`, `Components/WysiwygEditorOutputHtmlTest` (**20**) | **Mechanical**: move to `->named('…')`. No assertion changes, unless the file also matches (2). |
| **(2) Reads `$product->name` / `->description`, asserts the `products` columns, or writes them through `forceFill()`** (including `$product->name` passed back into `UpdateProduct`, which becomes a `TypeError` once the value is `null`) | `Feature/Products/ProductDescriptionSanitizationTest` (heaviest, about 20 column assertions), `CreateProductTest`, `UpdateProductTest`, `EditorTest`, `ProductAuthorizationTest`, `ProductVariantSkuUniquenessTest`, `ScreenAuthorizationTest`, `RefusalLoggingTest`, `Feature/Orders/PriceSnapshotTest`, `Feature/Dashboard/GetLowStockProductsTest` (line ~105, `forceFill(['name' => …])->save()`), `Browser/Components/WysiwygEditorTest`, `Browser/Products/EditorJourneyTest` | **Rewritten (partial)**: assertions move to `translated('name')` or to the default-language row, and `forceFill` name writes become translation-row writes. Inputs passed back to actions use the known fixture string, never `$product->name`. |
| **(3) Asserts the `#[Fillable]` list** | `Feature/Models/ProductTest` (line ~61) | **Rewritten**: the list becomes the six columns (**D-10**). The other column-type assertions are unchanged. |
| **(4) Dashboard locale tests** | `Feature/Dashboard/GetLowStockProductsTest` (also under (1) and (2)) | Its two `->todo()` locale tests stay `->todo()` (**Q-4(a)**, resolved). The `resolveName`-is-private seam test is unchanged. |
| **(5) Raw `DB::table('products')->insert([... 'name' => …])`** (MySQL 1054, unknown column) | `Feature/Products/ProductSkuUniquenessTest` (line ~99, the race insert), `ProductVariantSkuUniquenessTest` (line ~752), `ProductStockStatusTest` (line ~90), `ProductCategoryAssignmentTest` (line ~83, the FK test), `CreateProductTest` (line ~151, "omitting type throws") | **Mechanical**: drop `name` (and `description`) from the insert's column list. No other assertion changes, **except** that the two `toThrow` tests must assert the **specific driver error code**, so they cannot pass on a 1054: `ProductCategoryAssignmentTest` asserts **1452** (FK), and `CreateProductTest` asserts **1364/1048** (NOT NULL / no default). |
| **Unchanged by construction** | `Unit/Concerns/ProductValidationRulesTest` (gains one no-uniqueness guard), `ProductMediaTest`, `ProductBoundariesTest` (its `name` boundary goes through the action, so the input is unchanged and only an assertion that reads the column moves, if any), `Feature/Policies/ProductPolicyTest`, `Unit/ArchitectureTest`, the `Unit/Enums/Product*` tests, `Feature/Orders/OrderModelTest` (verified), and the eight files whose `'name'` belongs to `ProductAttributeType` (`GenerateProductVariantCombinationsTest`, `ProductVariantValidationBoundsTest`, `ProductVariantCombinationTest`, `UpdateProductVariantTest`, `VariantBuilderQueryTest`, `VariantBuilderGeneratorTest`, `Models/ProductVariantTest`, `Browser/Products/VariantBuilderTest`) | Regression only. In `ProductSkuUniquenessTest`, only the raw-insert column list loses `name`, and no assertion changes (**D-7**). |
| **Seeder** | `Feature/Seeders/DemoDataSeederTest` | Regression only: the factory's `afterCreating` keeps the demo products named. |

## Expected outcome

`products` no longer carries `name` or `description`. Every product's title and description live in `product_translations`, one row per store language, and existing rows are backfilled byte-for-byte into the store default. `translated('name')` and `translated('description')` each resolve the requested language, then the store default's, then `null`, **independently of each other**. A description reaches the column sanitized whatever write path it arrives by, including a bare `SetTranslation` call. `CreateProduct`/`UpdateProduct` keep their exact signatures and write the default language inside their existing transactions. The products list, product editor, order product picker, order-line snapshot and dashboard low-stock list behave as before, now reading the translation table. Deleting a product removes every language's content, and `StoreLanguage::translationUsageCount()` counts product translations. A fresh install and the full test suite migrate cleanly with an empty `store_languages` table.

## Acceptance criteria

- [ ] `product_translations` exists with a UUIDv7 primary key, two non-nullable UUID FKs, a `NOT NULL` `VARCHAR(255)` `name`, a nullable `MEDIUMTEXT` `description`, timestamps, and **no** slug/meta column.
- [ ] `php artisan db:table product_translations` reports **three** indexes: `primary`, `UNIQUE(product_id, store_language_id)`, and InnoDB's auto-created FK index on `store_language_id` (**D-11**). A different number is investigated, not accepted.
- [ ] No unique index on `name` exists in any position, and `productNameRules()` / `productDescriptionRules()` / `productSkuRules()` are byte-identical to `HEAD` (**D-4**, **D-7**).
- [ ] The FK to `products` cascades on delete, proven by a test. The FK to `store_languages` restricts and is defensive only (**D-19**).
- [ ] `BackfillProductTranslations` has the shipped 0074 shape: `assertCanBackfill()` refuses only when products exist **and** no default language does; `translationRowsFor()` is pure; `__invoke()` is idempotent (`whereNotExists`) and uses `chunkById(500)` (**D-13**).
- [ ] Every pre-existing product holds exactly one default-language translation, with `name` and `description` byte-identical (a `null` description stays `null`).
- [ ] `products.name` and `products.description` are dropped in a separate, later migration that **refuses to run** while any product lacks a translation row and calls no `dropUnique()`. Its `down()` restores both columns as nullable and is documented as non-inverse (**D-12**).
- [ ] A `description` written through **bare `SetTranslation`** is stored sanitized, and the actions still sanitize **before** `validate()` (**D-8**).
- [ ] `SetTranslation`, `HasTranslations`, `StoreLanguage`, `SanitizeProductDescription` and `config/html-sanitizer.php` are unmodified.
- [ ] `Product`'s `#[Fillable]` is exactly `['sku', 'product_category_id', 'type', 'status', 'price', 'stock']` (**D-10**).
- [ ] `CreateProduct`/`UpdateProduct` signatures are byte-identical to `HEAD`. Each calls `SetTranslation` **below** its `LogRefusedPrivilegedAttempt::authorize()` first statement and **inside** its existing `DB::transaction()`. `CreateProduct` keeps `attempts: 3` and `UpdateProduct` keeps no `attempts:`. A failure anywhere in the transaction leaves no partial write (**D-15**, **D-16**).
- [ ] Every live reader of the dropped columns at `HEAD` is migrated (`Products\Index`, `Products\Editor`, `Orders\Show`, `CreateOrder`, `AddOrderItem`, `OrderItemFactory`, `GetLowStockProducts`, `ProductFactory`), Two checks confirm it:
  - `grep -rnE "(product|target)->(name|description)" app database` finds nothing. The `target` alternative covers `Products\Index::confirmDelete()`'s `$target->name` (line 84).
  - `grep -nE "'name'|orderBy\('name'\)" app/Livewire/Products/Index.php app/Livewire/Products/Editor.php app/Livewire/Orders/Show.php app/Actions/Orders/CreateOrder.php app/Actions/Orders/AddOrderItem.php app/Actions/Dashboard/GetLowStockProducts.php database/factories/ProductFactory.php database/factories/OrderItemFactory.php` (the eight **D-20** files only) leaves no hit that reads a **product** column.

  An unscoped `orderBy('name')` grep would match about 20 unrelated models (**D-20**).
- [ ] `order_items.product_name` freezes the **store default language's** name through `Product::snapshotName()`, which reads the full `translations` relation. A product with no default-language row freezes its oldest non-blank translation, and a product with no usable name (zero translations or all-blank names) throws `LogicException` (**D-21**; Q-3 and Q-5 resolved as (a)).
- [ ] No `App\Livewire\Products\*` or `App\Livewire\Orders\*` class uses `SetTranslation`, enforced by `arch()` tests (**D-16**).
- [ ] No new permission, ability, policy or lang key. The permission catalog is unchanged at **43** (`RolePermissionSeederTest`; 42 is the Administrator grant), and `RolePermissionSeeder` is untouched (**D-16**).
- [ ] `config/store-languages.php` gains exactly one appended literal and survives `config:cache`.
- [ ] The full suite (`RefreshDatabase`, empty `store_languages` at migration time) is green.
- [ ] Optional manual check: `php artisan migrate:fresh --seed` against a **named scratch database** (for example `DB_DATABASE=scratch_0076`). It runs only with the **user's explicit authorization for that run**, per [docs/contracts/safety-rules.md](../../../docs/contracts/safety-rules.md), and never against the dev or testing database.

## Definition of Done
- [ ] Tests written and green: the **full suite unscoped** (`php artisan test`, or in directory chunks in a worktree session), because this story adds a model event and drops two columns that dozens of test files read
- [ ] `vendor/bin/pint --format agent` run **unscoped**
- [ ] **Larastan level 7 run and recorded** (`composer types:check`). A record that names two gates is a record of two gates.
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor). **Point the audit at** the D-8 second layer (a sanitizer in a model event) and at the bare `SetTranslation` entry point, which authorizes and validates nothing by design (0070 **D-9**) and which this story makes reachable for a second HTML-bearing column
- [ ] Documentation updated (docs-keeper): at minimum `docs/database/schema.md` (the first translation table with two translatable columns and a nullable `MEDIUMTEXT`), `docs/database/migrations.md` (the retrofit pair, whose drop migration has no index to drop), the Epic 5 decision digest entry, and the order snapshot language rule wherever `order_items.product_name` is documented
- [ ] Acceptance criteria met

## 4. Documented functional decisions

**D-1 — PRD's "product title" is `products.name`, and the column is not renamed.** There is no other candidate column. Renaming it to `title` would ripple through every reader for a vocabulary change with no functional content.

**D-2 — Column types track the parent's.** `name` is `VARCHAR(255) NOT NULL` and `description` is `MEDIUMTEXT NULL`, matching `2026_09_01_142007_create_products_table` exactly, because narrowing a column during a data move risks silent truncation. `name` stays `NOT NULL`, since a translation row without a name is not meaningfully a translation. 0024's **R-9** rule (a `MEDIUMTEXT` fattens the clustered index, so list queries never `SELECT *`) now applies to `product_translations` too; see **R-7**.

**D-3 — This story owns the first real-field per-field fallback test, and it is a wiring test.** `HasTranslations::translated()` already resolves per field (it reads `$field` from the requested row, then from the default row). What has never existed is a test against a real two-field table. **One fixture with one field present and one absent already distinguishes both wrong shapes**: a per-row resolver that picks the requested row returns `null` for the absent field, and one that picks the default row returns the wrong present field. So the test proves that `Product` is **wired** to the per-field resolver; it does not re-specify the resolver. *Correction:* an earlier draft claimed a two-field fixture "only approximates" the proof, which was wrong. The four-field fixture (name, description, slug, meta_title) moves to [0076a](../0076a-product-slug-and-seo-fields-backend.md), where it serves as a regression extension once those columns exist.

**D-4 — There is no name uniqueness to re-scope, and none is invented.** `productNameRules()` at `HEAD` is `['required', 'string', 'max:255']`, and `products` carries no unique on `name`. So there is no fold column, no `NormalizeForSearch`, no `->ignore()` question for `name`, and no `dropUnique()` in the drop migration. The absence gets a positive test (two products sharing a title both save). Nothing stops two products carrying the same French title, and nothing did before this story either.

**D-5 — Moved to [0076a](../0076a-product-slug-and-seo-fields-backend.md)** (the SEO field set and widths; Q-1 resolved there as (a)).

**D-6 — Moved to [0076a](../0076a-product-slug-and-seo-fields-backend.md)** (the slug: administrator-supplied, canonicalised, per-language unique).

**D-7 — `sku` is not translatable and does not move.** PRD Epic 5 lists SKU among the fields shown once, outside the tabs. `productSkuRules()`, the global `UNIQUE`, canonicalisation on write, and the 1062 catch are unchanged. In `ProductSkuUniquenessTest.php`, only the raw-insert column list (line ~99) loses `name`. No assertion changes, and any other diff to that file is a signal that something leaked across the boundary.

**D-8 — Description sanitization becomes two layers (confirmed at Phase 2).** 0024a's guarantee rested on *"the actions are the only way a description reaches the column"*. The retrofit falsifies that, because `SetTranslation` sanitizes nothing and must not be modified. Two layers are needed:
- **Layer 1:** the actions keep sanitizing **before** `validate()`, so `max:65535` measures the stored value (0024a **D-16** constraint 1). A model hook alone would break this ordering.
- **Layer 2:** `ProductTranslation::booted()` sanitizes on `saving` whenever `description` is dirty, which binds every writer, including a bare `SetTranslation` call and 0077's future action.

Sanitizing twice on the action path is safe only because 0024a's constraint 2 already requires and tests idempotence. This is a **pure addition**: `Product` never had a model-level hook to move. *Rejected alternatives:* the hook alone (it trades a security gap for a correctness gap); action-level only plus a "do not call `SetTranslation` for description" rule (a discipline-only guard on a shared primitive).

**D-9 — The backfill copies `name` and `description` byte-for-byte and never re-sanitizes.** It uses the query builder (no model event fires). Re-running the sanitizer during the migration could silently rewrite historical content if `config/html-sanitizer.php` has drifted since it was written, and a migration must not be an editorial event.

**D-10 — `products` keeps six fillable columns.** At `HEAD` the `#[Fillable]` list holds eight columns: `name`, `sku`, `product_category_id`, `type`, `status`, `price`, `stock` and `description`. `featured_media_id` is deliberately **not** fillable; only `SyncProductGallery` writes it. After the retrofit, **six** remain: `sku`, `product_category_id`, `type`, `status`, `price` and `stock`. Before the retrofit, `products` has **nine** non-key, non-timestamp columns. After it, **seven** remain: the six fillable columns plus `featured_media_id`. The siblings reach `#[Fillable([])]` and Products does not, which pattern-matching readers tend to get wrong, so this decision has its own test.

**D-11 — Three indexes on `product_translations` in this story.** They are `primary(id)`, `UNIQUE(product_id, store_language_id)` (which also serves the `product_id` FK, being leftmost there), and an auto-created index for the `store_language_id` FK (leftmost of nothing, so InnoDB creates one). [0076a](../0076a-product-slug-and-seo-fields-backend.md) adds `UNIQUE(store_language_id, slug)`. MySQL then silently drops the implicit FK index, so the count stays three but the composition changes; that story asserts its own count. Verify with `php artisan db:table`, never by reading the migration.

**D-12 — `down()` is knowingly not an inverse.** The values now live per language, so there is no single value to restore into a scalar column. Both columns come back **nullable** with no data, and no index is re-added because none existed. This matches 0072/0074.

**D-13 — The backfill is the shipped 0074 shape, copied rather than redesigned.** `App\Actions\Blog\BackfillBlogTagTranslations` already answers the problems an earlier draft tried to solve with its own pure-writer split:
- **(i)** `assertCanBackfill()` refuses **only** when there is data to carry and nowhere to carry it. A fresh install, and every `RefreshDatabase` run where `store_languages` is empty at migration time, therefore pass. An unconditional "no default language → throw" (the earlier draft's shape) fails the **entire** suite. That was Phase 2 finding 1.
- **(ii)** `translationRowsFor()` is the pure, directly unit-testable transform.
- **(iii)** `__invoke()` is idempotent through `whereNotExists`, so the drop migration's guard message ("re-run the backfill") is actionable, and it uses `chunkById(500)` so a large catalog is not loaded into memory.

The only differences from 0074 are the copied column set (`name` and nullable `description`, with no `normalized_name`) and the table names.

**D-14 — `scopeOrderByTranslatedName()` ships here, and it has two consumers in this story.** `Products\Index` is paginated and `Orders\Show::productOptions()` is limited. Both order by the column this story moves, and in both the ordering must happen **in SQL**: after pagination or a limit, a PHP-side sort (the `CompareTranslatedNames` pattern 0072 used for small unpaginated taxonomies) would sort the wrong set. The scope:
- left-joins the requested-language and default-language translation rows;
- **selects** `products.*` when the query has no columns set yet, and otherwise uses `addSelect`, so a caller's explicit, qualified `select([...])` is preserved rather than replaced;
- adds `COALESCE(NULLIF(requested.name, ''), default.name) AS translated_name`. The `NULLIF` matches `translated()`'s rule that `''` counts as absent (see the D-3 mirror test, which arranges a French `name = ''`);
- orders by `translated_name` ascending.

A product with no name in either language has `translated_name = NULL`. Under MySQL's ascending order, `NULL` sorts **first**, so such products head the list and render as `'—'`. This is accepted, and pinned by a test, rather than adding a `ISNULL()` sort key.

Callers qualify their own columns and tie-breakers with `products.` because of the join (`orderBy('products.id')`). The exact SQL is left to Phase 3. Fixed: the method name, the `?string $storeLanguageId = null` parameter (`null` means the default), the fallback semantics, the `NULLIF` rule, the select behaviour and the `translated_name` alias, which consumers read through `getAttribute('translated_name')`. Selecting the resolved name in the same join also avoids eager-loading whole translation rows into the list (**R-7**).

**D-15 — The translation write goes inside the existing transactions.** At `HEAD`, both actions already use `DB::transaction()`:
- **`CreateProduct`** uses `attempts: 3`. Its closure builds the row inside itself, so a retry is safe. The `SetTranslation` call goes inside that closure, after `forceCreate()`.
- **`UpdateProduct`** deliberately has **no** `attempts:`. Its closure mutates the outer `$product` instance, so a retry would run against a stale in-memory model (see the in-code comment and `docs/security/derived-column-invariants.md`). It also re-derives variant SKUs in the same transaction. The `SetTranslation` call goes inside that transaction and **`UpdateProduct` stays without `attempts:`**.

`SyncProductGallery`'s own inner transaction is already a savepoint inside the caller's at `HEAD`, so no new nesting is introduced. The wrapped code gains one write and one model event (the pure sanitizer), with no mail, event dispatch, cache flush or queued job.

**D-16 — Authorization: no new permission, ability or policy; the translation write sits below the existing self-authorization.** At `HEAD`, `CreateProduct` and `UpdateProduct` self-authorize through `LogRefusedPrivilegedAttempt::authorize()` as their **first statement** (0024 reversed its original D-15 at its split). The `SetTranslation` call goes **below** that statement, as in `CreateBlogTag`/`RenameBlogTag`. So every write path this story ships is authorized, and refusals are logged.
- `products` is already in `RolePermissionSeeder::MODULES`, so the permission catalog stays at **43** (`RolePermissionSeederTest.php:37`). The figure 42 at line 108 is the Administrator grant, not the catalog.
- Authoring content is not managing the language catalog, so no `store-languages.*` permission is required (0068 **D18**).
- There is no `ProductTranslationPolicy`.

**The residual is narrow and inherited:** a bare `SetTranslation` call authorizes and validates nothing (0070 **D-9**), so the second write path that **D-8** makes safe against XSS is **not** authorization-gated by itself. That is acceptable only because no caller outside a self-authorizing action exists. 0071 **D-13** states the rule that no `app/Livewire/**` class may import `SetTranslation`. *Correction (second Phase 2 pass):* `tests/Unit/ArchitectureTest.php` does **not** enforce it. The only guards are per component: `ProductCategories/LanguageTabsTest.php:144`, `Blog/BlogCategoryLanguageTabsTest.php:173`, and a string check in `Blog/BlogTagsIndexTest.php:379`. This story therefore adds `arch()` guards for the two namespaces it touches, `App\Livewire\Products` and `App\Livewire\Orders`. **Gate test line:** this story adds one test, through `UpdateProduct` (a denied user is refused and logged, and the translation is unchanged), and no direct `ProductPolicy` assertion, since `ProductPolicyTest` covers the policy.

**D-17 — UUIDv7 primary key** (ADR 0001 Amendment 1), as for every sibling translation table. Not reopened.

**D-18 — The action signatures do not change in this story.** `CreateProduct::__invoke(string $name, string $sku, ?string $productCategoryId, ?string $type, ?string $status, mixed $price, mixed $stock, ?string $featuredMediaId, array $orderedGalleryMediaIds, ?string $description = null)` and `UpdateProduct::__invoke(Product $product, string $name, …, ?string $description)` are byte-identical to `HEAD`; `$name` and `$description` narrow in meaning to "the default store language's". The widening for slug/meta is [0076a](../0076a-product-slug-and-seo-fields-backend.md)'s **D-18**. Because the signatures are unchanged, `Products\Editor::save()` and every action-calling test keep their call shape.

**D-19 — The `products` FK cascade is genuinely live.** `products` has no `SoftDeletes` and `DeleteProduct` performs an instance `->delete()`, so `cascadeOnDelete()` really fires, consistent with `product_media.product_id`. The `store_languages` FK is `restrictOnDelete()` and defensive only, because removal is an `is_active` flip (0068 **D5**).

**D-20 — Every live reader of the dropped columns is migrated in this story** (Phase 2 finding 3; precedent: 0074 commit `d8a6be2`, 0072 **D-14**). The readers at `HEAD`, verified by grep on 2026-11-06:
- `Livewire/Products/Index.php` (`select`/`orderBy('name')` around line 150–166, and `$deletingProductName` at line 84)
- `Livewire/Products/Editor.php` (lines 160 and 167)
- `Livewire/Orders/Show.php` (lines 304–308)
- `Actions/Orders/CreateOrder.php:245`
- `Actions/Orders/AddOrderItem.php:173`
- `database/factories/OrderItemFactory.php:85`
- `Actions/Dashboard/GetLowStockProducts.php:107`
- `database/factories/ProductFactory.php:39,45`

Read rules:
- **Admin lists** (products list, order picker) show the store-default name, or `'—'` when there is none (the 0075 Blog Tags list precedent).
- **The editor** reads the raw default-language row (the 0073 **D-6** precedent).
- **Order snapshots** follow **D-21**.
- **The dashboard** resolves the store default language (**Q-4** resolved as (a), 2026-11-06).

The minimal migration changes no screen's behaviour while the default language is unchanged. The earlier draft's claim that this story "ships no screen and touches no Livewire file" was false and is withdrawn, along with its R-1 hand-off.

**D-21 — `order_items.product_name` freezes the store default language's name (Q-3 resolved as (a), project owner, 2026-11-06).** The default language is the one guaranteed to exist for every product written through the actions, and an order denominated consistently is easier to reconcile. One seam serves all three writers (`CreateOrder`, `AddOrderItem`, `OrderItemFactory`): `Product::snapshotName(): string`. **Mechanism.** `scopeWithTranslationsFor()` always includes the default-language id (`HasTranslations.php`), so any loaded relation, filtered or not, contains the default row whenever one exists. Steps:
1. Read the loaded (or lazily loaded) `translations` relation. If it holds the default-language row with a **non-blank** `name`, return it. This is the common path: **no extra query**.
2. Otherwise, re-query `translations()` **unfiltered**, look first for the **default-language row with a non-blank `name`** (guards against a stale or custom-`with()` filtered relation), and otherwise return the oldest translation **with a non-blank `name`**, ordered by `created_at` then `id` (**Q-5** resolved as (a), 2026-11-06). `''` can be stored, as the D-3 mirror test does, and is skipped.
3. Otherwise, when the product has **no usable name** (zero translations, or only blank names; reachable only through `factory()->withoutTranslations()` or raw writes), throw `LogicException`. The user delegated this edge to the recommended option on 2026-11-06.

Tests pin both the filtered-relation case and the no-usable-name case.

The column is `NOT NULL`, so the seam never returns `null`. `CreateOrder` eager-loads `translations` (the full relation) on its bulk `findOrFail($productIds)` collection to avoid N+1 queries.

**D-22 — The factory follows the shipped `BlogTagFactory` shape.** `definition()` holds only parent columns. `configure()`'s `afterCreating` writes a default-language translation (creating a default `StoreLanguage` if none exists), so every `Product::factory()->create()` keeps producing a named product. `named(string $name, ?string $description = null)` supplies specific content, and `withoutTranslations()` creates a bare parent for the guard and fallback tests.

**Deviation from `BlogTagFactory`, required.** `BlogTagFactory::named()`/`withoutTranslations()` (lines 40–53) use `newInstance(['afterCreating' => new Collection([...])])`, which **replaces the whole `afterCreating` collection**. `ProductFactory::withGallery()` is itself an `afterCreating` callback, so copying that shape would make `withGallery()->named('X')` or `withGallery()->withoutTranslations()` silently drop the gallery. Instead, the two states change only the translation step. Mechanism (corrected in Phase 2 fourth pass, N-1): factory *state cannot carry the data*, because `Factory::newInstance()` only carries the constructor's fixed arguments, and a closure registered in `configure()` is bound to the instance `new()` built, so later instances can never read their own state. Instead the translation step is an **identifiable callback** (an invokable class or a tagged closure). `named()` rebuilds `afterCreating` by rejecting that callback and appending a new one with the given name/description; `withoutTranslations()` only rejects it; `withGallery()`'s callback survives in any order. Test: `Product::factory()->withGallery(2)->named('X')->create()` yields both the two-image gallery and the name `X`, and `withGallery(2)->withoutTranslations()` yields the gallery and zero translations. *Rejected:* an `afterMaking` shim that silently strips `name`/`description` out of `create([...])` attributes so the 20 mechanism-(1) files keep working unedited. It would hide that these are no longer columns, and a forged-attribute test could then pass vacuously.

## 5. Dependencies, risks, open technical questions

### Dependencies

All hard dependencies are **shipped** (`done/`): [0024](../done/0024-products-core-crud-backend.md) (products, model, actions, validation trait), [0024a](../done/0024a-product-description-html-sanitization.md) (`SanitizeProductDescription`), [0068](../done/0068-store-languages-catalog-backend.md) (`store_languages`, the usage registry), [0070](../done/0070-translatable-content-mechanism-product-categories-backend.md) (`HasTranslations`, `SetTranslation`, drift guard), [0074](../done/0074-translatable-content-retrofit-blog-tags-backend.md) (the backfill, guard and factory shapes copied here), and [0082](../done/0082-dashboard-home-overview-backend.md) (the `resolveName()` seam). So `depends_on` is empty.

**Dependents:** [0076a](../0076a-product-slug-and-seo-fields-backend.md) (slug/SEO; hard) and [0077](../0077-product-editor-language-tabs-ui.md) (language tabs; hard, through 0076a). **File-sharing sibling:** [0078](0078-translatable-content-retrofit-blog-posts-backend.md) also appends to `config/store-languages.php` and edits dashboard readers. No new Composer package.

### Risks

- **R-1 — Large test-rewrite surface** (see the disposition table). 30 distinct files break with certainty. Most of the work is mechanical: the 20 files under mechanism (1) and the 5 under mechanism (5). **Large but atomic** (INVEST note in the review log): dropping the columns forces every reader to move in the same change. Budget for it, and start Phase 3 with a full-suite run after the migrations to get the authoritative list.
- **R-2 — The sanitizer hook's container resolution is precedented but must be confirmed by execution.** `BlogTagTranslation`/`BlogCategoryTranslation` already call `app(NormalizeForSearch::class)` inside a `saving` hook. The only new element is that the resolved class wraps a third-party sanitizer. The bypass test proves it.
- **R-3 — Resolved by D-21** (Q-3 and Q-5 answered as (a) on 2026-11-06, and the zero-translation edge throws `LogicException`).
- **R-7 — A whole-row eager load drags `MEDIUMTEXT` into list renders.** `scopeWithTranslationsFor()` loads whole translation rows. For list screens this story therefore uses **D-14**'s join-selected `translated_name` rather than eager loading. `GetLowStockProducts` may eager-load, because its result set is small and bounded. Adding a column list to the shared scope would mean modifying `HasTranslations`, which is out of scope.
- **R-8 — The static default-language memo must be reset in tests** (`StoreLanguage::flushDefaultStoreLanguage()`) in the default-change scenario.
- **R-9 — N+1 trap:** `$model->translations()` (the method, which re-queries) versus `$model->translations` (the property, which respects eager loading).
- **R-10 — A stale relation after a write:** `SetTranslation` returns the translation row, not a refreshed parent. Callers that read back must `refresh()` or `load('translations')`.
- **R-11 — Ordering by a joined `COALESCE` changes `IndexQueryTest.php`'s pinned query shape.** That is a deliberate update, not a regression, and the review must check it against **D-14**.
- **R-13 — Process note retained from Phase 1:** facilitator-only claims were twice wrong in the original draft, and Phase 2 found more. Every claim in this rewrite was re-checked against `HEAD` on 2026-11-06.

Removed at the split: the old R-4 (sequencing against unshipped 0024, now moot), R-5 and R-14 (slug, moved to 0076a), R-6 (backfill upstreaming, moot because 0074 already shipped the shape), R-12 (provisional against unshipped stories, moot), and R-15 (nested transactions already execute at `HEAD`).

### Open questions for the product owner

**Q-1 — Moved to [0076a](../0076a-product-slug-and-seo-fields-backend.md)** (✅ resolved 2026-08-30 as (a)).

**Q-2 — Moved to [0076a](../0076a-product-slug-and-seo-fields-backend.md)** (✅ resolved 2026-08-30 as (a), per-language).

**Q-3 — Which store language does `order_items.product_name` freeze? ✅ RESOLVED 2026-11-06 as (a): the store default language.** Recorded as **D-21**.

**Q-4 — In which language does the dashboard's low-stock list resolve product names? ✅ RESOLVED 2026-11-06 as (a): the store default language.** Story 0082's **D-9** anticipated "the viewer's UI locale with default-language fallback", but no primitive maps an admin UI locale (`App\Enums\UiLocale`, `en`/`es`) to a store language (`store_languages.code`, `VARCHAR(10)`), and no shipped screen does so: every retrofitted admin list resolves the store default.
- **(a) The store default language, like every other admin list; the two `->todo()` locale tests stay `->todo()`, and the UI-locale mapping becomes a separate follow-up story _(recommended)_.** It is consistent with 0072–0075 and invents no mapping rule inside a retrofit.
- **(b) Map the viewer's `UiLocale` value to the store language with an equal `code`, falling back to the default, in `GetLowStockProducts` only, and activate the two `->todo()` tests.** This is what 0082 envisioned, but it introduces a locale-to-language rule on one screen that the rest of the dashboard does not follow.

**Q-5 — What does an order line freeze when the product has no default-language name? ✅ RESOLVED 2026-11-06 as (a): the oldest stored translation.** This can happen only after the store default changes to a language the product was never translated into. `order_items.product_name` is `NOT NULL`.
- **(a) The oldest stored translation's name, ordered by `created_at` then `id` _(recommended)_.** It is always a real, administrator-authored name, it never blocks order entry over a catalog translation gap, and it mirrors 0074's bounded fallback for a tag with no default-language name.
- **(b) Refuse the order line with a validation message** ("this product has no name in the store default language"). This is stricter, but it blocks order work until someone translates the catalog.

### Inherited open questions — listed, not resolved here
0070's **Q2** (per-language name uniqueness) is moot here, since Products has no name uniqueness. 0070's **Q3** (which story owns the tabs) is answered by [0077](../0077-product-editor-language-tabs-ui.md).

## 6. Technical tasks for later backlog creation

None of these are in scope for 0076.

1. **Slug and SEO fields**: [0076a](../0076a-product-slug-and-seo-fields-backend.md) (created at the split).
2. **Non-default-language authoring and the per-entity `SetProductTranslation` action**: [0077](../0077-product-editor-language-tabs-ui.md).
3. **A UI-locale to store-language mapping for dashboard readers**: a separate follow-up story, since **Q-4** was resolved as (a) on 2026-11-06. It would activate `GetLowStockProductsTest`'s two `->todo()` tests.
4. **An optional column list on `scopeWithTranslationsFor()`** (**R-7**), if a future list needs eager loading rather than a join.
5. **`ModuleRouteAccessTest.php` route coverage**, inherited and unrelated, untouched here.

## Provenance

**Phase 1 Three Amigos debate, 2026-08-29** (facilitator `product-owner`; `backend-expert`, `database-expert` and `backend-qa` all returned). The original debate's still-valid contributions are retained above: `database-expert`'s column types, cascade analysis and UUID argument (**D-2**, **D-17**, **D-19**); `backend-qa`'s sanitizer bypass test, never-re-sanitize backfill and no-uniqueness tripwire (**D-8**, **D-9**, **D-4**); and `backend-expert`'s observation that the hook is a pure addition (**D-8**). The original file also recorded a facilitator process failure (composing before two amigos had returned, then being contradicted on **D-16** and **D-18**); that lesson stands and is why this rewrite re-verified every claim against `HEAD`.

**Rewrite, 2026-11-06 (product-owner), after the Phase 2 rejection below.** The slug/SEO half was split into [0076a](../0076a-product-slug-and-seo-fields-backend.md). Every signature and line number above was verified against `HEAD` (`app/Models/Product.php`, `app/Actions/Products/{Create,Update}Product.php`, `app/Actions/Blog/BackfillBlogTagTranslations.php`, `database/migrations/2026_10_15_10000{0,1}_*`, `app/Concerns/{HasTranslations,ProductValidationRules}.php`, `app/Actions/Translations/SetTranslation.php`, and the eight reader sites in **D-20**). No application code, migration or test was written.

## Review log

### Phase 2 — INVEST validation (code-reviewer), 2026-11-06: **REJECTED**

Findings (all verified by the reviewer against `HEAD`):
1. The backfill threw when no default store language existed, even with zero products, which would fail the whole suite under `RefreshDatabase`. **Fixed:** **D-13** adopts the shipped 0074 shape.
2. The "neither the parent nor the mechanism exists" callout, R-4 and R-12 were false or stale, because 0024, 0068 and 0070 are shipped. **Fixed:** removed, and signatures re-verified.
3. The scope fence was false: dropping the columns breaks eight reader sites. **Fixed:** all are now under *Modify* (**D-20**), Q-3 is resolved as (a) (**D-21**), and the old R-1 and backlog items 1–2 are in scope.
4. The test disposition table was stale (93 files reference products, 56 touch name/description). **Fixed:** the table was re-derived by mechanism against `HEAD`.
5. The story was too large (slug/SEO). **Decision (project owner): split.** Slug/SEO moved to [0076a](../0076a-product-slug-and-seo-fields-backend.md).
6. Gherkin scenarios needed a non-default write path that this story does not ship. **Fixed:** they are restated against the default language through `UpdateProduct`, and the rest are pointed at 0077. No `SetProductTranslation` action is invented here.
7. The `#[Fillable]` and relation figures were wrong. **Fixed:** six fillable columns and five relations (**D-10**).
8. D-15 was stale (both actions already use transactions). **Fixed:** the write goes inside the existing transactions, and `UpdateProduct` keeps no `attempts:`.
9. and 10. Slug 1062 disambiguation and slug canonicalisation. **Moved** to 0076a.
11. The drop migration lacked the 0074 guard. **Fixed.**
12. Q-2 hedging. **Moved** to 0076a and collapsed there to (a).
13. D-16 was not re-derived. **Fixed:** the translation write sits below `LogRefusedPrivilegedAttempt::authorize()`, the Gate test line is decided, and the appsec pointer is narrowed to bare `SetTranslation`.
14. The D-3 rationale was wrong. **Fixed:** this is a wiring test, and one present plus one absent field suffices. The four-field fixture lives in 0076a.

**Decision:** split. The story was rewritten and is ready for a fresh Phase 2 run. It is not approved and not moved to `in-progress/`. **User answers, 2026-11-06:** 0076 **Q-4 (a)**, 0076 **Q-5 (a)** and 0076a **Q-3 (a)**. The user also delegated the zero-translation edge of `snapshotName()` to the recommended option (`LogicException`, see **D-21**).

### Phase 2 — INVEST validation (code-reviewer), second pass, 2026-11-06: **REJECTED** (local text fixes only; the split is confirmed sound)

Findings (all verified by the reviewer against `HEAD`), each followed by its fix:
1. "`ProductSkuUniquenessTest` passes unmodified" was false, because its line 99 raw-inserts `'name'`. **Fixed:** D-7, the disposition table, *Deliberately NOT tested* and the regression line now say that only the raw-insert column list loses `name` and no assertion changes.
2. The raw `DB::table('products')->insert(['name' => …])` breakage was missing. **Fixed:** it is now mechanism (5), listing its five files. The two `toThrow` tests must assert the specific error code: 1452 for the FK case, and 1364/1048 for the NOT NULL case.
3. The disposition counts were wrong: eight mechanism-(1) files use `'name'` only for `ProductAttributeType`, and `GetLowStockProductsTest` is not unchanged. **Fixed:** mechanism (1) now has 20 files (the reviewer's 19 plus `GetLowStockProductsTest`'s helper), `GetLowStockProductsTest` is under (1) and (2), `OrderModelTest` was verified to need no change, and the totals are corrected wherever they repeat (the table and **R-1**).
4. D-14's SQL details were incomplete. **Fixed:** the scope selects `products.*` when no columns are set and otherwise uses `addSelect`; `Orders\Show` uses a qualified `select`/`orderBy`; the `COALESCE` uses `NULLIF(…, '')`; and `NULL` names sort first.
5. The D-16 architecture-guard claim was false. **Fixed:** the claim is corrected, and two `arch()` tests (`App\Livewire\Products`, `App\Livewire\Orders`) are added.
6. The permission catalog is 43, not 42. **Fixed:** "unchanged at 43" (the 42 figure is the Administrator grant).
7. `snapshotName()` was underspecified. **Fixed:** it reads the full `translations` relation and throws `LogicException` on a product with zero translations, with tests for both.
8. Stale "open" wording. **Fixed:** section 6 item 3, **D-21**, the Orders snapshot test and the first review-log entry, and the user's answers are recorded.
9. The D-20 grep was too broad. **Fixed:** it is scoped to the eight D-20 files plus `product->(name|description)`.

Non-blocking findings, folded in:
- (i) The INVEST note now reads "large but atomic".
- (ii) The update-atomicity test injects its failure after `reDeriveVariantSkus()`. *(Superseded in the third pass: the listener route does not work, see below.)*
- (iii) The natural key is named `product_translations_product_language_unique`.
- (iv) The drop-guard test requires the migration file and calls `up()`.
- (v) `translated_name` is read through `getAttribute()`.
- (vi) `CreateOrder` eager-loads `translations`.
- (vii) The `migrate:fresh --seed` acceptance item needs per-run user authorization and a named scratch database.
- (viii) The Editor docblock at lines 41–42 is refreshed.

**Decision:** text fixed. Ready for a third Phase 2 run. Not approved, and not moved to `in-progress/`.

### Phase 2 — INVEST validation (code-reviewer), third pass, 2026-11-06: **REJECTED** (three small text fixes; the rest was re-verified at `HEAD`)

Blocking findings, each followed by its fix:
1. The update-atomicity test relied on a `ProductVariant::saved` listener that can never fire (`reDeriveVariantSkus()` writes with `DB::table()->update()`, `UpdateProduct.php:320`, and runs only when the SKU changes). **Fixed:** the test now changes the SKU so that a re-derived variant SKU collides with another product's SKU, reusing the F-6 setup in `ProductVariantSkuUniquenessTest` (lines ~662–716). It asserts that the translation row and the parent's `sku`/`price` are unchanged.
2. `Product` must implement `App\Concerns\Translatable` (`SetTranslation` type-hints `Model&Translatable`). **Fixed:** added to the `Product.php` Modify bullet and to recipe step 3.
3. Copying `BlogTagFactory`'s `newInstance(['afterCreating' => …])` would drop `withGallery()`. **Fixed:** D-22 and the factory bullet say the states change only the translation step, through factory state read by a single `configure()` callback. A `withGallery(2)->named('X')` test is added.

Non-blocking findings, folded in:
- The second-pass log now says 20 files in mechanism (1), not 19.
- D-21's step 2 now takes the oldest translation with a non-blank name, and step 3 explicitly names the no-usable-name case as the `LogicException` rule.
- The `snapshotName()` mechanism is stated: it uses the loaded default row when present, otherwise one unfiltered re-query, and never queries again in the common path.
- The acceptance grep gains `target->(name|description)`.
- A Tests-to-perform bullet is added for the no-store-language-permission scenario.
- The triage note on `RuntimeException` versus `ValidationException` (`ProductCategoryFactory.php:78`) is added.
- `GetLowStockProducts::resolveName()` is now typed `?string`.

**Decision:** text fixed. Ready for a fourth Phase 2 run. Not approved, and not moved to `in-progress/`.

**INVEST note (Small).** This story is **large but atomic**. Dropping `products.name`/`description` forces every reader to move in the same change. Splitting further would need an expand/contract migration with dual writes (keep the columns, write both stores, migrate the readers, then drop), which costs more than it saves for a single-tenant admin app with no zero-downtime requirement.

### Phase 2 — INVEST validation (code-reviewer), fourth pass, 2026-11-06: **APPROVED** (2026-11-06, code-reviewer)

No blocking findings. The story is ready for Phase 3 and moves to `in-progress/`.

#### Phase 3 starting notes (non-blocking; they bind the implementer)

- **N-1 (do FIRST): D-22's original factory mechanism cannot work in Laravel.** `Factory::newInstance()` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php` ~919-933) only carries the constructor's fixed arguments, so a custom property or flag is lost; and a callback registered in `configure()` is a closure bound to the instance `new()` built (~line 216), so later instances returned by `named()` reuse it and can never read their state. Followed literally, `named('X')` is silently ignored and the `withGallery(2)->named('X')` test fails (the test and the intent are correct). Workable mechanism: make the translation step an identifiable callback (invokable class or tagged closure); `named()` rebuilds `afterCreating` by rejecting that callback and appending a new one with the given name/description; `withoutTranslations()` only rejects it; `withGallery()`'s callback survives in any order. D-22's wording has been replaced accordingly.
- **N-2:** for the update-atomicity test use the FIXED collision (an existing product whose sku is `0009-M`; the in-transaction pre-check `UpdateProduct.php` ~290-311 throws `ValidationException` `parent_sku_change_collides` after `SetTranslation`), not the F-6 `DB::listen` race. Assert the caught exception's type and message as F-6 does (`ProductVariantSkuUniquenessTest.php` ~707-709). Change `price` in the call too, so "price unchanged" proves something.
- **N-3:** `snapshotName()` step 2: the unfiltered re-query must look for the default-language row with a non-blank name before falling back to the oldest non-blank translation (guards against a stale or custom-`with()` filtered relation). D-21 now says so.
- **N-4:** text aligned after the D-21 edit: the filtered-relation test now reads "never returns a wrong name because the relation was filtered" (product with only FR (older) and DE rows and no default row, loaded through `withTranslationsFor(DE)`, must freeze FR); the acceptance criterion and the zero-translations test now say "oldest non-blank" and `LogicException` for no usable name (includes all-blank names).
