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
