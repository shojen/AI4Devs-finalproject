# [0070] Translatable content mechanism — backend, piloted on Product Categories

## Description
The **foundational** story of [PRD Epic 5, Layer 2](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization): the per-store-language translatable-content mechanism, built once and piloted on Product Categories. It ships three things — a `<entity>_translations` child-table convention, a shared read-side contract (`App\Concerns\HasTranslations`) implementing the PRD's *"a missing translation falls back to the default store language"* rule, and one cross-cutting write primitive (`App\Actions\Translations\SetTranslation`) — then applies all three to `product_categories`, retrofitting story [0023](../done/0023-product-categories-backend.md)'s single `name` column onto the new shape and appending the first real entry to [0068](../done/0068-store-languages-catalog-backend.md)'s `translation_relations` registry.

**This story's output is a recipe, not a feature.** Four sibling stories (**0072** Blog Categories, **0074** Blog Tags, **0076** Products, **0078** Blog Posts) apply this pattern to their own tables without re-deriving any of it. Every decision below is therefore written to be copied.

> **Read this before anything else: this is a retrofit against shipped code, not a design against specs.**
> Re-verified against the live tree on 2026-09-28 (Phase 2 rewrite). Every dependency is implemented and in `done/`: [0023](../done/0023-product-categories-backend.md) (`product_categories` with `name` + `unique('name')`, `ProductCategory`, `ProductCategoryValidationRules`, `Create`/`Rename`/`DeleteProductCategory`), [0024b](../done/0024b-product-category-in-use-delete-guard.md) (the in-use delete guard), [0025](../done/0025-product-categories-ui.md) (the Product Categories screen), [0027](../done/0027-products-list-and-editor-ui.md) (the Products list and editor, whose category dropdown reads the category name) and [0068](../done/0068-store-languages-catalog-backend.md) (`store_languages`, `StoreLanguage`, `config/store-languages.php` shipped with `translation_relations => []`).
>
> **Consequence: dropping `product_categories.name` breaks shipped code, and this story owns every one of those breakages** (**D-15**). The screens keep their current single-name behaviour, read through the new mechanism; the language tabs remain story [0071](../0071-product-categories-language-tabs-ui.md)'s. If any file cited below has changed by the time Phase 3 starts, re-derive rather than trust — the [deferred-findings failure mode](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-deferred-storys-findings-were-claims-about-a-tree-that-no-longer-existed-and-one-of-them-would-have-reopened-a-bug-in-this-log--2026-08-23) this project already records once, and which this story's own first draft repeated.

## Type
backend | includes database-expert: **yes** (one new table, two migrations including a data backfill, one retrofit of an existing table). Touches three existing Livewire components and one Blade view **only** to keep their current behaviour working after the column drop (**D-15**) — no new UI, no language tabs.

## 1. Refined user story

> **As** a store administrator managing a multilingual catalog,
> **I want** each Product Category's name to be stored and resolved per store language, falling back to the store default when a translation is missing,
> **so that** the catalog reads correctly in every language the store authors in, and a partially-translated catalog degrades gracefully instead of rendering blank or failing.

> **As** the engineer who will build stories 0072, 0074, 0076 and 0078,
> **I want** the translation table shape, the fallback resolution and the write primitive to exist as one shared, tested mechanism,
> **so that** adding translations to a fourth or fifth entity is a migration plus two lines of model wiring, not a fifth independent re-derivation of the same rule.

> **As** a catalog administrator already using the Product Categories screen and the product editor,
> **I want** both to keep listing and editing category names exactly as they do today after the storage change,
> **so that** the retrofit is invisible to me until the language tabs arrive.

