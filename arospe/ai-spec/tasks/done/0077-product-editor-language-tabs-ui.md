# [0077] Product editor — language tabs (UI)

## Description
Adds per-store-language tabs to the product editor ([PRD Epic 5, Layer 2](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization): *"each active store language surfaces as a tab in the Product and Blog editors"*), so a catalog administrator authors a product's **five** translatable fields — `name`, `description`, `slug`, `meta_title`, `meta_description` — in every active store language from one screen. Non-translatable fields (`sku`, category, `type`, `status`, `price`, `stock`, imagery, sales regions) stay **outside** the tabs and render once.

**What exists today (verified at `HEAD`).** `app/Livewire/Products/Editor.php` and `resources/views/livewire/products/editor.blade.php` (0027) edit the **default language only**: scalar `$name` / `$description` and `#[Locked] ?string $slug / $metaTitle / $metaDescription` (0076a **D-33**, passed through unchanged on edit and `null` on create, `Editor.php:131-138`, `:472-503`). One `WysiwygEditor` (0021, `#[Modelable] $value`) is bound with `wire:model="description"`. `ProductTranslation`, `SetTranslation`, `CanonicalizeProductSlug`, `TranslateProductUniqueViolation` and the shared `resources/views/components/language-tab-strip.blade.php` (0071) are shipped. The guarded per-language write action is [0076b](0076b-product-translation-write-action-backend.md), which this story depends on.

**This story is UI only.** It consumes, unmodified: 0071's tab strip and component pattern, 0076/0076a's `CreateProduct` / `UpdateProduct` signatures and rule methods (`productNameRules()`, `productDescriptionRules()`, `productSlugRules(string $storeLanguageId, ?string $productId)`, `productMetaTitleRules()`, `productMetaDescriptionRules()` — `app/Concerns/ProductValidationRules.php:80,204,217,236,247`), and 0076b's `SetProductTranslation`.

## Type
frontend | includes database-expert: **no** (no migration, model, action, rule method, permission, policy or route)

## 1. Refined user story

> **As** a catalog administrator running a store that sells in more than one language,
> **I want** to author each product's title, description, slug and SEO fields per store language from tabs inside the one product editor, with the product's price, stock, SKU and status shown once outside them,
> **so that** I can translate a product without leaving the screen, and see at a glance which languages are still untranslated.

