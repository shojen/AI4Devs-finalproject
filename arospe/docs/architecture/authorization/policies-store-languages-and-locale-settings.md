# Authorization — Policies: StoreLanguage and LocaleSetting

> Part of [Authorization](../authorization.md). **Read this part when:** you touch `StoreLanguagePolicy`, `LocaleSettingPolicy`, or the widened meaning of `store-languages.edit`. The other parts are listed in the [hub](../authorization.md#table-of-contents).

### `StoreLanguagePolicy` — the first real call sites the seeded `store-languages.*` permissions ever got

[`App\Policies\StoreLanguagePolicy`](../../../app/Policies/StoreLanguagePolicy.php) (story 0068) gates the Store Languages catalog ([database/schema.md](../../database/schema-localization.md#store_languages)) on the already-seeded `store-languages.*` module permissions — those four permissions have existed in `RolePermissionSeeder::MODULES` since story 0002's catalog, unused by any policy or route until this story. No new permission, no `RolePermissionSeeder` change.

| Ability | Signature | Rule | Authorized from |
| --- | --- | --- | --- |
| `viewAny` | `(User $actor)` | holds `store-languages.view` | `routes/store-languages.php`'s own `can:store-languages.view` middleware |
| `create` | `(User $actor)` | holds `store-languages.create` | `App\Actions\StoreLanguages\AddStoreLanguage::__invoke()`, its own first statement |
| `update` | `(User $actor, StoreLanguage $target)` | holds `store-languages.edit` — `$target` is **not** consulted | `App\Actions\StoreLanguages\SetDefaultStoreLanguage::__invoke()` — the default swap |
| `delete` | `(User $actor, StoreLanguage $target)` | holds `store-languages.delete` — `$target` is **not** consulted | `App\Actions\StoreLanguages\RemoveStoreLanguage::__invoke()` — remove/deactivate |

**Four abilities, the `MediaPolicy` shape, not `SalesRegionPolicy`'s two-of-four.** Unlike the fixed, seeded Sales Regions catalog, `store_languages` genuinely is admin-creatable and admin-removable, so every seeded verb has a real call site: `create` is not dormant the way `sales-regions.create` is. This shape was a real design fork (the story's own task file's D14): a rejected alternative design would have pre-seeded the bundled fixture's ~184 candidate rows as inactive, under which `AddStoreLanguage` would never insert and gating it on `create` would have stretched that verb to cover an `UPDATE` — the coherent choice there would have been `store-languages.edit`, leaving `create` permanently dormant. The shipped design (validate-only against the fixture; a row exists only once an administrator picks it) is what keeps `create` an honest permission verb.

All three actions authorize as their own first statement, outside any validation and outside any transaction, so a refusal never runs a query or opens one — through a constructor-injected `App\Actions\Auth\LogRefusedPrivilegedAttempt::authorize(...)`, `targetType: 'store_language'` passed explicitly. `RemoveStoreLanguage` and `SetDefaultStoreLanguage` additionally enforce two **domain invariants** under a row lock, never through this policy — the current default cannot be removed, the last active language cannot be removed, and an inactive language cannot become the default — see [domain invariants](domain-invariants.md#a-domain-invariant-is-not-an-authorization-rule-and-does-not-live-here). Each binds a Super Admin actor identically: the invariant is about the data, not the actor, so the `Gate::before` bypass grants the *ability* but the invariant still refuses the *write*.

`VIEW_PERMISSION`/`CREATE_PERMISSION`/`EDIT_PERMISSION`/`DELETE_PERMISSION` are `public const` on the class, per [naming.md](../../conventions/naming/routes-and-permissions.md#permission-names)'s "name a permission once on the class that owns the rule" convention. `App\Policies\LocaleSettingPolicy` below **borrows** two of these four rather than restating them.

### `LocaleSettingPolicy` — two abilities, borrowing rather than restating `StoreLanguagePolicy`'s constants

[`App\Policies\LocaleSettingPolicy`](../../../app/Policies/LocaleSettingPolicy.php) (story 0068) gates the app's single default-dashboard/default-notification-language settings row ([database/schema.md](../../database/schema-localization.md#locale_settings)).

| Ability | Signature | Rule | Authorized from |
| --- | --- | --- | --- |
| `viewAny` | `(User $actor)` | holds `store-languages.view` | Still nothing: story 0069's `Index::mount()` authorizes `StoreLanguage`'s `viewAny` only, and its dashboard-defaults section shows the current values to anyone who can open the screen, disabling the selects unless this policy's `update` passes |
| `update` | `(User $actor, ?LocaleSetting $target = null)` | holds `store-languages.edit` | `App\Actions\Localization\SetDefaultUiLocale::__invoke()` and `SetDefaultNotificationLocale::__invoke()`, each its own first statement |

```php
// App\Policies\LocaleSettingPolicy
public const VIEW_PERMISSION = StoreLanguagePolicy::VIEW_PERMISSION;
public const EDIT_PERMISSION = StoreLanguagePolicy::EDIT_PERMISSION;
```

**A new permission is effectively forbidden here, and D25 (the story's own task file) is why this policy *references* `StoreLanguagePolicy`'s constants rather than declaring its own literal strings.** `RolePermissionSeeder::MODULES` is a fixed 10×4 grid this story does not change, and the two settings are managed on the same screen as the Store Languages catalog (story 0069) — the same "one tier spans logically-distinct operations on a shared screen" precedent [`SalesRegionPolicy::update()`](policies-sales-media-categories.md#salesregionpolicy--the-third-policy-and-the-first-with-no-target-branch) already establishes for the rate edit, the enable/disable toggle and the default swap. The constant-aliasing shape (rather than a second copy of the literal `'store-languages.edit'`) is what stops the two policies drifting apart on a future permission rename — a pattern this repo had not needed before this story.

⚠️ **This widens what `store-languages.edit` means, and it is worth stating explicitly rather than leaving it implicit (R-15).** Before this story, a role granted `store-languages.edit` could only change the store's default *content-authoring* language. Since this story, the identical grant also lets that role change the app's default *dashboard* and *default notification-email* languages — two settings that affect every signed-out visitor and every administrator who has never chosen their own preference (see [database/schema-localization.md](../../database/schema-localization.md#locale_settings) for the three-tier resolution chain those settings feed). `update()`'s `$target` parameter is nullable and unused: both actions authorize against the class itself, since there is only ever one settings row and it may not exist yet the first time either action runs.

`LocaleSettingPolicy::update()`'s Super Admin behaviour follows the ordinary `Gate::before` bypass shape every policy on this page shares — see [The Super Admin bypass](super-admin.md#the-super-admin-bypass).
