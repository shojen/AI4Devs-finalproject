# Store Languages Routes

Part of [Routes](routes.md) — see [routes.md](routes.md#why-this-file-exists) for the full app-owned route table and the shared module-gate pattern. This file covers the Store Languages catalog's permission-gated route.

## Table of Contents

- [`store-languages.index` — the Store Languages settings screen](#store-languagesindex--the-store-languages-settings-screen)

### `store-languages.index` — the Store Languages settings screen

Story 0068 shipped the backend (the `store_languages`/`locale_settings` tables, the two policies, the three catalog actions, this route behind a placeholder view); story 0069 replaced the placeholder with the real screen. Declared in its own [`routes/store-languages.php`](../../routes/store-languages.php), the same shape every prior area file uses:

```php
// routes/store-languages.php
use App\Livewire\StoreLanguages\Index as StoreLanguagesIndex;   // aliased: `Index` is ambiguous across areas,
// exactly like every other area file's own import
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:store-languages.view`, not Spatie's `permission:` -- same reason as every other
    // gated route in this app. See docs/architecture/authorization.md.
    Route::livewire('settings/store-languages', StoreLanguagesIndex::class)
        ->middleware(['can:store-languages.view'])
        ->name('store-languages.index');
});
```

**One Livewire component, two visually distinct sections (story 0069).** [`App\Livewire\StoreLanguages\Index`](../../app/Livewire/StoreLanguages/Index.php) (class-based, flat view `resources/views/livewire/store-languages.blade.php`, `lang/{en,es}/store-languages.php`) renders two `flux:card` sections that belong to **different i18n layers** (see [conventions/localization.md](../conventions/localization.md#the-two-layers-and-why-a-change-must-never-cross-between-them)):

- **Content languages (Layer 2).** Active `store_languages` rows, default first. Add from the bundled ISO 639-1 list (a picker of act-now buttons filtered client-side by Alpine; never a free-typed code; codes held by an *active* row are not offered, a removed language is), mark one as default, remove one. Removal of the current default is one click for the user but two backend calls: the chosen replacement is promoted with `SetDefaultStoreLanguage`, then the old default removed with `RemoveStoreLanguage`. The removal modal has three states (replacement select for a default, plain confirm, and "add another language first" with Confirm disabled for the last active language) and shows a usage line from `StoreLanguage::translationUsageCount()` only when it is greater than zero.
- **Dashboard defaults (Layer 1).** Two independent selects, each saved by its own method (`saveDefaultUiLocale()`, `saveDefaultNotificationLocale()`), constrained to the two `App\Enums\UiLocale` cases. The copy states the scope is system-wide, not the personal language switcher (`InteractsWithUiLocale` is deliberately **not** composed here).

```php
// app/Livewire/StoreLanguages/Index.php
public function saveDefaultUiLocale(SetDefaultUiLocale $setDefaultUiLocale, LogRefusedPrivilegedAttempt $log): void
{
    $log->authorize('update', LocaleSetting::class, targetType: 'locale_setting', targetId: LocaleSetting::SINGLETON_ID);

    $this->validate(
        ['defaultUiLocale' => $this->defaultUiLocaleRules()],
        attributes: ['defaultUiLocale' => __('localization.attributes.defaultUiLocale')],
    );

    $setDefaultUiLocale(UiLocale::from($this->defaultUiLocale));
    // ...
}
```

The locale actions take an already-typed `UiLocale` and validate nothing, so the component is the only layer holding the raw client-writable string: [`App\Concerns\LocaleSettingValidationRules`](../../app/Concerns/LocaleSettingValidationRules.php) (`defaultUiLocaleRules()`, `defaultNotificationLocaleRules()`, each `['required', 'string', Rule::enum(UiLocale::class)]`) runs **before** `UiLocale::from()`, so a forged value is a validation error and never a `ValueError`.

**Authorization and logging.** `mount()` calls `Gate::authorize('viewAny', StoreLanguage::class)` (unlogged, the documented exception: the route's `can:` already refuses a real HTTP actor). Every action method, including the two modal-opening disclosure paths (`openAddLanguageModal()`, `confirmRemoveLanguage()`), method-injects `LogRefusedPrivilegedAttempt` and authorizes itself with the right `targetType` (`store_language` or `locale_setting`; the latter passes `LocaleSetting::SINGLETON_ID` so the screen's refusal lines match the actions' own). Row targets are resolved with `findOrFail()` before the gate. Per-row and per-section controls render disabled with a tooltip (never hidden) from `allowsSafely()` hints that mirror the policy. `#[Locked]` covers exactly `$languages` and `$languageId`; the removal modal body (and its usage count) renders only when `$languageId !== ''` and `canRemoveSelectedLanguage` re-passes `delete` on each render. `$languageId` and `$code` are declared public properties solely so the `ValidationException` keys thrown by 0068's actions survive Livewire's dehydrate.

**Sidebar.** `config/modules.php` has `items.store_languages` in the **`store_settings` cluster** (`group => null`, `cluster => 'store_settings'`, `permissions` exactly `['store-languages.view']`, `current_when => 'store-languages.*'`), not in the `settings` group; no `expanded_when` change was needed. See [architecture/authorization.md](../architecture/authorization/how-to-gate.md). The topbar subtitle key is `topbar.store_languages.subtitle`.

The `store-languages.*` permissions have existed since story 0002's catalog; 0068 gave them their first policies and 0069 their first UI. `LocaleSettingPolicy` still **borrows** `store-languages.view`/`.edit` (D25), see [architecture/authorization.md](../architecture/authorization/policies-store-languages-and-locale-settings.md).

HTTP behaviour pinned by this story's own tests: guest → redirect to login; an actor without `store-languages.view` → 403; a holder → 200; a Super Admin holding zero permission rows → 200 (the `Gate::before` bypass).

`tests/Feature/Authorization/ModuleRouteAccessTest.php` is **not** extended to cover this route (a pre-existing gap this story does not close — see [routes.md](routes.md#app-owned-routes)'s own note on that file, and the story's own backlog item 7); this route's four-case HTTP block lives in its own dedicated test file instead, the same gap `sales-regions.index` already left.

_Last updated: 2026-10-03 — story 0069 (Store Languages settings screen): placeholder replaced by the real two-section component, sidebar entry in the `store_settings` cluster, `LocaleSettingValidationRules`._
