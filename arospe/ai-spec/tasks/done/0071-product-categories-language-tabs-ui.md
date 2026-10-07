# [0071] Product Categories taxonomy screen — language tabs (frontend)

## Description
Retrofit story [0025](../done/0025-product-categories-ui.md)'s shipped Product Categories management
screen so a category's name is authored **per active store language** through language tabs,
satisfying [PRD Epic 5, Layer 2](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization)'s
*"each active store language surfaces as a tab … in the taxonomy management screens"* and its
`Taxonomy names are translatable per store language` scenario. Consumes story
[0070](../done/0070-translatable-content-mechanism-product-categories-backend.md)'s shipped mechanism
(`HasTranslations`, `SetTranslation`, per-language uniqueness, `TranslateProductCategoryNameUniqueViolation`)
unchanged.

**This story establishes the repo's first tabbed UI**, and four siblings (0073 Blog Categories,
0075 Blog Tags, 0077 Products, 0079 Blog Posts) will copy it. Re-verified 2026-10-07:
`grep -rnE "<flux:tab(s|\.|[ >])|role=\"tab" resources/` returns **zero hits**, and the installed
Flux Free v2 ships **no** tabs component (`vendor/livewire/flux/stubs/resources/views/flux/` holds
`navbar`, `navlist`, `table`, … and no `tab*`).

