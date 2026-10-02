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
