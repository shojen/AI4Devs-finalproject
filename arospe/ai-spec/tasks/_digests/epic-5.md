# Epic 5 decision digest (Internationalization)

Append-only. See [workflow.md#decision-digest-per-epic](../../../docs/workflow/agents-and-epic-digests.md#decision-digest-per-epic)
for what belongs here and what doesn't. Created by story 0067's docs pass; stories 0066 and 0068
closed earlier and are not backfilled here (read their `done/` files or `docs/`).

## Story 0067 — Admin UI language switcher (UI)

- `App\Enums\UiLocale::label()` returns endonyms (`English`, `Español`), deliberately not via `__()` — story 0067.
- `App\Concerns\InteractsWithUiLocale`: `#[Computed] currentLocale()` and protected `applyUiLocale($locale, SetUserUiLocale, $redirectTo)`; used by `Settings\LanguageSwitcher` (chrome, routeless, mounted twice) and `Settings\Language` (route `language.edit`). Each `setLocale()` validates via `uiLocaleRules()` before `UiLocale::from()` — story 0067.
- Chrome redirect target is `url()->previous(route('dashboard'))` kept only when same-host, else the dashboard; the no-argument `previous()` fallback is dead code — story 0067.
- `lang/{en,es}/localization.php` holds `switcher.*`; `attributes`/`settings.*` stay reserved for story 0069. Settings navlist keys: `topbar.settings.{profile,security,appearance,language,language_subtitle}` — story 0067.
- Hook scheme: chrome `language-switcher` / `language-option-{value}`; settings tab `settings-language-switcher` / `settings-language-option-{value}`; mobile menu trigger `mobile-menu-button` — story 0067.
- The interface is **not** claimed fully translated (hardcoded chrome and `#[Title]` → `<title>` remain English) — story 0067.

## Story 0069 — Store Languages settings screen (UI)

- One class-based component `App\Livewire\StoreLanguages\Index` (flat view `resources/views/livewire/store-languages.blade.php`), two `flux:card` sections: content-language catalog (Layer 2) and dashboard defaults (Layer 1); route/permissions/actions are 0068's, unchanged — story 0069.
- Sidebar: `items.store_languages` is `group: null, cluster: 'store_settings'` (human's Q-3 answer), `permissions` exactly `['store-languages.view']`, icon `language`; the `settings` group and its `expanded_when` are untouched — story 0069.
- `App\Concerns\LocaleSettingValidationRules` (`defaultUiLocaleRules()`, `defaultNotificationLocaleRules()`, each `['required','string',Rule::enum(UiLocale::class)]`) was moved here from 0068 (its only caller); the locale actions take a typed `UiLocale`, so the component validates the raw string before `UiLocale::from()` — story 0069.
- Every action method method-injects `LogRefusedPrivilegedAttempt` (Phase 4 F-1); only `mount()` uses plain `Gate::authorize('viewAny')`, unlogged. `locale_setting` refusals pass `targetId: LocaleSetting::SINGLETON_ID` to match the actions' own log lines — story 0069.
- `#[Locked]` only on `$languages` and `$languageId`; `$languageId` and `$code` are real public properties so 0068's `languageId`/`code` ValidationException keys survive dehydrate; removal modal body renders only when `$languageId !== ''` and `canRemoveSelectedLanguage` (re-checks `delete`) — story 0069.
- Removing the default is two backend calls in one click: `SetDefaultStoreLanguage($replacement)` then `RemoveStoreLanguage($target)`; replacement compared with `$replacement->is($target)`, not string equality (`*_ci` collation) — story 0069.
- Lang: new `lang/{en,es}/store-languages.php`; `localization.php` gained `attributes.*` and `settings.*` (reserved by 0067); `topbar.store_languages.subtitle`; `navigation.items.store_languages` — story 0069.
- `InteractsWithUiLocale` is deliberately NOT composed on this screen (it is the personal switcher) — story 0069.

## Story 0071 — Product categories language tabs (UI)

- `App\Actions\ProductCategories\SetProductCategoryTranslation::__invoke(ProductCategory, StoreLanguage, string $name): ProductCategoryTranslation` is the copyable per-entity translation action (D-13): `LogRefusedPrivilegedAttempt::authorize('update', …)` first, trim, nested-data `Validator::make(['names' => [$id => $name]], ["names.{$id}" => rules], [], ['names.*' => label])`, 1062 race re-keyed through the translator, narrowed return. Only per-entity actions import `SetTranslation`; nothing under `app/Livewire/` may — story 0071.
- Shared `resources/views/components/language-tab-strip.blade.php` (props `languages`, `active`, `errorLanguageIds`): consumer must expose `setActiveLanguageTab(string $languageId)` and render its own panels, always mounted and hidden with `x-show` (never `@if`, D-2); id embedded with `Js::from()`, not `@js()` (D-8) — story 0071.
- Hooks: `language-tab-{id}`, `language-tab-error-{id}`, `language-panel-{id}`, `language-name-input-{id}`, `language-name-error-{id}`, `language-untranslated-{id}`; no assertion may match a language name or code (D-11) — story 0071.
- D-14 tab order: store default first, then remaining active languages by `name` (`orderByDesc('is_default')->orderBy('name')`) — story 0071.
- Q-4: `ProductCategoryValidationRules::uniqueNormalisedName()` changed by one line, the `$fail(...)` message, so a duplicate renders the localized attribute; pinned in both locales by `tests/Feature/ProductCategories/ProductCategoryTranslatedUniqueMessageTest.php` (Feature, not Unit: needs a real row) — story 0071.
- Q-5: one `save()` is one `DB::transaction()` opened after the component's gates and validation; a later language's refusal rolls back earlier writes, switches to the refused tab and keeps typed values — story 0071.
- B-1: on create, a non-blank non-default value needs a logged `update` check before validation and before the transaction; `#[Locked] $canAuthorTranslations` is only a UI hint — story 0071.
- L-1: `TranslateProductCategoryNameUniqueViolation::__invoke($e, $errorKey = 'name', $attributeLabel = 'name')` gained the optional third argument (backward compatible); the action also passes `['names.*' => __('products.categories.index.tabs.name_attribute')]` as validator attributes. Siblings (0073/0075/0077/0079) copy this widened translator — story 0071.
- Validator pitfall: a flat `["names.{id}" => $v]` data key is escaped by `parseData()` and never reaches a dotted rule key; use nested data (errors-log 2026-10-07) — story 0071.
- Browser tests live in the mirrored `tests/Browser/ProductCategories/` (D-9); the former flat `ProductCategoriesIndexTest.php` moved to `IndexTest.php` — story 0071.

## Story 0072 — Blog categories retrofit (backend)

- `blog_category_translations` mirrors 0070's shape but its per-language unique index is `UNIQUE(store_language_id, normalized_name)` (named `blog_category_translations_language_normalized_name_unique`), not raw `name`; `BlogCategoryTranslation::booted()` derives `normalized_name` on `saving` — story 0072 (D-1, D-3).
- `BlogCategoryValidationRules::blogCategoryRules()/nameRules()/uniqueNormalisedName()` gained a required `string $storeLanguageId` before the optional `?string $blogCategoryId`; the own-row exclusion is `where('blog_category_id', '!=', $id)`, never the translation PK — story 0072 (D-4).
- `TranslateBlogCategoryNameUniqueViolation::__invoke($e, $errorKey = 'name', $attributeLabel = 'name')` is ready for 0073's per-language action; it converts only a 1062 on the language/`normalized_name` index — story 0072 (R-11).
- `BackfillBlogCategoryTranslations` recomputes `normalized_name` (never copies); it refuses only when categories exist and no default store language does, because `store_languages` is empty at migration time on fresh installs/tests — story 0072 (D-5, D-15).
- Create/Rename write only the default-language translation (D-7); the non-default write path and the French Gherkin scenarios belong to 0073 — story 0072.
- BlogCategories/Index, BlogPosts/Index and BlogPosts/Editor were minimally migrated to `translated('name')` + `CompareTranslatedNames` (D-14); `FILTER_OPTIONS_LIMIT` now caps the rendered category list, not the query — story 0072.
- `down()` of the drop migration restores nullable columns, no unique index; rollback is data-lossy (D-11) — story 0072.

## Story 0073 — Blog categories language tabs (frontend)

- `BlogCategories\Index` swapped `public string $name` for `public array $names` keyed by store-language id (values plain strings, `''` = not typed, never `null`); adds `$activeLanguageId` and `setActiveLanguageTab()` and reuses `<x-language-tab-strip>` unmodified — story 0073 (D-2).
- `app/Actions/Blog/SetBlogCategoryTranslation` is the self-sufficient non-default-language writer (layer 2, direct-call tested) and the only blog-category caller of `SetTranslation`; `save()` gained it as a fourth method-injected parameter — story 0073 (D-8).
- Every language panel is always mounted and hidden with `x-show` + `x-cloak`, never an `@if` on `$activeLanguageId`; hooks are keyed by language id: `blog-category-name-input-{id}`, `language-tab-{id}`, `language-name-error-{id}`, `language-untranslated-{id}` — story 0073 (A-10, A-16, D-11).
- The edit field reads the raw translation row, never `translated('name')`, so a fallback is never silently saved as a translation — story 0073 (D-6).
- Known follow-up: the strip's `aria-label` is hardcoded (two consumers now), to be generalised by 0075 — story 0073 (Phase 5/6 notes).

## Story 0074 — Blog tags retrofit (backend)

- `blog_tag_translations` follows 0072's shape: `UNIQUE(store_language_id, normalized_name)` (named `blog_tag_translations_language_normalized_name_unique`) and `UNIQUE(blog_tag_id, store_language_id)`; exactly three indexes (the FK columns are leftmost in the composites) — story 0074 (D-1, D-3).
- `BackfillBlogTagTranslations` **copies** `name` and `normalized_name` byte-for-byte (unlike 0072); the column-drop migration aborts when a tag has no translation row; `down()` restores nullable columns, no unique index — story 0074 (D-10, D-11).
- `Create`/`FindOrCreateBlogTag` write parent + default-language translation in one `DB::transaction`, overriding 0059 D-10; `RenameBlogTag` does not — story 0074 (D-5).
- `BlogTagValidationRules::nameRules()/uniqueNormalisedName()` take a required `string $storeLanguageId` before the optional `?string $blogTagId`; own-row exclusion is `blog_tag_id !=`, never `->ignore()` — story 0074 (D-6).
- `FindOrCreateBlogTag` looks up the default language only, plus a bounded fallback that reuses a tag holding NO default-language name (after a default change), so a post save never mints a duplicate or detaches a tag; the editor/list show such a tag under any stored translation — story 0074 (D-8 amended).
- Non-default-language authoring and its French Gherkin scenarios belong to 0075; `TranslateBlogTagNameUniqueViolation` already accepts `$errorKey`/`$attributeLabel` for it — story 0074.

## Story 0075 — Blog tags language tabs (frontend)

- `app/Actions/Blog/SetBlogTagTranslation` is the self-sufficient non-default-language tag writer: logged `update` (target_type `blog_tag`) first, re-reads the tag and the language via `StoreLanguage::query()->active()`, **refuses an inactive language** (`names.{languageId}`, message `blog-tags.index.tabs.language_inactive`), own `Validator` with `nameRules()`, derived key, 1062 re-keyed — story 0075 (D-3).
- The 0071/0073 sibling actions still accept an inactive language and pin it in tests; aligning them is story 0087 (human decision) — story 0075.
- `BlogTags\Index`: `public array $names` keyed by language id; `#[Locked] $activeLanguageId` and `$originalTranslatedLanguageIds` (view-only hint, `save()` decides from re-read rows); `setActiveLanguageTab(string)`; edit modal only, create form keeps bare `blog-tag-name-input` with no tabs — story 0075 (D-7, D-8).
- `save()` authorizes the batch, writes changed languages only in one `DB::transaction()`; default language via `RenameBlogTag` with `name` re-keyed to `names.{defaultId}`; blank untranslated non-default tab skipped; blank default/previously translated refused; errored tab becomes active — story 0075 (D-9, D-12).
- Hooks: `blog-tag-name-input-{languageId}` plus the strip's `language-tab-*`/`language-name-error-*`/`language-untranslated-*`; list shows the store-default name or `—` — story 0075.
- `BlogTagValidationRules` now fails with `$fail('validation.x')->translate()` so attribute labels apply (same fix as categories) — story 0075.
- Refusal logging is `Log::warning` (outside the DB), so a rolled-back save keeps it; no refusal is logged inside the transaction in practice — story 0075 (D-9).
- Strip `aria-label` still uses the products key (not generalised); Q-4 badge not built; tab tests are in `BlogTagLanguageTabs*Test` — story 0075.

## Story 0076 — Products retrofit (backend)

- Split into 0076 (this) and 0076a (slug and SEO fields, `UNIQUE(store_language_id, slug)`, 1062 attribution by index name) after a Phase 2 rejection; large but kept atomic because the column drop, readers, order snapshots and factory must change together — story 0076.
- `product_translations`: UUID PK, `product_id` cascade, `store_language_id` restrict, `name` VARCHAR(255) NOT NULL, `description` MEDIUMTEXT NULL, three indexes (`product_translations_product_language_unique` named); first table with two translatable columns; migration pair `2026_11_06_100000/100001` with assert-before-DDL, a drop guard and a data-lossy `down()` — story 0076 (D-2, D-11, D-12).
- `ProductTranslation` `saving` hook sanitizes `description` when dirty (second layer after the actions); the backfill uses the query builder and never re-sanitizes — story 0076 (D-8, D-9, D-13).
- D-14: `Product::scopeOrderByTranslatedName(?string $storeLanguageId = null)` orders in SQL (paginated and limited consumers) with per-row fallback to the default language and exposes `translated_name`; callers qualify columns with `products.` — story 0076.
- D-21: `Product::snapshotName()` is the only source of `order_items.product_name` (default-language name from the loaded relation, else unfiltered re-query, else oldest non-blank, else `LogicException`); `CreateOrder` eager-loads `translations` — story 0076.
- D-22: `ProductFactory::configure()` registers `WritesDefaultProductTranslation` as an identifiable `afterCreating` step; `named()`/`withoutTranslations()` swap only it (`Factory::newInstance()` carries constructor arguments only, so a closure cannot read per-instance state, N-1) — story 0076.
- Lesson: a raw `DB::table('products')->insert()` that still sends `name` fails with 1054 (unknown column) before any FK or NOT NULL error, so a test asserting 1452/1364 must drop `name` first — story 0076.
- `GetLowStockProducts` resolves the name in the store default language (Q-4 (a)); UI-locale mapping is a follow-up story. `UpdateProduct` concurrent-save 1062 on the natural key is deferred to 0076a (R-22) — story 0076.

## Story 0078 - Blog posts retrofit (backend)

- `blog_post_translations`: UUID PK, `blog_post_id` cascade (never fires on soft delete, so trashed posts keep slugs reserved; child has no `SoftDeletes`), `store_language_id` restrict, `title` 255 NOT NULL, `slug` 255 NOT NULL, `body` MEDIUMTEXT NULL; exactly three indexes, `blog_post_translations_post_language_unique` and `blog_post_translations_language_slug_unique` (`BlogPostTranslation::LANGUAGE_SLUG_UNIQUE_INDEX`); migrations `2026_11_07_100000/100001`, drop `down()` re-adds nullable columns, so `migrate:rollback --step=2` loses title/slug/body (accepted F-4) - story 0078 (D-2, D-3, D-5, D-7).
- Slug is derived by the child's `saving` hook per language, when `title` OR `slug` is dirty, always from the title; the parent hook is deleted; `BlogPost` `#[Fillable]` is `['blog_category_id','status']` - story 0078 (D-4, D-9).
- Two-layer body sanitization: actions sanitize then validate and re-check the limit on the sanitized body UNTRIMMED; the child hook throws `LogicException` instead of letting the sanitizer truncate. `ProductTranslation`'s hook has the unfixed truncation shape (follow-up) - story 0078 (D-8, audit F-1).
- Lists use `BlogPost::scopeWithListTranslations()` (requested + default language + one fallback row, columns `blog_post_id, store_language_id, title`, no body/slug); `displayTitle()` falls back to any stored title; `translated('body')` on that partial relation is null - story 0078 (D-13, D-14).
- Factory: `WritesDefaultBlogPostTranslation` step, `titled($title, ?$body)` (NULL body by default) and `withoutTranslations()`; `config/store-languages.php` registers `blog_post_translations` - story 0078 (D-15).
- Actions keep their signatures (meaning the default language's) and write the translation inside the existing transaction; notification titles are a frozen `displayTitle()` snapshot - story 0078 (D-12, D-16, D-17).
- Accepted: F-3 concurrent first update of a post lacking a default row gives an unhandled 1062 on the post/language unique (500) - story 0078.
- Test lessons recorded in `docs/errors-log.md` (Pest helper-name clashes, derived expectations, `??=` defaults, failure injection via a model `saving` hook, serial chunks) - story 0078.


## Story 0076a — Product slug and SEO fields (backend)

- `product_translations` gained `slug` VARCHAR(160), `meta_title` VARCHAR(160), `meta_description` VARCHAR(500), all nullable, no backfill; `UNIQUE(store_language_id, slug)` named `product_translations_language_slug_unique`; still exactly three indexes because the composite supports the `store_language_id` FK and the migration drops `product_translations_store_language_id_foreign` when present; `down()` restores a plain index before dropping the unique (1553) — story 0076a (D-5, D-6, D-11b, D-34).
- `App\Actions\Products\CanonicalizeProductSlug` is the single slug canonicaliser (trim, `Str::slug`, blank becomes `NULL`), used by the actions before validating and by the `ProductTranslation` `saving` hook (which also trims meta fields) — story 0076a (D-6, D-30).
- `ProductValidationRules::productSlugRules(string $storeLanguageId, ?string $productId = null)` excludes the own row by `product_id !=`, never `->ignore()`; plus `productMetaTitleRules()` / `productMetaDescriptionRules()` — story 0076a.
- `CreateProduct`/`UpdateProduct` take trailing `?string $slug, $metaTitle, $metaDescription` (defaults `null` on create, required on update); `SetTranslation` writes them with name/description in the same transaction — story 0076a.
- `TranslateProductUniqueViolation` maps a 1062 by index name (`products_sku_unique` to `sku`, language-slug to `slug`, product-language natural key to `name` with `products.editor.concurrent_save_retry`); otherwise rethrows. Resolves 0076's deferred R-22 — story 0076a (D-31).
- `Products\Editor` has `#[Locked] ?string $slug/$metaTitle/$metaDescription` (default language) passed unchanged on edit, `null` on create, until 0077 adds inputs — story 0076a (D-33).
- New lang keys: `products.attributes.{slug,meta_title,meta_description}`, `products.editor.concurrent_save_retry` (en/es) — story 0076a.

## Story 0076b — Product translation write action (backend)

- `App\Actions\Products\SetProductTranslation::__invoke(Product, StoreLanguage, string $name, ?string $description = null, ?string $slug = null, ?string $metaTitle = null, ?string $metaDescription = null): ProductTranslation` — logged `update` authorize (`target_type` `product`) first, then re-reads the product; no new permission, route, policy or migration — story 0076b.
- It refuses an inactive or unknown language on `names.{id}` with `products.editor.tabs.language_inactive` (D-3); the 0087 structural guard must cover it, whichever of 0076b/0087 lands second — story 0076b.
- Sanitises the description and canonicalises the slug before validating nested data; meta fields are trimmed and nulled; name/meta stay stored unsanitised, so renderers must escape (carried to 0077 and the storefront) — story 0076b.
- `TranslateProductUniqueViolation::__invoke(QueryException, ?string $storeLanguageId = null)`: non-null re-keys slug/natural-key errors to `slugs.{id}`/`names.{id}`; null and the `sku` branch unchanged — story 0076b.
- New lang keys (en/es): `products.attributes.{name,description}`, `products.editor.tabs.language_inactive` — story 0076b.
- Accepted residuals: check-to-write race on language deactivation (L-1); product deleted between re-read and save gives an FK 1452 500 as in `SetProductCategoryTranslation` (L-2, fix belongs to the shared helper backlogged by 0087 D-5) — story 0076b.

## Story 0077 — Product editor language tabs (UI)

- `Products\Editor` holds five per-language arrays keyed by store-language id: `$names`, `$descriptions`, `$slugs`, `$metaTitles`, `$metaDescriptions`; the 0076a locked scalars are gone — story 0077.
- `#[Locked]` tab state: `$activeLanguageId`, `$defaultLanguageId`, `$originalTranslatedLanguageIds`, `$canAuthorTranslations` (UI hint only) — story 0077.
- `setActiveLanguageTab()` has no permission gate by design (`active()->findOrFail`); `prefillSlug()` is gated like `save()`; a public `hydrate()` fills `''` for missing keys and cannot be called as an action — story 0077.
- `save()`: narrow arrays to active languages, then D-8 (create-only actor cannot write non-default tabs; logged refusal before validation and the transaction), one `Validator` pass, one `DB::transaction` (default via `CreateProduct`/`UpdateProduct`, others via `SetProductTranslation`), D-10 re-keying plus first-errored-tab activation — story 0077.
- `typedString()` coerces non-string tampered values to `''` (appsec L-1); a locked panel's `WysiwygEditor` gets `:disabled` (I-1). Accepted: I-2 no-default-language invariant, sibling-wide — story 0077.
- One `WysiwygEditor` per language, bound through `descriptions.{id}`, so `Gallery` instances on the page are 3+N — story 0077.
- Lang keys (en/es): `products.editor.tabs.{untranslated,translation_requires_edit,slug_label,meta_title_label,meta_description_label}` — story 0077.
- The shared `<x-language-tab-strip>` now has four consumers, unmodified; the backlog item to generalise its hardcoded error-marker key (`products.categories.index.tabs.error_marker`) stays open — story 0077.
- Story-external breakage: `ProductListAndPickerOrderingTest` asserted the removed `name`/`description`; see `docs/errors-log.md` — story 0077.