> ## ⭐ This file is the master pattern for the translatable-taxonomy UI stories
>
> **Human architectural decision, 2026-08-30:** *"the component authorizes and validates the batch,
> but it must ALSO be controlled from the backend — everything must be controlled from both front
> and back for security."*
>
> Writing a non-default-language translation is therefore protected at **two independent layers**,
> and this story ships the missing backend one: a new domain action
> [`App\Actions\ProductCategories\SetProductCategoryTranslation`](#the-new-backend-action--the-layer-that-does-not-depend-on-a-caller)
> that **self-authorizes (and logs its refusals) and self-validates**, wrapping 0070's
> deliberately-unguarded `SetTranslation` primitive. The component authorizes and validates too,
> before calling it. **Neither layer is redundant** — see **D-4**, which a reviewer must read before
> "simplifying" either away.
>
> **Stories 0073, 0075, 0077 and 0079 copy this shape**, including the one case where it looks
> different: a component that structurally cannot validate (0060's Blog Tags, whose actions already
> own validation per 0059) ships **only** the action layer, and defence in depth still holds because
> the action is self-sufficient regardless of its caller. See **D-13**.

> **State of the tree this story is written against (re-verified 2026-10-07, Phase 2 rewrite).**
> 0023, 0024, 0024b, 0025, 0068 and 0070 are all closed in `done/` and shipped. The screen exists:
> `app/Livewire/ProductCategories/Index.php` (a single `public string $name` field, every gate going
> through `LogRefusedPrivilegedAttempt::authorize()`), `resources/views/livewire/product-categories.blade.php`
> (one `flux:input wire:model="name"` inside an `@if ($showModal)` block; the list cell already
> renders `$category['name'] ?? '—'`), and `lang/*/products.php` with `categories.index.action_not_allowed`
> (en/es at key parity). `vendor/` is installed. Every "specified, not implemented" and
> "`vendor/` is absent" hedge from the Phase 1 draft has been removed or resolved below.

## Type
frontend | includes database-expert: **no** | depends on **0070**, **0068**, **0025** (all `done/`) |
`backend-qa` owns `SetProductCategoryTranslationTest.php` at Phase 3

> **Classification note — accepted at Phase 2 (2026-10-06).** The 2026-08-30 defence-in-depth
> decision adds one `app/Actions/` class to a story classified **frontend**, which
> [workflow.md](../../../docs/workflow/task-files-links-and-ordering.md#task-classification-rule) would ordinarily read as
> *fullstack* and split. The story deliberately does **not** split: the action is a thin,
> self-authorizing wrapper over a primitive 0070 already ships (no model, migration, schema, route
> or permission change — `includes database-expert` stays **no**), and splitting would put the
> screen and the guard it depends on in different stories. `code-reviewer` accepted this in the
> Phase 2 record below.

---

## 1. Refined user story

> **As** a catalog administrator working in a multilingual store,
> **I want** each product category's name to be editable per active store language through tabs on
> the same modal I already use,
> **so that** the catalog reads correctly in every language the store authors in, without leaving
> the screen or learning a second workflow.

> **As** the engineer who will build stories 0073, 0075, 0077 and 0079,
> **I want** the tab strip, its state contract and its per-language error handling to exist as one
> reusable, tested pattern,
> **so that** adding tabs to a fourth taxonomy screen is a component include plus a property, not a
> fifth independent re-derivation of tab switching and per-language validation.

**Scope fence.** This story adds no route, model, migration, policy or permission. It adds
**exactly one action** (`SetProductCategoryTranslation`), widens one Livewire component and one
Blade view, extracts one anonymous Blade component (the tab strip), appends one lang group, changes
**one line** of `ProductCategoryValidationRules` (**Q-4**), and
migrates the existing Product Categories tests that bind to the removed `$name` property (see
*Files to create/modify*). Deletion, the in-use hard block and the product-count column are
[0024b](../done/0024b-product-category-in-use-delete-guard.md)/0025's and are untouched.

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and carries exactly one `When`, per
[gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3.

```gherkin
Feature: Product category names authored per store language

  # --- The tabs themselves ---

  Scenario: A catalog administrator sees one tab per active store language
    Given a catalog administrator, with Spanish and French active as store languages
    When they open a product category for editing
    Then a tab is offered for Spanish and a tab is offered for French

  Scenario: A removed store language is offered no tab
    Given a catalog administrator, with French removed as a store language
    When they open a product category for editing
    Then no French tab is offered

  Scenario: The default store language's tab is the one shown first
    Given a catalog administrator, with Spanish as the store default and French also active
    When they open a product category for editing
    Then the Spanish tab is the one shown

  Scenario: The tab strip lists the store default first and the other languages by name
    Given a catalog administrator, with French as the store default and German and Spanish also active
    When they open a product category for editing
    Then the tabs read French, then German, then Spanish

  # --- Reading a translation ---

  Scenario: A tab shows the name authored in its own language
    Given a catalog administrator, with a category named "Calzado" in Spanish and "Chaussures" in French
    When they switch to the French tab
    Then the name field shows "Chaussures"

  Scenario: An untranslated language's tab shows an empty field rather than the fallback
    Given a catalog administrator, with a category named "Calzado" in Spanish only
    When they switch to the French tab
    Then the name field is empty rather than showing "Calzado"

  Scenario: An untranslated language's tab says the name is not yet translated
    Given a catalog administrator, with a category named "Calzado" in Spanish only
    When they switch to the French tab
    Then they are told the category has no name in that language yet

  # --- Writing a translation ---

  Scenario: A catalog administrator translates a category into an additional language
    Given a catalog administrator, with a category named "Calzado" in Spanish only
    When they save the category with "Chaussures" entered on the French tab
    Then the category reads "Chaussures" in French and still reads "Calzado" in Spanish

  Scenario: A catalog administrator corrects a name in one language only
    Given a catalog administrator, with a category named "Calzado" in Spanish and "Chaussures" in French
    When they save the category with the French tab changed to "Souliers"
    Then the category reads "Souliers" in French and still reads "Calzado" in Spanish

  Scenario: Creating a category records the name entered on the default language tab
    Given a catalog administrator with permission to create product categories
    When they save a new category with "Calzado" entered on the Spanish default tab
    Then the category is created holding a Spanish name of "Calzado"

  Scenario: Unsaved text on a hidden tab survives switching tabs
    Given a catalog administrator who has typed "Chaussures" on the French tab
    When they switch to the Spanish tab and back to the French tab
    Then the French tab still shows "Chaussures"

  Scenario: A whitespace-only entry on an untranslated tab is treated as blank
    Given a catalog administrator, with a category named "Calzado" in Spanish only
    When they save the category with only spaces entered on the French tab
    Then the save is accepted and the category still holds no French name

  # --- Validation, including on a tab the administrator is not looking at ---

  Scenario: The default store language's name is required
    Given a catalog administrator editing a product category
    When they save the category with the default language tab left blank
    Then they are shown a validation message on that tab's name field

  Scenario: A validation message names the field rather than an internal key
    Given a catalog administrator editing a product category
    When they save the category with the default language tab left blank
    Then the message refers to the category's name and shows no internal identifier

  Scenario: A refusal on a hidden tab brings that tab into view
    Given a catalog administrator viewing the Spanish tab, with a duplicate name entered on the French tab
    When they save the category
    Then the French tab is brought into view carrying the validation message

  Scenario: A tab carrying a refusal is marked in the tab strip
    Given a catalog administrator whose save was refused because of the French tab's name
    When they switch away to the Spanish tab
    Then the French tab is still marked as carrying a problem

  Scenario: Two categories cannot share a name within one store language
    Given a catalog administrator, with a category named "Chaussures" in French
    When they save another category with "Chaussures" entered on the French tab
    Then they are shown a validation message on that tab's name field

  Scenario: The same name in two different store languages is permitted
    Given a catalog administrator, with a category named "Chaussures" in French
    When they save another category with "Chaussures" entered on the Spanish tab
    Then the save is accepted

  # --- The list, which has no tabs (already shipped by 0070 D-15; regression only) ---

  Scenario: The list shows each category's name in the store's default language
    Given a catalog administrator, with a category named "Calzado" in Spanish, the store default
    When they open the product category screen
    Then the category is listed as "Calzado"

  Scenario: A category with no name in the store default is listed without one
    Given a catalog administrator, with a category holding no name in the store default language
    When they open the product category screen
    Then the category is listed with a placeholder in place of a name and no error is raised

  # --- Authorization at the screen ---

  Scenario: An administrator who may only view the catalog cannot save a translation through the screen
    Given a signed-in administrator holding only the products view permission
    When a save carrying a French name is submitted for an existing category from their session
    Then the attempt is refused, the refusal is logged and no translation is stored

  Scenario: An administrator who may create but not edit categories can name a new category only in the default language
    Given a catalog administrator holding the products create permission but not the products edit permission
    When they open the form for a new product category
    Then only the default language tab's name field is available and every other language tab's name field is shown as unavailable

  Scenario: An administrator needs no store-language permission to author a translation
    Given a catalog administrator holding the products edit permission and no store language permissions
    When they save a category with a name entered on the French tab
    Then the translation is stored

  # --- The backend layer, which holds independently of the screen ---

  Scenario: Translating a category is refused for an actor lacking the products edit permission
    Given a signed-in administrator who does not hold the products edit permission
    When a product category's French name is set through the translation service
    Then the attempt is refused, the refusal is logged and no translation is stored

  Scenario: A blank translation is refused by the backend regardless of caller
    Given a catalog administrator holding the products edit permission
    When a product category's French name is set to a blank value through the translation service
    Then the attempt is refused with a validation error and no translation is stored

  Scenario: A duplicate name within one store language is refused by the backend regardless of caller
    Given a catalog administrator, with a product category named "Chaussures" in French
    When another category's French name is set to "Chaussures" through the translation service
    Then the attempt is refused with a validation error

  Scenario: A background importer receives the same protection as the screen
    Given a scheduled import running as an actor holding the products edit permission
    When it sets a product category's French name through the translation service
    Then the translation is stored under the same rules the screen enforces
```

Notes on three scenarios rewritten at Phase 2:

- *"An administrator who may only view the catalog …"* previously said every tab's field is "shown
  as unavailable". That premise was false against the shipped screen: a `products.view`-only actor
  cannot open the edit modal at all (`openEditModal()` authorizes `update`, and the row's edit
  button renders disabled — 0025). The meaningful new risk is a **forged** `save()` carrying a
  non-default name, which is what the scenario now states.
- The two list scenarios are **already shipped** by 0070 D-15 and covered by
  `IndexRenderingTest.php` (`the list renders each categorys name …`, `a category with no
  default-language translation renders an em dash …`). They stay here as regression intent; this
  story adds no list test.
- The tab-order scenario (N-4) and the whitespace and message-wording scenarios (N-1, N-2) are new.

## Files to create/modify

### Create

| Path | Change |
| --- | --- |
| `app/Actions/ProductCategories/SetProductCategoryTranslation.php` | **New.** The backend layer of the 2026-08-30 defence-in-depth decision — logs-and-authorizes, self-validates, wraps 0070's `SetTranslation`. See **D-4** and the contract below. |
| `resources/views/components/language-tab-strip.blade.php` | **New.** The repo's first tabbed UI, an **anonymous** Blade component (this repo has no `app/View/`). The **strip only** — never the panel bodies; see **D-1**. |
| `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` | **New**, owned by `backend-qa`. **Direct-call** action tests, independent of any component. |
| `tests/Feature/ProductCategories/LanguageTabsTest.php` | **New.** Component-level tab behaviour. |
| `tests/Feature/ProductCategories/LanguageTabsRenderingTest.php` | **New.** DOM-level tab rendering. |
| `tests/Browser/ProductCategories/LanguageTabsTest.php` | **New.** Mirrored subfolder, per **D-9**. |

### Modify — production code

| Path | Change |
| --- | --- |
| `app/Livewire/ProductCategories/Index.php` | `public string $name` → `public array $names`; adds `$activeLanguageId`, `#[Locked] $originalTranslatedLanguageIds`, a `languages` computed/derived list, `#[Locked] $canAuthorTranslations` (**B-1**), `setActiveLanguageTab()` and `validationAttributes()`; `openCreateModal()`/`openEditModal()`/`closeModal()` reset the new state; `save()` gains `SetProductCategoryTranslation` and wraps its whole write batch in one `DB::transaction()` (**Q-5**). See **The component surface** below and **D-3**/**D-7**. |
| `app/Concerns/ProductCategoryValidationRules.php` | **One line only (Q-4, resolved 2026-10-07):** inside the existing `uniqueNormalisedName()` closure, `$fail(trans('validation.unique', ['attribute' => $attribute]));` becomes `$fail('validation.unique')->translate();`, so the Validator resolves `:attribute` through the caller's custom attributes. No method added, no signature or rule-order change. |
| `resources/views/livewire/product-categories.blade.php` | The modal's single `flux:input wire:model="name"` becomes `<x-language-tab-strip>` plus one `x-show` panel per active language. The list cell already renders `?? '—'` (0070 D-15) and gains only a `data-test` hook. |
| `lang/en/products.php` + `lang/es/products.php` | Append `categories.index.tabs.*` (untranslated hint, tab-error marker `aria-label`, the `name` validation attribute, and the `translation_requires_edit` hint shown on disabled non-default inputs in create mode, **B-1**). Key-for-key identical. See **D-10**. |

### Modify — existing tests this change breaks (migrated in place; no assertion is weakened)

Replacing `public string $name` with `public array $names` turns these red. Each is migrated, not
deleted; where a test's intent is now covered better by a new `LanguageTabs*` file, it is
**rewritten to the tabbed shape in place** so its original assertion survives under its original
name. `$defaultId` below means `StoreLanguage::defaultStoreLanguage()->id` from the test's own fixture.

| Path | What breaks | Migration |
| --- | --- | --- |
| `tests/Feature/ProductCategories/IndexTest.php` | 17 lines using `->set('name', …)` / `->assertHasErrors(['name'])` across the create/rename/blank/whitespace/over-length/duplicate/Super-Admin/forbidden cases (lines 117–561). | Rewrite in place: `set('name', X)` → `set('names.'.$defaultId, X)`; `assertHasErrors(['name'])` → `assertHasErrors(['names.'.$defaultId])`. Every case keeps its name, fixture and persistence assertion. Lines 77, 98, 123, 158, 194 and 608 read `ProductCategoryTranslation.name` and need no change. |
| `tests/Feature/ProductCategories/IndexRenderingTest.php` | `:77` *"the create and edit modal contains exactly one text input and no select markup"*; `:116–126` asserts the `name` error and `validation.required` with `attribute => 'name'`; `:147–155` the N-3 `resetValidation('name')` regression. | `:77` → rewritten as *"the create and edit modal contains exactly one text input per active store language and no select markup"* (count = N active languages, still zero `<select>`). `:116` → sets `names.{defaultId}` to `''` and still asserts the message `__('validation.required', ['attribute' => __('products.categories.index.tabs.name_attribute')])` renders and the modal stays open (in `en` the rendered text is unchanged: "The name field is required."). `:147` → asserts **every** `names.*` key is cleared by `closeModal()`, with two languages carrying errors, not one. |
| `tests/Feature/ProductCategories/RefusalLoggingTest.php` | `:131` `set('name', …)` in the `save()` refusal case. | `:131` → `set('names.'.$defaultId, …)`. **Plus one new case**: a direct call `app(SetProductCategoryTranslation::class)($category, $french, 'Chaussures')` by an actor lacking `products.edit` logs `Privileged action refused` once with `ability = 'update'`, `target_type = 'product_category'`, `target_id = $category->id`, and no secret-looking key (reusing the file's existing helper). |
| `tests/Browser/ProductCategoriesIndexTest.php` → **moved to** `tests/Browser/ProductCategories/IndexTest.php` | `:65–107` `assertValue('name', …)` / `fill('name', …)`; `:121` `fill('name', …)`; `:185–188` `fill('name', …)` + `assertSee(__('validation.unique', ['attribute' => 'name']))`. | `git mv` into the mirrored folder (**D-9**; it must be rewritten anyway, and the tabbed browser tests live beside it). Selectors become the `data-test` hooks: `fill('@language-name-input-'.$defaultId, …)` / `assertValue('@language-name-input-'.$defaultId, …)`. The duplicate-name test asserts the error marker `@language-name-error-{defaultId}` is visible instead of matching message text (matching message text risks **D-11**'s collisions; the localized wording itself is asserted at the feature layer, see **Q-4**). |

`tests/Unit/Concerns/ProductCategoryValidationRulesTest.php`, `RenameProductCategoryTest.php`,
`CreateProductCategoryTest.php` and `ProductCategoryTranslationAuthorizationTest.php` call the
actions and trait directly with the `name` key and are **not** affected (verified: none reference
the component's `$name`). Because of the **Q-4** one-line trait change, `ProductCategoryValidationRulesTest.php`
gains one assertion (below) and it, `CreateProductCategoryTest.php` and `RenameProductCategoryTest.php`
are re-run. For `name`-keyed callers the message is byte-identical **in `en` only**
(`The name has already been taken.`). In `es` it changes from `El name ya está en uso.` to
`El nombre ya está en uso.`, because `lang/es/validation.php:165` maps `attributes.name => 'nombre'`
and the Validator now applies that mapping. This is an improvement, and no existing test asserts the
`es` text (verified by `code-reviewer`, 2026-10-07).

| Path | Change |
| --- | --- |
| `tests/Feature/ProductCategories/ProductCategoryTranslatedUniqueMessageTest.php` | **New file (Feature, not Unit: `tests/Unit` is DB-free and the duplicate needs a real row). Pins one case, pinned in both locales:** a duplicate refused under the `name` key renders `The name has already been taken.` in `en` and `El nombre ya está en uso.` in `es`; a duplicate refused under a `names.{id}` key with custom attributes `['names.*' => __('products.categories.index.tabs.name_attribute')]` renders the localized attribute in each locale and does **not** contain `names.`. |
| `tests/Browser/Products/IndexTest.php` (`:11`) and `tests/Browser/Products/AttributeTypesIndexTest.php` (`:27`) | **Comment-only (N-5):** both cite `tests/Browser/ProductCategoriesIndexTest.php`; update the path to `tests/Browser/ProductCategories/IndexTest.php`. The moved file's own header comment (`:9`, "Deliberately kept FLAT …") is rewritten to state the D-9 move. No assertion changes. |

### Deliberately not touched

| File | Owner |
| --- | --- |
| `app/Concerns/HasTranslations.php`, `app/Actions/Translations/SetTranslation.php`, `app/Actions/Translations/CompareTranslatedNames.php` | 0070 — **consumed, never re-implemented** |
| `app/Models/ProductCategory.php`, `StoreLanguage.php`, `ProductCategoryTranslation.php` | 0070 / 0068 |
| `app/Concerns/ProductCategoryValidationRules.php` — everything except the one `$fail(...)` line | 0070. This story is a **consumer** and adds no method. The single line changed by **Q-4** (resolved 2026-10-07) is listed under *Modify — production code*; no other line, method, signature or rule order changes. |
| `app/Actions/ProductCategories/{Create,Rename,Delete}ProductCategory.php`, `app/Policies/ProductCategoryPolicy.php` | 0023 / 0024b / 0025 / 0070 — consumed unchanged. `TranslateProductCategoryNameUniqueViolation.php` is the one exception: it gained an optional third `string $attributeLabel = 'name'` argument (Phase 4 L-1), backward compatible, pinned by a new case in its unit test. This story *adds* `SetProductCategoryTranslation.php` beside them. |
| `routes/web.php`, `config/modules.php`, `lang/*/navigation.php` | 0025 — no route and no sidebar entry is added or changed |
| `database/seeders/RolePermissionSeeder.php` | catalog stays at **43** (`tests/Feature/Seeders/RolePermissionSeederTest.php:37`); translating adds no permission (0070 **D-13**) |
| The delete-confirmation modal and its in-use hard block | [0024b](../done/0024b-product-category-in-use-delete-guard.md) / 0025 — untouched by tabs |

### The new backend action — the layer that does not depend on a caller

```php
namespace App\Actions\ProductCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SetProductCategoryTranslation
{
    use ProductCategoryValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateProductCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Authorize, validate and persist one product category's name in one store language.
     *
     * @throws AuthorizationException  when the actor lacks products.edit (logged first)
     * @throws ValidationException     keyed "names.{$language->id}" — blank, over-length,
     *                                 or duplicate within that store language (incl. the 1062 race)
     */
    public function __invoke(
        ProductCategory $productCategory,
        StoreLanguage $language,
        string $name,
    ): ProductCategoryTranslation {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $productCategory,
            targetType: 'product_category',
            targetId: $productCategory->id,
        );

        $name = trim($name);
        $errorKey = "names.{$language->id}";

        // NESTED data, dotted rule key. A flat ["names.{id}" => $name] data key is escaped by
        // Validator::parseData() and never reaches the rule, so `required` refuses every name.
        Validator::make(
            ['names' => [$language->id => $name]],
            [$errorKey => $this->nameRules($this->normalizeForSearch, $language->id, $productCategory->id)],
        )->validate();

        try {
            $translation = ($this->setTranslation)($productCategory, $language, ['name' => $name]);
        } catch (QueryException $e) {
            throw ($this->translateNameUniqueViolation)($e, $errorKey);
        }

        if (! $translation instanceof ProductCategoryTranslation) {
            throw new LogicException('SetTranslation returned an unexpected model for a product category.');
        }

        return $translation;
    }
}
```

Each point in that block follows an existing convention or a verified fact:

- **The refusal-logging authorize call is the first statement**, outside any transaction,
  with the same call shape as `RenameProductCategory::__invoke()` (`RenameProductCategory.php:49`).
  *(Human decision, 2026-10-07, closing R-7.)* Every Product Categories write path already logs its
  refusals (the component at `Index.php:116,143,174,177,220,258`; `CreateProductCategory.php:63`;
  `RenameProductCategory.php:49`; `DeleteProductCategory.php:51,120`), so a bare `Gate::authorize()`
  here would make this action the **only** silent one. `ProductCategoryPolicy::update` maps to the
  seeded `products.edit`. No new permission, no new ability, catalog unchanged at **43**.
- **It authorizes `update` on the parent category, not on the translation row.** Translating is
  editing the category; there is deliberately no `TranslationPolicy` (0070 **D-13**).
- **All four dependencies are constructor-injected**, exactly as in `RenameProductCategory`, per
  [code-style.md's documented exception](../../../docs/conventions/code-style.md#exception-an-actions-own-dependency-is-constructor-injected-when-the-method-signature-is-a-public-contract):
  `__invoke()`'s parameter list is a public contract every direct caller matches verbatim.
  **Resolve it from the container, never `new` it, including in tests.**
- **It uses the `ProductCategoryValidationRules` trait and calls 0070's `nameRules()` unchanged.**
  Verified signature: `nameRules(NormalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null)`.
- **Validator data is nested and the rule key is dotted.** `code-reviewer` ran the flat form at
  Phase 2 and it failed `required` for a valid `"Chaussures"`; this rewrite re-ran both forms with
  `php artisan tinker` (2026-10-07) and confirmed the flat form reports `The names.x1 field is required.`
  for a non-empty value. The error key stays `names.{$language->id}` either way.
- **The race guard reuses `TranslateProductCategoryNameUniqueViolation`** (0070 **D-7 (ii)**),
  passing the derived `$errorKey`. Its signature already takes the key:
  `__invoke(QueryException $e, string $errorKey = 'name'): ValidationException`. It returns the
  exception for a 1062 on the per-language name index and rethrows anything else, so the caller
  writes `throw (...)(...)`, as `RenameProductCategory` does.
- **The error key is *derived*, never accepted as a parameter.** `"names.{$language->id}"` comes
  from the language the action was handed, per the
  [errors-log rule](../../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-guard-took-the-state-it-was-guarding-as-a-parameter-reopening-its-own-hole-one-level-up--2026-08-20)
  against a guard accepting its own state. **The `names.` prefix is a deliberate shared contract
  across all five taxonomy screens** — every consuming component declares `public array $names` (**D-3**).
- **The return type is narrowed explicitly.** `SetTranslation::__invoke()` returns `Model`
  (`SetTranslation.php:37`), so Larastan level 7 rejects a `ProductCategoryTranslation` return
  without narrowing. An always-on `instanceof` + `LogicException` is used rather than `assert()`,
  which production can compile out. (No precedent for either exists in `app/Actions`.)
- **Not `final`.** No class under `app/Actions` is `final` (`grep -rn "final class" app/Actions`
  is empty); this one follows its siblings.
- **Its messages surface to non-UI callers with the raw key** (e.g. "The names.0199… field is
  required."). Accepted at Phase 2 (N-1): the screen validates first and renders its own localized
  message; the action's message is only seen on the screen on the 1062 race path.

### The component surface, diffed against the shipped `Index`

```php
namespace App\Livewire\ProductCategories;

#[Title('Product categories')]
class Index extends Component
{
    use ProductCategoryValidationRules;   // unchanged

    /** @var array<int, array{id: string, name: ?string, productCount: int, canEdit: bool, canDelete: bool}> */
    public array $productCategories = [];        // unchanged (0070 D-15 already made `name` ?string)

    #[Locked] public ?string $editingCategoryId = null;   // unchanged
    public bool $showModal = false;                       // unchanged

    /** @var array<string, string> keyed by store_language_id; '' means "not typed". NEVER null. */
    public array $names = [];                     // REPLACES `public string $name = ''`

    /** @var array<int, string> language ids this category already held a translation in, at modal-open. */
    #[Locked] public array $originalTranslatedLanguageIds = [];

    public string $activeLanguageId = '';         // overwritten to a real id when the modal opens

    /** UI hint only (B-1): false in create mode for an actor lacking products.edit. */
    #[Locked] public bool $canAuthorTranslations = true;

    // showDeleteModal / deletingCategoryId / deletingCategoryName: unchanged

    public function setActiveLanguageTab(string $languageId): void;   // NEW — no Gate check (D-4); findOrFail on an ACTIVE language

    /** @return array<string, string> maps every `names.<id>` key to the localized "name" (N-1) */
    protected function validationAttributes(): array;                // NEW

    public function save(
        CreateProductCategory $createProductCategory,
        RenameProductCategory $renameProductCategory,
        SetProductCategoryTranslation $setProductCategoryTranslation,   // NEW
        NormalizeForSearch $normalizeForSearch,                          // kept
        LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,        // kept
    ): void;

    // mount / confirmDelete / deleteProductCategory / closeDeleteModal / loadProductCategories: unchanged.
}
```

What changes inside the methods that keep their signatures:

- **`openCreateModal()`** keeps its logged `create` gate, then `reset(['editingCategoryId', 'names', 'originalTranslatedLanguageIds'])`,
  seeds `$names[$id] = ''` for every active language, and sets `$activeLanguageId` to the first tab
  (**D-14**: the default, or the first by name when no default exists). It also sets
  `#[Locked] public bool $canAuthorTranslations` to `Gate::allows('update', new ProductCategory())`
  (**B-1**). When `false`, the view renders every **non-default** name input `disabled` with the
  `tabs.translation_requires_edit` hint; the default tab stays enabled. This is a UI hint only —
  enforcement is the `save()` check below. `openEditModal()` sets it to `true`, since that path has
  already authorized `update`.
- **`openEditModal()`** keeps its `findOrFail` → logged `update` gate order, then loads the raw
  translation rows for the active languages (**D-6**, never `withTranslationsFor()`), fills
  `$names[$id]` with each row's own `name` or `''`, records `$originalTranslatedLanguageIds`, and
  sets `$activeLanguageId` as above.
- **`closeModal()`** resets `editingCategoryId`, `names`, `originalTranslatedLanguageIds` and
  `activeLanguageId`, and clears **every** `names.*` error (e.g. `resetValidation()` with no
  argument, or one call per key). Today it resets only `name` and `resetValidation('name')`.
- **`save()`** keeps its first statements (the logged `create` or `update` gate per branch, against
  a freshly re-resolved target). It then trims every `$names` value **and writes the trimmed values
  back into `$names`** (**N-6**: `$this->validate()` validates the component's own properties, so
  the trimmed values must be in the property to be the ones validated; a value typed with
  surrounding spaces therefore re-renders trimmed after any refused save). **On the create branch
  only, if any non-default language's trimmed value is non-empty, it then also calls
  `$logRefusedPrivilegedAttempt->authorize('update', new ProductCategory(), targetType: 'product_category')`**
  (**B-1**, human decision 2026-10-07, option (a)). The unsaved instance is the gate target because
  `ProductCategoryPolicy::update()` takes an instance and there is no row yet; `targetId` is `null`,
  as for `create`. On the edit branch this check is already satisfied by the branch's own `update`
  gate, so no second call is made. A create-only actor who submits a forged non-default value is
  therefore refused and logged **before** anything is validated or written. It then builds
  per-language rules (**D-7**), runs `$this->validate()` across every active language's key, and on
  failure sets `$activeLanguageId` to the first erroring language before re-throwing (**D-2**). It writes the
  default language through `CreateProductCategory`/`RenameProductCategory` (Rename only when the
  trimmed default value differs from the row re-read in `save()`, never from a client-held copy), and
  every other non-blank language through `SetProductCategoryTranslation`. **Every write of the
  batch — the default-language Create/Rename call and each per-language call — runs inside one
  `DB::transaction()`** (**Q-5**, resolved 2026-10-07). The component's own gates and
  `$this->validate()` stay **before** the transaction opens. A `ValidationException` thrown by any
  write inside it (the 1062 race path, re-keyed per **D-4** ⚠️) rolls back every earlier write of
  the same click, and `save()` sets `$activeLanguageId` to that exception's language before
  re-throwing, so the modal stays open with every typed value intact. `CreateProductCategory`'s own
  inner transaction nests as a savepoint. **Every authorization the component itself performs
  runs before the transaction opens**, per the
  [authorize-outside-the-transaction rule](../../../docs/conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers),
  so for every operation the actor is entitled to perform no refusal ever reaches the transaction.
  The action's own first-statement gate is layer 2 and necessarily executes inside the batch's
  transaction, because the action is called there; after layer 1 has already authorized `update`
  it can only fail on a permission revoked between the two checks in the same request, in which
  case the whole batch rolls back and the refusal is still logged (to the log channel, not the
  database, so the rollback cannot erase it). After commit, `save()` calls `loadProductCategories()`
  and `closeModal()` as today.

**This supersedes 0025's committed surface** (`public string $name = ''` cannot survive 0070 **D-4**
dropping `product_categories.name`). 0025 is closed, so the amendment is this story's own code and
test migration, listed above (R-1, resolved).

⚠️ **`App\Actions\Translations\SetTranslation` must not appear in this component's imports.** It is
0070's deliberately-unguarded primitive. Verified 2026-10-07: nothing under `app/Livewire/` imports
it today. A `SetTranslation` import under `app/Livewire/` is a Phase 5 finding, pinned by an `arch()`
test below.

---

## Tests to perform — 3. QA test cases / validation scenarios

**Calibration, inherited from 0025:** this story does **not** re-run 0070's suite one layer up.
0070 proves the fallback chain, per-field resolution, the normalised uniqueness fold and the
backfill at the action/unit layer. This story asserts only that the **screen and the new action
route into those rules and render their outcome**.

### Feature — `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` (`backend-qa`)

**Direct-call only — every test resolves the action from the container
(`app(SetProductCategoryTranslation::class)(...)`) and mounts no component.** A `Livewire::test()`
exercises the action **through** its caller, so it passes whether the action or the component
authorizes.

- [ ] An actor holding `products.edit` sets a French name → the translation is stored and a `ProductCategoryTranslation` is returned.
- [ ] An actor **lacking** `products.edit` → `AuthorizationException`, and **no row is written**. (Its refusal-log assertion lives in `RefusalLoggingTest.php`, beside the other Product Categories log assertions.)
- [ ] A **Super Admin holding zero permission rows** succeeds, via `Gate::before`.
- [ ] A blank and a whitespace-only name → `ValidationException`, no row written (0070 **D-17** hands this write-path claim to this story).
- [ ] **A valid name passes validation** — the regression canary for the flat-data-key bug found at Phase 2: `"Chaussures"` for a fresh category/language pair must not be refused by `required`.
- [ ] An over-length name → `ValidationException`. One canary, not 0070's boundary matrix.
- [ ] A duplicate name **within one language** → `ValidationException`; the **same name in another language** → accepted.
- [ ] Re-setting the same category's own name in the same language → accepted (the `$productCategoryId` exclusion).
- [ ] The thrown `ValidationException`'s key is **`names.{$language->id}`**, asserted literally.
- [ ] **The 1062 race path is re-keyed to `names.{$language->id}`.** One canary: bind a `SetTranslation` test double that throws a constructed `QueryException` with `errorInfo = ['23000', 1062, "Duplicate entry '…' for key 'product_category_translations_store_language_id_name_unique'"]` and assert the resulting `ValidationException` carries the derived key. A second canary with a non-1062 code asserts the `QueryException` propagates unchanged.
- [ ] The name is **trimmed** before persistence.
- [ ] Calling twice for the same `(category, language)` updates rather than duplicating — one row-count assertion.
- [ ] An **inactive** store language is still writable through the action — no defensive `is_active` guard (0070 **D-6**).
- [ ] The action is **resolved from the container, never `new`-ed**, in every test.

### Feature — `tests/Feature/ProductCategories/RefusalLoggingTest.php` (modified, see Files)

- [ ] **New:** a direct-call refusal of `SetProductCategoryTranslation` logs `Privileged action refused` **once** with `actor_id`, `ability = 'update'`, `target_type = 'product_category'`, `target_id = $category->id` and no secret-looking key.

### Feature — `tests/Feature/ProductCategories/LanguageTabsTest.php`

*Happy path*
- [ ] Saving a new category with the default tab and one other language filled creates the category with the default name **and** stores the second language's translation — asserted as two distinct translation rows with the two distinct values.
- [ ] Editing only the French tab stores French and does **not** call `RenameProductCategory` (bind a spy/double for Rename). *Risk if missing:* the retrofit collapses back to "always rewrite the default row".
- [ ] **The component never reaches `SetTranslation` directly** — an `arch()` rule that `App\Livewire\ProductCategories` does not use `App\Actions\Translations\SetTranslation`, written as **one `expect()` per namespace, never `expect([...])`** (disjunctive; this repo has shipped one vacuous arch rule that way).
- [ ] The tab set equals the active store languages — asserted as a **count** against an N-active-language fixture, plus one inactive language that must not appear.
- [ ] **Tab order (N-4):** with a non-alphabetical default, `$languages` (or the rendered strip order) is the default first, then the rest by `name` — asserted by ids, never by names.
- [ ] `$activeLanguageId` equals the default language's id after `openCreateModal()` and after `openEditModal()`.

*Edge cases*
- [ ] **The fallback does not leak into the edit field.** A category named in Spanish only, opened, has `$names[$frenchId] === ''` — **not** "Calzado". **The sharpest bug this story can ship.**
- [ ] **Blank-because-untranslated is distinguishable from blank-because-cleared** — `$frenchId` absent from `$originalTranslatedLanguageIds` on the first, present on the second.
- [ ] **The store default changes under an existing catalog** (0070 **R-2**): Spanish-only category, French promoted to default via 0068's action, modal reopened → French renders blank without throwing, Spanish still shows "Calzado", and French is now the first tab.
- [ ] **A translation in a since-removed language**: French deactivated → (a) no French key in `$names`, and (b) the list row still renders a name through the fallback.
- [ ] Switching tabs preserves unsaved input in `$names` for the other languages.
- [ ] **Whitespace-only on an untranslated tab is a no-op** (N-2): `'   '` on French for a Spanish-only category → save accepted, no French row written, `SetProductCategoryTranslation` not called.
- [ ] `closeModal()` after a refused save clears `names`, `originalTranslatedLanguageIds`, `activeLanguageId` and **every** `names.*` error; reopening against a different category shows none of the previous state.

*Negative cases*
- [ ] **A validation error keyed to a non-visible tab.** Spanish tab active, duplicate name on the French tab, save → `assertHasErrors(['names.'.$frenchId])` **and** `assertSet('activeLanguageId', $frenchId)`. **The single highest-value test in this story.**
- [ ] The default language's tab blank → refused, unconditionally (0070 **Q1(a)**).
- [ ] A previously-translated non-default tab blanked → refused (**D-7**); a previously-untranslated one left blank → accepted and **not** written.
- [ ] Same name, two different languages → accepted. Same name, same language, two categories → refused. One canary each.
- [ ] A forged `setActiveLanguageTab()` with an unknown or **inactive** language id fails via `findOrFail()` on the active scope and leaves `$activeLanguageId` unchanged.
- [ ] A forged `names` key for an unknown or inactive language id submitted with `save()` is ignored — never reaches `SetProductCategoryTranslation` (the component iterates the active languages from the database, not the keys of `$names`).
- [ ] **An actor holding only `products.view` cannot write any tab's translation** through a forged `save()` — asserted **at the component**, with the same case asserted **at the action** in the direct-call file. The pair proves the two layers are independent.
- [ ] **A default-language refusal from 0023's actions renders on the default tab.** Force `RenameProductCategory` (container double) to throw its `name`-keyed `ValidationException`; assert the component surfaces it as `names.{defaultId}`. *Risk if missing:* verified 2026-10-07, Livewire's `SupportValidation::dehydrate()` keeps only error keys whose segment before the first dot is a component property (`Utils::hasProperty()` → `beforeFirstDot()`), so once `$name` is removed a `name`-keyed error is **silently dropped** from the snapshot, not merely unrendered.
- [ ] An actor with `products.edit` and **zero** `store-languages.*` permissions can translate (0070 **D-13**).
- [ ] **A create-only actor cannot translate on create, and is not refused for a correct create** (**B-1**). Actor holds `products.view` + `products.create`, not `products.edit`. After `openCreateModal()`: `assertSet('canAuthorTranslations', false)`. (i) Saving with only the default tab filled succeeds: the category is created, no non-default row exists, and `Log` receives **no** `Privileged action refused`. (ii) Saving with a forged French value (`set('names.'.$frenchId, 'Chaussures')`) throws `AuthorizationException`, logs `Privileged action refused` once with `ability = 'update'`, `target_type = 'product_category'`, `target_id = null`, and leaves the category count unchanged — proving the refusal happened before the transaction and before validation. *Risk if missing:* a correct create by a create-only administrator is refused halfway through inside the transaction, the case both [layout-conventions.md](../../../docs/conventions/directory-structure/layout-conventions.md) and the authorize-outside-the-transaction rule warn about.
- [ ] **A failing later-language write rolls back earlier writes of the same save** (**Q-5**). Edit a category whose Spanish default is "Calzado" and which has no French or German name; change the default to "Zapatos", fill French and German; bind a `SetProductCategoryTranslation` container double that succeeds for French and throws a `names.{germanId}`-keyed `ValidationException` for German. Assert: the default translation still reads "Calzado", **no** French or German row exists (row counts), `assertHasErrors(['names.'.$germanId])`, `assertSet('activeLanguageId', $germanId)`, and `$names` still holds all three typed values (they carry no surrounding whitespace, so they are unchanged by **N-6**'s write-back of trimmed values). *Risk if missing:* the transaction is dropped or opened around only part of the batch, and a refused click silently commits a half-save.

### Feature — `tests/Feature/ProductCategories/LanguageTabsRenderingTest.php`

- [ ] N tab controls render for N active languages, counted via `data-test="language-tab-{id}"` hooks, never by language name (**D-11**), in the **D-14** order.
- [ ] N panels render, each `data-test="language-panel-{id}"`, each holding one plain text input `data-test="language-name-input-{id}"` — inactive panels present and hidden by `x-show`, not omitted.
- [ ] An untranslated tab renders an empty input **plus** its "not yet translated" hint (`data-test="language-untranslated-{id}"`). *Risk if missing:* a stray `{{ $category->translated('name') }}` left over from 0025's markup.
- [ ] A tab carrying an error renders its marker on the **tab header** (`data-test="language-tab-error-{id}"`), not only on the field.
- [ ] A required-field refusal renders the localized attribute (N-1): the rendered message contains `__('products.categories.index.tabs.name_attribute')` and does **not** contain the substring `names.`.
- [ ] **A per-language duplicate refusal renders the translated message** (**Q-4**): a duplicate on the French tab renders exactly `__('validation.unique', ['attribute' => __('products.categories.index.tabs.name_attribute')])` inside `data-test="language-name-error-{frenchId}"`, and the rendered HTML of that error does **not** contain `names.` or the French language's id. Run once in `en` and once in `es` (via the component's UI locale), so both the attribute key and the framework message are proven localized.
- [ ] The compiled `wire:click` on each tab header carries the literal language id (read the rendered HTML) — **D-8**'s compiled-output check.

### Browser — `tests/Browser/ProductCategories/LanguageTabsTest.php`

- [ ] **Typing into the French tab, switching away and back, preserves the text.** A regression guard on the `x-show` markup (an expression matching the wrong id is invisible to `Livewire::test()`).
- [ ] **An inactive panel is still present in the DOM, merely hidden** — pins **D-2**'s universal rendering mode.
- [ ] A real `fill()` on a **non-default** tab followed by a real Save persists that language's text.
- [ ] A real click on a tab actually shows that tab's panel — the only level at which a compiled-`wire:click` no-op is detectable (**D-8**).
- [ ] A refused save on a hidden tab switches the visible panel to that tab and shows its error marker.
- [ ] A refused save's error does not survive Cancel + reopening against a different category — the `resetValidation()` regression 0018 shipped, now with N error keys.
- [ ] `->assertNoJavaScriptErrors()` on every test.
- [ ] **Not used anywhere:** `->waitForEvent('networkidle')` (banned here). Read `[wire:snapshot]` before reaching for a bounded `->wait(n)`.

### Browser — `tests/Browser/ProductCategories/IndexTest.php` (moved and migrated, see Files)

- [ ] Every existing test keeps its name and intent; only the field selectors change to `@language-name-input-{defaultId}` and the duplicate-name check asserts the `@language-name-error-{defaultId}` marker.

### Deliberately NOT tested here

- **The fallback chain, per-field resolution and `translated()`'s null-safety** — 0070's.
- **The uniqueness fold, the accent/case matrix, the composite `UNIQUE` backstop and the 1062 index discrimination** — 0070's (`TranslateProductCategoryNameUniqueViolation`). Two scoping canaries and one re-key canary only here.
- **`SetTranslation`'s `updateOrCreate` semantics** — 0070's; one row-count assertion here.
- **`StoreLanguage::scopeActive()` and the `defaultStoreLanguage()` memo** — 0068's / 0070's **D-10**.
- **The list query and its ordering** — shipped and tested by 0070 D-15 (`IndexRenderingTest.php`).
- **The delete guard, the in-use block and the `productCategoryId` error key** — 0024b's.
- **`ProductCategoryPolicy`'s abilities in the abstract** — 0023's / 0025's.

## Expected outcome

A catalog administrator opening a product category sees one tab per active store language, the
store default first and selected, the others in name order. Each tab holds that language's own
name — blank, and visibly marked as untranslated, where none exists, never silently pre-filled with
the fallback. They type a French name, save once, and the category now reads "Chaussures" in French
and "Calzado" in Spanish. A duplicate name is refused per language, so the same string is accepted
in two languages and refused twice in one; when the refusal belongs to a tab they were not looking
at, that tab is brought into view carrying a message that names the field, and stays marked while
they navigate. The list is unchanged: each category's name resolved through the store default, with
an em dash where none exists. Removing a store language removes its tab and preserves its content.

Behind the screen, `App\Actions\ProductCategories\SetProductCategoryTranslation` authorizes
`products.edit` (logging any refusal) and validates the name — blank, over-length,
duplicate-within-a-language — before any translation is persisted, **independently of who called
it**. 0070's unguarded `SetTranslation` primitive is reachable from no Livewire component.

## Acceptance criteria

- [x] The create/edit modal renders exactly one tab per **active** store language, ordered store default first and the rest by `name`, with the store default selected on open.
- [x] **Every language panel is mounted and hidden with `x-show`; no panel is conditionally rendered with `@if`** (**D-2**).
- [x] Each tab's field shows that language's **own** translation, read from the raw translation row — **never** through `translated()`'s fallback (**D-6**).
- [x] A language with no translation renders an empty field carrying a "not yet translated" hint, distinguishable in state from a cleared one.
- [x] Saving writes the default language through `CreateProductCategory`/`RenameProductCategory` and every other non-blank language through **`SetProductCategoryTranslation`** — never through `SetTranslation` directly, which appears in no import under `app/Livewire/` (**D-4**).
- [x] **`SetProductCategoryTranslation` logs-and-authorizes `update` on the category as its first statement and validates the name itself** with nested data and a derived `names.{id}` key, re-keys the 1062 race through `TranslateProductCategoryNameUniqueViolation`, and returns a narrowed `ProductCategoryTranslation`; proven by direct-call tests that mount no component.
- [x] **The component authorizes and validates too, before calling it**, and both layers are covered by their own tests. Neither may be removed as "duplication" (**D-4**, **D-13**).
- [x] A refusal from either layer lands on `names.{languageId}` and renders on that language's tab, including a default-language refusal re-keyed from the `name` key 0023's actions throw (**D-4** ⚠️).
- [x] Every Product Categories write path, including the new action, logs its refusals; `RefusalLoggingTest.php` covers the new action.
- [x] The permission catalog is unchanged at **43** and no new ability, policy or `TranslationPolicy` is added.
- [x] The default language's name is required; a previously-translated language's name may not be blanked; a previously-untranslated one may be left blank (or whitespace-only) and is not written (**D-7**).
- [x] A validation refusal keyed to a hidden tab **switches the active tab to that language** and marks it in the strip; the marker persists while the administrator navigates away.
- [x] Validation messages on the screen name the field through a localized attribute, never the raw `names.<id>` key — including the per-language uniqueness message, which `uniqueNormalisedName()` now emits as `$fail('validation.unique')->translate()` so the Validator resolves `:attribute` through `validationAttributes()` (**Q-4**). For `name`-keyed callers the message is byte-identical in `en`, and in `es` it becomes `El nombre ya está en uso.` (was `El name …`); both locales are pinned in `ProductCategoryValidationRulesTest.php`.
- [x] **One `save()` is atomic** (**Q-5**): every write of the batch runs inside one `DB::transaction()` opened after the component's gates and validation; a refusal from any later write rolls back every earlier write of the same click, switches to the refused tab and keeps all typed values (as trimmed and written back into `$names`, **N-6**).
- [x] **A create-only administrator is never refused for a correct create** (**B-1**): in create mode, an actor lacking `products.edit` sees every non-default name input disabled with a hint; whenever a create batch carries a non-empty non-default value, the component logs-and-authorizes `update` **before** validation and the transaction, so a forged value is refused and logged with nothing written, and a default-only create succeeds with no refusal logged.
- [x] A removed store language contributes no tab, and its stored content is neither shown nor destroyed.
- [x] Every tab, panel, field, hint and error marker carries a `data-test` hook; **no assertion in this story matches on a language name or a two-letter code** (**D-11**).
- [x] `IndexTest.php`, `IndexRenderingTest.php`, `RefusalLoggingTest.php` and the moved browser `IndexTest.php` are migrated to `names.{id}` with no assertion weakened or deleted.
- [x] `lang/en/products.php` and `lang/es/products.php` stay key-for-key identical.
- [x] No route, model, migration, policy, factory, seeder or permission change. **Exactly one action is added**; 0023's three actions, 0070's `SetTranslation` is unmodified, `TranslateProductCategoryNameUniqueViolation` changes only by the optional third `string $attributeLabel = 'name'` parameter (Phase 4 L-1; callers keyed on `name` are byte-identical), and `ProductCategoryValidationRules` changes by exactly **one line** — the `$fail(...)` call inside `uniqueNormalisedName()` (**Q-4**); no method, signature or rule order changes.

## Definition of Done
- [x] Tests written and green (**full suite unscoped**, not `--filter`)
- [x] `vendor/bin/pint --format agent` run **unscoped**, not `--dirty`
- [x] **Larastan level 7 run and recorded** — named explicitly because [errors-log.md](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-verification-record-that-lists-two-of-three-quality-gates-is-a-record-of-two-gates--2026-08-26) records three stories whose verification notes listed two of three gates
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor) — point the audit at: **both layers of the per-tab write path** (a `products.view` actor refused by the component *and* by `SetProductCategoryTranslation` called directly, each refusal logged), that `SetTranslation` is reachable from no `app/Livewire/` import, that the action's error key is derived from `$language->id` and never accepted as a parameter, `$originalTranslatedLanguageIds` being `#[Locked]`, `$names` being unlocked and iterated by active-language ids from the database (never by its own keys) (**D-3**)
- [x] **Compiled output of the tab strip verified by rendering, not by absence of an error** (**D-8**)
- [x] Documentation updated (docs-keeper) — at minimum `docs/api/routes.md` (the screen's tabbed modal and its `data-test` hooks), `docs/conventions/naming.md` (the first shared anonymous component whose consuming class must expose a fixed method name), `docs/architecture/authorization.md` (the new logged gate site), and the Epic 5 decision digest `ai-spec/tasks/_digests/epic-5.md` (the master pattern for 0073/0075/0077/0079, including the nested-data Validator rule)
- [x] Acceptance criteria met

---

## 4. Documented functional decisions

**D-1 — The tab *strip* is an extracted anonymous Blade component; the *panels* are not.** Four
siblings will render different panel shapes (this screen: one field; 0077 Products: title,
description, slug, meta) behind identical tab-switching UI. Extracting only the strip captures the
part that genuinely does not vary — headers, active state, error markers — without prematurely
abstracting a multi-field form builder nobody has specified. This repo's Blade components are
**all anonymous** (verified: no `app/View/` directory exists), so an anonymous component is the
convention; a nested Livewire child component is rejected as unprecedented here. *Rejected:* inline
markup duplicated per screen — five independent implementations of the tab contract. *Rejected
(verified 2026-10-07):* `flux:tabs` — the installed Flux Free v2 ships no tabs component.

Prop contract:

```php
['languages' => Collection, 'active' => string, 'errorLanguageIds' => array<int, string>]
```

**The strip hardcodes a call to `setActiveLanguageTab(string $languageId)`.** Every consuming
component must expose a method by that exact name — a real constraint on 0073/0075/0077/0079.

**Two obligations the strip places on the panels it does not render**, both binding on every
consumer:

- **Each panel is always mounted and hidden with `x-show`, never `@if`** (**D-2**).
- Each panel carries `data-test="language-panel-{languageId}"` so a hidden panel is still
  selectable — which under `x-show` it always is.

**D-2 — Tabs switch on a server round trip, not client-side Alpine. This is a deliberate
divergence from 0069 D-6, and the reason is the error case.** 0069 chose Alpine for its language
picker; three properties held there that do not hold here. *(i) Cardinality* — 184 fixture rows
versus a realistic 2–6 active languages. *(ii) Shape of the control* — the picker's rows are act-now
buttons with no bound state, while a tab reveals a panel of live `wire:model` inputs. *(iii)* **The
decisive one: a validation error can land on any tab, and only the server knows which.** A plain
`public string $activeLanguageId` lets `save()` set the active tab to the first erroring language
*before* the failed-validation re-render, so the right panel is visible on the very next paint using
the boring mechanism every other screen here already uses.

**Every panel stays mounted and is hidden with `x-show`, never `@if`. This is the shared
component's one and only rendering mode.** The two axes are independent: *how the active tab is
tracked* (server-side, above) and *whether an inactive panel stays in the DOM* (it does). Three
reasons, in order of weight:

1. **`@if` cannot survive a stateful panel, and one sibling already proves it.** Story
   [0077](../0077-product-editor-language-tabs-ui.md)'s **C-2** found that `@if` tears down N
   `wire:ignore`d WYSIWYG regions on every tab switch — story 0021's editor has no client-side
   refresh hook. That is what happens to **anything stateful** inside a panel.
2. **One mode is simpler for four consumers to reason about than two.** 0073 and 0075 already
   consume the strip as specified and **inherit `x-show` with no change on their end**.
3. **It costs this screen nothing.** A plain-text panel has no teardown cost, and N is 2–6.

> **Supporting note (hedge retired 2026-10-07).** The Phase 1 draft carried an unverified claim
> that Livewire sends every dirty deferred `wire:model` property on any action call. That claim
> no longer carries weight: under `x-show` the inputs are never removed from the DOM, so the
> "unsaved input lost on tab switch" failure mode is closed structurally. The browser test "typing,
> switching away and back preserves the text" is **retained** as a regression guard on the `x-show`
> markup itself.

**D-3 — `$names` is an unlocked array keyed by store-language id; `$originalTranslatedLanguageIds`
is `#[Locked]`.** `$names` follows 0025 **D-4**'s own reasoning for `$productCategories`: nothing
reads it for a decision — every write re-derives its target from the database, and `save()`
iterates the **active languages queried from the database**, never the keys of `$names`, so a
forged key is ignored. `$originalTranslatedLanguageIds` **is** locked, because it feeds **D-7**'s
conditional-requiredness branch: a forged value would let an actor blank away an existing
translation without tripping the blank-is-refused rule. That is a data-integrity concern rather
than a privilege one, locked for the same reason `$editingCategoryId` is locked.
`$activeLanguageId` stays unlocked and never binds a `<select>` — it drives an `x-show` comparison,
so the [null-bound-`<select>` trap](../../../docs/errors-log/archive-2026-07-21-to-2026-08-17.md#a-null-livewire-property-bound-to-a-native-select-silently-dropped-the-users-own-pick--2026-08-16)
is **structurally inapplicable** here.

**D-4 — Writing a translation is authorized and validated at TWO independent layers: the component
*and* a new self-sufficient backend action. Neither is redundant, and a reviewer must not collapse
them.** *(Human architectural decision, 2026-08-30.)*

This is the concrete answer to the half 0070 **D-9** left open. 0070's `SetTranslation`
deliberately authorizes and validates **nothing** (a self-authorizing `update` would make *creating*
a category require `products.edit`). That decision is sound and unchanged — but it means the
primitive is only as safe as its caller, and a component is the one caller that **cannot** protect a
future Artisan command, queued job or importer.

| Layer | Where | What it does | What it protects |
| --- | --- | --- | --- |
| **1 — component** | `App\Livewire\ProductCategories\Index::save()` | `$logRefusedPrivilegedAttempt->authorize('create'\|'update', …, targetType: 'product_category', …)` on the batch (the shipped gate, unchanged), plus — on create, when a non-default value is filled — a logged `update` check (**B-1**), then `$this->validate()` across every active language's key | fails fast before any write (and before the batch's `DB::transaction()` opens, **Q-5**), keeps the per-row `canEdit` hint honest, and renders every refusal on the right tab with a localized message |
| **2 — action** | `App\Actions\ProductCategories\SetProductCategoryTranslation` | `$this->logRefusedPrivilegedAttempt->authorize('update', $category, …)` then its own `Validator::make(...)->validate()` | binds **every** caller — a future importer, command or job inherits the whole rule by calling the action, with no component in sight |

**Why both, stated so it survives a "simplify this" review.** [The action-owns-the-rule convention](../../../docs/conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)
establishes that *"if an operation must not happen without a permission, the check lives in the
class that performs the operation"* — layer 2 — and task 0017's Sales Regions precedent adds the
converse: ***"a component that authorizes as well is a layer, not a redundancy… a reviewer who
deletes one of the two has removed a layer, not a redundancy."***

Two mechanics that follow:

- **The component authorizes the whole batch before any action runs**, because one `save()` click
  writes the default-language row **and** N other translation rows as one logical operation. Layer
  2 then re-authorizes per row.
- **`SetProductCategoryTranslation` is method-injected into `save()`** (per-method action
  injection), while its *own* four collaborators are constructor-injected (the documented
  exception).

> ⚠️ **The default-language path keys its refusals differently, and the component must adapt.**
> `CreateProductCategory` / `RenameProductCategory` throw `ValidationException` keyed **`name`**
> (0023's shape, frozen by 0070 **D-12**; `TranslateProductCategoryNameUniqueViolation` defaults to
> `'name'`), while every field on this screen is bound to **`names.{languageId}`**. Verified
> 2026-10-07 in `vendor/livewire/livewire/src/Features/SupportValidation/SupportValidation.php`:
> `dehydrate()` keeps an error only when `Utils::hasProperty($component, $key)` holds, and
> `hasProperty()` checks `property_exists($target, beforeFirstDot($key))`. Once `$name` is removed,
> a `name`-keyed error is **dropped from the snapshot entirely**, so the modal would stay open with
> no message. **The component catches that `ValidationException` around the Create/Rename call and
> re-throws it re-keyed `name` → `names.{defaultId}`** (and sets `$activeLanguageId` to the
> default). *Rejected:* widening 0070's two actions to key on `names.{id}` — a public contract
> their direct-call tests bind to. The same check confirms `names.{id}` errors **do** survive
> dehydration, because `names` is a declared property (resolves **D-8**'s open half).

**D-5 — Only active store languages get a tab. Content in a removed language is hidden, not shown
read-only.** 0070 **D-6** is emphatic that `translated()` must never filter on `is_active` and that
*"the `is_active` filter belongs one layer up, at the UI's 'which tabs do I render' decision"* —
this story is that layer, and this is that decision. *Hiding* costs no signal that is not already
provided at the right layer (`StoreLanguage::translationUsageCount()` and 0069's removal warning).
*Showing it* costs an extra query per modal open, a third tab state, and an affordance the
administrator cannot act on (reactivating a language is a `store-languages` operation) — the
"UI debt with unclear value" 0069 **D-8** rejected. **No content is lost either way**: 0070 **D-6**
guarantees it survives and becomes editable again when the language is reactivated.

**D-6 — The edit field reads the raw translation row; the list cell reads `translated()`. These are
different reads and conflating them is the sharpest bug this story can ship.**
`translated('name', $frenchId)` applies the fallback by design, which is **correct for the list**
and **wrong for the edit input**: binding it into the French field means an administrator who saves
without touching that tab silently creates a French translation byte-identical to the Spanish one.
So the modal loads
`$target->load(['translations' => fn ($q) => $q->whereIn('store_language_id', $activeIds)])` and
reads each language's own row, rendering `''` where none exists.

> ⚠️ **`scopeWithTranslationsFor()` is the wrong tool for the modal.** Verified against
> `HasTranslations.php`: that scope always widens to (requested, default) — at most two languages —
> because it was built for single-language-with-fallback resolution on a *list* path. The modal
> needs the raw value for **every** active language. It **is** the right tool for the list (**D-12**).
> Note that the shipped `openEditModal()` uses it today, correctly for a single default-language
> field; this story replaces that read.

**D-7 — Requiredness is conditional per tab, and the condition is a fact about this edit session.**
Three branches: the **default** language is always `required` (0070 **Q1(a)**); a **non-default,
previously untranslated** language is `nullable`, so leaving it blank is a no-op rather than a
refusal; a **non-default, previously translated** language is `required`, so blanking it out is
refused. The third branch is what makes the non-default blank-translation refusal (handed to this
story by 0070 **D-17**) hold at the UI layer. The condition is expressed in the component, **not**
pushed into `ProductCategoryValidationRules` — "was this language translated when the modal opened"
is a property of the session, not of the field. ⚠️ **This story ships no way to *remove* a
translation** — see **Q-1**.

*Mechanics (N-2, added at the Phase 2 rewrite):*

- `nameRules()` returns `['required', 'string', 'max:255', <uniqueness closure>]`. For a
  non-default, previously untranslated language the component builds
  `['nullable', ...array_values(array_filter($rules, fn ($rule) => $rule !== 'required'))]` —
  filtering by value rather than replacing index `0`, so a future reorder of the trait's list
  cannot silently turn the swap into a no-op. `string|max:255` and the uniqueness closure are kept.
- The closure is not an implicit rule, so Laravel skips it for an empty value — the intended
  behaviour for a blank untranslated tab.
- **Every `$names` value is trimmed in `save()` before validation** (and the trimmed values are what
  is validated and written). Without this, `'   '` on an untranslated tab passes `nullable|string`,
  is then trimmed to `''` by `SetProductCategoryTranslation`, and is refused by the action's
  `required` on a tab the administrator thinks they left blank. On a `required` tab, Laravel's
  `required` already treats whitespace-only as empty, so trimming changes nothing there.
- After validation, the component writes a non-default language **only** when its trimmed value is
  non-empty. A blank untranslated tab is skipped; a blank previously-translated tab never gets this
  far (refused above).

**D-8 — Error keys are `names.{languageId}`, and the compiled output of the strip must be verified
by rendering.** *Error-key survival is now verified, not deferred* (2026-10-07): Livewire's
`SupportValidation::dehydrate()` filters with `Utils::hasProperty()`, which tests only the segment
before the first dot, so `names.{id}` survives because `names` is a declared property (see **D-4** ⚠️
for the consequence on the `name` key).

> ⚠️ **A markup landmine, with its scope corrected.** What is verified by execution
> ([errors-log](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#two-directive-calls-in-one-blade-component-tags-attribute-string-silently-fail-to-compile--2026-08-26))
> is that `@js()` fails to compile **in the attribute of an `<x-…>` tag at the call site**; the log
> establishes nothing about `@js()` inside the component's own template and says the mechanism must
> not be guessed at. So: use `{{ \Illuminate\Support\Js::from($language->id) }}` inside the strip,
> pass props to `<x-language-tab-strip>` with `:`-bound expressions (never `@js()`), and **render
> the modal and read the real HTML at Phase 3**. A tab whose `wire:click` silently stringifies is a
> no-op invisible to `Livewire::test()->call('setActiveLanguageTab', …)`.

**D-9 — Browser tests go in the mirrored subfolder, `tests/Browser/ProductCategories/`.**
Re-verified 2026-10-07: `tests/Browser/` holds **five** flat files (`PaymentMethodsIndexTest`,
`ProductCategoriesIndexTest`, `RolesIndexTest`, `SalesRegionsIndexTest`, `UsersIndexTest`) beside
the mirrored subfolders, whose convention is `tests/Browser/<Domain>/IndexTest.php` (e.g.
`Products/IndexTest.php`, `BlogTags/IndexTest.php`, `StoreLanguages/IndexTest.php`). Because this
story must rewrite `ProductCategoriesIndexTest.php` anyway, it **moves** it to
`tests/Browser/ProductCategories/IndexTest.php` beside the new `LanguageTabsTest.php`, leaving four
flat files.

**D-10 — Copy extends `lang/*/products.php`; no new domain file, and language names are never
translation keys.** This story appends `categories.index.tabs.*` under the existing
`categories.index` group (today holding only `action_not_allowed`): the untranslated-tab hint, the
tab-error marker's `aria-label`, and `name_attribute` (the localized "name" used by
`validationAttributes()`, N-1). **A language's own display name is data**, read from
`store_languages.name` (the fixture's endonym), and must never be routed through `__()`.

**D-11 — No assertion in this story may match on a language name or a two-letter code.** The tab
labels *are* endonyms ("Español", "Français"), and 0067's account-menu switcher renders the same
strings in this page's chrome. Two-letter codes match inside ordinary prose (`fr` inside "from") —
the `assertSee('0%')`-inside-`10%` trap from 0018. Every assertion goes through a `data-test` hook.
A fixture must never pick a category name colliding with an active language's endonym.

**D-12 — The list is inherited unchanged from 0070 D-15.** The shipped query
(`Index::loadProductCategories()`, `Index.php:313-336`):

```php
ProductCategory::query()->withCount('products')->withTranslationsFor()->get()
    ->sort(fn (ProductCategory $a, ProductCategory $b): int => $compareTranslatedNames(
        $a->translated('name'), $a->id, $b->translated('name'), $b->id,
    ))
    ->values();
```

`App\Actions\Translations\CompareTranslatedNames` orders case- and accent-insensitively with an `id`
tie-break, and a name that resolves to `null` sorts last and renders `—`. This story changes none of
it. The reasoning that motivated it stands: a raw join filtered to the default language bypasses the
fallback chain, and `withTranslationsFor()` with no argument is a single eager load, so the list has
no N+1. It is the read for which that scope **is** the right tool, unlike the modal's (**D-6**).

**D-13 — The two-layer shape is the master pattern for 0073 / 0075 / 0077 / 0079, and it survives a
component that cannot validate.** Each sibling adds one action beside its own entity's existing
ones — `SetBlogCategoryTranslation`, `SetBlogTagTranslation`, `SetProductTranslation`,
`SetBlogPostTranslation` — following the contract above verbatim:

```php
Set<Entity>Translation::__invoke(<Entity> $entity, StoreLanguage $language, ...$translatableFields): <Entity>Translation
```

with `$this->logRefusedPrivilegedAttempt->authorize('update', $entity, targetType: …, targetId: $entity->id)`
as its first statement; its own `Validator::make(['<field>s' => [$language->id => $value]], ["<field>s.{$language->id}" => …])`
call — **nested data, dotted rule key** — reusing that entity's existing `<Noun>ValidationRules`
trait; an error key derived as `"names.{$language->id}"` (or `"{$field}s.{$language->id}"` for a
multi-field entity); its entity's unique-violation translator called with that derived key;
constructor-injected collaborators; an explicitly narrowed return type; and `SetTranslation` called
internally and reached from nowhere else. **What a sibling must not re-derive:** the two-layer split,
the derived-not-parameterised error key, the nested-data Validator shape, the rule that no component
imports `SetTranslation`, or the `x-show` panel-rendering mode (**D-2**).

**The case that looks like an exception and is not.** 0060's Blog Tags screen consumes actions that
[0059](../done/0059-blog-tags-backend.md) already made responsible for their own validation, so its
component does **not** validate — adding a layer 1 would duplicate a rule the action owns, which
[the convention](../../../docs/conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)'s
*"move the rule, never copy it"* forbids. **Defence in depth still holds there, because layer 2 is
self-sufficient by construction.** The principle is *"the operation is protected without relying
on its caller"*, not *"the check appears in exactly two files"*.

> ⚠️ **The direction of the asymmetry is the whole rule.** Component-only is never acceptable
> (0008a's finding). Action-only is acceptable wherever a component cannot validate without
> duplicating. A sibling author should ask *"if I delete the component, is the operation still
> protected?"* — if no, the story is not done.

**D-14 — Tab order: the store default first, then the remaining active languages by `name`.**
*(Human decision, 2026-10-07, N-4.)* The strip's `languages` collection is
`StoreLanguage::query()->active()->orderByDesc('is_default')->orderBy('name')->get()`, queried once
per modal open. It makes the `data-test` count assertions and browser click targets stable, and the
default tab — the only always-required one — is always the leftmost. When no default exists (0070's
reachable edge), the order is simply by `name` and the first tab is selected; saving then refuses
through 0070's existing `RuntimeException` in Create/Rename, unchanged by this story.

---

## 5. Dependencies, risks, open technical questions

### Dependencies

- **[0070](../done/0070-translatable-content-mechanism-product-categories-backend.md)** — `HasTranslations`, `SetTranslation`, `TranslateProductCategoryNameUniqueViolation`, the widened validation trait, the dropped `name` column, and the list query (D-15). **Done.**
- **[0025](../done/0025-product-categories-ui.md)** — the component, view, route and sidebar entry this story widens. **Done.**
- **[0068](../done/0068-store-languages-catalog-backend.md)** — `StoreLanguage`, `scopeActive()`, `defaultStoreLanguage()`. **Done.**
- **[0023](../done/0023-product-categories-backend.md) / [0024](../done/0024-products-core-crud-backend.md) / [0024b](../done/0024b-product-category-in-use-delete-guard.md)** — transitively via 0025. **Done.**
- No pending dependency remains (`ai-spec/tasks-status.json`: `depends_on: []`, `ready`). 0073, 0075 and 0079 are blocked on this story.
- **No new package.** No tabs library; Flux Free ships none.

### Risks

- **R-1 — Resolved 2026-10-07.** This story supersedes 0025's `public string $name` surface. 0025 is closed, so the amendment is not another story's edit: it is this story's own code change plus the test migrations listed under *Files to create/modify*.
- **R-2 — Resolved 2026-09-28** by 0070 D-15 (the list query was migrated with the column drop).
- **R-3 — Re-derived against shipped code, 2026-10-07.** Previously "designed against four unimplemented specs". Every contract this story consumes was re-read from the tree for this rewrite (`Index.php`, `RenameProductCategory.php`, `SetTranslation.php`, `TranslateProductCategoryNameUniqueViolation.php`, `ProductCategoryValidationRules.php`, `HasTranslations.php`, `StoreLanguage.php`, Livewire's `SupportValidation`). Residual risk: a Phase 3 author re-reads them anyway if any has changed on `main` since.
- **R-4 — Narrowed (N-3).** `loadProductCategories()` re-queries from scratch after every save, so the **list** cannot go stale. The residual risk is the **open modal** on a refused save: if a later per-language write is refused (the 1062 race path) after earlier writes in the same `save()` already ran, the modal stays open with `$originalTranslatedLanguageIds` describing the pre-save state. **Mitigated by Q-5 (resolved 2026-10-07):** the whole batch runs in one `DB::transaction()`, so earlier writes are rolled back and the pre-save state is still the true state; a dedicated rollback test pins it.
- **R-5 — Two sibling-story claims are stale.** 0069 **D-3** states `flux:separator` is used nowhere — it is used in `components/settings/layout.blade.php`. 0069 **D-17** states there are "two existing flat" browser files — there are **five** before this story and four after it (**D-9**). Neither changes a decision here.
- **R-6 — Resolved 2026-10-07.** Flux Free v2 is installed and ships no tabs component; the hand-built strip (**D-1**) stands.
- **R-7 — Resolved 2026-10-07 (human decision).** The new action logs its refusals through `LogRefusedPrivilegedAttempt`, like every other Product Categories write path; `RefusalLoggingTest.php` gains a direct-call case. The Phase 1 premise ("its three siblings stay silent") was false against the shipped code.
- **R-8 — The pattern this story sets is copied four times.** Any weakness in the strip's contract, the error-key shape, the Validator data shape or the requiredness rule is reproduced by 0073/0075/0077/0079. The Phase 2 flat-key bug is the proof: it would have refused every valid name on five screens.

### Open questions for the product owner

Per [contracts.md](../../../docs/contracts.md)'s Uncertainty Handling Rule, each carries a
recommendation rather than a silent assumption.

**Q-1 — Can an administrator *remove* a translation once it exists? ✅ RESOLVED 2026-08-30 — option (a).**
- **(a) No — a translation, once authored, can be corrected but not removed — _(recommended)_.** Faithful to 0070's *"A blank translation is refused"*, keeps the write path a single `updateOrCreate`, and removing the store language already exists as the remedy.
- **(b) Yes — blanking a non-default tab deletes that translation row.** Needs a delete path `SetTranslation` does not have and a confirmation affordance this story has not designed.

**Q-2 — Does the create modal offer every language tab, or only the store default? ✅ RESOLVED 2026-08-30 — option (a).**
- **(a) Offer every tab on create — _(recommended)_.** PRD Epic 5 does not distinguish create from edit; extra languages go through `SetProductCategoryTranslation`, fully authorized and validated.
- **(b) Default language only on create.** Closer to 0070's Gherkin, at the cost of a two-step workflow.

**Q-3 — Who applies 0070's R-1 fix to this screen's list query? ⚠️ SUPERSEDED 2026-09-28 by 0070 D-15.** 0070 applied **D-12**'s query itself; this story inherits it.

**Q-4 — How does the duplicate-name message get a localized attribute? ✅ RESOLVED 2026-10-07 — option (a)** (human decision). Applied in *Files to modify* (the one-line trait row and the `ProductCategoryValidationRulesTest.php` addition), *Deliberately not touched*, the rendering test for the translated per-language message, and the Acceptance criteria.
Found while verifying N-1, by running the validator (2026-10-07). `validationAttributes()` localizes
`required`/`string`/`max` (verified: `The name field is required.` for `names.x1` with
`['names.*' => 'name']`). It does **not** reach the uniqueness message, because 0070's
`uniqueNormalisedName()` closure calls `$fail(trans('validation.unique', ['attribute' => $attribute]))`,
which interpolates the raw key **before** the Validator's own `:attribute` replacement runs (verified:
`The names.x1 has already been taken.`). The duplicate-on-a-hidden-tab case is this story's
highest-value scenario, so the message would show an internal UUID exactly where it matters most.
- **(a) Change one line in the existing closure to `$fail('validation.unique')->translate();` — _(recommended)_.** The `:attribute` placeholder then survives to the Validator, which resolves it through `validationAttributes()` (verified: `The name has already been taken.` for `names.x1`). For the existing `name`-keyed callers (Create/Rename) the output is byte-identical **in `en`** (verified: `The name has already been taken.`); in `es` it improves from `El name ya está en uso.` to `El nombre ya está en uso.` (`lang/es/validation.php:165`; corrected after `code-reviewer` B-3). No existing test asserts the `es` text, so 0070's tests are unaffected. Cost: lifts "`ProductCategoryValidationRules` is unmodified" for one line inside an existing method, and siblings 0073/0075/0077/0079 would make the same one-line change in their own traits.
- **(b) Leave the trait untouched and accept the raw key in the uniqueness message.** Zero cross-story change; the screen shows "The names.0199… has already been taken." on the story's most important refusal.
- **(c) Map the closure's message per key in the component's `messages()`.** Rejected as a recommendation: Laravel looks up a closure rule's custom message under its class name (`ClosureValidationRule`), which is brittle and undocumented as a contract here.

**Q-5 — Is one `save()` atomic across its per-language writes? ✅ RESOLVED 2026-10-07 — option (a)** (human decision). Applied in *Files to modify* (the component row), the component's `save()` mechanics, the D-4 layer-1 row, R-4, the rollback test in `LanguageTabsTest.php`, and the Acceptance criteria.
Layer 1 validates every language before any write, so a mid-batch refusal is only reachable on the
1062 race path (or an unexpected `QueryException`). If it happens, earlier writes in that `save()`
have already committed: for example the default name is renamed but French is refused.
- **(a) Wrap the default-language write and every per-language write in one `DB::transaction()` in `save()` — _(recommended)_.** One click becomes all-or-nothing, matching what the administrator sees (the modal stays open with every value still typed). Precedent: `App\Livewire\Roles\Index`, `Shipping\Zones` and `Products\Editor` already open transactions in components, and `CreateProductCategory`'s own inner transaction nests as a savepoint. Refusal logging is unaffected (it writes to the log channel, not the database). **Every authorize call the component makes stays outside the transaction**, as the convention requires, including the create-path `update` check added by **B-1**. The action's own layer-2 gate runs inside it by construction and can only fail on a mid-request revocation (see the component's `save()` mechanics).
- **(b) No transaction; accept partial writes on the race path.** Smaller `save()`; the residue is a renamed default with an unsaved translation, recoverable by saving again.

## 6. Technical tasks for later backlog creation

Derived from this debate; **none are in scope for 0071**.

1. ~~Amend 0025~~ — **closed 2026-10-07**: 0025 is done; the amendment is this story's own code and test migration.
2. **Stories 0073 / 0075 / 0077 / 0079** reuse `language-tab-strip.blade.php`, expose `setActiveLanguageTab()`, **each add their own `Set<Entity>Translation` action per D-13** (logged authorize, nested-data Validator, derived key, unique-violation translator, narrowed return), and re-derive none of **D-2**, **D-4**, **D-6**, **D-7**, **D-8**, **D-13** or **D-14**. 0077/0079 are the first with **multiple** translatable fields per tab. 0073, 0075 and 0077 predate this rewrite — the coordinator propagates the Phase 2 corrections (nested Validator data, logged authorize, D-14 order, the Q-4 `$fail(...)->translate()` line in each sibling's own validation trait, and the Q-5 transaction around each save batch) to them, with this file as the reference.
3. **Retire the four remaining flat `tests/Browser/` files** into mirrored subfolders (0069's backlog item 5; this story moves the fifth).
4. **Correct 0069's D-3 and D-17 stale claims** (**R-5**).
5. ~~Decide whether the Product Categories write paths adopt refusal logging~~ — **closed 2026-10-07**: they all already do, and the new action does too (R-7).
6. ~~Revisit `flux:tabs`~~ — **closed 2026-10-07**: Flux Free v2 ships none (R-6).
7. **Surface "this language holds content but is no longer active"** somewhere an administrator can act on it — 0069's backlog item 3 (**D-5**).

## Provenance

**Phase 1 Three Amigos debate, 2026-08-30.** Participants: `product-owner` (facilitator),
`frontend-expert`, `frontend-qa` — both dispatched as real subagents. Classification (frontend) was
fixed by the coordinator; this story is one of a human-confirmed 14-story decomposition of PRD
Epic 5.

**Where the two converged, independently:** that the edit field must read the **raw** translation
row rather than `translated()`'s fallback (**D-6**); that a validation error on a hidden tab is the
story's sharpest risk and needs both a server-side switch and a persistent marker; that only active
languages get tabs; and that language names and two-letter codes make page-global assertions unsafe
on this screen.

**Where the facilitator decided rather than either participant.** `frontend-qa` left the
tab-switching mechanism open; `frontend-expert` recommended server-side. **Adopted server-side
(D-2)**, on the participant's own strongest argument — only the server knows which tab was refused.

**Post-debate amendment, 2026-08-30 — a human architectural decision.** The original **D-4** made
the component the sole authorizing and validating caller. The human ruled that both layers are
required. **D-4** was rewritten around the two-layer table, **D-13** was added, and
`SetProductCategoryTranslation` was added with its own direct-call test file.

**Second post-debate amendment, 2026-08-30 — `x-show` is the universal panel-rendering mode**, after
sibling story 0077's **C-2** showed `@if` tears down stateful panels. The tracking axis is unchanged.

**One participant claim was verified and corrected rather than propagated:** the `@js()`-inside-an-
anonymous-component claim was narrowed to what the errors log actually establishes (**D-8**).

**Phase 2 rewrite, 2026-10-07 — `product-owner`, addressing the `code-reviewer` rejection below.**
Every claim the rewrite relies on was re-verified against the live tree, not taken from the
rejection record: the shipped `Index.php` surface, gates and list query; `RenameProductCategory`'s
call shape; `SetTranslation`'s `Model` return; the translator's `$errorKey` parameter; the trait's
`nameRules()` order; the absence of `final class` under `app/Actions`; Flux Free's missing tabs
component; the five flat browser files; and the broken test lines. Three claims were checked by
execution (`php artisan tinker`, no database writes): the flat-data-key `required` failure, the
nested-data fix, and that `validationAttributes()` cannot reach the uniqueness closure's message —
which surfaced **Q-4**. Reading Livewire's `SupportValidation::dehydrate()` resolved **D-8** and
showed the D-4 ⚠️ adapter is mandatory, not cosmetic. Reviewing `save()`'s write order surfaced
**Q-5**. Human decisions applied: R-7 (the action logs refusals), N-4 (D-14 tab order), and — later the same day — Q-4 (a) (the one-line trait change) and Q-5 (a) (one transaction per save), each propagated to Files, mechanics, tests and Acceptance criteria. The `code-reviewer` re-validation that followed (B-1 to B-3, N-5, N-6) was folded in the same day, with B-1 resolved by the human as option (a).
**D-1 to D-7 and D-13 are unchanged as decisions**; edits inside them only remove stale "`vendor/`
is absent" hedges, replace `Gate::authorize()` with the shipped logged gate, and add the N-2
mechanics to D-7 and the nested-data shape to D-13.

**Not run by this phase**, per [workflow.md](../../../docs/workflow.md): the INVEST re-check (Phase 2),
TDD implementation (Phase 3), security audit (Phase 4), code review (Phase 5), or the docs pass
(Phase 6).

## Phase 2 — INVEST validation

> **Status: ADDRESSED 2026-10-07 by the `product-owner` Phase 2 rewrite above; awaiting `code-reviewer` re-validation.**
> - **Blocking 1 (stale premises):** the banner, Type, Dependencies, D-1/D-2/D-8/D-9/D-12, R-1 to R-7 and backlog items 1/5/6 were rewritten against the shipped tree. R-7 is closed by the human decision that the action logs its refusals.
> - **Blocking 2 (flat Validator data):** fixed to nested data with a dotted rule key, re-confirmed by execution, with a regression canary test.
> - **Blocking 3 (contract vs. prose):** the snippet now uses the trait, the logged authorize, the unique-violation translator with the derived key, an explicit `instanceof` narrowing and no `final`. Refusal-log and 1062 re-key tests were added.
> - **Blocking 4 (broken tests):** `IndexTest.php`, `IndexRenderingTest.php`, `RefusalLoggingTest.php` and the browser file (moved to `tests/Browser/ProductCategories/IndexTest.php`) are listed under *Files to modify*, each with its migration.
> - **N-1** adopted (`validationAttributes()` + `tabs.name_attribute`), with one verified gap raised as **Q-4**. **N-2** folded into D-7, including trimming before validation. **N-3** narrowed R-4 and raised **Q-5**. **N-4** adopted as **D-14** (human decision).
> - **Q-4 and Q-5 were RESOLVED 2026-10-07 by the human, both option (a)**, and propagated through the file. No open question remains; the story is ready for `code-reviewer` re-validation.

> **Status of the re-validation below: ADDRESSED 2026-10-07 by `product-owner`; awaiting `code-reviewer` approval.**
> - **B-1:** human chose option (a). `save()` logs-and-authorizes `update` before validation and the transaction whenever a create batch carries a non-default value. Create mode renders non-default inputs disabled for actors lacking `products.edit` (`#[Locked] $canAuthorTranslations`, `tabs.translation_requires_edit`). Added one Gherkin scenario, one `LanguageTabsTest.php` test and one AC line. The Q-5 option text and the `save()` mechanics no longer contradict each other: every *component* authorize is outside the transaction, and the action's layer-2 gate runs inside it and can fail only on a mid-request revocation.
> - **B-2:** added the `AuthorizationException` and `ValidationException` imports to the master snippet.
> - **B-3:** "byte-identical" is now limited to `en` in *Files to modify*, Q-4 (a) and the AC, and the `es` change (`El name` → `El nombre`) is stated. The `ProductCategoryValidationRulesTest.php` case pins both locales.
> - **N-5:** both Products browser comments, plus the moved file's own "kept FLAT" header, are listed under *Files to modify*.
> - **N-6:** `save()` writes the trimmed values back into `$names`. The reason is stated, and the rollback test and AC reflect it.

**2026-10-07 — `code-reviewer` final Phase 2 pass: ✅ APPROVED, moves to Phase 3.** All the fixes from the re-validation below are in place in the live tree:
- **B-1 (option (a)):**
  - A new Gherkin scenario (`:209-212`) and the component mechanics (`:481-488`, `:500-507`) cover the create-only actor.
  - `save()` checks `update` on `new ProductCategory()`, logged, before validation and before the transaction opens. This is coherent with the shipped code: `ProductCategoryPolicy::update()` ignores its target, and `LogRefusedPrivilegedAttempt::resolveTarget()` keeps the `targetType` passed in and logs `target_id = null`, which is what the `:603` test asserts.
  - The new test (`:603`) and acceptance criterion (`:674`) match the scenario.
  - The Q-5 option text (`:1010`) and the `save()` mechanics (`:519-527`) now agree: every component gate runs before the transaction, and the action's own gate can fail inside it only if the permission is revoked mid-request.
- **B-2:** the snippet now imports both exception classes (`:331,334`). Larastan level 7 on the re-extracted snippet passes with 0 errors.
- **B-3:** "byte-identical" is now limited to `en` (`:296`, `:672`, `:1002`), and both locales are pinned.
- **N-5:** both browser test comments are listed under *Files to modify* (`:305`).
- **N-6:** the write-back of trimmed values is stated (`:497-500`) and reflected in the rollback test and acceptance criterion (`:604`, `:673`).

I found no new contradiction.

**One non-blocking item for Phase 3:** the new scenario's *Then* ("shown as unavailable") is a rendering outcome, but the listed test only asserts the `canAuthorTranslations` flag. `frontend-qa` should add one assertion to `LanguageTabsRenderingTest.php`: in create mode, for an actor without edit permission, every non-default `language-name-input-{id}` renders `disabled` with the `translation_requires_edit` hint, and the default input does not. Without it, the view could ignore the flag and every test would still pass.

INVEST: all six criteria pass.

**2026-10-07 — `code-reviewer` re-validation: ❌ REJECTED (narrowly), returned to `product-owner`.** The four 2026-10-06 blocking findings are **fixed** against the live tree. Blocking 1: every premise I re-checked holds (`Index.php:116,143,174,177,220,258` gates, `:165-170` `save()` signature, `:313-336` list query; `RenameProductCategory.php:49`; `CreateProductCategory.php:63`; `DeleteProductCategory.php:51,120`; `SetTranslation.php:37` returns `Model`; the translator takes `$errorKey`; Flux ships no `tab*` stub; no `app/View/`; five flat browser files; `RolePermissionSeederTest.php:37` = 43; no `SetTranslation` import and no `final class` under `app/`). Blocking 2: confirmed by tinker without DB writes, the flat form reports `The names.{id} field is required.` for `"Chaussures"` and the nested form passes. Blocking 3: the snippet now matches its prose and `RenameProductCategory`. Larastan level 7 on the extracted snippet finds the `instanceof` narrowing clean (see B-2 for the one error). Blocking 4: the 17 `IndexTest.php` lines (117–561), `IndexRenderingTest.php:77,116–126,147–155`, `RefusalLoggingTest.php:131` and browser `:65–188` are exact. No other test binds the component's `name` (the Blog tests only call `openCreateModal`). Q-4 verified: `$fail('validation.unique')->translate()` with `['names.*' => 'name']` renders `The name has already been taken.` (en) and `El nombre ya está en uso.` (es); a blank `nullable` tab skips the closure. The Q-5 transaction design is coherent on the **edit** path: validation and gates run before it, the D-4 re-key and the `$activeLanguageId` switch work from inside it, and `CreateProductCategory`'s inner transaction nests as a savepoint. Three new findings remain. They are small but need correcting before the pattern is copied four times.

1. **B-1, blocking (Testable/Estimable; needs a human decision): the create path asks `update` of an actor who may hold only `products.create`.** On create, layer 1 authorizes only `create` (`Index.php:174`), but each non-default tab is then written through `SetProductCategoryTranslation`, whose first statement authorizes `update` (→ `products.edit`, `ProductCategoryPolicy.php:65-68`). With Q-2 (a) offering every tab on create, a create-only administrator who types a French name gets an `AuthorizationException` **inside** the Q-5 transaction. The new category is rolled back, the actor sees a 403, and a refusal is logged for a correct create. The story names this exact hazard in D-4 (0070 D-9) without closing it for create. The repo documents it twice: `docs/conventions/directory-structure/layout-conventions.md:24` ("`update` would be asked of an actor who legitimately holds only `products.create`, refusing a correct create halfway through"), and `controllers-and-authorization-rule.md:76-78` ("authorize every row the operation writes", authorized *outside* the transaction "so a refusal never opens one"). No Gherkin scenario, AC or test defines the outcome. The Q-5 text also contradicts itself: line 972 says "the authorize calls stay outside the transaction", while lines 489–491 accept the action's authorize running inside it. Options:
   - **(a) _(recommended)_** Layer 1 also logs-and-authorizes `update` before the transaction whenever the batch carries a non-empty non-default value, on both branches. In create mode the UI renders non-default inputs disabled, with a hint, when `Gate::denies` `products.edit`. The action's in-transaction gate then fails only on a mid-request revocation. Add one Gherkin scenario, one component test and one AC line, and reword line 972 and lines 489–491 to match.
   - **(b)** Create mode offers the default tab only to an actor lacking `products.edit`, and a forged non-default value is refused at layer 1 before the transaction.
   - **(c)** Accept the 403 and rollback. Not recommended, because it contradicts both convention docs above.
2. **B-2, blocking but trivial: the master snippet does not pass Larastan level 7 as written.** Its docblock `@throws AuthorizationException` / `@throws ValidationException` (lines 339–341) has no imports, so PHPStan reports `throws.notThrowable` (`App\Actions\ProductCategories\AuthorizationException|…ValidationException is not subtype of Throwable`). Fix: add `use Illuminate\Auth\Access\AuthorizationException;` and `use Illuminate\Validation\ValidationException;`. I re-ran the analysis with both imports and it passes with 0 errors.
3. **B-3, blocking but trivial: the "byte-identical" claim is false in `es`.** It appears at lines 289–291 and 295, in Q-4 (a) (line 964) and in AC line 635. `lang/es/validation.php:165` maps `attributes.name => 'nombre'`, so for `name`-keyed callers in `es` the old line rendered `El name ya está en uso.` and the new one renders `El nombre ya está en uso.` (verified by tinker). The change is an improvement and no existing test breaks (`grep` finds no `es` assertion of that message). The fix is to the claim: restrict "byte-identical" to `en`, state the `es` change, and have the new `ProductCategoryValidationRulesTest.php` case pin both locales.

**Non-blocking:**
- **N-5:** `tests/Browser/Products/IndexTest.php:11` and `tests/Browser/Products/AttributeTypesIndexTest.php:27` cite `tests/Browser/ProductCategoriesIndexTest.php` in comments. Update both when D-9 moves the file, or list them under *Files to modify*.
- **N-6:** say whether `save()` writes the trimmed values back into `$names`. The rollback test asserts that `$names` "still holds all three typed values".

INVEST: **Independent**, **Negotiable**, **Valuable** and **Small** pass. **Estimable** and **Testable** fail on B-1 alone. B-2 and B-3 are edits to wording and imports. Once B-1 is decided and folded in, and B-2/B-3 are corrected, I expect to approve without another full pass.

**2026-10-06 — `code-reviewer`: ❌ REJECTED, returned to `product-owner` for rewrite.** I checked the story against the live tree. 0023, 0024, 0024b, 0025, 0068 and 0070 are all in `done/` and shipped. The core design still holds: the two-layer split (D-4/D-13), the raw read in the edit field (D-6), active-only tabs (D-5), `x-show` panels (D-2), server-side tab tracking, conditional requiredness (D-7) and the anonymous strip component (D-1). The story fails **Estimable** and **Testable** for four reasons. First, its premises describe a tree that no longer exists. Second, the master action contract it hands to four siblings has a bug that refuses every valid name; I confirmed this by running the code. Third, that contract does not match its own prose or the three actions beside it. Fourth, the existing tests this change breaks are not listed. **Independent**, **Negotiable**, **Valuable** and **Small** pass. I accept not splitting the story (the Type ⚠️): the action is a thin wrapper, and splitting it out would separate the screen from the guard it relies on. `backend-qa` should own `SetProductCategoryTranslationTest.php` at Phase 3.

1. **Blocking: stale premises (Estimable).** Rewrite these against the shipped code:
   - The "this story retrofits a screen that does not exist yet" banner (lines 35–43), the Type line, every "**Specified, not implemented.**" in Dependencies, R-3, and the `lang/*/products.php` ⚠️, which says "Verified absent". All of them are false. `app/Livewire/ProductCategories/Index.php`, `resources/views/livewire/product-categories.blade.php` and `products.categories.index.action_not_allowed` exist (`lang/en/products.php:29-31`, with en/es key parity at 131 `=>` each). The sequencing fence for the lang file is moot.
   - The **D-12** snippet `->sortBy(fn … translated('name'))` is not what shipped. `loadProductCategories()` (`Index.php:313-336`) sorts through `App\Actions\Translations\CompareTranslatedNames`, which is case- and accent-insensitive with an `id` tie-break. Cite the shipped query, not the proposal.
   - The component diff does not match the shipped methods. The live `save()` (`Index.php:165-170`) injects `NormalizeForSearch` and `LogRefusedPrivilegedAttempt` as well as the two actions. Every gate in the component goes through `$logRefusedPrivilegedAttempt->authorize(…, targetType: 'product_category', targetId: …)`, not `Gate::authorize()`. That includes the D-4 layer-1 table row. The diffed `save()` signature must keep both injections. `openCreateModal`, `openEditModal` and `closeModal` (`:114-208`) reset only `name` and `resetValidation('name')`. The rewrite must state that they reset `names`, `originalTranslatedLanguageIds` and `activeLanguageId`, and clear every `names.*` error key.
   - **R-7's premise is false.** Every component method already logs refusals (`Index.php:116,143,174,177,220,258`), and so do all three sibling actions: `CreateProductCategory.php:63`, `RenameProductCategory.php:49`, and `DeleteProductCategory.php:51,120`. `tests/Feature/ProductCategories/RefusalLoggingTest.php` covers this. R-7 argued that the new action should stay silent for consistency, and that argument now points the other way: a silent `Gate::authorize()` would make it the **only** non-logging Product Categories write path. So `SetProductCategoryTranslation` constructor-injects `LogRefusedPrivilegedAttempt` and calls `authorize('update', $productCategory, targetType: 'product_category', targetId: $productCategory->id)` as its first statement. `RefusalLoggingTest.php` gets a direct-call case. Close R-7 and backlog item 5.
   - R-1, R-2, Q-3 and backlog item 1 ("Amend 0025 … not this story's to write") are stale because 0025 is closed. What is left is code this story must change itself (see 4).
   - `vendor/` **is** installed. Every "`vendor/` is absent, unverifiable" hedge is stale: D-1, D-2 ⚠️, D-8, R-6 and backlog item 6. **R-6 is resolved:** the installed Flux Free v2 ships no tabs component (`vendor/livewire/flux/stubs/resources/views/flux/` has `navbar`, `navlist` and `table`, but no `tab*`). Drop R-6 and backlog item 6, and keep the hand-built strip. D-8's error-key survival can now be checked against `vendor/livewire/livewire` instead of being deferred.
   - D-9 and R-5 say there are three flat Browser files. There are **five**: `PaymentMethodsIndexTest`, `ProductCategoriesIndexTest`, `RolesIndexTest`, `SalesRegionsIndexTest` and `UsersIndexTest`, against 15 mirrored subfolders. The decision (mirrored `tests/Browser/ProductCategories/`) stands.
   - Still true, and kept: `grep -rnE "<flux:tab(s|\.|[ >])|role=\"tab" resources/` returns nothing; there is no `app/View/`; the permission catalog has 43 entries (`tests/Feature/Seeders/RolePermissionSeederTest.php:37`); `StoreLanguage::scopeActive()` exists; `defaultStoreLanguage()` is `?self` and memoised; `SetTranslation` does not import anything under `app/Livewire/`; the `nameRules(NormalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null)` signature matches the action's call site; and `TranslateProductCategoryNameUniqueViolation::__invoke(QueryException $e, string $errorKey = 'name')` already takes the derived key.

2. **Blocking: the action contract refuses every valid name (Testable).** `Validator::make(["names.{$language->id}" => $name], ["names.{$language->id}" => …])` passes a **flat** data key that contains a dot. `Validator::parseData()` escapes that dot (`vendor/laravel/framework/src/Illuminate/Validation/Validator.php:366-385`), while the rule key still resolves as the nested path `names → {id}`, so `required` never sees a value. I ran it to confirm: the flat form fails with `names.{uuid}` for a valid `"Chaussures"`, and the nested form passes. Fix:
   ```php
   // ❌ always fails `required`
   Validator::make(["names.{$language->id}" => $name], ["names.{$language->id}" => $rules])->validate();
   // ✅ nested data, dotted rule key — error still keyed "names.{$language->id}"
   Validator::make(['names' => [$language->id => $name]], ["names.{$language->id}" => $rules])->validate();
   ```
   Siblings 0073/0075/0077/0079 copy this contract word for word, so fix it here.

3. **Blocking: the action contract does not match its own prose (Estimable/Testable).** The snippet (lines 260–295) must match what the text and the three sibling actions do:
   - It calls `$this->nameRules(…)` without `use ProductCategoryValidationRules;`.
   - The prose says it reuses `TranslateProductCategoryNameUniqueViolation` as the race guard, but the constructor does not inject it and there is no `try { … } catch (QueryException $e) { throw ($this->translateNameUniqueViolation)($e, "names.{$language->id}"); }`.
   - Use `LogRefusedPrivilegedAttempt` instead of `Gate::authorize()` (see 1).
   - The return type is `ProductCategoryTranslation`, but `SetTranslation::__invoke()` returns `Model` (`app/Actions/Translations/SetTranslation.php:37`). Narrow the type explicitly (an `instanceof` assert), or Larastan level 7 fails.
   - `final class` appears nowhere in `app/Actions` (`grep -rn "final class" app/Actions` returns nothing). Follow the siblings and drop it.
   - Add the matching tests: a refusal-log assertion, and the race path re-keyed to `names.{id}` (one canary with a constructed 1062).

4. **Blocking: existing tests this change breaks are missing from "Files to modify" (Testable, full-suite gate).** Replacing `public string $name` with `public array $names` turns these red. The story must migrate them; "this story edits no other story's file" is about task files, not tests:
   - `tests/Feature/ProductCategories/IndexTest.php`: about 20 cases use `->set('name', …)` / `assertHasErrors(['name'])` (lines 117–622).
   - `tests/Feature/ProductCategories/IndexRenderingTest.php:77`: `'the create and edit modal contains exactly one text input'`, which this story explicitly invalidates. Also lines 124–155, the `name` error and the `resetValidation('name')` regression.
   - `tests/Feature/ProductCategories/RefusalLoggingTest.php:131`.
   - `tests/Browser/ProductCategoriesIndexTest.php`: lines 65–190 (`fill('name')`/`assertValue('name')`, and an `assertSee` of `validation.unique` with `attribute => 'name'`).

   State whether each one is rewritten in place or replaced by the new `LanguageTabs*` files, without lowering any assertion. The flat browser file may also move to `tests/Browser/ProductCategories/` under D-9.

### Non-blocking (fold in during the rewrite)

- **N-1, validation attribute.** The `nameRules()` closure and `required` render `:attribute` as the raw key, for example "The names.0199… field is required". Add a `validationAttributes()` (or `messages`) mapping `names.*` to the localized "name" in the component, plus the matching `tabs.*`/attribute lang key. The action's message surfaces to non-UI callers as-is, and that is acceptable.
- **N-2, D-7 mechanics.** `nameRules()` puts `'required'` first. Say how the component swaps it for `'nullable'` on a previously untranslated non-default tab while keeping `string|max:255|unique`. A closure rule is not implicit, so it is skipped for an empty value, which is correct.
- **N-3, R-4.** `loadProductCategories()` re-queries from scratch, so the list cannot go stale. Narrow R-4 to the open modal only, or drop it.
- **N-4, tab order.** The Gherkin fixes which tab is *selected* but not the order of the strip. Pin it (recommended: default first, then by `name`) so the `data-test` count assertions and the browser click targets are stable.

### Open decisions for the human

None block the rewrite. Item 1's R-7 resolution (the new action logs its refusals) follows from the shipped code and from the story's own consistency argument. The project owner may overturn it. N-4's ordering is a minor product call with a recommendation attached.

## Phase 4 — Security audit (2026-10-07)

**`appsec-auditor`: ✅ APPROVED, continues to Phase 5.** No Critical, High or Medium findings. The audit covered the working-tree diff: `SetProductCategoryTranslation`, `ProductCategoryValidationRules`, `ProductCategories\Index`, `product-categories.blade.php`, `components/language-tab-strip.blade.php`, and `lang/{en,es}/products.php`. Each Definition of Done point was checked against the code:

- **Both layers of the per-tab write path.** In the component, `save()` authorizes `create`/`update` (logged) before anything else. In the action, `SetProductCategoryTranslation::__invoke()` calls `authorize('update', …)` (logged) as its first statement. Tests cover both: `LanguageTabsTest` (`products.view` forged save), `SetProductCategoryTranslationTest` (lacking `products.edit`), and `RefusalLoggingTest` (direct-call refusal logged).
- **B-1.** On the create branch, a non-blank non-default value triggers a logged `update` check. The check runs after trimming, before validation and before `DB::transaction()`, so nothing is written. The action re-checks inside the transaction.
- **Primitive reachability.** `SetTranslation` is imported only by `CreateProductCategory`, `RenameProductCategory` (both default language, shipped in 0070) and the new action. No `app/Livewire/` file imports it.
- **Error key.** Derived as `"names.{$language->id}"` inside the action. It is not a parameter.
- **Locking.** `$editingCategoryId`, `$originalTranslatedLanguageIds` and `$canAuthorTranslations` are `#[Locked]`. `$names` and `$activeLanguageId` are unlocked. `save()` iterates the active languages it queries, coerces non-string values to `''`, and never reads `$names`' own keys. `$activeLanguageId` is only an `x-show` comparison and an error-tab fallback. It never feeds a write. The Livewire payload cap (1 MB, nesting depth 10) bounds a padded `$names`.
- **Forged language ids.** `setActiveLanguageTab()` uses `active()->findOrFail()`. `save()` ignores keys for unknown or inactive languages (tested).
- **IDOR.** `$editingCategoryId` is locked, written only from `$target->id`, and re-resolved with `findOrFail()` and re-authorized in `save()`.
- **Output encoding.** Names, language names and error text render through `{{ }}`. Ids in Alpine/`wire:click` expressions go through `Js::from()` (`JSON_HEX_*` flags) and are server-generated UUIDs. No `{!! !!}`.
- **Transaction integrity.** The create/rename and every non-default write share one `DB::transaction()`. A `ValidationException` or `AuthorizationException` from any write rolls back the whole save (tested).
- **Refusal-log content.** `actor_id`, `ability`, `target_type` and `target_id` only. No names or other user input.

Low / informational, non-blocking (no fix required to pass):

- **L-1, Low (CWE-209, cosmetic).** On the 1062 race path, `TranslateProductCategoryNameUniqueViolation` builds the message with `['attribute' => $errorKey]`. The same happens when the action's own `Validator::make()` refuses (no custom attributes). Both paths render `The names.<uuid> has already been taken.` The UUID is not sensitive (it already appears in `data-test` attributes), but it breaks N-1. Fix: pass `['names.*' => __('products.categories.index.tabs.name_attribute')]` as the action's validator attributes. Give the translator an optional `$attributeLabel` argument, defaulting to `'name'`. The sibling stories should copy that version.
- **I-1, informational.** The action accepts an inactive `StoreLanguage`. This is intentional and tested ("an inactive store language is still writable through the action"). Only the component filters to active languages. A future non-UI caller that should not author inactive languages must filter on its own side.
- **I-2, informational (DoS, bounded).** Each language's uniqueness closure loads every category name in that language, and the action re-runs it per non-default language. The cost is O(languages x categories) per save. That is acceptable at catalog scale. If catalogs grow large, revisit with a normalized-name column.

Durable pattern recorded in `docs/security/livewire-authorization/action-level-authorization.md#an-unguarded-write-primitive-is-reached-only-through-a-self-authorizing-self-validating-per-entity-wrapper`, so 0073/0075/0077/0079 can copy it.

## Phase 5 — Final code review (2026-10-07)

**`code-reviewer`: ✅ APPROVED, continues to Phase 6.** Reviewed the working-tree diff against every acceptance criterion and DoD item, after the L-1 fix.

**Quality gates (run by the reviewer, not taken from the handoff):**
- `vendor/bin/pint --format agent` **unscoped**: passed. No file was reformatted.
- Larastan level 7 (`phpstan analyse`, project-wide, `phpstan.neon`): 0 errors.
- **Full suite, unscoped**, in directory chunks. Each chunk ran on its own database (`testing_0071_product_categories_language_tabs_ui` = A, `…_b` = B). No two processes shared a database, and `tests/Browser` ran alone at the end.

| Chunk | DB | Tests | Passed | Skipped | Failed |
| --- | --- | --- | --- | --- | --- |
| `tests/Unit` | A | 367 | 367 | 0 | 0 |
| `Feature/{ProductCategories,Products,Translations,StoreLanguages}` | B | 918 | 918 | 0 | 0 |
| `Feature/{Actions…Media}` + `DashboardTest.php`, `ExampleTest.php` | A | 2189 | 2184 | 5 | 0 |
| `Feature/{Models,Navigation,Notifications,Orders,PaymentMethods,Policies,Providers}` | B | 1393 | 1393 | 0 | 0 |
| `Feature/{Roles,SalesRegions,Seeders,Settings}` | B | 428 | 428 | 0 | 0 |
| `Feature/{Shipping,ShippingRates,ShippingZones,Users}` | A | 544 | 544 | 0 | 0 |
| `tests/Browser` (alone) | A | 269 | 266 | 3 | 0 |
| **Total** | | **6108** | **6100** | **8** | **0** |

None of the 8 skips is in this story's files. `grep` finds no `skip`/`todo` under `tests/{Feature,Browser}/ProductCategories` or `tests/Unit/Actions/ProductCategories`. The orphaned `playwright run-server` processes were killed by exact PID.

**Scope:** no route, model, migration, policy, factory, seeder or permission file changed. `RolePermissionSeederTest.php:37` is still 43 and green. No file under `app/Livewire/` imports `SetTranslation`, and the `arch()` rule at `LanguageTabsTest.php:144` pins this. The edits to the other task files are link-integrity updates for the `in-progress/` move.

**Every acceptance criterion was verified in code and covered by a passing test.** Evidence:
- The tab order is D-14 (`Index.php` `languages()`).
- Panels use `x-show` and never `@if` (`product-categories.blade.php`, panel loop).
- The raw-row read is D-6 (`openEditModal()` plucks `ProductCategoryTranslation`).
- D-7 requiredness filters by value.
- The B-1 check runs before validation and before the transaction.
- The Q-5 transaction covers the whole batch, and the re-key from `name` to `names.{defaultId}` is in place.
- `firstErroringLanguageId()` switches to the refused tab.
- The action follows the master contract and adds the L-1 attributes.
- `lang/{en,es}/products.php` are key-for-key identical.

**Non-blocking findings (fold into Phase 6/7; none blocks approval):**
1. **The acceptance criterion at `:679` is now stale.** It says `TranslateProductCategoryNameUniqueViolation` is "unmodified", but the L-1 fix gave `__invoke()` an optional third parameter, `string $attributeLabel = 'name'` (`TranslateProductCategoryNameUniqueViolation.php:43`). The change is backward compatible: callers keyed on `name` are byte-identical, and 0070's tests stay green. It was recommended by `appsec-auditor` and is pinned by a new case in `TranslateProductCategoryNameUniqueViolationTest.php`. Amend `:679` and the *Deliberately not touched* row (`:314`) to record the L-1 widening, so 0073/0075/0077/0079 copy the widened translator.
2. **The Q-4 test is in a different file than the one the story names.** The story lists `tests/Unit/Concerns/ProductCategoryValidationRulesTest.php`, but the case shipped as `tests/Feature/ProductCategories/ProductCategoryTranslatedUniqueMessageTest.php`. The reason is sound and documented in that file's header: `tests/Unit` is DB-free, and the duplicate needs a real row. It pins both locales as required. Update *Files to modify* (`:304`) to match.
3. **The browser duplicate-name test is weaker than the story asks.** It uses `assertPresent('@language-name-error-{defaultId}')` (`tests/Browser/ProductCategories/IndexTest.php`), while the story asks for the marker to be *visible*. The default panel is the shown one, so the outcome holds today. `assertVisible` would state the intent exactly.

**Phase 6 resolution (docs-keeper, 2026-10-07):** findings 1 and 2 are folded into the story text (the acceptance criterion, the *Deliberately not touched* row and *Files to modify* now describe the shipped state). Finding 3 is closed: the browser duplicate assertion is now `assertVisible`.

## Phase 7 -- Closure (2026-10-07)

All acceptance criteria and Definition of Done items are ticked, backed by the Phase 4 (security, approved), Phase 5 (code review, approved; full suite 6108 tests, 0 failed) and Phase 6 (documentation) records above. The file moved from `ai-spec/tasks/in-progress/` to `ai-spec/tasks/done/`; link integrity verified in both directions (64 links, 0 broken) and the coordination files regenerated.