**Scope fence — this story ships no new screen and no language tabs.** It *does* migrate the shipped consumers of `product_categories.name` (the Product Categories screen, the product editor's category dropdown, the Products list's eager load, the factory and the affected tests) to the new mechanism, reading and writing the **default store language** only (**D-15**). The per-language tabs belong to story [0071](../0071-product-categories-language-tabs-ui.md) (**Q3**, closed).

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and carries exactly one `When`, per [gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3.

```gherkin
Feature: Per-store-language content, piloted on Product Categories

  # --- Resolution and fallback: the PRD's own rule ---

  Scenario: A catalog administrator reads a category translated into the requested language
    Given a catalog administrator, with a product category named "Calzado" in Spanish and "Chaussures" in French
    When the category's French name is requested
    Then "Chaussures" is returned

  Scenario: A missing translation falls back to the default store language
    Given a catalog administrator, with a product category named "Calzado" in the default store language
      and no French translation
    When the category's French name is requested
    Then "Calzado" is returned, because the store default supplies the fallback

  Scenario: A category translated in neither the requested nor the default language resolves to nothing
    Given a catalog administrator, with a product category holding no translation in any store language
    When the category's French name is requested
    Then no name is returned and no error is raised

  Scenario: A field absent in the requested language falls back independently of its siblings
    Given a catalog administrator, with a translatable entry whose French title is present and whose French description is absent
    When the entry's French description is requested
    Then the default store language's description is returned
    And the French title is still the one returned for the title

  Scenario: A translation authored in a removed store language is still readable
    Given a catalog administrator, with a product category translated into French and French since removed as a store language
    When the category's French name is requested
    Then "Chaussures" is returned, because removal preserves stored content

  # --- The store default changing under an existing catalog ---

  Scenario: Promoting a new default store language re-points the fallback
    Given a catalog administrator, with a product category named only in Spanish and Spanish as the store default
    When a store administrator makes French the store default
    Then the category's fallback name resolves through French rather than Spanish

  Scenario: A catalog only translated into the previous default renders no name after a default change
    Given a catalog administrator, with a product category named only in Spanish and Spanish as the store default
    When a store administrator makes French the store default
    Then the category's French name resolves to nothing and no error is raised

  # --- Storing a translation: the unguarded primitive (D-9, D-12) ---
  # These two scenarios describe the storage primitive SetTranslation, called directly. It neither
  # authorizes nor validates by design. Guarded authoring of a NON-default language by an
  # administrator (authorization, blank refusal, "no row written") is story 0071's
  # SetProductCategoryTranslation, not this story's (D-17).

  Scenario: A translation in an additional language is stored alongside the existing one
    Given a catalog administrator, with a product category named "Calzado" in Spanish and French active as a store language
    When the storage primitive stores the French name "Chaussures" for the category
    Then the category holds both the Spanish and the French translation

  Scenario: Storing a translation again for the same language replaces it
    Given a catalog administrator, with a product category already named "Chaussures" in French
    When the storage primitive stores the French name "Souliers" for the category
    Then the French translation reads "Souliers" and no second French row exists

  Scenario: Creating a category stores its name in the default store language
    Given a catalog administrator with permission to create products
    When they create a product category named "Calzado"
    Then the category holds one translation, in the default store language

  # --- Name rules, now scoped per language (checked at the rule layer, D-17) ---
  # The widened ProductCategoryValidationRules take the store language as an argument. These
  # scenarios exercise the rules for a NON-default language directly; no write path is involved and
  # no claim is made about rows written.

  Scenario: A blank name is refused by the name rules in a non-default language
    Given a catalog administrator, with French active as a store language
    When a blank French name is checked against the category name rules
    Then the name is refused with a validation error

  Scenario: Two categories cannot share a name within one store language
    Given a catalog administrator, with a product category named "Chaussures" in French
    When the French name "Chaussures" is checked for another category against the name rules
    Then the name is refused with a validation error

  Scenario: The same name in two different store languages is permitted
    Given a catalog administrator, with a product category named "Chaussures" in Spanish
    When the French name "Chaussures" is checked for another category against the name rules
    Then the name is accepted, because uniqueness is scoped to one store language

  Scenario: A category keeps its own name when checked again in the same language
    Given a catalog administrator, with a product category named "Chaussures" in French
    When the French name "Chaussures" is checked for that same category against the name rules
    Then the name is accepted rather than refused as a duplicate

  # --- Authorization, on the two write actions this story ships (default language only) ---

  Scenario: An administrator without the products edit permission cannot rename a category
    Given a signed-in administrator who does not hold the products edit permission, and a category named "Calzado"
    When they attempt to rename the category to "Zapatería"
    Then the attempt is refused and the default-language name still reads "Calzado"

  Scenario: An administrator needs no store-language permission to rename a category
    Given a catalog administrator holding the products edit permission and no store language permissions
    When they rename a product category to "Zapatería"
    Then the default-language name reads "Zapatería", because authoring content is not managing the language catalog

  Scenario: Creating a category needs the create permission, not the edit permission
    Given a catalog administrator holding the products create permission and not the products edit permission
    When they create a product category named "Calzado"
    Then the category is created with its default-language name, even though creating writes a translation

  # --- The removal warning this story completes ---

  Scenario: Removing a language in use reports the content it affects
    Given a store administrator, with French active and holding product category translations
    When the usage count for French is requested
    Then it reports the number of French translations held

  # --- Migrating existing data (the backfill) ---

  Scenario: Existing categories are carried into the default store language
    Given a platform operator, with existing product categories named "Calzado" and "Bolsos" and Spanish as the store default
    When the database migrations are run
    Then each category holds exactly one Spanish translation carrying its original name unchanged

  Scenario: A fresh installation migrates with no categories and no store languages
    Given a platform operator installing on an empty database, with no product categories and no store languages
    When the database migrations are run
    Then the migrations complete without error and no translation row is written

  Scenario: Categories cannot be migrated without a default store language
    Given a platform operator, with existing product categories and no default store language
    When the database migrations are run
    Then the migration stops with an error explaining that a default store language is required
    And no translation row is written

  # --- Shipped screens keep working (default store language only) ---

  Scenario: The Product Categories screen lists categories by their default-language name
    Given a catalog administrator, with categories named "Zapatos" and "Bolsos" in the default store language
    When they open the Product Categories screen
    Then the categories are listed as "Bolsos" then "Zapatos"

  Scenario: A category with no default-language name is listed with an em dash
    Given a catalog administrator, with a category named only in a store language that is no longer the default
    When they open the Product Categories screen
    Then that category's name is shown as "—" and the screen renders without error

  Scenario: Renaming a category from the Product Categories screen changes its default-language name
    Given a catalog administrator with permission to edit products, and a category named "Calzado" in the default store language
    When they rename it to "Zapatería" from the Product Categories screen
    Then the category's default-language translation reads "Zapatería" and no other language's translation changes

  Scenario: The product editor offers categories by their default-language name
    Given a catalog administrator with permission to create products, with categories named "Zapatos" and "Bolsos" in the default store language
    When they open the product editor
    Then the category dropdown offers "Bolsos" then "Zapatos"
```

## Files to create/modify

### Create — the reusable mechanism

- **`app/Concerns/HasTranslations.php`** — the shared read-side contract, mixed into every translatable model. One implementation, five consumers.

  ```php
  namespace App\Concerns;

  use App\Models\StoreLanguage;
  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\HasMany;

  trait HasTranslations
  {
      /** Set by the consuming model; never inferred. */
      abstract protected function translationModel(): string;

      /** @return HasMany<Model, $this> */
      public function translations(): HasMany
      {
          return $this->hasMany($this->translationModel());
      }

      /**
       * Resolve one translatable field for a store language, falling back to the store
       * default when the field is absent there. Returns null when neither supplies it, and
       * also when no default store language exists at all — NEVER throws, because this runs
       * on a list-rendering path (D-6).
       */
      public function translated(string $field, ?string $storeLanguageId = null): ?string
      {
          $defaultId = StoreLanguage::defaultStoreLanguage()?->id;
          $requestedId = $storeLanguageId ?? $defaultId;

          // Reads the ALREADY-LOADED relation collection, never the relation method —
          // ->translations() would re-query per call and defeat eager loading (R-4).
          $value = $this->translations->firstWhere('store_language_id', $requestedId)?->{$field};

          if ($value !== null && $value !== '') {
              return $value;
          }

          return $this->translations->firstWhere('store_language_id', $defaultId)?->{$field};
      }

      /** Eager-load only the two languages a render actually needs, never every locale (R-4). */
      public function scopeWithTranslationsFor(Builder $query, ?string $storeLanguageId = null): void
      {
          $ids = array_unique(array_filter([$storeLanguageId, StoreLanguage::defaultStoreLanguage()?->id]));

          $query->with(['translations' => fn ($q) => $q->whereIn('store_language_id', $ids)]);
      }
  }
  ```

  **`translated()` is per-field, not per-row, and that is load-bearing** — see **D-5**. It is invisible on this pilot (a category has one field) and becomes the whole point on 0076/0078.

- **`app/Actions/Translations/SetTranslation.php`** — the single write primitive, in a **new cross-cutting-concern folder**, not a module area (**D-8**):

  ```php
  namespace App\Actions\Translations;

  final class SetTranslation
  {
      /** @param  array<string, string|null>  $attributes */
      public function __invoke(Model $translatable, StoreLanguage $language, array $attributes): Model
      {
          return $translatable->translations()->updateOrCreate(
              ['store_language_id' => $language->id],
              $attributes,
          );
      }
  }
  ```

  **This action deliberately does not authorize, and the reason is specific rather than an exemption** — see **D-9**. It is `updateOrCreate` on the `(entity, language)` natural key, which is what makes re-translating replace rather than duplicate.

### Create — the Product Categories pilot

- **`database/migrations/<timestamp>_create_product_category_translations_table.php`** — the child table plus its backfill, in one `up()`, following [`add_status_to_users_table`](../../../database/migrations/2026_08_11_175426_add_status_to_users_table.php)'s precedent of backfilling in the same migration that creates the thing needing backfilling:

  ```php
  public function up(): void
  {
      // Before any DDL: refuses only when categories exist and no default language does (D-16).
      app(BackfillProductCategoryTranslations::class)->assertCanBackfill();

      Schema::create('product_category_translations', function (Blueprint $table): void {
          $table->uuid('id')->primary();
          $table->foreignUuid('product_category_id')->constrained('product_categories')->cascadeOnDelete();
          $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();
          $table->string('name', 255);
          $table->timestamps();

          $table->unique(['product_category_id', 'store_language_id']);
          $table->unique(['store_language_id', 'name']);
      });

      app(BackfillProductCategoryTranslations::class)();
  }

  public function down(): void
  {
      Schema::dropIfExists('product_category_translations');
  }
  ```

  Four decisions in that block, each argued below: the UUIDv7 PK (**D-2**), the two *different* `onDelete` behaviours (**D-3**), the `unique(['store_language_id', 'name'])` per-language uniqueness backstop (**D-7**, kept because **Q2** is decided as *every language*), and the backfill living in an **extracted, testable class** rather than inline (**D-11**).

  `constrained('product_categories')` and `constrained('store_languages')` both pass the table name **explicitly**. For `product_category_id` this is required — Laravel would infer `product_categories` correctly here, but the habit is what [migrations.md](../../../docs/database/migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here) records. For `store_language_id` it is **defensive readability only**: `store_language_id` → `store_language` → `store_languages` resolves correctly unaided. 0068's backlog item 3 describes passing it as required "per migrations.md's rule"; that rule is about *inference failure* (`parent_id` → `parents`, `uploaded_by` → `uploadeds`), which does not apply here. Recorded so nobody reads it as load-bearing when it is not (**R-8**).

  ⚠️ **Correction, 2026-08-29 — this originally claimed four indexes; it is three.** No explicit `index()` on either FK column — `constrained()` supplies what InnoDB requires, but re-reading the migration above: `store_language_id` **is** the leftmost column of the *second* `unique(['store_language_id', 'name'])`, not merely the first. So both FK columns are covered as a leftmost prefix of one of the two `UNIQUE`s — `product_category_id` by the first, `store_language_id` by the second — and InnoDB auto-creates no separate FK index for either. Expect **three** real indexes: `primary` and the two `UNIQUE`s. Verify with `php artisan db:table product_category_translations` rather than by reading the migration, as the rule below already says — this file's own first draft is the reason that rule exists. Sibling stories 0072 and 0074, applying this same shape to their own tables, independently found three and flagged this file's "four" as self-contradictory; this correction reconciles it rather than leaving three files disagreeing about one pattern.

- **`database/migrations/<timestamp>_drop_name_from_product_categories_table.php`** — a **second, separate** migration, ordered strictly after the first (**D-4**):

  ```php
  public function up(): void
  {
      Schema::table('product_categories', function (Blueprint $table): void {
          $table->dropUnique(['name']);   // explicitly, before the column — migrations.md's own rule
          $table->dropColumn('name');
      });
  }

  public function down(): void
  {
      Schema::table('product_categories', function (Blueprint $table): void {
          $table->string('name')->nullable();
          $table->unique('name');
      });
  }
  ```

  **`down()` is deliberately not a perfect inverse, and that is stated rather than hidden**: it restores the column and index but cannot restore the values, which now live in the child table. It is `nullable()` for exactly that reason — a non-nullable restore would fail against any existing row. This is the one place in this story where the repo's `down()`-symmetry rule is knowingly bent; a rollback across this pair is data-lossy in the same way [ADR 0001's own `users` conversion set](../../../docs/decisions/0001-uuid-primary-keys.md#consequences) is, and for the same reason.

- **`app/Actions/ProductCategories/BackfillProductCategoryTranslations.php`** — the extracted backfill (**D-11**), fail-loud per [seeder-safety.md](../../../docs/security/seeder-safety.md#a-catalog-seeder-must-fail-loudly-rather-than-commit-a-structurally-invalid-catalog)'s posture — **but only when there is something to backfill** (**D-16**):

  ```php
  /**
   * Refuse ONLY when there is data to carry over and nowhere to carry it. Zero categories ->
   * no-op, whatever store_languages holds. This is the branch every fresh install and every
   * RefreshDatabase test run takes: store_languages is populated only by StoreLanguageSeeder,
   * never by a migration, so it is EMPTY at migration time there (D-16).
   */
  public function assertCanBackfill(): void
  {
      if (! DB::table('product_categories')->exists()) {
          return;
      }

      throw_if(
          ! DB::table('store_languages')->where('is_default', true)->exists(),
          new RuntimeException(
              'Cannot backfill product_category_translations: product categories exist but no default '
              .'store language does. Run StoreLanguageSeeder (php artisan db:seed --class=StoreLanguageSeeder) '
              .'and re-run the migration.',
          ),
      );
  }

  /**
   * The pure transform: one translation row per category, in the given language, name copied
   * byte-for-byte, id = (string) Str::uuid7(), created_at/updated_at = the given timestamp.
   * No database access — this is what the backfill tests exercise (D-11).
   *
   * @param  iterable<object{id: string, name: string}>  $categories
   * @return list<array{id: string, product_category_id: string, store_language_id: string, name: string, created_at: mixed, updated_at: mixed}>
   */
  public function translationRowsFor(iterable $categories, string $storeLanguageId, CarbonInterface $now): array;

  /** @return int the number of translation rows written (0 when there are no categories) */
  public function __invoke(): int
  {
      $this->assertCanBackfill();

      if (! DB::table('product_categories')->exists()) {
          return 0;
      }

      // Thin glue: read id+name from product_categories in chunks, insert
      // translationRowsFor(...) with the default language's id. Runs only inside the migration.
  }
  ```

  **Why the transform is split out:** by the time any test body runs, `RefreshDatabase` has applied **both** migrations, so `product_categories.name` no longer exists and pre-backfill rows **cannot be arranged** at all — re-adding the column inside a test would be DDL, which MySQL auto-commits, breaking the test's transaction isolation. So the part that can be wrong (one row each, the right language, byte-identical names) lives in `translationRowsFor()`, tested with plain arranged objects; `assertCanBackfill()` needs no `name` column and is tested against the real schema; the remaining read-and-insert glue is a few lines reviewed at Phase 5.

  Query builder, never the Eloquent model — a migration must not depend on a model whose shape a later story can change, matching `add_status_to_users_table`'s precedent of importing an enum for a *value* but never a model for control flow.

  **The precondition runs before the DDL, not after it.** MySQL commits `CREATE TABLE` implicitly, so a throw *after* `Schema::create` would leave an orphan `product_category_translations` table that makes the next `php artisan migrate` fail with "table already exists". The class therefore exposes the check separately — `public function assertCanBackfill(): void` (the "categories exist and no default language" throw above, and nothing else) — and the migration calls it as the **first** statement of `up()`, before `Schema::create`; `__invoke()` calls it too, so a direct call is equally safe. A refused run then leaves the schema exactly as it found it, and the operator's remedy is simply: seed a default store language, re-run `migrate`.

- **`app/Actions/ProductCategories/TranslateProductCategoryNameUniqueViolation.php`** — the extracted 1062 discrimination (**D-7 (ii)**), a stateless, container-resolved translator following the shipped precedent [`App\Actions\Products\TranslateProductVariantUniqueViolation`](../../../app/Actions/Products/TranslateProductVariantUniqueViolation.php):

  ```php
  namespace App\Actions\ProductCategories;

  class TranslateProductCategoryNameUniqueViolation
  {
      /**
       * Returns the `validation.unique` ValidationException ONLY for MySQL error 1062 on the
       * `product_category_translations_store_language_id_name_unique` index. Any other
       * QueryException (an FK violation such as 1452, a 1062 on the
       * (product_category_id, store_language_id) index, no errorInfo at all) is rethrown unchanged.
       *
       * $errorKey is the field the caller reports on. It is derived by the caller from its own
       * arguments (0070's two actions pass 'name'; 0071's SetProductCategoryTranslation passes
       * "names.{$language->id}"). It never decides WHETHER the violation is a name collision.
       */
      public function __invoke(QueryException $e, string $errorKey = 'name'): ValidationException;
  }
  ```

  *Why extracted:* the FK-violation branch cannot be reached through `Create`/`Rename`, which always pass the id of an existing default language. A pure translator makes every branch testable with constructed exceptions, with no database. The name-index branch is also covered end to end, through the adapted race tests of the two actions.

- **`app/Models/ProductCategoryTranslation.php`** — `use HasFactory, HasUuids;`, `#[Fillable(['name'])]`. `name` **is** fillable here (unlike `Media`'s server-derived columns) because it is genuinely form-supplied; `product_category_id` and `store_language_id` are omitted and written only by `SetTranslation`'s explicit key list. `belongsTo` both parents.
- **`database/factories/ProductCategoryTranslationFactory.php`** — with a `forLanguage(StoreLanguage $language)` state, so no test has to hand-build the FK pair.
- **`app/Actions/Translations/`** — the folder itself is new (**D-8**).

### Modify — the Product Categories pilot

- **`app/Models/ProductCategory.php`** (0023's) — `use HasTranslations;` plus the one thing the trait cannot infer:

  ```php
  protected function translationModel(): string
  {
      return ProductCategoryTranslation::class;
  }
  ```

  `#[Fillable(['name'])]` becomes **`#[Fillable([])]`** — the parent row now has no mass-assignable column at all, the same zero-fillable shape 0068's `StoreLanguage` reaches by a different route.

- **`app/Models/StoreLanguage.php`** (0068's) — gains the memoised default resolver (**D-10**) and its flush:

  ```php
  private static ?self $defaultCache = null;

  /** Separate from the cache, so a "no default exists" answer is memoised too (D-10). */
  private static bool $defaultResolved = false;

  /** Null-safe: returns null when no default row exists (a not-yet-seeded install) — D-6. */
  public static function defaultStoreLanguage(): ?self
  {
      if (! self::$defaultResolved) {
          self::$defaultCache = static::query()->where('is_default', true)->first();
          self::$defaultResolved = true;
      }

      return self::$defaultCache;
  }

  public static function flushDefaultStoreLanguage(): void
  {
      self::$defaultCache = null;
      self::$defaultResolved = false;
  }

  protected static function booted(): void
  {
      // SetDefaultStoreLanguage / AddStoreLanguage / RemoveStoreLanguage all write through
      // $model->save() (verified), so this re-points the fallback within the same request
      // without editing any 0068 action.
      static::saved(fn () => self::flushDefaultStoreLanguage());
  }
  ```

  **The memo lives on `StoreLanguage`, never inside `HasTranslations`** — PHP gives each consuming class its own copy of a trait's static properties, so a trait-resident memo would silently become one cache per translatable model, each independently querying for the same global row. **Nullable, not `firstOrFail()`** — the read side must render an empty catalog on an unseeded database (every `RefreshDatabase` test, including every existing Products-editor test, renders the category dropdown with no store language present); the **write** side fails loud instead, see the two actions below.

  **The "no default" answer is memoised too, deliberately** (round-2 Phase 2 finding 3). A plain `??=` would store nothing when the query returns `null`, so on the no-default read path — legitimate per **D-6**, and the state of every `RefreshDatabase` render — each `translated()` call would re-query: one query per row. The separate `$defaultResolved` flag closes that. It is safe because every model-path write to `store_languages` fires `saved` and flushes: 0068's three actions, `StoreLanguageSeeder` (`forceCreate`) and `StoreLanguageFactory` all go through Eloquent (verified 2026-09-28). Only a raw `DB::table()` write bypasses the hook, and only tests do that; such a test must call `flushDefaultStoreLanguage()` itself (see the "no default store language row at all" test).

- **`tests/Pest.php`** — the flush is chained onto the **existing** binding, exactly:

  ```php
  use App\Models\StoreLanguage;

  pest()->extend(TestCase::class)
      ->use(RefreshDatabase::class)
      ->beforeEach(fn () => StoreLanguage::flushDefaultStoreLanguage())
      ->in('Feature', 'Browser');
  ```

  (`Pest\PendingCalls\UsesCall::beforeEach(Closure)` exists in the installed Pest, verified 2026-09-28.) *Why it is required, not optional:* `RefreshDatabase` rolls back rows without firing model events, so without it the memo carries test N's default-language row — a row that no longer exists — into test N+1 (**R-6**). *Why `Unit` is not in the list:* `tests/Unit` has no `TestCase`/`RefreshDatabase` binding and no database, so no Unit test may call `defaultStoreLanguage()` or `translated()`. Every test that does is a Feature test (see Tests to perform). That rule is what makes the Feature/Browser-only flush sufficient.

- **`app/Concerns/ProductCategoryValidationRules.php`** (0023's) — the **exact** widened signatures, fixed here so 0071 and this story cannot diverge (0071's call site `nameRules($this->normalizeForSearch, $language->id, $productCategory->id)` already matches):

  ```php
  protected function productCategoryRules(NormalizeForSearch $normalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null): array;
  protected function nameRules(NormalizeForSearch $normalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null): array;
  protected function uniqueNormalisedName(NormalizeForSearch $normalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null): Closure;
  ```

  The new parameter is inserted **second, before** the existing optional id (it is required, so it cannot follow an optional one). `uniqueNormalisedName()` now plucks `name` from `product_category_translations` `WHERE store_language_id = ?`, excluding rows whose `product_category_id` is the given id. The two-layer scheme 0023's **D-4** established is unchanged in kind — normalised PHP comparison through the shared `App\Actions\NormalizeForSearch` as the primary guard, DB unique index as the backstop — only re-scoped from global to per-language. `ShippingZoneValidationRules` and `ShippingRateValidationRules` only mention this signature in docblocks; they are not callers and stay untouched.

- **`app/Actions/ProductCategories/CreateProductCategory.php`** and **`RenameProductCategory.php`** (0023's, already self-authorizing through `LogRefusedPrivilegedAttempt::authorize()` since 0025) — **signatures unchanged** (`__invoke(string $name)` / `__invoke(ProductCategory $c, string $name)`), meaning narrowed to *"the default store language's name"* (**D-12**). Each constructor-injects `SetTranslation` and `TranslateProductCategoryNameUniqueViolation`, per [code-style.md](../../../docs/conventions/code-style.md#exception-an-actions-own-dependency-is-constructor-injected-when-the-method-signature-is-a-public-contract)'s documented exception. After authorizing, each resolves `StoreLanguage::defaultStoreLanguage()` and, when it is `null`, throws a legible `RuntimeException` (*"no default store language is configured"*) before validating — the write-side fail-loud counterpart of the null-safe read. Validation passes the default language's id to `productCategoryRules()`. `CreateProductCategory` writes the parent row (`ProductCategory::create()` with no attributes) and its default-language translation in **one transaction**. The existing `23000` catch becomes `catch (QueryException $e) { throw ($this->translateNameUniqueViolation)($e); }`. The translator maps only a 1062 on the `product_category_translations_store_language_id_name_unique` index to the same `name`-keyed `validation.unique` message as today, and rethrows anything else — an FK violation, or a 1062 on the `(product_category_id, store_language_id)` index (**D-7 (ii)**).
- **`app/Actions/ProductCategories/DeleteProductCategory.php`** — **untouched.** `cascadeOnDelete()` removes translations with the parent. Its 0024b in-use guard is shipped, and its own comment already anticipates this story's cascading FK: its 1451 catch and its drift-guard test match only **restricting** FKs against `product_categories`, so the new cascading one correctly trips neither.

### Modify — shipped consumers of `product_categories.name` (D-15)

Every one of these reads or writes the dropped column today (verified by grep on 2026-09-28). Each is migrated to read the **default store language** through the mechanism; none gains language tabs.

- **`app/Livewire/ProductCategories/Index.php`** (0025's) — `loadProductCategories()` replaces `->orderBy('name')->orderBy('id')` with 0071 **D-12**'s query (`withCount('products')->withTranslationsFor()->get()->sortBy(fn ($c) => $c->translated('name'))->values()`), plus the `id` tie-break 0025 already guarantees — sort by `[translated('name'), id]`, nulls last, and the row's `'name' => $category->translated('name')` becomes `?string` in the `@var` row shape. `openEditModal()` prefills `$this->name = $target->translated('name') ?? ''` (no argument = the default language, so no cross-language fallback can leak into the edit field — 0071 **D-6**'s hazard does not arise with a single default-language field). `confirmDelete()` reads `$target->translated('name') ?? '—'`. `save()` passes the default language's id to `productCategoryRules()`; when no default exists, `save()` surfaces the actions' refusal (it does not pre-empt it).
- **`resources/views/livewire/product-categories.blade.php`** (0025's) — the name cell and the four `aria-label`s render `$category['name'] ?? '—'` (the em-dash convention `users.blade.php` / `roles.blade.php` already use). No other markup change.
- **`app/Livewire/Products/Editor.php`** (0027's) — `categoryOptions()` becomes `ProductCategory::query()->withTranslationsFor()->get()`, sorted in PHP by `translated('name')` then `id`, mapped to `['id' => …, 'name' => $category->translated('name') ?? '—']`. The `list<array{id: string, name: string}>` shape is unchanged, so `products/editor.blade.php` is untouched.
- **`app/Livewire/Products/Index.php`** (0027's) — the eager load `'category:id,name'` becomes `'category:id'`. Verified: the `->through()` row mapping and `products.blade.php` never read `category`, so the column list is the only thing that breaks; removing the eager load entirely is **out of scope** (it would change `IndexQueryTest`'s pinned query shape for no user-visible gain).
- **`database/factories/ProductCategoryFactory.php`** (0023's) — `definition()` returns `[]`; an `afterCreating` hook writes one default-language translation with `fake()->unique()->words(2, true)`, **provisioning a default `StoreLanguage` via `StoreLanguage::factory()->default()` when none exists** (so the ~40 existing `ProductCategory::factory()->create()` call sites keep working unedited). Two states: `named(string $name)` (the default-language name to write instead of a fake one) and `withoutTranslations()`. **`withoutTranslations()` creates the parent row and nothing else: no translation, and no default `StoreLanguage` provisioned either** (round-2 Phase 2 finding 5). Two tests depend on this. The D-6 "no translation anywhere" test needs the missing translation. The backfill-refusal test needs "categories exist and no default store language exists", which it cannot arrange if the factory quietly creates a default. How the state skips the `afterCreating` hook is Phase 3's choice (for example, a state flag that the hook checks, or clearing the hook collection through `newInstance(['afterCreating' => collect()])`); the behaviour is pinned by a factory test below.
- **Existing tests that write or read the column** — every `ProductCategory::factory()->create(['name' => …])` becomes `->named(…)->create()`, every `$category->name` / `fresh()->name` read becomes `translated('name')`, and every `assertDatabaseHas('product_categories', ['name' => …])` targets `product_category_translations`. Known set, verified by grep: `tests/Feature/ProductCategories/{Create,Rename,Delete}ProductCategoryTest.php`, `IndexTest.php`, `IndexRenderingTest.php`, `RefusalLoggingTest.php`, `tests/Unit/Concerns/ProductCategoryValidationRulesTest.php` (its anonymous-class harness takes the widened signature; it stays DB-free), `tests/Feature/Models/ProductCategoryTest.php`, `tests/Feature/Products/{EditorTest,EditorRenderingTest,IndexQueryTest}.php` (the last one's header comment only), `tests/Browser/ProductCategoriesIndexTest.php` and `tests/Browser/Products/EditorJourneyTest.php`. **The authoritative list is whatever the unscoped suite reports red after the drop migration lands** — the code-reviewer counted ~26 files touching the column; Phase 3 fixes them all, because the [full-suite gate](../../../docs/contracts/testing-and-parallel-agents.md) makes this story un-closable otherwise. Test **intent** is preserved; only the fixture/read mechanics change.
- **`config/store-languages.php`** (0068's) — **the entire production diff is one appended array literal**, which is 0068's **D8** contract:

  ```php
  'translation_relations' => [
      ['table' => 'product_category_translations', 'column' => 'store_language_id'],
  ],
  ```

  No closures, survives `config:cache`. No edit to `RemoveStoreLanguage`, to `StoreLanguage::translationUsageCount()`, or to any component — that is the property being verified, not merely asserted.

- **`lang/en/products.php`** / **`lang/es/products.php`** — the validation `attributes` leaf and any refusal copy the changed rules reference, key-for-key identical.

### Deliberately not touched

- **`database/seeders/RolePermissionSeeder.php`** — no new permission, no new module slug. The catalog stays at **43** permissions (`orders.refund` was added by story 0051), `Administrator` at 42 of 43. Translating a category authorizes against the *same* `products.*` abilities 0023's **D-8** already established (**D-13**).
- **`app/Policies/ProductCategoryPolicy.php`** — no new ability. There is deliberately **no `TranslationPolicy`** (**D-13**).
- **`app/Actions/StoreLanguages/*`**, **`app/Models/LocaleSetting.php`**, **`app/Http/Middleware/SetUiLocale.php`** — all 0068/0066 territory. This story adds the resolver, its flush and a `saved` hook to `StoreLanguage` and touches nothing else in that domain.
- **Any Livewire component or view other than the four listed under D-15**, **`resources/views/livewire/products/editor.blade.php`**, **`config/modules.php`**, **`routes/**`** — no new screen, no sidebar entry, no route. The language tabs are 0071's.
- **Blog screens (0060 Blog Tags, 0062 Blog Categories)** — they order by their **own** tables' `name` columns, which this story does not touch; their retrofits are 0074's and 0072's.

## The public contract siblings 0072 / 0074 / 0076 / 0078 consume

```php
// App\Concerns\HasTranslations — mixed into every translatable model
public function translations(): HasMany;
public function translated(string $field, ?string $storeLanguageId = null): ?string;   // null-safe, never throws
public function scopeWithTranslationsFor(Builder $query, ?string $storeLanguageId = null): void;
abstract protected function translationModel(): string;                                 // the one thing each model declares

// App\Models\StoreLanguage — added methods
public static function defaultStoreLanguage(): ?self;   // memoised; null when no default row exists (read side never throws)
public static function flushDefaultStoreLanguage(): void;   // called by StoreLanguage's own `saved` hook and by tests/Pest.php

// App\Actions\Translations\SetTranslation
public function __invoke(Model $translatable, StoreLanguage $language, array $attributes): Model;
```

**The copyable recipe, in the order a sibling performs it:**

1. Create `<entity>_translations`: UUIDv7 PK; `foreignUuid('<entity>_id')->constrained('<entities>')->cascadeOnDelete()`; `foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete()`; one column per translatable field, sized to match the parent's original; `unique(['<entity>_id', 'store_language_id'])`; optionally `unique(['store_language_id', <the field that was globally unique>])`; `timestamps()`. No explicit `index()` on either FK.
2. Create `<Entity>Translation` — `HasFactory, HasUuids`, `#[Fillable([...translatable fields only])]`.
3. On the parent: `use HasTranslations;` plus `translationModel()`. Drop the now-translated columns from `#[Fillable]`.
4. Re-scope the entity's existing `<Noun>ValidationRules` uniqueness by `store_language_id`, still folded through the shared `NormalizeForSearch`, and test it at the rule layer with a non-default language id (**D-17**). If the entity has a per-language `UNIQUE`, move its write actions' `23000` catch into a `Translate<Entity>NameUniqueViolation`-shaped translator, unit-tested with constructed exceptions (**D-7 (ii)**).
5. Reuse `SetTranslation` **unmodified** — a sibling is a consumer, never a re-implementer.
6. Append **exactly one** `{table, column}` literal to `config/store-languages.php`.
7. If the parent table already holds rows and a shipped column is dropped: backfill with a precondition that refuses **only** when rows exist and no default language does (**D-16**), and migrate every shipped consumer of the dropped column in the **same** story (**D-15**) — found by grep, confirmed by the unscoped suite.

**What a sibling must NOT re-derive:** the per-field fallback chain (**D-5**), the default-language memo (**D-10**), the authorization shape (**D-13**), the `SetTranslation` primitive, and the drift-guard test (**D-14**) — which picks a new entry up for free.

**What does NOT generalise, and is 0070's alone:** the Product Categories backfill, its tests, and the specific consumer list under **D-15**. Every sibling's parent table (`blog_categories`, `blog_tags`, `products`, `blog_posts`) is shipped too, so each sibling owns the **same kind** of work for its own table (step 7) — the shape is copied, the file list is not.

## Tests to perform — 3. QA test cases / validation scenarios

Feature and Unit for everything new. **Suite placement rule:** anything that calls `StoreLanguage::defaultStoreLanguage()`, `translated()` or `withTranslationsFor()`, or reads any table, is a **Feature** test. `tests/Unit` is bound to neither `TestCase` nor `RefreshDatabase` (`tests/Pest.php` binds only `Feature` and `Browser`). The only Unit tests here are DB-free: the `translationRowsFor()` transform, the unique-violation translator (with `uses(TestCase::class)` for `trans()`, following `tests/Unit/Models/StoreLanguageFixtureTest.php`) and the existing validation-rules harness. **No new browser test** — this story adds no screen; the two existing browser files that touch category names (`tests/Browser/ProductCategoriesIndexTest.php`, `tests/Browser/Products/EditorJourneyTest.php`) are adapted under **D-15** and must stay green.

### Fallback resolution (the highest-value block) — `tests/Feature/Models/HasTranslationsTest.php`
Feature, not Unit: every case resolves the default through `StoreLanguage::defaultStoreLanguage()`, which queries `store_languages` (round-2 Phase 2 finding 2).
- [ ] Feature: translation present for the requested language → that value returned.
- [ ] Feature: missing for requested, present for default → **the default's value specifically**, not merely "non-null". *Why the distinction matters:* a non-null assertion passes even if the resolver silently picked the first translation alphabetically rather than the actual default.
- [ ] Feature: missing for both → returns `null` and **does not throw** (**D-6**).
- [ ] Feature: **per-field** fallback — a row present in the requested language with one field populated and another `null` resolves each field independently. *Risk if missing:* this is invisible on the single-field pilot and is the whole mechanism on 0076/0078; it must be pinned here, on the story that owns the contract, using a two-field fixture even though `ProductCategory` has one field. **Fixture shape, pinned so it needs no DDL:** the store languages are real rows (the default must resolve through the memo), but the two-field translations are unsaved model instances with `store_language_id`, `title` and `description` set via `forceFill()`, attached with `$model->setRelation('translations', new Collection([...]))`. `translated()` reads the loaded relation (**R-4**), so no `*_translations` table with two columns is created. Creating such a table inside a test would be DDL, which MySQL commits implicitly, breaking `RefreshDatabase`'s isolation.
- [ ] Feature: the requested language is **inactive** → the translation still resolves (**D-6**). *Risk if missing:* a future defensive `is_active` filter would silently defeat 0068's **D5**, whose entire purpose is that removal preserves readable content.
- [ ] Feature: the default store language is **changed** under a catalog translated only into the old default → resolution re-points to the new default and the old-default-only category resolves to `null` without error. *Why:* this is a normal operation, not data corruption — see **R-2**.
- [ ] Feature: **no default store language row at all**, forced with `DB::table('store_languages')->update(['is_default' => false])` to bypass 0068's actions, **followed immediately by an explicit `StoreLanguage::flushDefaultStoreLanguage()`** (round-2 Phase 2 finding 4). A raw query-builder update fires no `saved` event, so without the flush the memo still holds the default the factory provisioned and the test would silently pass through a stale default. Then: the **read** side returns `null` without throwing (`translated()`, `withTranslationsFor()`, the Product Categories screen and the product editor all render), and the **write** side (`CreateProductCategory`, `RenameProductCategory`) refuses with the legible "no default store language" `RuntimeException`, writing nothing — never an unhandled null-property error.
- [ ] Feature: the default memo is **flushed on write** — promote French to default through 0068's `SetDefaultStoreLanguage` and, **in the same test without any manual flush**, `translated('name')` resolves through French. *Why:* proves the `saved` hook, not the Pest `beforeEach`, is what keeps a single request coherent.
- [ ] Feature: the memo does **not** leak across tests — two consecutive tests each create their own default language and each resolves its own (the `beforeEach` flush, **R-6**).
- [ ] Feature: the "no default" answer is memoised — with no store language at all, rendering N categories through `withTranslationsFor()` + `translated('name')` issues **one** `store_languages` query, not N (asserted by counting `store_languages` queries via `DB::listen`). Creating a default through `StoreLanguage::factory()->default()->create()` afterwards is picked up with no manual flush, because `saved` flushes (**D-10**).

### The write primitive — `tests/Feature/Translations/SetTranslationTest.php` (direct call, D-12)
- [ ] Feature: storing a French name for a category that holds a Spanish one leaves **both** rows, each with its own value.
- [ ] Feature: storing a French name twice updates the one French row — asserted as the row's new value **and** a row count of one for `(category, French)`. This is what makes a re-translation replace rather than duplicate.
- [ ] **Not asserted here, by design:** any refusal. `SetTranslation` neither authorizes (**D-9**) nor validates. Guarded non-default authoring is 0071's (**D-17**).

### Name rules, re-scoped per language — rule layer (D-17)
**Where:** `tests/Feature/ProductCategories/ProductCategoryNameRulesPerLanguageTest.php`. It is Feature, because `uniqueNormalisedName()` reads `product_category_translations`. It uses the same anonymous-class harness shape as `tests/Unit/Concerns/ProductCategoryValidationRulesTest.php`, which exposes the protected trait methods, and it runs `Validator::make(['name' => $candidate], $harness->exposedProductCategoryRules(app(NormalizeForSearch::class), $storeLanguageId, $ignoreCategoryId))`. **Every case passes a NON-default `$storeLanguageId`** (French, with Spanish as the default). Rows are arranged through `ProductCategoryTranslationFactory::forLanguage()`. Each case asserts `passes()`/`fails()` and the failing key; **none claims anything about rows written**. That claim belongs to a guarded write path, which is 0071's.
- [ ] Feature: two categories, same normalised name, **same** language → fails on `name`.
- [ ] Feature: two categories, **identical** name, **different** languages → **passes**. *Why this exact pairing:* it is the only test that proves the scope moved from global to per-language. It must use the byte-identical string in both languages — a fixture that also differs in case or whitespace would pass under a rule that ignores language scoping entirely, because the incidental difference would be doing the work.
- [ ] Feature: the category's own name, re-checked in the same language with its own id as `$ignoreCategoryId` → passes.
- [ ] Feature: case-only and accent-only duplicates **within** one language still collide (`"Nino"` / `"Niño"`), proving the comparison still routes through `NormalizeForSearch`.
- [ ] Feature: the same accent-folded pair in **different** languages does **not** collide.
- [ ] Feature: an empty string and a 256-character string fail on `name` for the non-default language id. *Why:* requiredness and length are not language-scoped, and this shows that holds for a non-default language, the one no write path in this story reaches. Whitespace-only input is trimmed **by the calling action** before validation (0023's R-6 shape). This story proves that for `Create`/`Rename` (below); 0071 proves it for `SetProductCategoryTranslation`.
- [ ] Feature (adapted, `Create`/`RenameProductCategoryTest`): blank and whitespace-only names are still refused on the default-language path, and no translation row is written.

### The 1062 discrimination — `tests/Unit/Actions/ProductCategories/TranslateProductCategoryNameUniqueViolationTest.php` (D-7 (ii))
Unit, with `uses(TestCase::class)` for `trans()` and no `RefreshDatabase`. Exceptions are constructed: a `PDOException` whose `errorInfo` is set, wrapped in a `QueryException` / `UniqueConstraintViolationException`. No database is touched.
- [ ] Unit: 1062 whose message names `product_category_translations_store_language_id_name_unique` → returns a `ValidationException` keyed on the given `$errorKey` (default `name`) with the `validation.unique` message.
- [ ] Unit: 1062 on `product_category_translations_product_category_id_store_language_id_unique` → the **same exception instance** is rethrown, not a `ValidationException`.
- [ ] Unit: 1452 (FK violation, e.g. a nonexistent `store_language_id`) → the same instance is rethrown. *Why:* 0023's `23000` catch was written for a table with exactly one unique constraint. This table has two `UNIQUE`s plus two FKs, so a blanket `23000` → "name taken" is newly wrong. The FK case cannot be reached through `Create`/`Rename`, which is why it is tested here.
- [ ] Unit: a `QueryException` with no `errorInfo` → rethrown.
- [ ] Feature (adapted, the existing `Create`/`RenameProductCategoryTest` race tests): the collision driven through the **real** `(store_language_id, name)` index still surfaces as a `name`-keyed `ValidationException`. This proves the actions actually route their catch through the translator.

### The backfill
- [ ] Unit: `translationRowsFor()` over N arranged `{id, name}` objects returns **exactly one** row each, carrying the given store language id, with the name **byte-identical** to the original and a distinct UUID id — asserted per row, **never** as a row count. *Why:* a count assertion passes even if every row got the wrong name, an empty string, or all rows collapsed to one value — the [count-assertion failure mode](../../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-count-based-assertion-over-rendered-html-counted-a-wrapper-element-it-never-meant-to-include--2026-08-21) this project records.
- [ ] Unit: names with leading/trailing whitespace and a name at the 255-character boundary survive `translationRowsFor()` unchanged.
- [ ] Feature: `assertCanBackfill()` / `__invoke()` with **existing categories and no default store language** throws the legible `RuntimeException` and writes nothing. Categories are arranged through the factory's `withoutTranslations()` state, which provisions **no** store language (the test first asserts `store_languages` is empty, so a factory regression fails here rather than making the test pass for the wrong reason). The check reads only row existence, never the dropped column.
- [ ] Feature: **zero categories and no store language at all** → `assertCanBackfill()` does not throw and `__invoke()` returns `0` writing nothing. *Why this is the most important backfill test:* it is the branch every fresh install and every `RefreshDatabase` run takes, and the first draft of this story made it throw — which would have aborted the entire test suite at migration time (Phase 2 finding 1).
- [ ] Feature: **zero categories and a default language present** → no-op, returns `0`.
- [ ] **Phase 5 review item, deliberately not a test:** `assertCanBackfill()` is the **first** statement of the migration's `up()`, before `Schema::create`. *Why not a test:* proving it would mean dropping and re-creating a table inside a `RefreshDatabase` transaction, and MySQL's implicit DDL commit would break that test's isolation for every test after it — a worse cost than one reviewed line. *Why it matters:* a throw after the `create` would leave an orphan table that blocks the operator's re-run.
- [ ] The migration itself is otherwise **not** separately tested — `RefreshDatabase` proves it runs, and extracting the logic (**D-11**) is what makes the part that could actually be wrong testable. This is a deliberate application of [what-not-to-test.md](../../../docs/testing/qa/what-not-to-test.md)'s migration rule, legitimate **only because** of the extraction; left inline it would be an untested data transform wearing a green checkmark.

### The `translation_relations` registry
- [ ] Feature: `StoreLanguage::translationUsageCount()` returns the **true count** of translations in a given language, now that a real entry is registered. This is what turns 0068's **R-7** from a negative assertion into a verified one.
- [ ] Feature (**the drift guard**, 0068 backlog item 2 — **D-14**): every registered `{table, column}` pair resolves against the live schema (`Schema::hasTable()` / `Schema::hasColumn()`), **and** every table matching the `*_translations` suffix — enumerated with `Schema::getTableListing(schemaQualified: false)` (verified present in the installed `laravel/framework` v13.19.0; the flag returns bare table names rather than `database.table`) — appears in the registry. The second half is the likelier failure — a sibling story forgetting to append its entry — and it is derived from the live schema rather than a hardcoded list, so it never goes stale and no sibling ever writes its own.
- [ ] Feature: `php artisan config:cache` succeeds with the appended entry — run as an assertion, not trusted to review (0068 **R-8**).
- [ ] **Not written:** an assertion that `config('store-languages.translation_relations')` equals a literal array. That duplicates the file without verifying behaviour — [what-not-to-test.md](../../../docs/testing/qa/what-not-to-test.md)'s static-config rule. The drift guard asserts a **schema fact**, which is why it is real coverage and this would not be.

### Shipped consumers (D-15) — behaviour preserved, not re-specified
- [ ] Feature (`ProductCategories\Index`): the list is ordered by default-language name, then `id` — two categories arranged in **reverse** alphabetical creation order prove the sort is by name, not insertion.
- [ ] Feature (`ProductCategories\Index`): a category holding no default-language translation renders `—` in the list and does not throw.
- [ ] Feature (`ProductCategories\Index`): the edit modal prefills the **default-language** name; saving a rename changes only the default-language translation row — a pre-existing French translation of the same category is byte-identical afterwards.
- [ ] Feature (`ProductCategories\Index`): the delete confirmation names the category by its default-language name.
- [ ] Feature (`Products\Editor::categoryOptions()`): options are ordered by default-language name, carry `{id, name}`, and render with zero store languages present (empty catalog, no throw).
- [ ] Feature (`Products\Index`): the existing `IndexQueryTest` query-count invariant still holds with `'category:id'`.
- [ ] Feature (`ProductCategoryFactory`): `ProductCategory::factory()->create()` on a database with **no** store language provisions exactly **one** default language and one translation in it; a second `create()` reuses that language rather than adding another default. *Risk if missing:* two `is_default = true` rows would silently violate 0068's single-default invariant in every test that creates two categories.
- [ ] Feature (`ProductCategoryFactory`): `ProductCategory::factory()->withoutTranslations()->create()` on an empty database leaves **both** `store_languages` and `product_category_translations` empty. *Risk if missing:* the backfill-refusal test above could no longer arrange its "categories exist, no default" state.
- [ ] Every existing test adapted under **D-15** keeps its original assertion **intent**; a test is never deleted or weakened to get green (a deletion needs explicit user approval per the project rules).

### Query shape
- [ ] Feature: rendering N categories through `withTranslationsFor()` issues a **bounded** number of queries regardless of N — asserted with a query count, and proven able to move by removing the eager load.
- [ ] Feature: `translated()` reads the already-loaded relation and issues **no** additional query per call. *Why:* `$model->translations()` (the relation *method*) and `$model->translations` (the *property*) look near-identical and only the second respects eager loading.

### Authorization — through the two write actions this story ships
Every case calls the action directly (`app(RenameProductCategory::class)(...)` / `app(CreateProductCategory::class)(...)`), mounting no component.
- [ ] Feature: an actor without `products.edit` calling `RenameProductCategory` → `AuthorizationException`, and the default-language translation is **byte-identical** afterwards. An actor with it → the default-language name changes. (0023/0025's existing refusal tests, adapted to read the translation row.)
- [ ] Feature: an actor holding `products.edit` and **zero** `store-languages.*` permissions renames a category successfully (**D-13**). This is not vacuous: `Rename` self-authorizes, so the test would fail if a `store-languages.*` check were ever added to that path.
- [ ] Feature: an actor holding `products.create` and **not** `products.edit` creates a category, including its default-language translation. *Risk if missing:* this is the exact bug that would follow from making `SetTranslation` self-authorize `update` — see **D-9**.
- [ ] Feature (`tests/Feature/Policies/`, where the existing policy tests live, since holders need permission rows): `ProductCategoryPolicy`'s four abilities are unchanged in meaning — no new ability, tested for a holder and a non-holder.
- [ ] **Not here — 0071's (D-17):** authorization of a **non-default-language** write. Its enforcement point, `SetProductCategoryTranslation`, is 0071's (its `SetProductCategoryTranslationTest`: "lacking `products.edit` → `AuthorizationException`, no row written", and its component/action pair for a `products.view`-only actor).

### Deliberately NOT tested here
- [ ] **`NormalizeForSearch`'s own folding table** (ß, ç, CJK, whitespace, idempotence) — owned and unit-tested by story 0022. Duplicating it creates a second specification of the fold that can drift from the first; this story proves category names *go through* it via the case/accent cases above.
- [ ] **`StoreLanguage`'s own CRUD and invariants** (add/remove/default-swap, last-active guard, default-must-be-active) — 0068's. This story tests only the *interaction* points where behaviour is genuinely new.
- [ ] **Language tabs, per-language editing from a screen, or any new rendering** — 0071's. The only rendering assertions here are the D-15 preservation checks above.
- [ ] **Guarded authoring of a non-default language** — authorization, blank/whitespace refusal and "no row written" for a French write — 0071's `SetProductCategoryTranslationTest` (**D-17**). This story ships no guarded non-default write path, so it tests the rules that path will reuse (rule layer) and the primitive it will wrap (`SetTranslation`), never the path itself.

## Expected outcome

`product_categories` no longer carries a `name` column; every category's name lives in `product_category_translations`, one row per store language, with existing rows backfilled into the store default — and a fresh install with no categories migrates cleanly before any seeder has run. `ProductCategory::translated('name')` returns the requested language's name, the store default's when that is absent, and `null` when neither exists (or no default language exists at all) — never an exception, on any read path. The Product Categories screen and the product editor's category dropdown behave exactly as before, reading and writing the default store language's name, with `—` for a category that has none. A translation can be stored in any store language through the shared primitive, and the name rules enforce uniqueness per language rather than globally. Guarded, per-language authoring from the screen arrives with 0071, which reuses exactly these rules and this primitive. `StoreLanguage::translationUsageCount()` returns a real number for the first time, so story 0069's removal-warning line renders without any component change. Four sibling stories can now add translations to their own entities by writing one migration, one model, two lines of parent-model wiring and one appended config literal.

## Acceptance criteria

- [ ] `product_category_translations` exists with a UUIDv7 primary key, two non-nullable UUID FKs, a `name`, and timestamps; `php artisan db:table product_category_translations` reports exactly **three** indexes (`primary` and both `UNIQUE`s — `store_language_id` is the leftmost column of the second `UNIQUE`, so no separate FK index is auto-created; corrected 2026-08-29, see the note above the migration block).
- [ ] The FK to `product_categories` cascades on delete; the FK to `store_languages` restricts, and is understood to be defensive-only because 0068's **D5** never deletes a row.
- [ ] `product_categories.name` and its `unique('name')` index are gone, dropped in a **separate** migration ordered after the one that creates and populates the child table.
- [ ] Every pre-existing category holds exactly one translation row in the store default language, with its name preserved byte-for-byte.
- [ ] The backfill aborts loudly **only** when categories exist and no default store language does, before any DDL, and writes nothing; with zero categories it is a no-op whatever `store_languages` holds, so `migrate` on an empty database and every `RefreshDatabase` test run succeed without seeding.
- [ ] `translated()` resolves requested → default → `null`, **per field**, and never throws — including when no default store language exists; the write actions refuse legibly in that state instead.
- [ ] `StoreLanguage::defaultStoreLanguage()` is memoised, **including the "no default" answer**. It is flushed by `StoreLanguage`'s own `saved` hook, so a default change re-points the fallback within the same request, and by the `beforeEach` chained onto the Feature/Browser binding in `tests/Pest.php`.
- [ ] Every test that resolves the default store language or reads a table lives in `tests/Feature`; no `tests/Unit` test touches the database.
- [ ] `ProductCategoryValidationRules` exposes exactly `productCategoryRules(NormalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null)`, `nameRules(...)` and `uniqueNormalisedName(...)` with that same parameter order, and is verified at the rule layer with a **non-default** language id (required, max length, per-language uniqueness, self-exclusion).
- [ ] Every shipped consumer of `product_categories.name` listed under **D-15** is migrated: the Product Categories screen lists, prefills, renames and confirms deletion by default-language name (`—` when absent); the product editor's dropdown lists by default-language name; the Products list no longer selects `category.name`; `ProductCategoryFactory` provisions a single default language and a translation, and its `withoutTranslations()` state provisions neither. No grep hit for a read or write of `product_categories.name` remains in `app/`, `resources/`, `database/factories/` or `tests/`.
- [ ] A translation authored in a store language that is later removed remains readable.
- [ ] Name uniqueness is enforced per store language, through the shared `NormalizeForSearch` fold, with the composite `UNIQUE` as the backstop. `Create`/`Rename` route their `QueryException` through `TranslateProductCategoryNameUniqueViolation`, which maps only a 1062 on the `(store_language_id, name)` index to a validation error and rethrows every other constraint violation (unit-tested with constructed exceptions). There are **three** constraints of note, not four; see the correction above.
- [ ] Writing a category's default-language name requires `products.create` (create) or `products.edit` (rename), and **no** `store-languages.*` permission. The permission catalog is unchanged at **43** (`Administrator` at 42 of 43). Guarded authoring of a non-default language is **not** delivered here; it is 0071's (**D-17**).
- [ ] `config/store-languages.php` gains **exactly one** appended array literal, contains no closures, and survives `config:cache`; `RemoveStoreLanguage`, `StoreLanguage::translationUsageCount()` and every 0068/0069 component are untouched.
- [ ] The drift-guard test fails when a registered pair is broken **and** when a `*_translations` table exists unregistered.
- [ ] `HasTranslations` and `SetTranslation` are consumed, not re-implemented, by the pilot — no fallback logic exists at any call site.

## Definition of Done
- [ ] Tests written and green (**full suite unscoped**, not `--filter`)
- [ ] `vendor/bin/pint --format agent` run **unscoped**, not `--dirty`
- [ ] **Larastan level 7 run and recorded** — named explicitly because [errors-log.md](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-verification-record-that-lists-two-of-three-quality-gates-is-a-record-of-two-gates--2026-08-26) records three consecutive stories whose verification notes listed two of three gates and were read as records of all three
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper) — at minimum `docs/database/schema.md` (a new domain table whose rows are per-language content, the ER diagram entry, and `product_categories` losing `name`), `docs/database/migrations.md` (a migration that **removes** a source-of-truth column after backfilling it elsewhere, its knowingly non-inverse `down()`, and the "precondition before DDL" rule of **D-16**), `docs/conventions/directory-structure.md` (`app/Actions/Translations/` as a **cross-cutting concern** folder) and `docs/conventions/base-standards.md` (`HasTranslations` as a behavioural trait in `app/Concerns/`, beside `ResolvesFlagReasonLabel` / `ResolvesSalesRegionFromAddress`), `docs/conventions/naming.md` (`<Entity>Translation` and `<entity>_translations` as the pattern four siblings copy), and `docs/architecture/authorization.md` (recording that translated content adds **no** ability and **no** permission — a deliberate non-addition worth stating so a later story does not add one)
- [ ] **Recorded as a handoff, not done here:** the remaining sibling-story coordination in **R-1** (0072/0074/0076/0078 each own their own shipped consumers). Story 0071 was aligned with this story's D-15 ownership decision on 2026-09-28, and with D-17 at the round-2 rewrite (see the rewrite notes at the end of this file); no other story file is edited.
- [ ] Acceptance criteria met

## 4. Documented functional decisions

**D-1 — A child table, not a JSON column.** 0023's **D-7** left this open ("a `product_category_translations` child table, or a JSON column"); it resolves decisively toward the child table, and the first reason alone would settle it. **(i) 0068's registry structurally requires a real column.** Its **D8** already committed to `DB::table($table)->where($column, $languageId)->count()` as the *entire* generalized removal-warning mechanism for every future translation table. That is a plain equality filter on a real column; a JSON blob cannot satisfy it without `JSON_CONTAINS` special-casing inside a helper that must stay generic. Choosing JSON does not merely cost something here — it breaks a contract 0070 does not get to renegotiate, since 0068 is closed-spec on it. **(ii) Per-language uniqueness needs something indexable.** 0023's **D-4** two-layer scheme (normalised PHP comparison primary, DB index backstop) has no backstop at all against a JSON column, reducing a deliberately two-layer guarantee to one. **(iii) No referential integrity.** A JSON object's keys are strings with no FK; a stale or typo'd language reference sits invisibly inside a blob forever. **(iv) Multi-field generalisation.** 0076/0078 translate five fields; one row per `(entity, language)` with a column per field is the shape that composes with Eloquent eager loading, while JSON degenerates into either five blobs or one blob that makes "which products lack a French title" unanswerable without decoding every row. The one thing JSON would buy — no join on a single-language read — is worth nothing here, because **D-10**'s eager-load shape reaches two total queries for a whole list anyway, and this repo has **zero** precedent for business data in a JSON column.

**D-2 — UUIDv7 primary key, following ADR 0001 Amendment 1 unchanged; no new ADR exception. (`database-expert` recommended otherwise; the dissent is recorded.)** [Amendment 1](../../../docs/decisions/0001-uuid-primary-keys.md#amendment-1-2026-08-27--the-scope-is-the-policy-not-the-list-of-seven) states the policy as *"every new business entity is UUIDv7"*, with **one** named exception for a high-volume internal geography lookup table. `database-expert` argued for a `bigint` surrogate on the grounds that a translation row is never addressed by its own id (its natural key is the `(entity, language)` pair, which every read path already holds), that enumeration safety therefore cannot apply, and that this becomes the highest-row-count table the repo has created — asking for the same explicit ADR-amendment treatment stories 0016 and 0019 received. **Rejected, for three reasons.** *(i)* The volume argument does not reach the named exception's bar: products × store languages in a backoffice catalog is 10³–10⁴ rows, three to four orders of magnitude below a geography lookup table, and 36 bytes across 10⁴ rows is not a cost worth a policy carve-out. *(ii)* 0068's **D19** precedent is a *different structural case* — a singleton, where a second row can never exist to enumerate toward — and importing its reasoning here would stretch it past what it argued. *(iii)* Amendment 1 explicitly weighs this trade already and records that **a mixed-PK domain is worse than an over-provisioned key**; adding a third exception in the story that is supposed to establish a pattern four siblings copy would make "what PK does a translation table use" a per-story question forever. The upside of the rejected option — a genuinely smaller key — is real but small; the upside of the chosen one is that **no sibling has to think about it**, which is this story's whole purpose. *Also rejected:* a true composite PK with no surrogate — no model in this repo uses one, and Eloquent's `find()`/route-binding/factory tooling all assume a single key column.

**D-3 — The two foreign keys behave differently on delete, and the asymmetry is the point.** `product_category_id` is **`cascadeOnDelete()`** — a translation has no meaning without its parent; it *is* the parent's dependent data, the same "worthless without its owner" test this repo's domain tables already apply wherever a child row is pure dependent data: `product_media.product_id`, `product_variant_values.product_variant_id`, `product_attribute_values` (its type FK), `order_items`, `blog_post_tag` and `shipping_zone_geography_entry` all cascade for exactly that reason (verified by grep of `database/migrations/`, 2026-09-28). The criterion that separates them from a restricting FK is the one that decides here too: `sales_regions.parent_id` uses `restrictOnDelete()` precisely because a cascade there would destroy administrator-configured tax rates, whereas here the cascade destroys exactly the data that has just become meaningless. `store_language_id` is **`restrictOnDelete()`** per 0068 backlog item 3, and is **defensive only — it will essentially never fire**, because 0068's **D5** makes removal an `is_active` flip and never a delete. That makes it the third instance of a pattern [migrations.md](../../../docs/database/migrations.md) already documents for `media.uploaded_by`: keep the clause because it is correct against a genuine hard delete, but never write code that relies on it running.

**D-4 — `product_categories.name` is dropped, not kept as a denormalised mirror. (`database-expert` recommended keeping it; this is the story's most consequential reversal of an expert recommendation.)** The alternative — keep `name` on the parent as a cached copy of the default-language translation, with a single named writer — is genuinely attractive: it breaks nothing, and this repo has a precedent for a seeder-owned denormalised column in `SalesRegion.name`. **It was rejected because the store default is not fixed.** PRD Epic 5 makes *"set French as the store's default language"* an explicit, supported operation, and 0068 ships `SetDefaultStoreLanguage` to perform it. Under the mirror design, that single action would have to rewrite the mirror column of **every row of every translatable table in the application** to stay correct — a cross-table resync that 0068 never anticipated, that would put real behaviour inside an action this story is forbidden to edit, and that would destroy **D8**'s central promise that a later story extends the mechanism by *"appending one array literal, and nothing else"*. A denormalised copy is cheap only while the thing it denormalises is stable; here it demonstrably is not. Dropping the column makes a default change **free** — the fallback simply resolves through a different row. *The cost, stated plainly and not minimised:* three shipped components, one view, the factory and a set of existing tests read or write the dropped column and must be migrated **by this story** (**D-15**), and `down()` becomes knowingly non-inverse. Both are one-time, reviewable costs; the resync obligation would have been permanent and silent. *Re-checked at the 2026-09-28 rewrite, with 0023/0025/0027 now shipped:* the argument is unchanged — the mirror would still need `SetDefaultStoreLanguage` to resync every translatable table — and the drop's cost is now bounded and enumerated rather than speculative.

**D-5 — Fallback resolves per **field**, not per row.** A naive reading of the PRD sentence (*"a missing translation falls back to the default store language"*) suggests: if the requested language's row is absent, use the default's row. That is indistinguishable from per-field resolution on this pilot, because a category has exactly one translatable field — and it is **wrong** the moment 0076 ships. Per-row fallback means a product correctly titled in French but with no French description loses its French **title** too, because the whole row is discarded in favour of the default's. Per-field costs nothing here, degrades to identical behaviour for single-field entities, and is the only version that stays correct for the multi-field siblings. **It must nonetheless be tested here**, on the story that owns the contract, with a two-field fixture — a regression is structurally invisible to every test `ProductCategory` alone can write.

**D-6 — Missing in both languages returns `null`; the mechanism never throws, and an inactive language is never refused.** Two branches, one reason. *(a) Missing everywhere → `null`.* This is **not** purely a data-corruption state: the moment an administrator promotes a new default (**R-2**), every entity translated only into the *old* default reaches this branch, in normal operation, on a live store. Throwing would take down an entire list render because one row is under-translated — the failure mode this repo already guards against by using `Gate::allows()` rather than `authorize()` in a list query. The rendering layer applies the em-dash convention `users.blade.php` and `roles.blade.php` already use for "nothing here". *(b) An inactive requested language still resolves.* 0068's **D5** exists specifically so a removed language's content survives and stays re-editable; a read path that silently refused an inactive `store_language_id` would defeat half of that decision's own reasoning. **The `is_active` filter belongs one layer up**, at the UI's "which tabs do I render" decision — never inside the fallback. Recorded emphatically because adding that guard is a plausible defensive reflex for a later reviewer, and it would be a regression. *(c) No default language at all → `null` on read, a legible refusal on write.* This state is reachable legitimately — every fresh install before `db:seed`, and every `RefreshDatabase` test — so the read side treats it like a missing translation, while `CreateProductCategory`/`RenameProductCategory` refuse, because writing a "default-language" name with no default language is meaningless. *Deliberately unresolved by the mechanism:* whether the flagged default row is itself active. 0068's **D6** makes that unreachable through its actions, and re-validating it on every read would be [a domain invariant enforced in the wrong place](../../../docs/architecture/authorization/domain-invariants.md#a-domain-invariant-is-not-an-authorization-rule-and-does-not-live-here).

**D-7 — Uniqueness moves from global to per-language, keeping 0023's two-layer scheme unchanged in kind.** 0023's **D-4**/**D-12** made category-name uniqueness a normalised PHP comparison through the shared `App\Actions\NormalizeForSearch`, with `unique('name')` as a defence-in-depth backstop. The direct port is `UNIQUE(store_language_id, name)` plus the same PHP fold scoped by language. Two consequences worth stating. *(i)* The same name in two different languages must be **permitted** — a store may legitimately hold a category named "Chaussures" in French and another named "Chaussures" in Spanish, and the test proving this is the only one that can catch a missing language scope in the `WHERE` clause. *(ii)* The `23000` catch 0023 wrote for a single-constraint table is **newly unsafe**: this table has two `UNIQUE`s and two FKs, so blanket-translating `23000` to "name taken" would misreport an FK violation — the catch narrows to error 1062 on the `(store_language_id, name)` index only, following `CreateProduct`'s existing `errorInfo[1] === 1062` narrowing. That narrowing is **extracted** into `TranslateProductCategoryNameUniqueViolation`, following the shipped `TranslateProductVariantUniqueViolation` precedent, instead of being written inline twice. *Why extract rather than leave it inline (round-2 Phase 2 finding 1):* through `Create`/`Rename` the FK branch is unreachable, because both always pass the id of an existing default language, so an inline catch would ship its most important branch untested. As a pure translator, every branch is unit-tested with constructed exceptions. It also gives 0071's `SetProductCategoryTranslation` one implementation to reuse, with its own derived `names.{id}` key, instead of a third copy. *Rejected:* demoting the discrimination to a Phase 5 review item. That is the fallback the reviewer offered, but it is not needed, since a clean isolation exists. Uniqueness binds in **every** store language, not only the default — **Q2**, decided (a).

**D-8 — `app/Actions/Translations/` is a new cross-cutting-concern folder, not a module area.** [base-standards.md](../../../docs/conventions/directory-structure.md#directory-structure) is explicit that a subfolder is either a module area or a **named cross-cutting concern**, and that a class serving two areas belongs to the concern rather than to whichever area called it first — the rule `app/Actions/Auth/` established and `LogRefusedPrivilegedAttempt` confirmed by being imported from seven classes across two areas. `SetTranslation` will be imported by ProductCategories, Products, BlogCategories, BlogTags and BlogPosts equally; filing it under `ProductCategories/` would make every later sibling's import read as a cross-area dependency on a module it has nothing to do with. `Translations` names the concern; a `Shared/` or `Common/` catch-all is explicitly forbidden by the same rule.

**D-9 — `SetTranslation` deliberately does **not** authorize, and the reason is structural rather than an exemption.** [base-standards.md](../../../docs/conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)'s rule is that an authorization rule belongs to the action performing the operation, so the obvious move is `Gate::authorize('update', $translatable)` inside `SetTranslation` — generic, and it would work. **It is wrong, and the counter-example is concrete:** `CreateProductCategory` calls `SetTranslation` to write the new category's default-language name. If the primitive authorizes `update`, then *creating* a category would require `products.edit` rather than `products.create`, silently locking out an actor granted exactly the permission for the job. The alternative — passing the ability name in as a parameter — is the shape this project's own errors log records as [a guard taking the state it guards as a parameter](../../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-guard-took-the-state-it-was-guarding-as-a-parameter-reopening-its-own-hole-one-level-up--2026-08-20), making the guard only as strong as every present and future call site. So: **`SetTranslation` is a persistence primitive whose correct ability is a property of the calling operation, not of itself**, and the calling action authorizes before invoking it. `backend-expert` reached the same conclusion by analogy to `DeleteProductCategory`; the create/update permission split above is the harder reason and is recorded as the operative one. ⚠️ This is a genuine narrowing of the action-owns-the-rule convention and must be read narrowly: it applies to a primitive shared across operations with *different* abilities, never as licence to leave a domain action ungated.

**D-10 — The default-language lookup is memoised in a static on `StoreLanguage`, with no cache layer.** 0068's **D27** settled the identical question for `LocaleSetting` and its reasoning transfers without modification: `CACHE_STORE=database` in this app today, so a cache *hit* is an indexed read against the `cache` table replacing an indexed read against a table that starts at one row and stays in the dozens — no gain, plus an invalidation obligation and a staleness class of bug. What actually needs solving is **query count within one request**, which a static memo solves for free on this NTS, Octane-free stack. **The memo lives on `StoreLanguage`, never in `HasTranslations`** — PHP gives each consuming class its own copy of a trait's static properties, so a trait-resident memo would become one independent cache per translatable model, each querying for the same global row. The memo stores the "no default exists" answer as well, through a separate resolved flag, because a `??=` memo re-queries on every `null` and the no-default read path is legitimate (**D-6**). Three obligations follow, none of which 0068 had to name: the memo is **flushed by `StoreLanguage`'s own `saved` hook**, so a default change made earlier in the same request re-points the fallback without editing any 0068 action (all three write through `$model->save()`, verified); it is **flushed before every Pest case** from `tests/Pest.php`, because `RefreshDatabase`'s rollback fires no model event (**R-6**); and the list read path must eager-load via `withTranslationsFor()` restricted to the requested + default language ids, never `with('translations')` unbounded, which would load every language for every row.

**D-11 — The backfill is an extracted, container-resolved class, not logic inside the migration closure.** [what-not-to-test.md](../../../docs/testing/qa/what-not-to-test.md) legitimately excuses migration mechanics because `RefreshDatabase` proves every migration runs — but that argument covers **DDL**, not a **data transform** embedded in one. "The `ALTER TABLE` succeeded" and "every pre-existing category got exactly one translation row with its exact original name" are different claims, and `RefreshDatabase` proves only the first. Worse, the transform is *structurally unarrangeable* in a test that uses `RefreshDatabase`: by the time a test body runs, the backfill has already executed against zero rows, so there is no point at which pre-existing rows can be arranged. Extracting the logic — and, within it, the pure `translationRowsFor()` transform, because after both migrations have run the source column no longer exists to arrange rows in (see the note under the backfill class) — makes it directly callable and directly testable, exactly as `SalesRegionSeeder::assertValidCountryFixture()` and 0068's **D17** fixture reader already do for the same defensive reason. Left inline, this would be an untested data-transform branch — the [vacuous-coverage failure](../../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-pest-arch-rule-over-an-array-of-namespaces-shipped-green-while-proving-nothing--2026-08-18) this project records twice. *Rejected:* orchestrating a partial `migrate --path=` run to arrange a genuine pre-backfill state — possible, but fragile against every future migration reordering.

**D-12 — 0023's two write actions keep their signatures; their meaning narrows to "the default store language".** `CreateProductCategory::__invoke(string $name)` and `RenameProductCategory::__invoke(ProductCategory $c, string $name)` are unchanged in shape and now write the *default-language* translation rather than a scalar column. *Rejected:* widening them to `array $namesByLanguageId` — that changes a public contract story 0025 and every direct-call test bind to, for a capability nothing yet asks for (the PRD's tab UI edits one language at a time). *Rejected:* replacing them with a single generic action — it would erase the create/update permission distinction **D-9** depends on. Writing a **non-default** language goes through `SetTranslation`, wrapped by the guarded action the UI story adds (0071's `SetProductCategoryTranslation`). This story ships no such caller, which is why `SetTranslation`'s own tests call it directly, and why this story makes **no** claim about guarded non-default authoring (**D-17**).

**D-13 — Translated content adds no permission, no ability, and no policy.** Verified against 0023's **D-8**: product categories gate on the seeded **`products.view/create/edit/delete`** permissions — there is no `product-categories.*` slug in `RolePermissionSeeder::MODULES` and none should be added. Authoring a translation is *using* an already-configured language, not managing the language catalog, so it requires **no `store-languages.*` permission** — 0068's **D18** draws precisely this boundary, and requiring a second permission would invent a requirement the PRD never states. There is deliberately **no `TranslationPolicy`**: a generic cross-entity translation resource would need "may this actor touch this parent" logic, which is just `ProductCategoryPolicy::update` restated under a new name, and [`SalesRegionPolicy`](../../../app/Policies/SalesRegionPolicy.php)'s own docblock records that defining abilities nothing calls adds untested surface. **No step-up requirement**, for the reason 0068's **D13** gives and this story reuses rather than re-derives: step-up binds identity-sensitive, hard-to-reverse operations, and re-typing a category's French name is neither.

**D-14 — The drift guard derives its expectation from the live schema, never from a list.** 0068's backlog item 2 assigns the first translation story the guard that every registered `{table, column}` pair actually exists. Its **easy** half is `Schema::hasTable()` / `hasColumn()` over the registry. Its **hard and more valuable** half is the inverse — a translation table that exists but was never registered, which is the likelier real failure, since a sibling story appending its entry is a step a human can simply forget. Writing that against a hardcoded expected list would defeat the entire point, because every sibling would then have to edit the test — destroying **D8**'s "append one array literal and nothing else" property. So this story establishes the `<entity>_translations` **suffix as a naming convention** and the guard enumerates tables matching it, set-equating that against the registry. One short stable string is hardcoded; the list of tables never is. This mirrors what task 0018 recorded for `config/modules.php` — both generic drift guards picked a new entry up for free. The table listing uses `Schema::getTableListing(schemaQualified: false)` (R-9, resolved).

**D-15 — The story that drops a shipped column migrates every shipped consumer of it; the language tabs stay in 0071. (Added at the 2026-09-28 Phase 2 rewrite.)** With 0025 and 0027 shipped, dropping `product_categories.name` turns five production files and a set of tests red the moment the second migration runs. Three owners were possible:
- **(a) 0070 migrates every consumer to read/write the default store language, preserving today's behaviour exactly — _(chosen)_.** It is the only option under which this story can pass its own [full-suite gate](../../../docs/contracts/testing-and-parallel-agents.md) and be closed, and it leaves no window in which a shipped screen throws a SQL error. The migration is mechanical (replace a column read with `translated('name')`, an `orderBy` with a PHP sort over `withTranslationsFor()`), and it adopts 0071 **D-12**'s list query verbatim so 0071 inherits it rather than re-deriving it.
- **(b) 0071 migrates the Product Categories screen** (its prior **Q-3** answer). Rejected: 0071 depends on 0070, so between the two merges `main` would hold a Product Categories screen and a product editor that throw — and 0070 could not pass its own suite gate at all. 0071 also never covered the product editor dropdown, the Products list eager load or the factory, which would have had no owner.
- **(c) Keep `name` until 0071 lands, dropping it there.** Rejected: it moves the backfill, the drop and the consumer migration into a UI story, and reopens **D-4**'s rejected mirror-column window.

What 0070 does **not** take from 0071: tabs, per-language editing from the screen, `SetProductCategoryTranslation`, the per-tab requiredness rules and every rendering concern 0071 specifies. 0070's screen changes are the smallest edits that keep today's single-name behaviour working, and 0071 builds on top of them. **All consumer reads use the default store language.** Which store language an administrator's *UI locale* should map to for display is not decided here — nothing in PRD Epic 5 ties the two together yet, and 0071's tabs make every language visible explicitly.

**D-16 — The backfill refuses only when there is data it cannot place, and it checks before any DDL. (Added at the 2026-09-28 Phase 2 rewrite; Phase 2 finding 1.)** `store_languages` is populated **only** by `StoreLanguageSeeder`, never by a migration, and `tests/Pest.php` applies `RefreshDatabase` without seeding — so at migration time on a fresh install and in **every** test run there is no default language, and the first draft's unconditional throw would have aborted the whole suite. The precondition is therefore conditional: zero categories → nothing to carry, nothing required; categories present and no default → refuse, because silently dropping names or inventing a language would both be data loss. The same [seeder-safety](../../../docs/security/seeder-safety.md#a-catalog-seeder-must-fail-loudly-rather-than-commit-a-structurally-invalid-catalog) posture survives — loud when something real would be lost, silent when nothing would. Checking **before** `Schema::create` keeps a refused run from leaving an orphan table behind (MySQL auto-commits DDL). *Rejected:* seeding a default language from inside the migration — it would make a migration own catalog data that 0068 **D17** assigns to the seeder and its fixture, and would pick a language on the operator's behalf.

**D-17 — Each claim is tested on a surface this story ships; guarded non-default authoring is 0071's. (Added at the round-2 Phase 2 rewrite, 2026-09-28; round-2 finding 1.)** This story has three write paths. `Create` and `Rename` write **only the default language** (**D-12**). `SetTranslation` neither authorizes nor validates (**D-9**). The earlier drafts still phrased blank refusal, "no row written", per-language uniqueness and `products.edit` refusal as **French writes by an administrator**. No path in this story can perform or refuse such a write, so those claims could not be implemented or verified here. The "needs no store-language permission" scenario was even vacuously true against the unguarded primitive. Each claim is therefore re-targeted:

| Claim | Where 0070 proves it | Where the guarded French write is proven |
|---|---|---|
| Per-language uniqueness (same language refused, other language accepted, self-exclusion, case/accent fold) | Rule layer: `ProductCategoryNameRulesPerLanguageTest`, `Validator::make` with a **non-default** `$storeLanguageId` | 0071 `SetProductCategoryTranslationTest` (one canary per direction) |
| Blank / over-length refused in a non-default language | Rule layer, same file (empty string, 256 chars) | 0071 `SetProductCategoryTranslationTest` ("blank and whitespace-only → `ValidationException`, no row written") |
| Whitespace-only trimmed then refused, no row written | `Create`/`Rename` (default language), adapted tests | 0071, same test |
| `products.edit` required; no `store-languages.*` needed | `Rename` (and `products.create` for `Create`), direct-call tests | 0071 `SetProductCategoryTranslationTest` + its component/action pair for a `products.view`-only actor, and its zero-`store-languages.*` test |
| 1062 vs FK / other-unique discrimination | Unit: `TranslateProductCategoryNameUniqueViolation` with constructed exceptions; end to end via the adapted race tests | 0071 reuses the translator |
| Storing / replacing a translation in another language | `SetTranslation` direct-call tests | 0071, one row-count canary |

*Rejected: moving `SetProductCategoryTranslation` into 0070.* It would make every French-write scenario provable here. But it reverses **D-15**'s split, which gave the screen-facing behaviour to 0071 and the mechanism to 0070. It would also rewrite 0071's backend layer, including its defence-in-depth decision, its derived `names.{id}` error-key contract and its direct-call test file, in a story that ships no caller for it. The rule-layer and translator tests prove every piece of logic that action would reuse. What they do not prove is the action's own wiring (authorize first, trim, validate, write), and that wiring is exactly what 0071 already specifies and tests.

## 5. Dependencies, risks, open technical questions

### Dependencies

- **[Story 0023](../done/0023-product-categories-backend.md)** — hard dependency, **shipped**. This story retrofits its table, its model, its validation trait, its factory and two of its three actions.
- **[Story 0068](../done/0068-store-languages-catalog-backend.md)** — hard dependency, **shipped**. Supplies `store_languages`, the `is_default` row this story's fallback resolves through, and the `translation_relations` registry (shipped empty) this story populates. This story adds the default resolver, its flush and a `saved` hook to `StoreLanguage` and touches nothing else in that domain.
- **[Story 0024b](../done/0024b-product-category-in-use-delete-guard.md)** — **shipped**. Its `DeleteProductCategory` guard already anticipates this story's cascading FK (see the untouched-file note above).
- **[Story 0025](../done/0025-product-categories-ui.md)** and **[Story 0027](../done/0027-products-list-and-editor-ui.md)** — **shipped** consumers of `product_categories.name`, migrated by this story (**D-15**).
- **[Story 0071](../0071-product-categories-language-tabs-ui.md)** — depends on this story; aligned with **D-15** on 2026-09-28, and with **D-17** (guarded non-default authoring, and reuse of `TranslateProductCategoryNameUniqueViolation`) at the round-2 rewrite.
- **Story 0022** — supplies `App\Actions\NormalizeForSearch`, which the re-scoped uniqueness rule keeps using unchanged. *Verified:* despite its "searchable-multi-select-component" title, 0022's **D13** genuinely does own that class, so 0023's citation is correct and not a mis-reference.
- **[Story 0069](../0069-store-languages-settings-ui.md) depends on this story** in one visible way: its removal-confirmation line renders a usage count only when that count exceeds zero, which cannot happen until this story appends the first registry entry. Its own note says *"from story 0070 onward it appears automatically with no component change"* — this story is what makes that true.
- **No new Composer package.** No `spatie/laravel-translatable` or equivalent; the mechanism is ~60 lines of first-party code and a dependency would import conventions this repo has not agreed to.

### Risks

- **R-1 — Dropping `product_categories.name` (D-4) breaks shipped code; this story now owns that breakage (D-15).** *Rewritten 2026-09-28; the first draft treated 0025/0027/0060/0062 as unimplemented specs needing amendments.* 0025 and 0027 are shipped, so the break is real code: `ProductCategories\Index`, its Blade view, `Products\Editor::categoryOptions()`, `Products\Index`'s eager load, `ProductCategoryFactory` and the tests listed under **D-15** — all migrated here. 0060 (Blog Tags) and 0062 (Blog Categories) are **not** affected by this story at all: they read their own tables' `name` columns, whose retrofits are 0074's and 0072's. **The residual coordination risk** is that each of 0072/0074/0076/0078 drops a shipped column too and must apply recipe step 7 for its own consumers; any of those files still describing its consumers as unimplemented specs is stale in the same way this one was, and should be re-verified at its own Phase 2.
- **R-2 — A changed store default reaches the deepest fallback branch in normal operation, not only under data corruption.** The instant an administrator promotes French to default, every entity translated only into the *old* default has no translation in the *new* default and resolves to `null`. This is correct behaviour under **D-6**, but any UI story that assumes "every row always resolves to something" will render blanks it did not plan for. Flagged for the UI story rather than solved here, and pinned by a mandatory test above.
- **R-3 — Closed by events (2026-09-28).** The first draft left open a cheaper path — amend 0023 so `product_categories.name` is never created — if 0070 were sequenced first. 0023 has shipped, so that path no longer exists; this is a genuine retrofit against live data, which is what the two migrations and the backfill are written for.
- **R-4 — N+1 is the default failure mode of this mechanism, in two distinct shapes.** The obvious one is rendering a list without `withTranslationsFor()`. The subtle one is `$model->translations()->where(...)->first()` (the relation **method**, which always re-queries) instead of `$model->translations->firstWhere(...)` (the **property**, which reads the hydrated collection). They differ by one character and only the second respects eager loading. Worth an explicit code-review checklist line at Phase 5.
- **R-5 — A stale relation after a write renders the pre-save value.** `SetTranslation` returns the translation row, not the parent, so a component that saves and then re-reads `$category->translated('name')` without `->load('translations')` shows the old value. Invisible to `Livewire::test()->call(...)`, which never renders — the recurring lesson that a passing component-level test proves nothing about compiled output.
- **R-6 — The static memo (D-10) outlives a `RefreshDatabase` rollback.** Pest runs every case in one process, and the rollback fires no model event, so without a reset test N+1 would resolve test N's default-language id — a row that no longer exists. Mitigated by the `tests/Pest.php` `beforeEach` flush, and within a request by the `saved` hook; both are tested. *Residual, flagged not solved:* a long-running **queue worker** keeps the memo across jobs, so a queued job that reads translations after an administrator changes the default in another process would use the old default until the worker restarts. No queued job reads translations today; the first story that adds one must flush the memo per job (or read without it).
- **R-7 — 0023's stated rationale rests on a premise that is false against the current repo, and both experts repeated it.** 0023's **D-4**/**R-2** justify the normalised PHP comparison partly on *"SQLite in CI, MySQL locally and in production."* **Verified false:** `phpunit.xml` sets `DB_CONNECTION=mysql` and `DB_DATABASE=testing`, `.github/workflows/tests.yml` runs a `mysql:8.4` service with `DB_CONNECTION: mysql`, and `.env.example` is MySQL — a fact [docs/database/schema.md](../../../docs/database/schema-products/sales-regions-and-media.md#media) independently relies on when it notes `FULLTEXT` "genuinely would work" here. **The conclusion survives the premise:** a PHP `===` is still *weaker* than a `utf8mb4_unicode_ci` index, so the normalised comparison is still required to stop the index raising `23000` on a pair PHP accepted. But this story must not propagate the stale reason, and 0023's own text should be corrected. Recorded because both `database-expert` and `backend-qa` restated it from 0023 without verifying — `backend-qa` at least hedged it ("if that's still true"), which is the discipline that caught it.
- **R-8 — One claim in 0068's backlog item 3 is defensive advice presented as a rule.** It says `store_language_id`'s `constrained()` needs the table name passed explicitly *"per migrations.md"*. That rule is about **inference failure** (`parent_id` → a nonexistent `parents`, `uploaded_by` → `uploadeds`); `store_language_id` resolves to `store_languages` correctly unaided. Passing it is good practice and this story does, but a reader should not infer that omitting it would break — recorded so the sibling stories copying this migration know which parts are load-bearing.
- **R-9 — Resolved (Phase 2, 2026-09-28).** `Schema::getTableListing($schema = null, $schemaQualified = true)` exists in the installed `laravel/framework` v13.19.0; the drift guard calls it with `schemaQualified: false` to get bare table names (**D-14**).
- **R-10 — Deleting a category now also deletes its translations.** The cascade follows the repo's established dependent-data pattern (**D-3**), so it is not novel; what is worth stating is its interaction with the shipped delete path. `DeleteProductCategory` has carried 0024b's in-use guard since that story shipped: a category still referenced by products is refused before any delete, so translations are only ever removed together with a category that was genuinely deletable. That is intended, and stated so it is met as a decision.

### Product questions — decided

Per [contracts.md](../../../docs/contracts.md)'s Uncertainty Handling Rule, each carried a recommendation rather than a silent assumption. **All three are closed as of 2026-09-28 (Phase 2 rewrite, `product-owner`).** Q1 and Q2 are decided on their recommended options because the downstream stories built on them already rest on those answers: 0071's user-confirmed 2026-08-30 resolutions (its **Q-1**/**Q-2**) and its **D-7** requiredness rule presuppose Q1(a), and its tests and acceptance criteria ("same name accepted across two languages, refused twice within one") presuppose Q2(a); 0073 and 0076 likewise state they assume Q1(a). Choosing otherwise now would reopen five written stories for no identified gain. **The project owner may still overturn either before Phase 3 starts**; doing so would change the items noted under each.

**Q1 — Must every entity always hold a translation in the default store language? ✅ DECIDED 2026-09-28 — (a) yes.** Binding consequences: `CreateProductCategory` always writes a non-blank default-language name; the backfill gives every pre-existing row one; the default-language name can be corrected but never blanked (0071 **D-7**). A category *can* still reach the "no default-language name" state, but only through a **store-default change** (**R-2**) or direct data surgery — never through a create — which is why the screens render `—` rather than assume a name. This story assumed **yes** — `CreateProductCategory` writes one, and the backfill guarantees one for every pre-existing row — which keeps **D-6**'s "resolves to nothing" branch a rare state rather than the normal condition of a new category.
- **(a) Yes — a create always writes the default-language translation, and it is required — _(recommended)_.** It is the only reading under which 0023's **D-7** backfill makes sense, it keeps the deepest fallback branch exceptional, and it means a category can never be invisible in the catalog it belongs to.
- **(b) No — a category may exist with no translations**, becoming visible only once someone names it. Cheaper for a bulk-import flow, but every list screen must then render nameless rows, and "which categories are nameless" becomes a real support question.

**Q2 — Should name uniqueness be enforced in *every* store language, or only in the default? ✅ DECIDED 2026-09-28 — (a) every language.** Binding consequences: the `UNIQUE(store_language_id, name)` index **exists** (it is the second of the three indexes); `uniqueNormalisedName()` is scoped by `store_language_id` and applies to whichever language is being written; the `23000` catch narrows to 1062 on that index. This story assumed **every language** (**D-7**), as the direct port of 0023's confirmed "duplicate names are refused" criterion.
- **(a) Every language — _(recommended)_.** It is the faithful port of an already-confirmed rule, it keeps the mechanism uniform across siblings, and a duplicate French name is as confusing in a French-language storefront as a duplicate Spanish one is in a Spanish one.
- **(b) Default language only**, leaving other languages unconstrained. Its real advantage is workflow: mid-translation, an administrator may legitimately want two categories both temporarily reading "Chaussures" in French before finishing. Its cost is that duplicates become permanent and undetectable in every non-default language, and the `UNIQUE(store_language_id, name)` index would have to go.
- This is a genuine product call about how administrators translate in practice; it was decided on (a) for the consistency reasons stated at the top of this section.

**Q3 — Which story owns the language-tabs UI for the taxonomy screens? ✅ CLOSED 2026-09-28 — (a), answered by the backlog itself.** Dedicated Epic 5 UI stories exist per taxonomy: [0071](../0071-product-categories-language-tabs-ui.md) (Product Categories), 0073 (Blog Categories), 0075 (Blog Tags). 0025 is shipped and is not amended in place. The one boundary that needed drawing — who keeps the shipped Product Categories screen working across the column drop — is **D-15**: 0070 does, and 0071 adds the tabs on top.

## 6. Technical tasks for later backlog creation

Derived from this debate; **none are in scope for 0070**.

1. ~~Amend stories 0025, 0027, 0060 and 0062~~ — **withdrawn 2026-09-28.** 0025/0027 are shipped and their code is migrated by this story (**D-15**); 0060/0062 are unaffected by 0070 and belong to 0074/0072. What remains is **R-1**'s residual: 0072/0074/0076/0078 should each be re-verified at their own Phase 2 for the same stale "unimplemented consumer" premise.
2. **Correct story 0023's D-4/R-2 "SQLite in CI" premise** — **R-7**. The design conclusion stands; only its stated reason is wrong, and leaving it is how a false premise gets repeated by the next three stories that read it (as it already was, twice, in this debate).
3. **Stories 0072 / 0074 / 0076 / 0078** each follow the copyable recipe above and append **one** `translation_relations` literal. None writes its own drift guard, its own fallback, or its own write primitive.
4. **0076 or 0078 — whichever ships first with more than one translatable field — must add the multi-field per-field-fallback test** that this pilot can only approximate with a synthetic fixture (**D-5**).
5. **A `slug` uniqueness decision for 0076/0078.** PRD makes slug/SEO fields translatable; whether two products may share a French slug is a routing question those stories must answer explicitly, following **D-7**'s per-language scoping reasoning rather than re-deriving it.
6. **Revisit D-10's no-cache decision when Redis lands** (PRD assumption 18), together with 0068's **D27**, which records the flush-after-commit ordering so it need not be re-derived.
7. **`ModuleRouteAccessTest.php` still covers two routes while four exist** — inherited unclosed from stories 0017/0018/0068, and untouched here since this story adds no route.

## Provenance

**Phase 1 Three Amigos debate, 2026-08-29.** Participants: `product-owner` (facilitator), `backend-expert`, `database-expert`, `backend-qa` — all three dispatched as real subagents and all three returned. **Nothing outside this file was created or modified**: no application code, migration or test was written, and the files of stories 0023, 0066, 0067, 0068 and 0069 are untouched.

**Where the three converged:** a child table over a JSON column (`database-expert` argued it from the registry contract, `backend-expert` from Eloquent composition, and neither dissented); `cascadeOnDelete()` on the parent with a defensive-only `restrictOnDelete()` on `store_language_id`; per-language uniqueness re-scoped from 0023's existing two-layer scheme; no new permission, no `TranslationPolicy`, no step-up; and a schema-derived rather than list-derived drift guard.

**They split on three points, each resolved above with the dissent recorded rather than dropped.**

*(a) The primary key.* `database-expert` recommended a `bigint` surrogate as a third named exception to ADR 0001 Amendment 1, on the ground that a translation row is never addressed by its own id and that this becomes the repo's highest-row-count table — and explicitly asked for sign-off rather than deciding it. `backend-expert` recommended UUIDv7, reasoning that a translation row holds real authored content. **Resolved in favour of UUIDv7 (D-2)**, on a third argument neither made: the amendment already weighs this exact trade and records that a mixed-PK domain costs more than an over-provisioned key, and a story whose purpose is to produce a pattern four siblings copy is the worst possible place to make the PK a per-story question. `database-expert`'s procedural point — that any divergence deserves explicit ADR treatment — is honoured by *not* diverging, which is the outcome that needs no amendment at all.

*(b) The parent `name` column.* `database-expert` recommended **keeping** it as a denormalised default-language mirror with a single named writer, to avoid breaking downstream consumers. `backend-expert` recommended it **never be created**, by amending 0023 before it ships. **Resolved by rejecting both as stated (D-4):** the column is dropped by migration, because the mirror carries a permanent, silent obligation neither expert priced — a store-default change would force `SetDefaultStoreLanguage` to resync every translatable table, putting real behaviour inside an action this story may not edit and breaking 0068 **D8**'s "append one array literal" promise outright. `backend-expert`'s amend-0023 path is preserved as the cheaper option **if** sequencing allows it (**R-3**), rather than assumed away.

*(c) Whether `SetTranslation` authorizes.* `backend-expert` said no, by analogy to `DeleteProductCategory`. The facilitator initially reached the opposite conclusion from [base-standards.md](../../../docs/conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)'s action-owns-the-rule convention, then found the concrete counter-example that settles it: a self-authorizing `update` would make *creating* a category require `products.edit`. **`backend-expert`'s conclusion is adopted, with the create/update permission split recorded as the operative reason (D-9)** rather than the analogy it originally rested on.

**Two claims in the facilitator's own briefs were wrong and are corrected here rather than quietly dropped**, in the spirit of 0068's **R-18**. The brief to `backend-expert` referred to *"the existing `product-categories.*` permissions"*; **no such slug exists** — `RolePermissionSeeder::MODULES` holds ten module slugs and product categories gate on `products.*` per 0023's **D-8** (**D-13**). And the brief to `database-expert` repeated 0068's characterisation of the explicit-table-name rule; `database-expert` caught and corrected it (**R-8**).

**One claim both experts made was verified false by the facilitator** — 0023's *"SQLite in CI, MySQL in production"* premise, which `database-expert` restated as established fact and `backend-qa` restated with a hedge. `phpunit.xml`, `.github/workflows/tests.yml` and `.env.example` are all MySQL. The design conclusion survives; the stated reason does not (**R-7**, backlog item 2).

**Not run by this phase**, per [workflow.md](../../../docs/workflow.md): the INVEST check (Phase 2), TDD implementation (Phase 3), security audit (Phase 4), code review (Phase 5), or the docs pass (Phase 6).

## Phase 2 — INVEST validation

**2026-09-28 — `code-reviewer`: ❌ REJECTED, returned to `product-owner` for rewrite.** Validated against the live tree (0023, 0024b, 0025, 0027 and 0068 are all shipped). The core mechanism (D-1, D-2, D-5–D-10, D-13, D-14) remains sound; the story fails **Estimable/Small/Testable** because its scope and migration plan no longer match the code it retrofits.

1. **Blocking — the backfill breaks every migration run on an empty database.** `BackfillProductCategoryTranslations` throws when no default store language exists, unconditionally. `store_languages` is populated only by `StoreLanguageSeeder`, never by a migration, and `tests/Pest.php` applies `RefreshDatabase` without seeding — so every Feature/Browser test (and any fresh `migrate` before `db:seed`) would abort. The guard must fire only when there are category rows to backfill; add a test for "zero categories, no default → no-op".
2. **Blocking — dropping `product_categories.name` breaks shipped code the story declares out of scope.** "Deliberately not touched: `resources/views/**`, `app/Livewire/**`" is no longer achievable: `app/Livewire/ProductCategories/Index.php` (`orderBy('name')`, `$category->name`), `app/Livewire/Products/Editor.php::categoryOptions()` (`orderBy('name')`, `get(['id','name'])`), `app/Livewire/Products/Index.php` (`'category:id,name'` eager load), `database/factories/ProductCategoryFactory.php` (`'name'` definition) and ~26 test files read or write the column. The Files-to-modify list, tests and ACs must cover migrating these consumers to `translated('name')` / `withTranslationsFor()`, or the full-suite gate cannot pass.
3. **R-1 is stale:** 0025 and 0027 are shipped (a code break, not a spec amendment); 0060/0062 are blog screens and belong to 0072/0074, not here. Rewrite it; also decide explicitly whether 0071 (which assumes the screen is still unbuilt) takes over the Product Categories screen changes or 0070 does.
4. **Stale facts to correct:** the "neither dependency exists" banner and "not yet implemented" dependency lines; the permission catalog is **43** (`orders.refund`, story 0051), not 42; D-3/R-10's "first `cascadeOnDelete()` in domain tables" is false (product_variants, order_items, product_media, blog_post_tag, …); R-10's "until story 0024 adds its in-use guard" — 0024b's guard is shipped; `nameRules()` today is `nameRules(NormalizeForSearch, ?string)`, so state the exact widened signature (0071 already quotes a different one).
5. **Open questions Q1/Q2 carry no recorded answer** (0071 already cites "0070 Q1(a)" as decided); record the product owner's decisions — Q2 determines whether the `UNIQUE(store_language_id, name)` index exists at all. Q3 is answered by story 0071's existence.

Verified and **not** findings: R-7's correction is right (`phpunit.xml`, `.github/workflows/tests.yml` `mysql:8.4`, `.env.example` are all MySQL); R-9 resolved — `Schema::getTableListing($schema = null, $schemaQualified = true)` exists in the installed `laravel/framework` v13.19.0 (pass `schemaQualified: false` for bare names); `product_categories.name` + `unique('name')` exist exactly as the two-migration plan assumes; `config/store-languages.php` ships `translation_relations => []`; `StoreLanguage` has no `defaultStoreLanguage()` yet.

### Rewrite by `product-owner` — 2026-09-28, ready for Phase 2 re-evaluation

Every point above was re-verified against the live tree (grep/read of the cited files) before correcting it. **Phase 2 is not self-approved here; it returns to `code-reviewer`.**

1. **Backfill (finding 1)** — new **D-16**: `BackfillProductCategoryTranslations::assertCanBackfill()` refuses only when categories exist **and** no default store language does; zero categories is a no-op whatever `store_languages` holds. It runs as the first statement of the migration's `up()`, before `Schema::create`, so a refused run leaves no orphan table. The name-copying logic moved into a pure `translationRowsFor()` transform, because after both migrations run the source column no longer exists to arrange test rows in. New Gherkin (fresh install, refused migration) and tests (zero categories with and without a default language).
2. **Shipped consumers (findings 2–3)** — new **D-15**: 0070 migrates every shipped consumer of `product_categories.name` to the default store language: `ProductCategories\Index` + its view, `Products\Editor::categoryOptions()`, `Products\Index`'s eager load (`'category:id'`), `ProductCategoryFactory` (`named()`/`withoutTranslations()` states, auto-provisioned default language) and the affected tests. Added to Files to modify, Gherkin, tests and ACs. 0071 keeps the tabs and inherits the list query; 0071 was updated to match (its Q-3/R-2/D-12 notes, the files table and the `nameRules()` signature comment). R-1 rewritten (0060/0062 belong to 0074/0072); R-3 closed.
3. **Read side with no default language** — this came out of finding 1: every `RefreshDatabase` test that renders the product editor would hit `defaultStoreLanguage()` with an empty `store_languages`. So `defaultStoreLanguage()` now returns `?self` (the read side returns `null`, the write actions refuse legibly), the memo is flushed by `StoreLanguage`'s `saved` hook and by a `tests/Pest.php` `beforeEach` (R-6 rewritten, including the queue-worker residual).
4. **Stale facts (finding 4)** — banner and dependency lines now say "shipped"; permission catalog **43** (`Administrator` 42 of 43); D-3/R-10 no longer claim a "first cascade" and cite the existing cascades instead; R-10 is now in the past tense about 0024b's guard; exact widened signatures are pinned: `productCategoryRules`/`nameRules`/`uniqueNormalisedName(NormalizeForSearch $normalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null)`, which matches 0071's call site; the `23000` catch narrows to 1062 on the name index. The DoD docs line no longer claims "first" for things that are not first.
5. **Q1/Q2/Q3 (finding 5)** — Q1 decided (a), Q2 decided (a) (the `UNIQUE(store_language_id, name)` index stays), both consistent with what 0071 (user-confirmed 2026-08-30), 0073 and 0076 already assume; the project owner may overturn either before Phase 3. Q3 closed: 0071/0073/0075 exist.

## Phase 2 — INVEST validation (re-evaluation)

**2026-09-28 — `code-reviewer`: ❌ REJECTED, returned to `product-owner` for rewrite.** This is a full re-validation from scratch, not only a check of the five points above. All five earlier findings are **genuinely resolved** when checked against the live tree (see "Verified" below). The story still fails **Testable** (and **Independent** from 0071), because of one gap that was already in the first draft and that the first review missed.

1. **Blocking — several scenarios, tests and one AC need a guarded, validating write path for a *non-default* language, and 0070 does not ship one.** 0070 ships exactly three write paths. `CreateProductCategory` and `RenameProductCategory` only ever write the **default** language (D-12). `SetTranslation` deliberately neither authorizes (D-9) nor validates, and it has **no caller** in this story (D-12). D-15 hands the guarded non-default writer, `SetProductCategoryTranslation`, to 0071 explicitly. 0071 line 379 even says so: *"0070's 'a blank translation is refused' scenario has no enforcement point at all, since `SetTranslation` validates nothing"*. So these items cannot be implemented or verified at Phase 5 inside this story's scope:
   - The Gherkin *"A blank translation is refused"* (French; "no translation row is written").
   - The Gherkin *"An administrator without the products edit permission cannot translate a category"* (French). Its companion, *"…needs no store-language permission…"*, is vacuously true against an unguarded primitive.
   - The Gherkin *"Two categories cannot share a name within one store language"* / *"same name in two languages is permitted"* / *"keeps its own name"*, all phrased as French writes. There is no French write action to refuse or accept them.
   - These tests: "blank and whitespace-only translations are refused on **every** language path". There is no non-default path.
   - The test "a **foreign-key** violation (nonexistent `store_language_id`) is not misattributed". It is unreachable, because Create/Rename always pass the existing default's id.
   - The test "an actor without `products.edit` cannot write a translation". Only the default-language `Rename` path exists, and it is already covered.
   - The AC "Authoring a translation requires the entity's own `products.*` ability".

   **Required fix (recommended):** re-target each item to a surface 0070 actually ships.
   - Per-language uniqueness and blank rules: test them at the **rule layer**, calling the widened `productCategoryRules()` with a non-default `$storeLanguageId` through `Validator::make`. Make no "no row written" claim.
   - Authorization: state it only through `Create`/`Rename`, which are the default language.
   - The 1062-vs-FK discrimination: make it testable in a way that can actually be reached. One way is to extract the `errorInfo`/index-name check into a small method and unit-test it with constructed `QueryException`s. The other is to demote it to a named Phase 5 review item, with the reason stated.
   - French-write authorization, blank refusal and "no row written": record them as **0071's**. 0071 already owns them (its `SetProductCategoryTranslationTest` items at lines 376–380, 411 and 413).

   *Alternative, not recommended:* pull `SetProductCategoryTranslation` into 0070. That reverses D-15's split and rewrites 0071's backend layer.

Non-blocking. Fix these in the same pass:

2. **"Unit" is the wrong suite for the `translated()` fallback tests.** `translated()` calls `StoreLanguage::defaultStoreLanguage()`, which queries `store_languages`. `tests/Pest.php` binds `RefreshDatabase` only to `Feature`/`Browser`, and `tests/Unit` is unbound (see `tests/Unit/Actions/NormalizeForSearchTest.php`'s header). Label these tests Feature, or say how they opt in. Also, the `tests/Pest.php` bullet refers to "the Feature/Browser/Unit bindings", but no Unit binding exists. Spell out the exact registration, for example `pest()->beforeEach(...)->in('Feature', 'Browser', 'Unit')`.
3. **The memo does not store a `null` result.** `self::$defaultCache ??= …->first()` re-queries on every call while no default exists. On the no-default read path, which D-6 calls legitimate, `translated()` therefore issues one query per row. Either state this as accepted, or memoise a sentinel.
4. **The "no default store language row at all" test** clears the default with `DB::table()->update()`, which fires no `saved` event. The memo still holds the default the factory provisioned, so the test must call `flushDefaultStoreLanguage()` after the update. State that step, or the test resolves through a stale default.
5. **`ProductCategoryFactory::withoutTranslations()` must also skip the auto-provisioning of a default language.** The backfill-refusal test needs "categories exist, no default language". Say this explicitly.
6. **Stale lines left in 0071. These are not 0070's to fix, but flag them for 0071's own Phase 2:**
   - Line 39 still reads "0023, 0024, 0025, 0068 and 0070 are all unimplemented Phase 1 files", and R-3 says the same.
   - R-1 still describes `orderBy('name')` / the row shape as amendments 0071 carries, but 0070 D-15 now applies them.

**Verified, and no longer findings:**
- **Consumers (D-15).** Every consumer D-15 lists reads or writes `name` exactly as described: `ProductCategories\Index.php` (lines 140/210/290/295), `Products\Editor::categoryOptions()` (line 491), `Products\Index.php`'s `'category:id,name'` (line 153) and `ProductCategoryFactory::definition()`. No other `app/` consumer exists. All six `tests/Feature/ProductCategories/*` files, including `IndexRenderingTest` and `RefusalLoggingTest`, exist and are in scope.
- **Permission catalog.** It has 43 entries (`ORDER_PERMISSIONS = ['orders.refund']`), and `Administrator` holds 42 (`RolePermissionSeederTest`).
- **D-3's cascades.** They are accurate: `product_media`, `product_variant_values`, `product_attribute_values`, `order_items`, `blog_post_tag` and `shipping_zone_geography_entry` all use `cascadeOnDelete()`.
- **`nameRules()` today.** Its current signature is `nameRules(NormalizeForSearch, ?string)`. The widened three-parameter signature is consistent across this file (lines 420–422 and the AC) and 0071's call site.
- **D-16 on an empty database.** `assertCanBackfill()` returns before touching `store_languages` when `product_categories` is empty. That is exactly the state of every `RefreshDatabase` run and fresh `migrate`. It runs before `Schema::create`.
- **`translationRowsFor()` against D-11.** Keeping it a pure transform is coherent with D-11. It is the only way to arrange pre-drop rows once the `name` column is gone.
- **`defaultStoreLanguage(): ?self`.** It is consistent with every place it is used:
   - `translated()` and `scopeWithTranslationsFor()` both use `?->id`.
   - The public-contract block declares `?self`.
   - `Create`/`Rename` refuse legibly on `null`.
   - All three 0068 writers go through `save()` / `forceCreate()`, so `saved` fires. The evidence is `SetDefaultStoreLanguage` lines 61/63, `RemoveStoreLanguage` line 98 and `AddStoreLanguage` line 70.
- **Q1(a)/Q2(a).** Both are recorded as decisions. The `UNIQUE(store_language_id, name)` index is consistent across the migration, the three-index AC, D-7 and the narrowed 1062 catch.
- **Other checks.**
   - `StoreLanguageFactory::default()` exists.
   - `Schema::getTableListing($schema = null, $schemaQualified = true)` exists.
   - `tests/Unit/ArchitectureTest.php` has no rule that `HasTranslations` or `app/Actions/Translations/` would trip.

### Rewrite by `product-owner` — 2026-09-28 (round 2), ready for Phase 2 re-evaluation

Each finding was re-verified against the live tree before correcting it. The files checked were `tests/Pest.php`, `app/Actions/ProductCategories/{Create,Rename}ProductCategory.php`, `app/Concerns/ProductCategoryValidationRules.php`, `database/factories/{ProductCategory,StoreLanguage}Factory.php`, `database/seeders/StoreLanguageSeeder.php`, `app/Actions/Products/TranslateProductVariantUniqueViolation.php` and Pest's `UsesCall`. **Phase 2 is not self-approved here; the story returns to `code-reviewer`.**

1. **Blocking (non-default write coverage)** — resolved on the reviewer's recommended route. The decision and the rejected alternative are recorded as the new **D-17**, with a claim-by-claim ownership table.
   - **Gherkin, per-language uniqueness and blank refusal:** re-phrased as rule-layer checks for a non-default language. They make no "no row written" claim.
   - **Gherkin, the two primitive scenarios:** re-phrased as the unguarded `SetTranslation` storing or replacing a row.
   - **Gherkin, authorization:** now stated only through `Rename`/`Create`. That gives three scenarios, including `products.create`-without-`products.edit`. The "no store-language permission" scenario now runs against a self-authorizing action, so it is no longer vacuous.
   - **Tests, rule layer:** a new Feature file, `ProductCategoryNameRulesPerLanguageTest`, calls `Validator::make` through the trait harness with a non-default `$storeLanguageId`.
   - **Tests, primitive:** a new direct-call `SetTranslationTest` block.
   - **1062 vs FK:** extracted into `TranslateProductCategoryNameUniqueViolation`, following the shipped `TranslateProductVariantUniqueViolation` precedent. It is unit-tested with constructed exceptions (name index, the other unique index, 1452, no `errorInfo`), and wired end to end through the adapted race tests. D-7 (ii) and the Create/Rename bullet are updated.
   - **Acceptance criteria:** the authorization AC now covers default-language writes only.
   - **Handed to 0071:** French-write authorization, blank refusal and "no row written". 0071 already owns their tests.
   - **Minimal consistency edits to 0071:** its `SetProductCategoryTranslation` contract now reuses the translator with its derived `names.{id}` key, and two sentences that called the blank refusal "0070's scenario" now cite D-17. Nothing else in 0071 was touched, so its stale banner/R-1/R-3 lines are left for its own Phase 2, as the reviewer asked.
2. **Unit vs Feature** — the fallback tests are relabelled Feature (`tests/Feature/Models/HasTranslationsTest.php`), and a suite-placement rule is stated at the top of the tests section. The per-field two-field fixture is pinned to an in-memory `setRelation()` shape, so it needs no DDL. The policy test is moved to `tests/Feature/Policies/`. The `tests/Pest.php` change is now the exact chained call, `->beforeEach(fn () => StoreLanguage::flushDefaultStoreLanguage())` on the existing Feature/Browser binding, with the reason `Unit` is excluded.
3. **Null memo** — resolved by memoising the "no default" answer through a separate `$defaultResolved` flag (code block, D-10), rather than accepting one query per row. Safety is argued: every model-path writer, including the seeder and the factory, fires `saved`. A new query-count test pins it.
4. **Flush after `DB::table()->update()`** — the "no default store language row at all" test now names the explicit `flushDefaultStoreLanguage()` call and why it is needed.
5. **`withoutTranslations()`** — it now explicitly provisions no store language either. A new factory test pins that, and the backfill-refusal test asserts `store_languages` is empty before acting.

## Phase 2 — INVEST validation (re-evaluation round 3)

**2026-09-28 — `code-reviewer`: ✅ APPROVED — passes to Phase 3.** This is a full re-validation of the rewritten file from scratch, checked against the live tree. It is not only a check that the round-2 gap is closed. INVEST: **Independent** (0071 now depends on 0070 one way; 0070 claims nothing that needs 0071's action), **Negotiable** (Q1–Q3 decided, and the project owner may still overturn them), **Valuable** (a recipe for four siblings, plus the 0069 usage count), **Estimable** (every file, signature and consumer is enumerated), **Small** (large, but it cannot be split: the column drop forces the consumer migration into the same story, per D-15), and **Testable** (every claim maps to a surface this story ships; see 1).

1. **The D-17 split is real.** Every Gherkin scenario, every "Tests to perform" line and every AC was checked against the three write paths in scope.
   - Resolution, fallback and default-change scenarios are **reads**. They are arranged through factories, and the default change goes through 0068's shipped `SetDefaultStoreLanguage`.
   - The two primitive scenarios are direct `SetTranslation` calls.
   - The four name-rule scenarios are rule-layer checks, with no "row written" claim.
   - The three authorization scenarios are `Rename`/`Create`, so they use the default language only.
   - The backfill scenarios call the extracted class.
   - The screen scenarios reach `Create`/`Rename` through the D-15 component.

   The race tests are adaptable. The existing `CreateProductCategoryTest.php:134` drives the collision through `DB::listen`, and the same technique works against `product_category_translations`. The whitespace-trim claim holds, because both actions `trim()` before validating today. **No item points at a guarded non-default write.** The ACs at 644, 647 and 648 are worded consistently with this.
2. **`TranslateProductCategoryNameUniqueViolation` is well specified.**
   - **Location:** `app/Actions/ProductCategories/`.
   - **Signature:** `__invoke(QueryException $e, string $errorKey = 'name'): ValidationException`. It returns the exception for the name index and rethrows everything else.
   - **Branches:** 1062 on the name index, 1062 on the other unique index, 1452, and no `errorInfo`.

   The precedent `App\Actions\Products\TranslateProductVariantUniqueViolation` exists and has the assumed shape: stateless, container-resolved, constructor-injected by three actions, matching on the index name with `str_contains($e->getMessage(), …)`, and ending in `throw $e`. There is one deliberate divergence, and it is correct. The precedent takes `UniqueConstraintViolationException`, but a 1452 FK violation is a plain `QueryException` in Laravel, so the wider parameter type is required for the FK branch to be reachable at all. The index names cannot collide as substrings: `…_product_category_id_store_language_id_unique` does not contain `…_store_language_id_name_unique`. The tests use `uses(TestCase::class)` for `trans()`, following the precedent in `tests/Unit/Models/StoreLanguageFixtureTest.php` and about 15 other Unit files.
3. **The "DB → Feature, never Unit" rule is existing convention, not a new rule.** `docs/testing/backend/unit-tests.md` (line 5, "if your test needs the database … put it in `tests/Feature/`") and `docs/testing/philosophy.md` (the Unit row) already state it. Nothing in the story contradicts it:
   - D-11 names no suite.
   - The only Unit items are DB-free: the pure `translationRowsFor()` transform, the translator with constructed exceptions, and the existing reflection-only `ProductCategoryValidationRulesTest` harness, which never invokes the uniqueness closure.
   - The rule-layer uniqueness tests are correctly Feature.
4. **The `tests/Pest.php` binding is correct for the installed Pest v4.7.5.** `Pest\PendingCalls\UsesCall::beforeEach(Closure $hook): self` exists, as do `extend`/`use`/`in`, all of which return `self`. Hooks are registered in `__destruct`, so their position in the chain does not matter. `flushDefaultStoreLanguage()` is declared `public static` in both the code block and the public-contract block.
5. **`$defaultResolved` does not reintroduce the memo bug.** `flushDefaultStoreLanguage()` resets **both** `$defaultCache` and `$defaultResolved`. Every model-path write to `store_languages` fires `saved`: the three 0068 actions (`save()`/`forceCreate()`, verified by grep), `StoreLanguageSeeder::forceCreate` and the factory. `saved` fires even on a clean `save()`. The only bypass is a raw `DB::table()` write, which the one test that uses it now flushes explicitly. The query-count test pins both halves: one query with no default, and pickup with no manual flush after `factory()->default()->create()`.
6. **The three 0071 edits are consistent with 0070.** They are the translator reuse with a derived `names.{id}` key (line 303), the `SetProductCategoryTranslationTest` blank bullet citing D-17 (line 379) and the D-7 sentence citing D-17. There is no duplicated responsibility: 0071 line 436 still assigns the fold matrix and the misattribution guard to 0070.

Non-blocking. Fix these in Phase 3, or in 0071's own Phase 2:

- **a. (0070) One Gherkin scenario contradicts the test file's "every case passes a NON-default id".** *"The same name in two different store languages is permitted"* arranges French and then checks **Spanish**, which is the default in that file's fixture. ✅ Flip it: arrange "Chaussures" in Spanish and check it in **French**. That exercises the non-default id and proves the same thing.
- **b. (0070) The `tests/Pest.php` snippet omits `use App\Models\StoreLanguage;`.** It is trivial, but add the import when implementing.
- **c. (0071, for its own Phase 2) Two items are still stale:**
  - Q-1 (lines 817–819) still quotes 0070's *"A blank translation is refused"* scenario. That scenario no longer exists in 0070; it is now D-17 plus the rule-layer scenario.
  - 0071's `SetProductCategoryTranslation` code block (lines 260–295) neither constructor-injects `TranslateProductCategoryNameUniqueViolation` nor shows its `try`/`catch`, which contradicts its own line 303.

  Neither item affects 0070's scope.

*Not run:* the test suite. Phase 2 validates a specification, and no application code, test or migration was changed by this review or by the rewrite. The full-suite gate belongs to Phase 3/5.

### Phase 2 non-blocking fixes applied — 2026-09-28

Items **a** and **b** above were applied directly to this file (trivial, textual, no design decision involved):

- The *"The same name in two different store languages is permitted"* scenario now arranges "Chaussures" in Spanish (the default) and checks it in French, matching `ProductCategoryNameRulesPerLanguageTest`'s "every case passes a NON-default id" convention.
- The `tests/Pest.php` code block now includes `use App\Models\StoreLanguage;`.

## Phase 3 — TDD, complete

`database-expert` built the schema layer (migrations, `BackfillProductCategoryTranslations`, `ProductCategoryTranslation`, both factories). `backend-qa` wrote 8 failing test files (red). `backend-expert` implemented `HasTranslations`, `SetTranslation`, the `StoreLanguage`/`ProductCategory` model changes, the widened `ProductCategoryValidationRules`, `TranslateProductCategoryNameUniqueViolation`, the `Create`/`RenameProductCategory` migration onto the mechanism, `config/store-languages.php`, `tests/Pest.php`, and the four D-15 consumers — plus a new `app/Concerns/Translatable.php` interface, not in the original file list, needed because `HasMany`'s `TDeclaringModel` generic is invariant so no interface can declare `translations(): HasMany` compatibly across concrete models; it exposes only `firstTranslationOrNew()`. `backend-qa` confirmed green: the 8 new files, every adapted pre-existing file, both Browser tests, and the full unscoped suite (4720 tests, 4717 passed, 3 skipped, 0 failed).

## Phase 4 — Security audit, complete (2026-09-28)

`appsec-auditor` found one Low finding, **F-1**: `TranslateProductCategoryNameUniqueViolation` discriminated MySQL's 1062 by `str_contains($e->getMessage(), ...)`, which embeds already-interpolated, user-controlled text (the category name itself), and never checked `errorInfo[1] === 1062` first — a crafted name could make an unrelated DB error (the other unique index, a 1452 FK violation, a deadlock) get misreported as "name already taken". `backend-expert` fixed it: check `errorInfo[1] === 1062` first, then match the index name against `errorInfo[2]` (the driver's own message, never `getMessage()`) anchored at the string's end; added the regression test that reproduces the original bug. Re-audited and confirmed: ✅ no findings pending. Wrote `docs/security/constraint-violation-discrimination.md` as the durable rule, referenced from `docs/security/README.md`.

## Phase 5 — Final code review, round 1: ❌ returns to Phase 3 (2026-09-28)

`code-reviewer` ran the full unscoped suite itself (not trusting the prior report) and found it red: 4721 tests, 4717 pass, **1 fails**, 3 skipped. Pint (unscoped, read-only `--test`) and Larastan level 7 (unscoped) are both clean — explicitly run and recorded, not inferred from a prior pass. Three findings, all traced to real causes:

1. **Blocking, the one red test — `backend-qa`'s to fix.** `tests/Feature/StoreLanguages/TranslationRelationsRegistryTest.php:52`'s drift-guard half calls `Schema::getTableListing(schemaQualified: false)` with no `$schema` argument, which on MySQL lists tables across **every database on the server**, not just the current connection's. This repo's own worktree convention (one `testing_<id>` database per worktree, same shared MySQL server — `docs/testing/worktree-databases.md`) means `product_category_translations` legitimately exists in more than one database at once during normal parallel development, so the guard's set-equality check sees a duplicate and fails — deterministically, not a flake, and it will recur for every sibling story (0072/0074/0076/0078) that copies this table shape unless fixed here first. Fix: scope the call to the current connection, `Schema::getTableListing(schema: DB::connection()->getDatabaseName(), schemaQualified: false)` (or `Schema::getCurrentSchemaListing()`). Also remove the now-stale red-phase TDD comments in that file ("Fails today because…", "out of this agent's scope to edit").
2. **Blocking, code regression — `backend-expert`'s to fix.** `app/Livewire/ProductCategories/Index.php:309-326` and `app/Livewire/Products/Editor.php:497-514` sort categories with `$nameA <=> $nameB`, a byte comparison, replacing the old `orderBy('name')` which ran under the column's `utf8mb4_unicode_ci` collation (case- and accent-insensitive). Byte order puts `"Zapatos"` before `"bolsos"` and sorts `"Árboles"`/`"Ñandú"`/`"Óptica"` after every ASCII name — a real behavior change from what shipped, breaking D-15's "preserving today's behaviour exactly" promise, invisible to the existing tests because they only use ASCII, initial-capital names. Both call sites duplicate the same comparator, and all four sibling stories would otherwise copy the bug. Fix: one shared, collation-aware comparator (not a third copy), with a test using `['Zapatos', 'bolsos', 'Árboles']`.
3. **Blocking, missing coverage the Gherkin/"Tests to perform" already require — `backend-qa`'s to fix.** (a) A category with no default-language name renders `—`, untested (no test asserts the em dash). (b) Renaming a category in the default language leaves an existing French translation byte-for-byte untouched, untested (no Rename/Index test seeds a second language first). (c) `ProductCategory::factory()->create()` called twice against an empty `store_languages` table provisions **one** default language and the second `create()` reuses it rather than racing a second `is_default` row — the exact risk the story itself flags, uncovered outside the `withoutTranslations()` half already covered in `BackfillProductCategoryTranslationsTest.php:25`. (d) With no default store language at all, the Product Categories screen and the product editor still render (story line ~560) — today only proved at the model layer (`translated()`/`withTranslationsFor()`), never through the Livewire components themselves.

Non-blocking, for `docs-keeper` at Phase 6: the `app/Concerns/Translatable.php` interface is confirmed well-justified (`HasMany`'s invariant generic forces it; `updateOrCreate()` would silently drop `store_language_id` through the fillable guard, which is why `firstTranslationOrNew()`+`forceFill()` exists instead) and belongs in the recipe/public-contract sections; the natural-key unique index's real name is `product_category_translations_category_language_unique` (the default-generated name is 74 characters, over MySQL's 64-character identifier limit), not the name the story's own code sample shows at lines ~394/583 — docs should cite the real one. §6's seven backlog items are confirmed genuinely deferred to other stories; none blocks this one.

Read-only review; no application code, test, migration or this file's prior sections were changed by `code-reviewer`. Returning to Phase 3 for findings 1 and 3 (`backend-qa`) and finding 2 (`backend-expert`), then re-running the full unscoped suite and Phase 5 again.

Item **c** is left as-is — it belongs to 0071's own Phase 2, not to this story.

**Phase 2 is now closed: ✅ passed (round 3), non-blocking findings resolved. Proceeding to Phase 3.**
