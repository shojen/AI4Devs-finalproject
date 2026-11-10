# [0076b] Product translation write action (backend)

## Description
Adds `App\Actions\Products\SetProductTranslation`, the self-authorizing, self-validating action that writes **one product's five translatable fields in one store language** — `name`, `description`, `slug`, `meta_title`, `meta_description` — over story 0070's deliberately unguarded `App\Actions\Translations\SetTranslation` primitive. It is the backend layer of the two-layer write guard (decision **D-17**, human decision 2026-08-30: *"everything must be controlled from both front and back for security"*) that the product editor's language tabs ([0077](../0077-product-editor-language-tabs-ui.md)) call for every non-default store language. It also widens `TranslateProductUniqueViolation` so a duplicate-key race on this path lands on the per-language error key.

**Origin.** Split out of 0077 on 2026-11-10 by the project owner (decision **A**) after 0077's Phase 2 rejection: 0077 had a backend action inside a story classified frontend. Per the [task ordering rule](../../../docs/workflow/task-files-links-and-ordering.md#task-ordering-rule) the backend half takes a number below its consumer, so it is `0076b` (the `0076a` split-suffix precedent), not `0088`.

**Builds on, all shipped:** [0070](0070-translatable-content-mechanism-product-categories-backend.md) (`SetTranslation`, `HasTranslations`, `StoreLanguage`), [0071](0071-product-categories-language-tabs-ui.md) (**D-13** master contract, **L-1** widened translator, **B-1**), [0075](0075-blog-tags-language-tabs-ui.md) (**D-3** inactive-language refusal), [0076](0076-translatable-content-retrofit-products-backend.md) (`product_translations`, `ProductTranslation`), [0076a](0076a-product-slug-and-seo-fields-backend.md) (slug/SEO columns, `CanonicalizeProductSlug`, the three rule methods, `TranslateProductUniqueViolation`).

## Type
backend | includes database-expert: **no** (no migration, model, column, index or query-scope change)

## 1. Refined user story

> **As** a catalog administrator running a store that sells in more than one language,
> **I want** every write of a product's content in a given store language to be authorized and validated by the backend itself,
> **so that** no screen, importer or command can store a translation I am not allowed to write, in a language the store no longer offers, or with content that breaks the catalog's rules.