**Scope fence.** No backend class is added or changed: every non-default language is written through 0076b's `SetProductTranslation`, the default language through `CreateProduct` / `UpdateProduct`. The products list (`App\Livewire\Products\Index`) is not touched; it already orders and displays by translated name (0076: `orderByTranslatedName()` / `translated('name')`).

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and has exactly one `When` ([gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3).

```gherkin
Feature: Per-store-language content in the product editor

  # --- The tab set ---

  Scenario: The editor offers one tab per active store language
    Given a catalog administrator, with Spanish and French active as store languages
    When they open a product in the editor
    Then a language tab is offered for Spanish and for French

  Scenario: A deactivated store language gets no tab
    Given a catalog administrator, with French deactivated as a store language and a product translated into French
    When they open the product in the editor
    Then no tab is offered for French

  Scenario: The default store language's tab is the one shown first
    Given a catalog administrator, with Spanish as the default store language and French also active
    When they open a product in the editor
    Then the Spanish tab is first and active

  Scenario: A store with a single language still presents a coherent editor
    Given a catalog administrator, with Spanish as the only active store language
    When they open a product in the editor
    Then the translatable fields render once, in a single Spanish tab

  # --- Switching ---

  Scenario: Switching tabs shows the target language's title
    Given a catalog administrator, with a product titled "Zapatillas Running" in Spanish and "Chaussures de course" in French
    When they switch to the French tab
    Then the editor shows "Chaussures de course" as the product's title

  Scenario: Switching tabs shows the target language's description
    Given a catalog administrator, with a product described in Spanish and in French
    When they switch to the French tab
    Then the rich-text editor shows the French description and not the Spanish one

  Scenario: Switching tabs keeps unsaved work in the tab being left
    Given a catalog administrator who has typed a new Spanish title without saving
    When they switch to the French tab and back to the Spanish one
    Then their unsaved Spanish title is still there

  Scenario: Switching tabs keeps a description typed moments before
    Given a catalog administrator who has just typed into the Spanish description
    When they switch to the French tab and back to the Spanish one
    Then the Spanish description still holds everything they typed

  Scenario: Switching tabs stores nothing
    Given a catalog administrator with a product open in the editor
    When they switch to the French tab
    Then the product's stored content is unchanged in every store language

  Scenario: Switching tabs leaves the product's shared fields alone
    Given a catalog administrator with a product open in the editor
    When they switch to the French tab
    Then the SKU, price, stock, status, type, category, images and sales regions are unchanged

  Scenario: The product's shared fields are presented once, not once per language
    Given a catalog administrator, with three active store languages
    When they open a product in the editor
    Then the SKU, price, stock, status, type and category are each offered exactly once, outside the language tabs

  # --- Writing a translation ---

  Scenario: A catalog administrator translates a product into an additional language
    Given a catalog administrator, with a product titled only in Spanish and French active
    When they save the product with a French title
    Then the product carries a French translation alongside its Spanish one

  Scenario: Saving one language leaves the others untouched
    Given a catalog administrator, with a product titled in Spanish and in French
    When they save the product with only its French title changed
    Then the Spanish title is unchanged

  Scenario: The default language's slug and SEO fields are now editable
    Given a catalog administrator, with a product open in the editor
    When they save the product with a new Spanish slug and meta title
    Then the Spanish translation holds the new slug and meta title

  Scenario: Creating a product stores its content in the default store language
    Given a catalog administrator holding the products create permission
    When they create a product filling in only the default language's tab
    Then the product holds exactly one translation, in the default store language

  Scenario: A catalog administrator who may also edit products adds a translation while creating
    Given a catalog administrator holding the products create and edit permissions
    When they create a product with a Spanish and a French title
    Then the new product holds a Spanish and a French translation

  Scenario: An administrator who may only create products cannot add a translation while creating
    Given an administrator holding the products create permission but not the products edit permission
    When they create a product with a Spanish title and a French title
    Then the save is refused and no product is created

  Scenario: Non-default tabs are shown as locked to an administrator who may only create
    Given an administrator holding the products create permission but not the products edit permission
    When they open the editor to create a product
    Then the non-default language tabs explain that adding a translation needs the edit permission

  Scenario: Content naming a deactivated language does not block an administrator who may only create
    Given an administrator holding the products create permission but not the products edit permission, with German deactivated as a store language
    When they create a product with a Spanish title and a submission carrying a German title
    Then the product is created with only its Spanish translation and no refusal is recorded

  # --- Untranslated and blank tabs ---

  Scenario: An untranslated tab opens empty and names what it falls back to
    Given a catalog administrator, with a product titled only in the default store language
    When they open the product's French tab
    Then the French fields are empty and a notice says the product has no French content yet

  Scenario: Saving an untranslated tab without filling it in creates no translation
    Given a catalog administrator viewing a product's empty French tab
    When they save the product without entering any French content
    Then the product still holds no French translation

  Scenario: A partially filled tab requires its title
    Given a catalog administrator who has entered a French slug and left the French title empty
    When they save the product
    Then the save is refused against the French title

  Scenario: A partially filled tab needs no slug or SEO content
    Given a catalog administrator who has entered a French title and left the French slug and SEO fields empty
    When they save the product
    Then the save is accepted and the French translation is stored without a slug

  Scenario: Emptying a language that was already translated is refused
    Given a catalog administrator, with a product already translated into French
    When they save the product with every French field cleared
    Then the save is refused, because clearing a tab is not how a translation is removed

  Scenario: The default language's title is always required
    Given a catalog administrator with a product open in the editor
    When they save the product with the Spanish title cleared
    Then the save is refused against the Spanish title

  Scenario: A product translated in no language at all opens without error
    Given a catalog administrator, with a product holding no translation in any store language
    When they open the product in the editor
    Then every language tab opens with empty fields and no error is raised

  # --- Refusals ---

  Scenario: A refusal leaves every language unwritten
    Given a catalog administrator who has entered a valid Spanish title and a French slug with no French title
    When they save the product
    Then neither the Spanish nor the French content is changed

  Scenario: A refusal in a language the administrator is not looking at is surfaced
    Given a catalog administrator on the Spanish tab, having entered a French slug but no French title
    When they save the product
    Then the French tab is marked as carrying the problem and becomes the active tab

  Scenario: A refusal on the default language's field lands on its own tab
    Given a catalog administrator on the French tab, with another product already holding the Spanish slug they typed
    When they save the product
    Then the Spanish tab is marked as carrying the problem and becomes the active tab

  Scenario: Two products cannot share a slug within one store language
    Given a catalog administrator, with another product whose French slug is "chaussures-de-course"
    When they save this product's French slug as "chaussures-de-course"
    Then the save is refused with the reason shown beside the French slug field

  Scenario: The same slug in two different store languages is permitted
    Given a catalog administrator, with a product whose French slug is "chaussures-de-course"
    When they save that same product's Spanish slug as "chaussures-de-course"
    Then the save is accepted

  Scenario: A slug colliding only after canonicalisation is still refused
    Given a catalog administrator, with another product whose French slug is "chaussures-de-course"
    When they save this product's French slug as "  Chaussures De Course  "
    Then the save is refused, because the collision is judged on the canonical form

  Scenario: Two products may both be saved without a slug in the same language
    Given a catalog administrator, with another product holding no French slug
    When they save this product leaving its French slug empty
    Then the save is accepted

  Scenario Outline: Over-long SEO content is refused in any store language
    Given a catalog administrator, with French active as a store language
    When they save a product whose French <field> exceeds its allowed length
    Then the save is refused with the reason shown beside that field

    Examples:
      | field            |
      | meta title       |
      | meta description |

  # --- The slug affordance ---

  Scenario: An empty slug is pre-filled from the title of its own language
    Given a catalog administrator who has typed "Chaussures de course" as a product's French title
    When they leave the French title field
    Then the French slug field is pre-filled with "chaussures-de-course"

  Scenario: A slug the administrator already typed is never overwritten
    Given a catalog administrator, with a product whose French slug is "souliers"
    When they change the French title and leave the field
    Then the French slug still reads "souliers"

  # --- Content safety ---

  Scenario: A description written in a non-default language is stored safely
    Given a catalog administrator on a product's French tab
    When they save a French description containing a script tag
    Then the stored French description holds no script tag

  # --- Authorization and tampering ---

  Scenario: An administrator without the products edit permission cannot translate a product
    Given an administrator holding only the products view permission
    When they submit a product save carrying a French title
    Then the save is refused and the product holds no French translation

  Scenario: A submission naming a store language that is not active writes nothing for it
    Given a catalog administrator, with French active and German deactivated as a store language
    When they submit a product save carrying content for German
    Then the product's stored translations cover only the active store languages

  Scenario: A forged tab switch to a language that is not active is refused
    Given a catalog administrator, with German deactivated as a store language
    When they request a switch to the German tab
    Then the request is refused as not found and the active tab does not change
```

## Files to create/modify

Phase 3 of this story must not run in the same batch as any story touching the files below (Parallel Agent File-Ownership Rule); today that is [0087](../0087-translation-write-actions-refuse-inactive-store-language.md) (`lang/{en,es}/products.php`).

### Modify

| Path | Change |
| --- | --- |
| `app/Livewire/Products/Editor.php` | Replace `$name`, `$description` and the three `#[Locked]` default-language SEO scalars with the five per-language arrays and tab state (**D-2**); `mount()` hydration from raw rows (**D-6**); `setActiveLanguageTab()` (**D-7**); `prefillSlug()` (**D-9**); `save()` recomposition (**D-5**), create-path check (**D-8**) and error re-keying (**D-10**); `#[Computed] languages()` and the error-language list for the strip. |
| `resources/views/livewire/products/editor.blade.php` | `<x-language-tab-strip>` (unmodified), one always-mounted panel per active language with its five fields and its own `WysiwygEditor` (**D-1**); new hooks on the non-translatable controls (**D-11**). |
| `lang/en/products.php` / `lang/es/products.php` | `products.editor.tabs.*` UI strings (untranslated notice, edit-permission lock notice, field labels not already present). Extend, never recreate; key-for-key identical. The attribute labels and `language_inactive` key are 0076b's. |
| `tests/Feature/Products/EditorTest.php` | Every `name` / `description` / `slug` / `metaTitle` / `metaDescription` assertion retargets to `names.{defaultId}` etc. |
| `tests/Feature/Products/EditorRenderingTest.php` | All N panels present at once; per-language hooks inside their panel; non-translatable hooks exactly once and outside every panel; error marker absent when clean. |
| `tests/Feature/Products/ScreenAuthorizationTest.php` | Component-level gate cases (this file holds the `Editor` gates; `ProductAuthorizationTest.php` tests the actions directly and is not touched). The file does not enumerate public methods, so each new method gets explicit cases: an allow/deny pair for `prefillSlug()` and the cases for `setActiveLanguageTab()` listed under *Tests to perform*; `product_translations` added to the existing `Editor::save()` refusals' "writes nothing" assertions. |
| `tests/Browser/Products/EditorJourneyTest.php` | One extra leg: author a second language, save, reopen, both survive. |

### Create

| Path | Purpose |
| --- | --- |
| `tests/Feature/Products/EditorLanguageTabsTest.php` | Tab set, order, hydration, switching, tamper. |
| `tests/Feature/Products/EditorTranslationsTest.php` | Save composition, default vs non-default write split, the three blank-tab states, create path. |
| `tests/Feature/Products/EditorTranslationValidationTest.php` | Per-language validation and error keys through the component. |
| `tests/Browser/Products/EditorLanguageTabsTest.php` | Real-DOM cases (**D-13**). |

### Deliberately not touched
`app/Actions/**` (incl. 0076b's action), `app/Concerns/ProductValidationRules.php`, `app/Models/**`, `app/Policies/ProductPolicy.php`, `database/**`, `routes/**`, `config/**`, `app/Livewire/Components/WysiwygEditor.php` and its view, `resources/views/components/language-tab-strip.blade.php`, `lang/{en,es}/components.php`, `app/Livewire/Products/Index.php`, and `tests/Unit/ProductTranslationArchitectureTest.php` — its existing `App\Livewire\Products` rule (the `arch()` call at lines 10–12) already forbids `SetTranslation` in this namespace and must stay green; no new guard is added.

## Tests to perform — 3. QA test cases / validation scenarios

This screen hits all three recorded `Livewire::test()` blind spots at once (an uncompiled attribute, a `null`-bound control, a `wire:ignore`d region), so the browser level carries real weight.

### `tests/Feature/Products/EditorLanguageTabsTest.php`
- [ ] Tab set equals the **active** languages, default first then by name (0071 D-14); an inactive language with a stored translation gets no tab and no hydrated value.
- [ ] Loading hydrates all five fields for every language, asserted field-by-field across all five, from the language's **own** row; an untranslated language hydrates `''` everywhere (never `translated()`'s fallback).
- [ ] No hydrated value is `null` (every leaf is `string`).
- [ ] `setActiveLanguageTab()` with an active id switches; with an inactive, unknown or malformed id it throws `ModelNotFoundException` (`StoreLanguage::query()->active()->findOrFail()`, as the three shipped siblings), leaves `$activeLanguageId` unchanged and writes nothing.
- [ ] `$activeLanguageId`, `$defaultLanguageId`, `$originalTranslatedLanguageIds`, `$canAuthorTranslations` are `#[Locked]`: a client `set()` on each throws.
- [ ] Switching tabs writes nothing: product count, `product_translations` rows and `updated_at` unchanged.
- [ ] The non-translatable properties are identical across a switch, compared as one array.
- [ ] **Tamper:** array entries for an inactive, nonexistent or never-rendered language id write no row; the persisted `store_language_id` set **equals** the expected set (not `toContain`).
- [ ] Single-active-language store renders and saves.

### `tests/Feature/Products/EditorTranslationsTest.php`
- [ ] Default language written through `UpdateProduct` / `CreateProduct` with `$names[$default]` etc. (slug and SEO no longer passed as `null` on create); non-default languages through `SetProductTranslation`, one call per engaged language. Editing only the French tab leaves the Spanish row unchanged.
- [ ] **Three blank-tab states, one test each (D-4):** untouched → no row, no error; engaged → `names.{id}` required; blanked (previously translated) → refused on `names.{id}`, row intact.
- [ ] A forged `$originalTranslatedLanguageIds` cannot turn a blanked tab into an untouched one (locked; and `save()` re-reads the stored rows, not the property).
- [ ] Blank optional fields reach the database as `NULL` (via 0076b); two products with an empty French slug both save.
- [ ] `<script>` in the French description is stored sanitised (one test; the allow-list is 0024a's).
- [ ] One transaction: a refusal from `SetProductTranslation` for a later language (e.g. a slug race) rolls back the default-language update, the region sync and earlier languages, and keeps typed values.
- [ ] **Create path (D-8):** create + edit holder creates with Spanish and French; create-only holder with a non-blank French field is refused **before** validation and before the transaction, with a logged refusal, and no product is created; create-only holder with blank French fields creates normally; create-only holder whose payload carries a non-blank value under a **deactivated** language's id (and blank active non-default fields) creates normally — the key is dropped by narrowing (D-3) before the D-8 check, so no refusal is logged and no row is written for that language.

### `tests/Feature/Products/EditorTranslationValidationTest.php`
- [ ] Error keys are `names.{id}`, `descriptions.{id}`, `slugs.{id}`, `metaTitles.{id}`, `metaDescriptions.{id}` for every language **including the default** (the action's `name` / `slug` / `meta_title` / … keys are re-keyed, **D-10**).
- [ ] Messages use the localized attribute labels, never the dotted key.
- [ ] Slug: same slug two languages one product → accepted; two products one language → refused on `slugs.{id}`; own slug unchanged → accepted (fixture includes a second product with a different slug); slug in a language with no row yet → accepted; canonicalisation before the rule (`"  Chaussures De Course  "`).
- [ ] Blank title on an engaged tab refused in the active tab and in a non-active one; the default's blank title refused unconditionally.
- [ ] `metaTitle` > 160 / `metaDescription` > 500 refused on their own keys.
- [ ] A refused save writes nothing in `products`, `product_translations` (exact row set), `product_media`, `product_sales_region`.
- [ ] After a refusal, `$activeLanguageId` is the first errored language in tab order.
- [ ] Stale error bag: a French error followed by a tab switch does not render the French message inside the Spanish panel.

### `tests/Feature/Products/ScreenAuthorizationTest.php` (extend)
- [ ] `Editor::prefillSlug()` allow: an actor holding the mode's ability (`products.edit` on edit, `products.create` on create) gets the slug pre-filled. Deny: the actor's permission is revoked after mount → `AuthorizationException`, `$slugs` unchanged, refusal logged.
- [ ] `Editor::setActiveLanguageTab()`: no Gate check by design, matching the shipped siblings (`ProductCategories/Index.php:160-172`, "discloses nothing the open modal does not already hold"); allow: an authorized actor switches to an active id; deny: an inactive id → `ModelNotFoundException`, tab unchanged, nothing written. Only actors who passed `mount()`'s gate can reach it.
- [ ] The existing `Editor::save()` refusal tests also assert `product_translations` is unchanged.

### `tests/Feature/Products/EditorRenderingTest.php` (extend)
- [ ] All N `language-panel-{id}` elements are in the DOM simultaneously (catches `@if` replacing `x-show`).
- [ ] Each translatable hook sits inside its own panel; each non-translatable hook (`product-sku-input"` matched **with** its closing quote) appears exactly once and outside every panel, using a three-language dataset and counts derived from the language count.
- [ ] `language-tab-error-{id}` present only for languages with errors.
- [ ] Assertions are scoped to a panel (page-global `assertSee` is unsafe: every label repeats N times).

### `tests/Browser/Products/EditorLanguageTabsTest.php`
Each test ends with `assertNoJavaScriptErrors()`.
- [ ] **B-1** A real click on a tab switches the visible panel (compiled `wire:click` check).
- [ ] **B-2** The description region within `[data-test="product-description-editor-{frId}"]` shows the French fixture string and the visible region does **not** show the Spanish one; both languages are populated with distinct strings.
- [ ] **B-3** Unsaved title survives a switch, read off the DOM.
- [ ] **B-4** Text typed into a description survives a switch made within the 400 ms debounce (0021 D9); a short bounded `->wait(1)` with a comment naming the debounce contract; `networkidle` waits are banned.
- [ ] **B-5** A non-active tab's refusal is visible without further clicking.
- [ ] **B-6** The slug pre-fill fires on a real blur and does not overwrite an existing slug.

### Not tested here
`translated()` and the fallback mechanism (0070, 0076); the sanitiser allow-list (0024a); slug canonicalisation as a hook and the 1062 mapping (0076a, 0076b); the action's own authorization and inactive-language refusal (0076b); the WYSIWYG internals (0021); gallery and multi-select internals (0020, 0022); `StoreLanguage` CRUD (0068); the tab strip's own markup (0071).

## Expected outcome
A catalog administrator opening a product sees a tab per active store language, the store default first and active. Switching tabs shows that language's five fields, including its rich-text description, without losing unsaved work in any tab; shared fields stay on screen once. Saving writes the default language through `CreateProduct` / `UpdateProduct` and every engaged non-default language through `SetProductTranslation`, in one transaction; an untouched tab writes nothing. Any refusal marks its tab, makes it active, and leaves everything unwritten. A language that is not active cannot be written, whatever the payload says.

## Acceptance criteria
- [ ] One tab per **active** store language via the unmodified `<x-language-tab-strip>`, default first then by name; default active on load.
- [ ] The five translatable fields render inside always-mounted panels (`x-show` + `x-cloak`, never `@if`), one panel per language, each with its own `WysiwygEditor`.
- [ ] `sku`, category, `type`, `status`, `price`, `stock`, imagery and sales regions render exactly once, outside the panels, and are unchanged by a tab switch.
- [ ] Switching tabs writes nothing and preserves unsaved input in every tab, including rich text typed within the last 400 ms.
- [ ] `$activeLanguageId` is server-side and `#[Locked]`; it changes only through `setActiveLanguageTab()` (active ids only) and `save()`'s refusal handling. No `products-focus-language` (or any other) browser event is dispatched for tab focus.
- [ ] Untranslated tabs hydrate from their own row and render empty with an untranslated notice.
- [ ] Untouched non-default tab → no row; engaged → title required; blanked → refused; default title always required.
- [ ] `save()` reads every field array by the server-derived active-language list; keys for any other language are dropped before validation.
- [ ] Create path: a create-only actor gets a locked-tab hint and a non-blank non-default value is refused by a logged `update` check before validation and before the transaction.
- [ ] Every per-language error is keyed `{field}s.{languageId}`, the default language included; the first errored tab becomes active.
- [ ] The slug pre-fills from its own language's title on blur, only when empty, server-side.
- [ ] `prefillSlug()` on a title that canonicalises to nothing (e.g. `"!!!"` or `"   "`) leaves `$slugs[$id]` as `''`, never `null` (D-9).
- [ ] `save()` never writes back into the bound arrays: a slug typed as `"---"` (canonicalises to `null`) is stored as `NULL`, and after a refusal elsewhere `$slugs[$id]` still reads `"---"` (a string, not `null`); a typed `"  Chaussures De Course  "` likewise stays as typed (D-5).
- [ ] No `wire:model`-bound value is ever `null`.
- [ ] The D-11 hooks exist on every branch of every control.
- [ ] No backend file changes; `tests/Unit/ProductTranslationArchitectureTest.php` stays green unchanged; `lang/en/products.php` and `lang/es/products.php` stay key-for-key identical; no hardcoded user-facing string.

## Definition of Done
- [x] Tests written and green, including the six browser tests; full suite green unscoped before closure
- [x] Pint and Larastan clean (Larastan: only the pre-existing local-only error in `SanitizeProductDescription.php:98`, an untouched file)
- [x] Code reviewed (`code-reviewer`)
- [x] No security findings (`appsec-auditor`) — point the audit at: payload narrowing to active languages (D-3); the locked properties and `setActiveLanguageTab()` input; the create-path `update` check (D-8); that `SetTranslation` is unreachable from `App\Livewire\Products`; `prefillSlug()` authorization
- [x] Documentation updated (`docs-keeper`): epic-5 digest entry; `docs/api/routes.md` (editor's public methods) if it lists them
- [x] Acceptance criteria met

## 4. Documented functional decisions

**D-1 — One `WysiwygEditor` per active language, all mounted, hidden with `x-show`.** The editor's region is `wire:ignore`d and seeds from `$value` once (0021 D9; no refresh hook), so swapping one instance's content on tab change is programmatic replacement it cannot do. Each language gets its own instance bound with `wire:model="descriptions.{id}"` and a distinct `wire:key`, seeding once from its own value; 0021 is consumed unmodified. Rejected: one instance re-keyed per tab (loses up to 400 ms of typed text on switch and rebuilds the nested gallery), Alpine reaching into the ignored region, amending 0021 with client hooks (kept as the fallback if page weight proves unacceptable — **R-2**). Replacing `x-show` with `@if` reintroduces the data loss silently; B-3/B-4 and the all-panels-present rendering test catch it.

**D-2 — State: five parallel arrays keyed by store-language id** (0071's shape, error keys `{field}s.{id}`). `public array $names, $descriptions, $slugs, $metaTitles, $metaDescriptions` (`array<string, string>`, `''` = empty, never `null`); `#[Locked] string $defaultLanguageId`; `#[Locked] array $originalTranslatedLanguageIds` (view hint only — `save()` decides from re-read rows, 0075 D-8); `#[Locked] string $activeLanguageId`; `#[Locked] bool $canAuthorTranslations` (create-path UI hint, **D-8**). Languages come from a `#[Computed] languages()` (`StoreLanguage::query()->active()->orderByDesc('is_default')->orderBy('name')`). The scalars `$name`, `$description`, `$slug`, `$metaTitle`, `$metaDescription` are removed. The five arrays cannot be locked (they are `wire:model` targets), which is why **D-3** is required.

**D-3 — `save()` iterates the server-derived active-language list, never the arrays' keys.** Each value is read as `$this->names[$language->id] ?? ''`; other keys are dropped before validation and never written. 0076b's action re-checks `active()` itself (owner decision B), so the hole is closed at both layers.

**D-4 — Blank-tab states (Q-1, Q-2 resolved by the owner 2026-08-30; Q-4 by 0071 D-7).** Per non-default language, computed server-side from the narrowed values and the **re-read** stored rows: *untouched* (all five blank, no stored row) → skipped, no validation, no write; *engaged* (any field non-blank) → title required, other four optional; *blanked* (all five blank, row exists) → refused on `names.{id}` (blanking is not a delete path). The default language's title is always required (0070 Q1(a)). Requiring all five fields on an engaged tab is rejected: it contradicts 0076 D-2's nullable SEO columns.

**D-5 — Save composition.** Authorize `create` / `update` (existing) → narrow (**D-3**) → create-path check (**D-8**) over the narrowed non-default values, so a key for an inactive or unknown language is dropped, never refused or logged → trim titles, canonicalise slugs with `CanonicalizeProductSlug`, sanitise descriptions with `SanitizeProductDescription` into a **local copy** — never written back into `$names` / `$slugs` / `$descriptions` etc., so the canonicaliser's `null` never reaches a `wire:model` target and typed input survives a refusal as typed (mirroring the actions, so layer-1 `max:` and `unique` see the stored form for every language, not only the default) → validate core fields plus per-language rules for the default and every engaged language, and refuse blanked tabs → existing gallery/region validation and `resolveSelected()` → one `DB::transaction()`: `CreateProduct` / `UpdateProduct` with the default language's five values → `SetProductTranslation` per engaged non-default language → `SyncProductSalesRegions` → redirect. All component-side refusals happen before the transaction opens; refusals thrown by the actions inside it roll everything back (0071 Q-5). Both the component and the actions sanitise and validate: that duplication is the two-layer design, not a smell.

**D-6 — Hydration reads raw rows** (`$product->translations` filtered to active languages), never `translated()`, so a fallback is never saved back as a translation (0071 D-6, 0073 D-6).

**D-7 — Tab switching is server-side**, as shipped by 0071 (C-2 settled): the strip calls `setActiveLanguageTab(string $languageId)`, which accepts only an active language id (`StoreLanguage::query()->active()->findOrFail()`, as 0075) and writes nothing. `$activeLanguageId` is `#[Locked]` (0075 D-7), so only this method and `save()` change it. Panels are always mounted, so a round trip on switch never discards typed content. There is **no** `products-focus-language` event: after a refusal, `save()` sets `$activeLanguageId` to the first errored language in tab order and rethrows (0071 Q-5, 0075 D-12).

**D-8 — Create path (reviewer item 12; adopted from 0071 B-1).** `SetProductTranslation` authorizes `update` (0076b D-2), so a create-only actor cannot write a non-default language. On create, if any non-default language is engaged — computed over the values already narrowed to active languages (**D-3**, **D-4**), so a payload key for an inactive language never triggers this check — `save()` runs `LogRefusedPrivilegedAttempt->authorize('update', new Product, targetType: 'product')` **before validation and before the transaction**, so the refusal is logged once and nothing is created. `mount()` sets `$canAuthorTranslations = Gate::allows('update', new Product)` on create (always `true` on edit, where `mount()` already required `update`); when `false`, non-default panels render their inputs disabled with a notice — a hint only, the server check is the guard. An actor holding create **and** edit writes all languages in the same save. `prefillSlug()` authorizes the same ability as `save()` for the current mode.

**D-9 — Slug pre-fill is server-side, on blur, per language, only into an empty field.** `wire:blur="prefillSlug({{ Js::from($language->id) }})"` on each title input; the method accepts only an active language id and fills `$slugs[$id]` with `CanonicalizeProductSlug($names[$id]) ?? ''` when `$slugs[$id] === ''` (the canonicaliser returns `?string`; `null` for a title with no sluggable characters, which must never land in a bound array). Server-side because a JS slugifier diverges from `Str::slug()`'s transliteration on `ñ`, `ç`, `ü`, `ß`. Never: overwrite a non-empty slug, derive a slug on save, copy the default's slug into another language, or auto-suffix on collision.

**D-10 — The default language's action errors are re-keyed.** `CreateProduct` / `UpdateProduct` throw bare `name`, `description`, `slug`, `meta_title`, `meta_description` keys; `save()` catches the `ValidationException`, maps them to `names.{defaultId}`, `descriptions.{defaultId}`, `slugs.{defaultId}`, `metaTitles.{defaultId}`, `metaDescriptions.{defaultId}` (other keys such as `sku` unchanged), sets the active tab and rethrows (0075 D-9). `SetProductTranslation` already throws per-language keys.

**D-11 — Hooks, keyed by language id** (0071 D-11; no assertion may match a language name or code). Consumed from the strip, unmodified: `language-tab-strip`, `language-tab-{id}`, `language-tab-error-{id}` (`language-tab-strip.blade.php:16,27,41`; there is no default badge). Added here: `language-panel-{id}` (the strip's consumer obligation), `product-name-input-{id}`, `product-slug-input-{id}`, `product-meta-title-input-{id}`, `product-meta-description-input-{id}`, `product-description-editor-{id}` (wrapping each WYSIWYG), `language-name-error-{id}`, `language-untranslated-{id}`, `language-translation-locked-{id}`; and on the shared fields, which have none today: `product-sku-input`, `product-price-input`, `product-stock-input`, `product-status-select`, `product-type-select`, `product-category-select`. The strip's error-marker label stays `products.categories.index.tabs.error_marker` (strip unmodified; generalising it is a separate follow-up).

**D-12 — Dynamic values in component tags use `{{ Js::from(...) }}`, never `@js()`** (errors-log 2026-08-26: `@js()` inside an anonymous component's attribute is never compiled). Disabled controls follow the existing `@if`/`@else` + explicit `<flux:tooltip>` pattern; tests match `disabled="disabled"`.

**D-13 — Browser tests live in the mirrored `tests/Browser/Products/`**, matching 0027's existing files.

**D-14 — No page-level error outlet.** Each error has a field inside exactly one panel; Flux's field errors render there, plus one explicit `<flux:error>` under each WYSIWYG. The editor is a routed page, so no `resetValidation()` on navigation is needed.

**Settled questions.** Q-1 (empty untranslated tab) and Q-2 (blank tab writes nothing) — owner, 2026-08-30. Q-3 (only active languages get tabs and are writable) — 0071 D-5, 0075 D-3, owner decision B and 0087. Q-4 (blanking is not a delete) — 0071 D-7. Q-5 (list screen language) — answered by 0076; the list already uses `orderByTranslatedName()` / `translated('name')`. C-1 (consume the strip unmodified), C-2 (server-side `$activeLanguageId` + `setActiveLanguageTab()`, panels `x-show` + `x-cloak`, as in `ProductCategories/Index.php:104,167` and `product-categories.blade.php:123-132`), C-3 (hooks keyed by id) and C-4 (`{field}s.{id}` keys over five arrays) — all settled by the shipped 0071 and 0073/0075 code; 0073 and 0075 both reuse the strip.

## 5. Dependencies, risks, open technical questions

### Dependencies
- `depends_on`: [0076b](0076b-product-translation-write-action-backend.md) (closed 2026-11-10, PR pending) — `SetProductTranslation`, the `products.attributes.{name,description}` labels and `products.editor.tabs.language_inactive`. Unblocked: Phase 3 may start once the 0076b branch is merged into the base.
- Shipped and consumed: [0021](0021-wysiwyg-rich-text-editor-component.md), [0027](0027-products-list-and-editor-ui.md), [0070](0070-translatable-content-mechanism-product-categories-backend.md), [0071](0071-product-categories-language-tabs-ui.md), [0075](0075-blog-tags-language-tabs-ui.md), [0076](0076-translatable-content-retrofit-products-backend.md), [0076a](0076a-product-slug-and-seo-fields-backend.md).
- `conflict_risk_with`: [0087](../0087-translation-write-actions-refuse-inactive-store-language.md) (`lang/{en,es}/products.php`; its inactive-language rule, which this story relies on through 0076b) and [0079](0079-blog-post-editor-language-tabs-ui.md) (sibling editor-tabs pattern; 0079 cites this story's D-numbers). Not a dependency on either.

### Risks
- **R-1** `wire:model="descriptions.{id}"` on a `#[Modelable]` child is a dotted array path; Phase 3 proves it binds and writes back (B-2/B-4) before building the rest. UUIDs contain no `.`.
- **R-2** Page weight: each `WysiwygEditor` embeds its own `Media\Gallery`, so the page mounts 2 + N galleries. Acceptable at the expected 2–4 languages; if not, D-1's fallback applies.
- **R-3** Transactions nest (component → actions → `SyncProductGallery`); Laravel uses savepoints. The rollback test in `EditorTranslationsTest` proves it by execution.
- **R-4** The WYSIWYG's 400 ms debounce still races an immediate Save click (pre-existing 0027/0021 hazard); this story does not widen it beyond one region per language.
- **R-5** Every label repeats N times; tests scope assertions to a panel.
- **R-6** Old `EditorTest` assertions on `name` / `description` break by design and are retargeted, not deleted.

### Open questions
None blocking. D-8 adopts 0071's approved B-1 rule for the create path; the owner may overrule at Phase 2.

## 6. Technical tasks for later backlog creation
- Generalise the strip's error-marker `aria-label` key away from `products.categories.*` (four consumers after this story).

## Provenance
Phase 1 debate 2026-08-29/30 (`product-owner`, `frontend-expert`, `frontend-qa`); D-1, D-3, D-4, D-9 and the test plan come from it. Rewritten 2026-11-10 against `HEAD` after the Phase 2 rejection below: the file now describes the shipped code, the backend action moved to 0076b (owner decision A), the inactive-language refusal lives in that action (owner decision B), and the reconciliation with 0071 is folded into the decisions.

- Phase 2 REJECTED 2026-11-10 (code-reviewer): stale vs shipped code, Small failed.
- Rewritten 2026-11-10 after split A/B decisions.
- 2026-11-10 (`product-owner`): Phase 2 re-review edits applied — D-5 narrows before the D-8 check (new create-only/deactivated-language scenario and test); D-9 `?? ''` and D-5 local copy with matching tests; `setActiveLanguageTab()` specified as `ModelNotFoundException`; component gate cases moved to `ScreenAuthorizationTest.php` (`ProductAuthorizationTest.php` dropped from the Files table); line citations corrected (architecture guard 10–12, strip hooks 16/27/41).
- Phase 2 APPROVED 2026-11-10 (code-reviewer, delta confirmation after the split and defects 1-7); owner decisions A (split), B (inactive languages refused from day one), and keeping the 0071/0075 precedent of no permission check on setActiveLanguageTab() are recorded.
- Unblocked 2026-11-10: [0076b](0076b-product-translation-write-action-backend.md) closed (Phase 7, PR pending); Phase 3 starts once it is merged.

## Implementation records (Phases 3-6)

### Phase 3 - TDD
- Red -> green. The 7 assumptions flagged by QA all held against the shipped code.
- One story-external test broke and was retargeted: `tests/Feature/Products/ProductListAndPickerOrderingTest.php` (asserted the removed `name`/`description` properties; not in the Files table). Logged in `docs/errors-log.md`.

### Phase 4 - appsec-auditor: PASS
- L-1 (Low): non-string tampered array values raised a `TypeError` -> FIXED with `typedString()` (7+1 tests).
- I-1 (Info): the WYSIWYG was not disabled in locked panels -> FIXED with `:disabled`.
- I-2 (Info): no-default-language invariant -> ACCEPTED (sibling-wide, not specific to this story).

### Phase 5 - code-reviewer: APPROVED, conditions met
- Full suite unscoped in chunks: A 1375, B 825, C 1435, D 1041 (1036 passed, 5 skipped), E 743, F 1122, Browser 286 (283 passed, 3 skipped) = 6827 tests, 0 failures, 8 skips none in files touched by this story. Pint unscoped passed.

### Story vs shipped
1. `ProductListAndPickerOrderingTest` was not in the Files table (see Phase 3).
2. `ScreenAuthorizationTest` had no `save()` refusal tests, so the `product_translations` assertion went on the mount refusal plus a new mount test.
3. D-12 not followed as written: locked inputs use a `:disabled` prop with no `<flux:tooltip>`, and no test matches `disabled="disabled"` - ACCEPTED, the lock notice explains the state.
4. A public `hydrate()` and an extra `data-test="product-language-tabs"` wrapper were not specified.
5. `editor.tabs.{slug,meta_title,meta_description}_label` keys were added, as the Files table allowed.

### Phase 6 - docs-keeper
- Updated: `docs/api/products/products-index-and-editor.md`, `routeless-components.md`, `docs/api/routes.md`, the two `docs/security/livewire-authorization/` parts, `docs/conventions/naming/livewire-components-and-views.md`, `docs/database/schema-products/categories-and-products.md`, `docs/errors-log` (one entry), and the epic-5 digest.

### Phase 7 - closure
Phase 7 closed 2026-11-10, PR pending. Task file moved `in-progress/` -> `done/`; link-integrity check (both directions) and `tasks-map.md` / `tasks-status.json` regeneration done.
