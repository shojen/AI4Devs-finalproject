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