**Scope fence.** No screen, Livewire component, route, permission, policy, migration or Blade view. The only callers in this story are tests; the editor wiring is 0077's. `CreateProduct` / `UpdateProduct` keep their signatures and keep writing the default store language.

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and has exactly one `When` ([gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3). "Store a translation" means calling the action directly, with no screen involved: that is the layer this story proves.

```gherkin
Feature: Guarded per-store-language product content writes

  # --- Writing ---

  Scenario: A catalog administrator stores a product's content in an additional language
    Given a catalog administrator holding the products edit permission, with French active and a product titled only in Spanish
    When they store a French translation titled "Chaussures de course"
    Then the product holds a French translation titled "Chaussures de course" alongside its Spanish one

  Scenario: Storing a translation again updates it rather than duplicating it
    Given a catalog administrator, with a product already translated into French
    When they store a new French title for the product
    Then the product still holds exactly one French translation, carrying the new title

  Scenario: Storing one language leaves the others untouched
    Given a catalog administrator, with a product titled in Spanish and in French
    When they store a new French title for the product
    Then the Spanish translation is unchanged

  Scenario: Optional fields left empty are stored as absent
    Given a catalog administrator, with French active
    When they store a French translation with only a title
    Then the French description, slug, meta title and meta description are stored as absent rather than empty text

  Scenario: Authoring content does not require managing store languages
    Given a catalog administrator holding the products edit permission and no store-language permission
    When they store a French translation of a product
    Then the translation is stored

  # --- Refusals ---

  Scenario: An administrator without the products edit permission cannot translate a product
    Given an administrator holding only the products view permission
    When they try to store a French translation of a product
    Then the request is refused, the refusal is logged and no translation is stored

  Scenario: An administrator who may only create products cannot translate one
    Given an administrator holding the products create permission but not the products edit permission
    When they try to store a French translation of a product
    Then the request is refused and no translation is stored

  Scenario: A store language that is no longer active cannot receive content
    Given a catalog administrator, with French deactivated as a store language
    When they try to store a French translation of a product
    Then the request is refused against the French title and no translation is stored

  Scenario: A blank title is refused
    Given a catalog administrator, with French active
    When they try to store a French translation whose title is blank
    Then the request is refused against the French title and no translation is stored

  Scenario Outline: Over-long SEO content is refused
    Given a catalog administrator, with French active
    When they try to store a French translation whose <field> exceeds its allowed length
    Then the request is refused against the French <field> and no translation is stored

    Examples:
      | field            |
      | meta title       |
      | meta description |

  # --- Slugs ---

  Scenario: Two products cannot share a slug within one store language
    Given a catalog administrator, with another product whose French slug is "chaussures-de-course"
    When they try to store this product's French slug as "chaussures-de-course"
    Then the request is refused against the French slug

  Scenario: A slug colliding only after canonicalisation is still refused
    Given a catalog administrator, with another product whose French slug is "chaussures-de-course"
    When they try to store this product's French slug as "  Chaussures De Course  "
    Then the request is refused against the French slug, because the collision is judged on the canonical form

  Scenario: The same slug in two different store languages is permitted
    Given a catalog administrator, with a product whose Spanish slug is "chaussures-de-course"
    When they store that product's French slug as "chaussures-de-course"
    Then the translation is stored

  Scenario: A product keeps its own slug when re-saved
    Given a catalog administrator, with a product whose French slug is "chaussures-de-course"
    When they store that product's French translation again with the slug unchanged
    Then the translation is stored rather than refused as a duplicate

  Scenario: Two products may both have no slug in the same language
    Given a catalog administrator, with another product holding no French slug
    When they store this product's French translation with an empty slug
    Then the translation is stored with no slug

  Scenario: A slug taken by a concurrent save is reported against the slug field
    Given a catalog administrator whose French slug is claimed by another product between validation and storage
    When the store attempt reaches the database
    Then the request is refused against the French slug rather than failing with a server error

  # --- Content safety ---

  Scenario: A description is stored safely whatever the caller
    Given a catalog administrator, with French active
    When they store a French description containing a script tag
    Then the stored French description holds no script tag

  Scenario: A description that fits only after sanitisation is accepted
    Given a catalog administrator, with French active
    When they store a French description whose raw markup exceeds the length limit but whose sanitised form does not
    Then the translation is stored
```

## Files to create/modify

### Create

| Path | Purpose |
| --- | --- |
| `app/Actions/Products/SetProductTranslation.php` | The action. Contract in [D-1](#4-documented-functional-decisions). Scaffold with `php artisan make:class`. |
| `tests/Feature/Products/SetProductTranslationTest.php` | Direct-call tests; mounts no component. |

### Modify

| Path | Change |
| --- | --- |
| `app/Actions/Products/TranslateProductUniqueViolation.php` | Optional trailing `?string $storeLanguageId = null` (**D-4**). Backward compatible: `CreateProduct` / `UpdateProduct` call it unchanged. |
| `tests/Unit/Actions/Products/TranslateProductUniqueViolationTest.php` | Cases for the re-keyed form; existing cases unchanged. |
| `lang/en/products.php` / `lang/es/products.php` | `products.attributes.name`, `products.attributes.description` (beside the existing `slug` / `meta_title` / `meta_description`), and `products.editor.tabs.language_inactive`. Key-for-key identical. |

### Deliberately not touched

`SetTranslation`, `HasTranslations`, `ProductTranslation`, `Product`, `StoreLanguage`, `ProductPolicy`, `RolePermissionSeeder`, `ProductValidationRules` (composed, no method added), `CanonicalizeProductSlug`, `SanitizeProductDescription`, `CreateProduct`, `UpdateProduct`, every Livewire component and view, `tests/Unit/ProductTranslationArchitectureTest.php` (its `App\Livewire\Products` guard, the `arch()` call at lines 10–12, already covers the "no component imports `SetTranslation`" rule and must stay green).

## Tests to perform — 3. QA test cases / validation scenarios

All in `tests/Feature/Products/SetProductTranslationTest.php`. Every test resolves the action with `app(SetProductTranslation::class)` (it has constructor dependencies) and mounts no component.

- [ ] `products.view`-only actor → `AuthorizationException`, a refusal log line with `target_type` `product` and the product id, **zero** rows written.
- [ ] `products.create`-only actor → refused (the action authorizes `update`, **D-2**).
- [ ] `products.edit` holder with no `store-languages.*` permission → succeeds; Super Admin passes via `Gate::before`.
- [ ] Inactive language → `ValidationException` keyed `names.{languageId}` with the `products.editor.tabs.language_inactive` message, no row; asserted in **both** locales' message presence via the lang key, not a literal.
- [ ] Authorization runs before the inactive-language check (an unauthorized actor targeting an inactive language gets `AuthorizationException`, not a validation error).
- [ ] Blank / whitespace-only `name` → refused on `names.{id}`; no row.
- [ ] `metaTitle` > 160, `metaDescription` > 500 → refused on `metaTitles.{id}` / `metaDescriptions.{id}`; boundary values (160 / 500) accepted.
- [ ] Error messages use the localized attribute label (`products.attributes.*`), not the dotted key — this is the nested-data pitfall (flat `"names.{id}"` data keys never reach the rule; [errors-log](../../../docs/errors-log.md), 2026-10-07 entry). A test whose expected message contains `names.` fails.
- [ ] Slug canonicalised through `CanonicalizeProductSlug` **before** the unique rule: `"  Chaussures De Course  "` vs an existing `chaussures-de-course` (same language, other product) → refused on `slugs.{id}`.
- [ ] Slug uniqueness matrix: same slug, two languages, one product → accepted; same slug, two products, one language → refused; own slug unchanged → accepted, with a second product holding a different slug in the fixture.
- [ ] Insert case: a slug in a language the product has no row in yet → accepted.
- [ ] `''` for description, slug, meta title, meta description → persisted `NULL`; two products with a `NULL` French slug coexist.
- [ ] Re-invocation for the same `(product, language)` → one row, updated.
- [ ] `<script>` in the description → stored sanitised through this path; a description whose raw length exceeds `productDescriptionRules()`'s max but whose sanitised form fits → accepted.
- [ ] 1062 race on `product_translations_language_slug_unique` → `ValidationException` keyed `slugs.{id}` (failure injected the way the 0078 errors-log entry records, e.g. a model `saving` hook); on `product_translations_product_language_unique` → `names.{id}` with `products.editor.concurrent_save_retry`; any other `QueryException` rethrown unchanged.
- [ ] The passed product is re-read after authorization: a product deleted after the caller loaded it → `ModelNotFoundException`, no FK error.
- [ ] Return type is `ProductTranslation` (narrowed from `SetTranslation`'s `Model`).
- [ ] Reference scan over `app/Actions/Products`, scoped to this one directory because other namespaces legitimately use `SetTranslation`. It reuses the `SyncProductGallery` scan of `tests/Feature/Products/ProductAuthorizationTest.php:249-274` (`fileReferencesSyncProductGalleryOutsideComments()`) **including its comment stripping** — `token_get_all()` skipping `T_COMMENT`/`T_DOC_COMMENT` tokens (audit finding **F-5**) — so a docblock or comment that merely mentions `SetTranslation` is not counted as a reference. The set of files referencing `SetTranslation` **equals** `{CreateProduct.php, UpdateProduct.php, SetProductTranslation.php}`.

`TranslateProductUniqueViolationTest.php` (unit): with a language id the slug index maps to `slugs.{id}` and the natural key to `names.{id}`; without one, today's bare `sku` / `slug` / `name` mapping is byte-identical.

**Not tested here:** the sanitiser's allow-list (0024a), `SetTranslation` / `translated()` mechanics (0070), slug canonicalisation as a model hook (0076a), the editor (0077).

## Expected outcome
Any caller can write a product's content in one active store language only through `SetProductTranslation`, which refuses an unauthorized actor (logged), an inactive language, a blank title, over-long SEO text and a duplicate slug in that language — each on a `{field}s.{languageId}` key — sanitises the description and canonicalises the slug before validating, stores blank optional fields as `NULL`, and turns a concurrent duplicate into a field error instead of a 500.

## Acceptance criteria
- [ ] `SetProductTranslation::__invoke(Product $product, StoreLanguage $language, string $name, ?string $description = null, ?string $slug = null, ?string $metaTitle = null, ?string $metaDescription = null): ProductTranslation` exists. `SetTranslation` is reached in `App\Actions\Products` only by `CreateProduct`, `UpdateProduct` and `SetProductTranslation` (asserted by the reference-scan test case above).
- [ ] First statement: `LogRefusedPrivilegedAttempt->authorize('update', $product, targetType: 'product', targetId: $product->id)`; then the product is re-read (`Product::query()->whereKey(...)->firstOrFail()`); no `Gate::authorize`.
- [ ] The language is re-read through `StoreLanguage::query()->active()`; an inactive or missing language is refused on `names.{id}` before any validation or write.
- [ ] Validation uses nested data and the rule methods `productNameRules()`, `productDescriptionRules()`, `productSlugRules($languageId, $product->id)`, `productMetaTitleRules()`, `productMetaDescriptionRules()`, with `names.*` / `descriptions.*` / `slugs.*` / `metaTitles.*` / `metaDescriptions.*` attribute labels.
- [ ] Error keys are derived from the language inside the action, never accepted as a parameter.
- [ ] `TranslateProductUniqueViolation` accepts an optional language id; existing callers' behaviour is unchanged.
- [ ] No new permission, ability, policy, route or migration; `lang/en/products.php` and `lang/es/products.php` stay key-for-key identical.

## Definition of Done
- [x] Tests written and green; full suite green unscoped before closure
- [ ] Pint and Larastan clean (Pint unscoped passed; Larastan not recorded here)
- [x] Code reviewed (`code-reviewer`)
- [x] No security findings (`appsec-auditor`) — point the audit at: authorize-then-re-read order, the inactive-language refusal, derived error keys, the 1062 re-keying, sanitise-before-validate
- [x] Documentation updated (`docs-keeper`): epic-5 digest entry; the 0087 inactive-language rule lists this action
- [x] Acceptance criteria met

## 4. Documented functional decisions

**D-1 — Contract (D-17 of the original 0077, master pattern 0071 D-13).** Modelled on `app/Actions/Blog/SetBlogTagTranslation.php` (the latest sibling, which already refuses inactive languages) and `app/Actions/ProductCategories/SetProductCategoryTranslation.php`. Constructor-injected collaborators (code-style's exception for actions whose `__invoke()` is a public contract): `LogRefusedPrivilegedAttempt`, `SanitizeProductDescription`, `CanonicalizeProductSlug`, `SetTranslation`, `TranslateProductUniqueViolation`; `use ProductValidationRules`. Order inside `__invoke()`:

1. Logged `update` authorization on the passed product (**D-2**).
2. Re-read the product; work only with the fresh copy.
3. Derive `$id = $language->id`; re-read the language through `active()`; refuse if `null` (**D-3**).
4. Trim `name`; sanitise `description` (`SanitizeProductDescription`, before validating so `max:` measures the stored value — 0024a D-16); canonicalise `slug` (`CanonicalizeProductSlug`, before validating so the unique rule compares the stored form — 0076a D-6). Never inline `Str::slug()`.
5. `Validator::make(['names' => [$id => $name], 'descriptions' => [$id => …], 'slugs' => [$id => …], 'metaTitles' => [$id => …], 'metaDescriptions' => [$id => …]], ["names.{$id}" => …, …], [], ['names.*' => __('products.attributes.name'), …])->validate()`.
6. `''` → `null` for description, meta title and meta description (slug already `null` when blank). `name` is never null (`NOT NULL`, 0076 D-2).
7. `try { ($this->setTranslation)($product, $activeLanguage, [...snake_case columns...]) } catch (QueryException $e) { throw ($this->translateUniqueViolation)($e, $activeLanguage->id); }`.
8. Narrow: `instanceof ProductTranslation`, else `LogicException`.

Named parameters, not `array $fields` (original 0077 **D-19**): the signature is the whitelist, and the required/optional split is visible. Called once per language, never once per field.

**D-2 — The action authorizes `update`, always.** A product translation is an edit of an existing product; `ProductPolicy::update()` maps to `products.edit`. No new ability, no translation policy, no `store-languages.*` requirement (authoring content uses a configured language, it does not manage the catalog — 0070 D-13). Consequence for callers: an actor holding only `products.create` cannot write a non-default language, even while creating; 0077 applies 0071 **B-1** at the component for that case.

**D-3 — An inactive store language is refused in the action itself** (owner decision **B**, 2026-11-10; 0075 D-3; the amendment note in [0087](../0087-translation-write-actions-refuse-inactive-store-language.md) §6). Component-side filtering is not enough: the action is the layer that binds callers the editor never sees. Message key `products.editor.tabs.language_inactive`, keyed `names.{id}` like 0075. Ordered after authorization so an unauthorized probe learns nothing about language state.

**D-4 — The duplicate-key translator is widened with a language id, not with `$errorKey` / `$attributeLabel`.** 0071's **L-1** passes one error key because its translator maps one index. `TranslateProductUniqueViolation` maps three indexes to three fields (`products_sku_unique` → `sku`, language-slug → `slug`, product-language natural key → `name`; `TranslateProductUniqueViolation.php:21-38`), so a single `$errorKey` cannot express the mapping. With `$storeLanguageId` given, the slug index maps to `slugs.{id}` and the natural key to `names.{id}`; the SKU branch is unreachable from this path and stays as is. Without it, behaviour is byte-identical. Recorded for Phase 2 because it deviates in form, not intent, from the reviewer's suggested shape.

**D-5 — The default language is not refused.** Like every sibling, the action writes any active language. The editor writes the default through `UpdateProduct`/`CreateProduct` (0077); a direct call for the default is still fully authorized and validated, so refusing it would add a rule with no security value.

## 5. Dependencies, risks, open technical questions

### Dependencies
- `depends_on`: none pending (0070, 0071, 0075, 0076, 0076a are in `done/`).
- Dependent: [0077](../0077-product-editor-language-tabs-ui.md).
- `conflict_risk_with`: [0087](../0087-translation-write-actions-refuse-inactive-store-language.md) — both edit `lang/{en,es}/products.php`, and 0087 adds a structural guard (`tests/Unit/ArchitectureTest.php`) for the inactive-language rule. Whichever lands second makes sure that guard covers `SetProductTranslation` and passes.

### Risks
- **R-1** The 1062 race test needs failure injection; follow the 0078 errors-log technique rather than mocking `SetTranslation`.
- **R-2** `ProductTranslation`'s `saving` hook still has the truncating sanitiser shape 0078's audit flagged (digest, 0078 F-1); out of scope here, but a test that relies on the hook to truncate would hide it. The action's own sanitise-then-validate keeps over-long content from reaching the hook.

### Open questions
None. Decisions A and B were confirmed by the project owner on 2026-11-10.

## 6. Technical tasks for later backlog creation
- When 0087 lands, consider extracting a shared "resolve active language or refuse" helper across the `Set*Translation` actions (0087 D-5).

## Provenance
Split from [0077](../0077-product-editor-language-tabs-ui.md) on 2026-11-10 (owner decisions A and B) after 0077's Phase 2 rejection by `code-reviewer` on the same day. The action contract is the original 0077 **D-17**/**D-19** text, corrected against the shipped code: rule method names are 0076a's, the slug canonicaliser is `CanonicalizeProductSlug`, authorization uses `LogRefusedPrivilegedAttempt` with a re-read, validation uses nested data, the return is narrowed, and the inactive-language refusal is added.

- Phase 1: written 2026-11-10 (`product-owner`) from the split.
- 2026-11-10 (`product-owner`): Phase 2 re-review defects applied — the `SetTranslation` acceptance criterion reworded (CreateProduct/UpdateProduct also import it) with a matching reference-scan test case (comment-stripping scan, F-5); architecture-guard citation corrected to lines 10–12.
- Phase 2 APPROVED 2026-11-10 (code-reviewer, delta confirmation after the split and defects 1-7); owner decisions A (split), B (inactive languages refused from day one), and keeping the 0071/0075 precedent of no permission check on setActiveLanguageTab() are recorded.

- Phase 3 (2026-11-10): red 31 feature + 5 unit tests, then green; backend-qa's flagged assumptions all held.
- Phase 4 appsec PASS (2026-11-10). L-1 (check-to-write race on language deactivation) and L-2 (product deleted between re-read and save gives an FK 1452 500, same as `SetProductCategoryTranslation`) ACCEPTED and recorded; the fix belongs to the shared helper backlogged by 0087 D-5. I-1: name and meta fields are stored unsanitised, so renderers must escape (carried to 0077 and the storefront).
- Phase 5 code-reviewer APPROVED statically (2026-11-10). N-1 closed (locale dataset). N-2 recorded: the action also trims `metaTitle`/`metaDescription`, which the spec did not ask for.
- Full suite green, unscoped, in chunks: A 1375, B 731, C 1429, D 1041 (1036 passed, 5 skipped), E 743, F 1122, Browser 279 (276 passed, 3 skipped) = 6720 tests, 0 failures; the 8 skips are in no file this story touches. Pint unscoped passed.
- Phase 6 docs synced (2026-11-10, `docs-keeper`).
- Phase 7 closed 2026-11-10, PR pending (task moved `in-progress/` to `done/`; inbound links repointed; `tasks-map.md` and `tasks-status.json` regenerated).
