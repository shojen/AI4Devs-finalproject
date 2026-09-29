# Store Languages Routes

Part of [Routes](routes.md) — see [routes.md](routes.md#why-this-file-exists) for the full app-owned route table and the shared module-gate pattern. This file covers the Store Languages catalog's permission-gated route.

## Table of Contents

- [`store-languages.index` — another permission-gated route, shipped backend-only](#store-languagesindex--another-permission-gated-route-shipped-backend-only)

### `store-languages.index` — another permission-gated route, shipped backend-only

Story 0068 (backend: the `store_languages`/`locale_settings` tables, the two policies, the three catalog actions, this route). Declared in its own [`routes/store-languages.php`](../../routes/store-languages.php), the same shape every prior area file uses:

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

**This route resolves to a deliberate minimal placeholder, matching what story 0017 shipped before 0018 replaced it.** [`App\Livewire\StoreLanguages\Index`](../../app/Livewire/StoreLanguages/Index.php) exists so `Livewire::test()` and an HTTP visit have something to render against; the real card/list UI — adding a language from the bundled ISO 639-1 picker, changing the default, removing a language, and the two default-locale settings on the same screen — is story **0069**'s. Until then:

- **The `store-languages.*` permissions are not new** — they have existed in `RolePermissionSeeder::MODULES` since story 0002's catalog, unused by any route or policy until this story. This is the first story to give them a real gated surface and real `Gate` abilities behind them (`StoreLanguagePolicy`, `LocaleSettingPolicy` — see [architecture/authorization.md](../architecture/authorization/policies-store-languages-and-locale-settings.md)).
- **`/settings/store-languages` carries no `config/modules.php` sidebar entry yet** — the identical temporary half-state `roles.index` sat in between stories 0010 and 0013, and `sales-regions.index` between 0017 and 0018. Acceptable only because 0069 immediately follows; that story must add the `items.store_languages` entry (`permissions` exactly `['store-languages.view']`) and both `navigation.php` leaves.
- **Three domain actions exist and are fully tested behind this route with no UI caller yet**: `App\Actions\StoreLanguages\AddStoreLanguage`, `RemoveStoreLanguage`, `SetDefaultStoreLanguage` — each self-authorizes against `StoreLanguagePolicy` as its own first statement, so a queued job or Artisan caller inherits the same rule story 0069's component will use as a second layer. See [../database/schema-localization.md](../database/schema-localization.md#store_languages) for what each writes.
- **The two default-locale settings actions (`App\Actions\Localization\SetDefaultUiLocale`/`SetDefaultNotificationLocale`) have no route of their own at all** — they are reached only through story 0069's future form on this same screen, gated by `LocaleSettingPolicy`, which **borrows** `store-languages.view`/`.edit` rather than adding a new permission (D25) — see [architecture/authorization.md](../architecture/authorization/policies-store-languages-and-locale-settings.md) for the stated cost this borrowing has on what `store-languages.edit` now means.

HTTP behaviour pinned by this story's own tests: guest → redirect to login; an actor without `store-languages.view` → 403; a holder → 200; a Super Admin holding zero permission rows → 200 (the `Gate::before` bypass).

`tests/Feature/Authorization/ModuleRouteAccessTest.php` is **not** extended to cover this route (a pre-existing gap this story does not close — see [routes.md](routes.md#app-owned-routes)'s own note on that file, and the story's own backlog item 7); this route's four-case HTTP block lives in its own dedicated test file instead, the same gap `sales-regions.index` already left.
