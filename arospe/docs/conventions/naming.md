# Naming Conventions

Real naming patterns observed across the codebase. For migration file naming specifically, see [database/migrations.md](../database/migrations.md#file-naming) — not repeated here.

## Table of Contents

- [Classes](#classes)
- [Livewire components and views](#livewire-components-and-views)
- [Traits and their methods](#traits-and-their-methods) — split out to [naming-validation-traits.md](naming-validation-traits.md)
- [Route names](#route-names)
- [Permission names](#permission-names)
- [Translation keys](#translation-keys)
- [Boolean properties](#boolean-properties)

## Classes

One class per file, `StudlyCase`, filename matches class name exactly (PSR-4). Verified across `app/Actions/Fortify/`, `app/Livewire/`, `app/Concerns/`:

| Class | File |
| --- | --- |
| `App\Actions\Fortify\CreateNewUser` | `app/Actions/Fortify/CreateNewUser.php` |
| `App\Livewire\Settings\TwoFactor\RecoveryCodes` | `app/Livewire/Settings/TwoFactor/RecoveryCodes.php` |
| `App\Livewire\Actions\Logout` | `app/Livewire/Actions/Logout.php` |

Single-purpose invokable actions are named as an imperative verb phrase, not suffixed with `Action` or `Service`: `Logout`, `CreateNewUser`, `ResetUserPassword`, `RequestEmailChange`, `ConfirmEmailChange` (Fortify's own convention, followed consistently by this app's own actions in `app/Actions/Users/`).

Controllers, by contrast, **are** suffixed `Controller`, and are named after the action they front: `ConfirmEmailChange` (the action) → `ConfirmEmailChangeController` (the invokable controller in front of it). Same for listeners and notifications, which are named as a statement about what happened rather than a command: `ActivateVerifiedUser`, `PendingEmailVerification`.

| Class | File |
| --- | --- |
| `App\Actions\Users\ConfirmEmailChange` | `app/Actions/Users/ConfirmEmailChange.php` |
| `App\Http\Controllers\ConfirmEmailChangeController` | `app/Http/Controllers/ConfirmEmailChangeController.php` |
| `App\Listeners\ActivateVerifiedUser` | `app/Listeners/ActivateVerifiedUser.php` |
| `App\Notifications\PendingEmailVerification` | `app/Notifications/PendingEmailVerification.php` |
| `App\Enums\UserStatus` | `app/Enums/UserStatus.php` |
| `App\Actions\Roles\EnforceAdministratorPermissionGrant` | `app/Actions/Roles/EnforceAdministratorPermissionGrant.php` |
| `App\Actions\Roles\EnforceGrantorPermissionScope` | `app/Actions/Roles/EnforceGrantorPermissionScope.php` |
| `App\Policies\UserPolicy` | `app/Policies/UserPolicy.php` |
| `App\Exceptions\RoleInUseException` | `app/Exceptions/RoleInUseException.php` |
| `App\Actions\Auth\EnsureRecentPasswordConfirmation` | `app/Actions/Auth/EnsureRecentPasswordConfirmation.php` |
| `App\Exceptions\PasswordConfirmationRequiredException` | `app/Exceptions/PasswordConfirmationRequiredException.php` |
| `App\Actions\SalesRegions\SetDefaultSalesRegion` | `app/Actions/SalesRegions/SetDefaultSalesRegion.php` |
| `App\Policies\SalesRegionPolicy` | `app/Policies/SalesRegionPolicy.php` |
| `App\Concerns\SalesRegionValidationRules` | `app/Concerns/SalesRegionValidationRules.php` |
| `App\Actions\Media\GenerateImageConversions` | `app/Actions/Media/GenerateImageConversions.php` |
| `App\Policies\MediaPolicy` | `app/Policies/MediaPolicy.php` |
| `App\Concerns\MediaValidationRules` | `app/Concerns/MediaValidationRules.php` |
| `App\Actions\NormalizeForSearch` | `app/Actions/NormalizeForSearch.php` |
| `App\Exceptions\UnresolvedSelectionException` | `app/Exceptions/UnresolvedSelectionException.php` |
| `App\Livewire\Components\MultiSelectOptionsResolver` | `app/Livewire/Components/MultiSelectOptionsResolver.php` |
| `App\Livewire\Components\SearchableMultiSelect` | `app/Livewire/Components/SearchableMultiSelect.php` |
| `App\Policies\ProductPolicy` | `app/Policies/ProductPolicy.php` |
| `App\Actions\Products\SyncProductSalesRegions` | `app/Actions/Products/SyncProductSalesRegions.php` |
| `App\Actions\Products\ResolveProductTaxRate` | `app/Actions/Products/ResolveProductTaxRate.php` |
| `App\Actions\Products\ResolvedTaxRate` | `app/Actions/Products/ResolvedTaxRate.php` |
| `App\Actions\Products\SearchSalesRegions` | `app/Actions/Products/SearchSalesRegions.php` |
| `App\Enums\TaxRateResolutionTier` | `app/Enums/TaxRateResolutionTier.php` |
| `App\Exceptions\NoDefaultSalesRegionException` | `app/Exceptions/NoDefaultSalesRegionException.php` |
| `App\Policies\ProductAttributeTypePolicy` | `app/Policies/ProductAttributeTypePolicy.php` |
| `App\Actions\Products\SyncProductAttributeValues` | `app/Actions/Products/SyncProductAttributeValues.php` |
| `App\Concerns\ProductAttributeValidationRules` | `app/Concerns/ProductAttributeValidationRules.php` |
| `App\Models\ProductVariant` | `app/Models/ProductVariant.php` |
| `App\Actions\Products\CreateProductVariant` | `app/Actions/Products/CreateProductVariant.php` |
| `App\Actions\Products\UpdateProductVariant` | `app/Actions/Products/UpdateProductVariant.php` |
| `App\Actions\Products\DeleteProductVariant` | `app/Actions/Products/DeleteProductVariant.php` |
| `App\Actions\Products\HashVariantCombination` | `app/Actions/Products/HashVariantCombination.php` |
| `App\Actions\Products\DeriveVariantSku` | `app/Actions/Products/DeriveVariantSku.php` |
| `App\Actions\Products\TranslateProductVariantUniqueViolation` | `app/Actions/Products/TranslateProductVariantUniqueViolation.php` |
| `App\Concerns\ProductVariantValidationRules` | `app/Concerns/ProductVariantValidationRules.php` |
| `App\Actions\Products\GenerateProductVariantCombinations` | `app/Actions/Products/GenerateProductVariantCombinations.php` |

Policies are named `<Model>Policy` — and here the name is not merely a convention but a binding: Laravel 13 auto-discovers `App\Policies\UserPolicy` for `App\Models\User` by that exact name, so renaming it silently unbinds every `Gate::authorize()` call against a `User` (see [base-standards.md](directory-structure.md#directory-structure)). Policy **methods** are named after the ability, as a bare verb phrase in camelCase and without a `can` prefix: `viewAny`, `update`, `promoteToAdministrator`, `updateSensitiveAttributes` — matching how they read at the call site, `Gate::authorize('promoteToAdministrator', $target)`.

Enum cases use TitleCase keys with lowercase backing values — `case Active = 'active';` in `App\Enums\UserStatus`, matching the project `CLAUDE.md` rule. `App\Enums\RoleName` is the exception the rule tolerates: its backing values are the seeded role names *exactly as persisted* (`case Administrator = 'Administrator';`), because the value is compared byte-for-byte against a database row — see [architecture/authorization.md](../architecture/authorization.md#the-administrator-tiers-identity).

**Shared identity predicates on a model are named `is<Thing>(self $x): bool` and take the row.** `App\Models\Role` carries three: the `public static` `isAdministratorRole()` and `isSuperAdminRoleRow()`, both of which a *different* class calls with a `Role` in hand, and the `private` `isSuperAdminRole()`, which asks the same question about `$this` from inside the model's own guards. The `Row` suffix on the middle one exists only to disambiguate it from that private sibling; it is a wart, accepted deliberately over renaming a method 0008's guards already depend on. When adding the next one, prefer a name that needs no suffix — and never let a private instance helper and a public static one differ by suffix alone if you can avoid it.

## Livewire components and views

Component class is `StudlyCase`; its Blade view is the **kebab-case** version of the class name, in a mirrored directory structure under `resources/views/livewire/`:

| Component | View |
| --- | --- |
| `App\Livewire\Settings\Security` | `resources/views/livewire/settings/security.blade.php` |
| `App\Livewire\Settings\Profile` | `resources/views/livewire/settings/profile.blade.php` |
| `App\Livewire\Settings\DeleteUserForm` | `resources/views/livewire/settings/delete-user-form.blade.php` |

✅ Good — `DeleteUserForm` → `delete-user-form.blade.php` (each word boundary becomes a hyphen).
❌ Bad — do not use `deleteuserform.blade.php` or `DeleteUserForm.blade.php`; Livewire's convention-based view resolution expects the kebab-case mirror.

### Exception: a component named `Index` resolves to its **parent folder's** name

The mirror rule above has one exception, and it is Livewire's, not this project's. A component class named `Index` inside a subfolder drops the `.index` segment entirely and resolves to the **subfolder name**:

| Component | View — actual | View — what the mirror rule would predict |
| --- | --- | --- |
| `App\Livewire\Users\Index` | `resources/views/livewire/users.blade.php` | ~~`resources/views/livewire/users/index.blade.php`~~ |
| `App\Livewire\Roles\Index` | `resources/views/livewire/roles.blade.php` | ~~`resources/views/livewire/roles/index.blade.php`~~ |
| `App\Livewire\SalesRegions\Index` | `resources/views/livewire/sales-regions.blade.php` | ~~`resources/views/livewire/sales-regions/index.blade.php`~~ |
| `App\Livewire\Products\Index` | `resources/views/livewire/products.blade.php` | ~~`resources/views/livewire/products/index.blade.php`~~ |
| `App\Livewire\Products\AttributeTypes\Index` | `resources/views/livewire/products/attribute-types.blade.php` | ~~`resources/views/livewire/products/attribute-types/index.blade.php`~~ |
| `App\Livewire\Customers\Index` | `resources/views/livewire/customers.blade.php` | ~~`resources/views/livewire/customers/index.blade.php`~~ |

This is explicit in the installed vendor source:

```php
// vendor/livewire/livewire/src/Finder/Finder.php — Finder::generateNameFromClass()
// If using an index component in a sub folder, remove the '.index' so the name is the subfolder name...
if ($fullName->endsWith('.index')) {
    $fullName = $fullName->replaceLast('.index', '');
}
```

So `App\Livewire\Users\Index` becomes the component name `users`, and `users` resolves to `livewire/users`. The nested path is still *offered* as a fallback (`Finder` also probes `<folder>/index.blade.php` and `<folder>/<folder>.blade.php`), but the flat file is what this repo uses and what a reader should expect to find.

✅ Good — the real pairing in this repo: `app/Livewire/Users/Index.php` ↔ `resources/views/livewire/users.blade.php`.
❌ Bad — assuming the mirror rule holds and looking for (or creating) `resources/views/livewire/users/index.blade.php`. It is not the path Livewire reports as the component's view, and a second file there is a silently unused duplicate.

The third row (task 0017) adds the one thing the first two could not show: **the subfolder name is kebab-cased on the way down**, so a multi-word area segment splits — `App\Livewire\SalesRegions\Index` resolves to `livewire/sales-regions.blade.php`, not `livewire/salesregions.blade.php`. That is the ordinary mirror rule applied to the *folder* name after `.index` is stripped, but `Users` and `Roles` are single words and demonstrated none of it.

**This has already cost real time once — twice now — so it is worth stating as a habit rather than a rule to recall.** Task 0010's own Phase 1 spec — and its sibling 0011's — both wrote the nested path for `App\Livewire\Roles\Index`, and the error surfaced only when the story's test suite ran and threw `Illuminate\View\ViewException: File does not exist at path .../resources/views/livewire/roles.blade.php`. Livewire never even probes the nested path first, so nothing hints at the mistake until something renders. When adding an `Index` component, resolve the view path **by running the component**, not by reasoning about it.

Task 0017 hit the *other* half of the same trap, and it is worth knowing because it costs nothing to walk into: its task file quoted the rule correctly and its component was written to the flat path, but an `artisan make:` scaffold still deposited an unused `resources/views/livewire/sales-regions/index.blade.php` stub on disk. Nothing failed — the flat view resolved, the tests passed, and the stub simply sat there as a silently-unused duplicate until it was noticed and removed (verified: only `resources/views/livewire/sales-regions.blade.php` exists today). **So the check is not only "did I write the right path" but "is there a second file at the wrong one".**

Practical consequence when adding the next module screen: an `Index` component for a new area lands at `resources/views/livewire/<area>.blade.php`, one level *shallower* than its class. Any other component in that same subfolder follows the normal mirror rule, so the two live at different depths — that asymmetry is expected, not a mistake. **Story 0027 is the first real instance of this, replacing the hypothetical `App\Livewire\Users\Editor` this paragraph used to cite** (`Users` has no `Editor` component — its create/edit form is a modal on `Index` itself, not a second class): `App\Livewire\Products\Index` → `resources/views/livewire/products.blade.php` (the `Index`-in-a-subfolder exception, flat, the table's **fourth** row) sits one level shallower than its sibling `App\Livewire\Products\Editor` → `resources/views/livewire/products/editor.blade.php` (the ordinary mirror rule, nested) — same folder, two different view depths, exactly as predicted.

The **fifth** row (story 0028) is the first **two-level-deep** subfolder before an `Index` class, and it confirms the mechanism generalises rather than needing a special case: `Finder::generateNameFromClass()` strips only the trailing `.index` segment, so `App\Livewire\Products\AttributeTypes\Index` becomes the component name `products.attribute-types` (both remaining segments kebab-cased independently — `AttributeTypes` → `attribute-types`, not `attributetypes`), which resolves to the flat `livewire/products/attribute-types.blade.php` — one level shallower than the class's own three-segment namespace, never `livewire/products/attribute-types/index.blade.php`. Verified by running the component (per the habit two paragraphs above), not by reasoning about it from the vendor source alone.

**Story 0019 is the first real instance of that "any other component" case, and it is worth naming because the exception above is memorable enough to be over-applied.** `App\Livewire\Media\Gallery` resolves to `resources/views/livewire/media/gallery.blade.php` — the **normal** mirror rule, nested, because the class is not named `Index`. The exception keys on the class name, never on the component living in a subfolder. Note the story's own task file had to state this explicitly to stop the mistake being made in the other direction, which is the tell that the exception has become the thing people remember.

Story 0021 is the second confirmation: `App\Livewire\Components\WysiwygEditor` → `resources/views/livewire/components/wysiwyg-editor.blade.php`, the ordinary mirror rule again, for the identical reason — the class is not named `Index`, and living inside a subfolder that is itself not a module area (`Components/`, per [base-standards.md](directory-structure.md#directory-structure)) changes nothing about which rule applies.

Story 0022 is the third: `App\Livewire\Components\SearchableMultiSelect` → `resources/views/livewire/components/searchable-multi-select.blade.php`. The sibling `MultiSelectOptionsResolver` in the same folder is a plain interface with no view of its own, and is not subject to this rule at all — the mirror rule (and its exception) governs a Livewire `Component` subclass, not every file that happens to live under `app/Livewire/`.

Story 0031 is the fourth: `App\Livewire\Products\VariantBuilder` → `resources/views/livewire/products/variant-builder.blade.php`, the ordinary mirror rule once more, for the same reason as the three before it — the class is not named `Index`. What is new about this instance is not the naming rule but what the component *is*: this app's first **nested child** component embedded inside another module's own routed page (`<livewire:products.variant-builder :product-id="$productId" .../>` inside `resources/views/livewire/products/editor.blade.php`) rather than mounted at a route or a modal of its own — and the mirror rule does not care. `App\Livewire\Products\Editor` (story 0027, already the ordinary-rule instance the "practical consequence" paragraph above cites) and `VariantBuilder` now sit in the same `Products/` folder at the same nesting depth, both following the plain `<class-path>` ↔ `<kebab-case-path>.blade.php` pairing with no `Index`-exception in sight.

Note: `resources/views/livewire/auth/*.blade.php` (login, register, forgot-password, etc.) are **plain Blade views**, not Livewire components — they live under `livewire/` for directory consistency but are bound directly as Fortify's auth views, e.g. `Fortify::loginView(fn () => view('livewire.auth.login'))` in [`app/Providers/FortifyServiceProvider.php`](../../app/Providers/FortifyServiceProvider.php). Don't assume every file under `resources/views/livewire/` has a matching PHP component class — check for one before citing it.

## Traits and their methods

Split out into its own file, per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule — this was the largest section in this file. See **[Validation Trait Naming Conventions](naming-validation-traits.md)** for the full `<Noun>ValidationRules` trait-and-method naming convention: the base pattern, the entity-prefix collision exception, and the file-by-file trait roster.

## Route names

Dot notation, `<resource>.<action>`, verified in `routes/settings.php`:

```php
// routes/settings.php
Route::livewire('settings/profile', Profile::class)->name('profile.edit');
Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');
Route::livewire('settings/security', Security::class)->name('security.edit');
```

Full real route names, including the ones Fortify registers, are listed in [api/routes.md](../api/routes.md).

## Permission names

Same dot notation as route names, one level lower: `<module-slug>.<action>`, where the module slug is **kebab-case** and the action is a bare verb. Verified in `database/seeders/RolePermissionSeeder.php`, which owns the canonical catalog:

```php
// database/seeders/RolePermissionSeeder.php
public const MODULES = [
    'users', 'products', 'sales-regions', 'shipping', 'payment-methods',
    'customers', 'orders', 'blog', 'store-languages', 'media',
];

public const ACTIONS = ['view', 'create', 'edit', 'delete'];

/**
 * Non-CRUD permissions that sit outside the module x action grid.
 *
 * @var array<int, string>
 */
public const ROLE_PERMISSIONS = ['roles.manage', 'roles.manage-administrators'];
```

✅ Good — `sales-regions.delete`, `payment-methods.view`, `roles.manage-administrators`: kebab-case slug, dot separator, verb (or verb phrase, itself kebab-cased) after the dot.
❌ Bad — do not write `salesRegions.delete`, `sales_regions.delete`, `delete-sales-regions`, or a prose form like `'manage administrator-level roles/users'`. A permission name that isn't in the seeded catalog makes `can()` / `hasPermissionTo()` throw `PermissionDoesNotExist` at runtime, so this is a correctness rule, not just a style preference.

A permission that a new feature needs is added to the constants above — never as a string only one component knows about. The catalog, the two seeded roles, and which of them hold what are documented in [architecture/authorization.md](../architecture/authorization.md#permission-catalog).

`'media'` (story 0019) is the **only** slug appended since task 0002 wrote this constant, and the one-line diff above is genuinely the whole production change — but what that one line costs elsewhere, and the one thing it silently breaks, are in [The `media` module, and what a catalog amendment costs](../architecture/authorization.md#the-media-module-and-what-a-catalog-amendment-costs). Read it before adding the eleventh.

**Where a permission name is written in PHP, name it once on the class that owns the rule.** Task 0009 established this for the two role-management names, as `public const` on the policy that decides with them — read by the policy itself, by `App\Actions\Roles\EnforceAdministratorPermissionGrant`, and by both classes' tests:

```php
// app/Policies/RolePolicy.php
public const ADMINISTRATOR_LEVEL_PERMISSION = 'roles.manage-administrators';
public const ROLE_MANAGEMENT_PERMISSION = 'roles.manage';
```

[`App\Policies\SalesRegionPolicy`](../../app/Policies/SalesRegionPolicy.php) (task 0017), [`App\Policies\MediaPolicy`](../../app/Policies/MediaPolicy.php) (story 0019), `App\Policies\ProductCategoryPolicy` (story 0023), [`App\Policies\ProductPolicy`](../../app/Policies/ProductPolicy.php) (story 0024) and [`App\Policies\ProductAttributeTypePolicy`](../../app/Policies/ProductAttributeTypePolicy.php) (story 0028) all follow the same shape — one `public const <VERB>_PERMISSION` per ability the policy defines. `MediaPolicy` is the first to carry all four (`VIEW`/`CREATE`/`EDIT`/`DELETE`); the others carry only the ones they need — `SalesRegionPolicy` has one ability per verb, so `VIEW_PERMISSION`/`EDIT_PERMISSION` alone are unambiguous read as `SalesRegionPolicy::EDIT_PERMISSION`. `ProductPolicy` and `ProductCategoryPolicy` gate on the identical `products.*` strings (a category is a product sub-resource sharing the module), so their same-named constants on two different classes deliberately equal the same string — two policies agreeing on one catalog permission, not duplication to collapse. `App\Policies\UserPolicy` is the sole outlier among these seven policies, still re-typing its literals at four call sites (task 0009's Phase 4 finding **F5**, deliberately deferred) — a cleanup candidate, not the pattern to copy. The rule is that the constant names the **rule**, at whatever precision the class requires, not that every policy shares the same constant names.

✅ Good — `$user->hasPermissionTo(self::ADMINISTRATOR_LEVEL_PERMISSION)`, and `RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION` from a collaborator.
❌ Bad — re-typing `'roles.manage-administrators'` at each call site. This is exactly what `App\Policies\UserPolicy` still does at four of its own call sites (task 0009's Phase 4 finding **F5**, pre-existing and deliberately deferred); it is a known cleanup candidate, not the pattern to copy. The constant name describes the *rule* (`ADMINISTRATOR_LEVEL_PERMISSION`), not the string, so a future catalog rename touches one line.

## Translation keys

`lang/<locale>/<domain>.php` — one file per domain area, keys grouped by feature, every segment `snake_case`. Verified in [`lang/en/users.php`](../../lang/en/users.php) and its Spanish counterpart, which must stay key-for-key identical:

```php
// lang/en/users.php
'statuses' => [
    'active' => 'Active',
    // ...
],

'email_change' => [
    'notification_subject' => 'Confirm your new email address',
    'pending_notice' => 'A change to :email is pending. Use the link sent to that address to confirm it.',
    'confirmed' => 'Your email address has been updated.',
    'refused' => 'This email verification link is no longer valid.',
    'throttled' => 'Too many email change requests. Please try again later.',
],
```

✅ Good — `users.statuses.active`, `users.email_change.throttled`: domain file, feature group, snake_case leaf. Values that interpolate use Laravel's `:placeholder` form (`:email`).
❌ Bad — do not write `users.emailChange.throttled` (camelCase segment), a flat `users.email_change_throttled` (no group), or a literal string inline in a component instead of a key. `App\Enums\UserStatus::label()` resolves `__('users.statuses.'.$this->value)` by convention, so a status label that isn't in the `statuses` group renders as its own raw key.

**A `label()` method on an enum is not automatic — an enum with one rendering site keeps its copy in that screen's own lang file.** `UserStatus::label()` exists because the status is rendered in more than one place and by the enum's own `cases()` loop. [`App\Enums\SalesRegionKind`](../../app/Enums/SalesRegionKind.php) deliberately has **no** `label()` even now that task 0018 renders it: `kind` drives structure everywhere on the Sales Regions list (indentation, chevron, grouping) and is surfaced as text in exactly one place, the edit modal's read-only context block, so the two labels live in that screen's own file as `sales-regions.labels.kind_country` / `kind_fiscal_territory` and the view matches on the case. Story 0016 deferred `label()` to "the first story that actually renders `kind`"; 0018 is that story and declined it, which is the decision to copy — **add `label()` when a second consumer appears, not when the first one does**, because a one-caller `label()` is indirection that hides which lang group owns the copy. Note the leaves are still snake_case (`kind_fiscal_territory`) even though the enum's backing value is too — the match is a coincidence of this enum, not a rule to rely on.

**A count-dependent message is one key with a `|`-delimited plural form, resolved with `trans_choice()` — never two keys and never a hand-built `$count === 1 ? … : …`.** [`lang/en/roles.php`](../../lang/en/roles.php) (task 0010) is the first one:

```php
// lang/en/roles.php
'index' => [
    'delete_blocked' => 'This role cannot be deleted while it is still held by :count user.|This role cannot be deleted while it is still held by :count users.',
],
```

```php
// app/Livewire/Roles/Index.php — deleteRole()
trans_choice('roles.index.delete_blocked', $role->users_count, ['count' => $role->users_count])
```

✅ Good — the singular/plural split lives in the *translation file*, so a locale with different plural rules (Spanish here, and any future one) can express them without touching PHP.
❌ Bad — `delete_blocked_one` / `delete_blocked_many` as two keys, or branching on the count in the component. Both hardcode English's two-form plural rule into code that other locales have to live with.

**Six `trans_choice()` keys exist in this codebase as of story 0024b, in two different established forms — neither is "the newer one", and both have coexisted since task 0019.** `lang/en/roles.php` (task 0010, extended by task 0011) has three keys in the **simple `singular|plural`** form shown above — `index.delete_blocked`, `index.summary`, `index.permission_count`. `lang/en/media.php` (story 0019) has two keys in a different, **explicit-range** form instead — `gallery.count_summary`, `gallery.selection_count` — because both of those need to express a genuine **zero**-count case (`{0} No images|{1} :count image|[2,*] :count images`) that the simple form's `count === 1 ? first : second` selection cannot represent. `lang/en/products.php`'s `categories.delete_blocked` (story 0024b) is the **sixth** key overall, and it goes back to the **simple** form: the guard it renders for (`App\Actions\ProductCategories\DeleteProductCategory`) only ever throws once the count is already positive, so there is no zero case to express and reaching for the explicit-range form would buy nothing.

✅ Good — the choice between the two forms is not precedent order, it is **which of the two already-established forms fits the message's own semantics**: does the message ever need to render at `count === 0`? If yes, explicit-range (`media.php`'s shape); if the count is guaranteed positive by the code path that renders it, simple `singular|plural` (`roles.php`'s and now `products.php`'s shape).
❌ Bad — treating this as "there are two forms, so pick whichever" without checking the zero-case question, or (a mistake this story's own task file caught at Phase 2 review) describing a new simple-form key as "the second `trans_choice` key, the first outside `roles.php`" — that undercounts by four the moment `media.php`'s two explicit-range keys are counted too.

**The rule binds a Blade template exactly as it binds a component.** Task 0011 added two more `trans_choice()` keys to the same group — `roles.index.summary` (the list's live role count) and `roles.index.permission_count` (each row's granted-permission count) — and the second one shipped its first draft with `':count permission|:count permissions'` written **inline in `resources/views/livewire/roles.blade.php`**, where `lang/es/roles.php` could never reach it (Phase 5 finding F-3). A hardcoded plural is no more acceptable in a view than in PHP; the giveaway is the `|` character appearing anywhere outside a `lang/` file.

**A key leaf is `snake_case` even when the value it names is not — map at render, never rename the value.** This story is where the two collide: the permission catalog's own names are `<module-slug>.<action>` with kebab-case segments (`sales-regions.view`, `roles.manage-administrators`), and those names are fixed by the seeded catalog. The labels are therefore **composed** from two flat arrays rather than written one key per permission:

```php
// lang/en/roles.php — top-level siblings of 'index', not nested under it
'modules' => ['users' => 'Users', 'sales_regions' => 'Sales regions', /* … */ 'roles' => 'Roles'],
'actions' => ['view' => 'View', /* … */ 'manage_administrators' => 'Manage administrator-level roles/users'],
```

```blade
{{-- resources/views/livewire/roles.blade.php --}}
__('roles.modules.'.str_replace('-', '_', $module)).' — '.__('roles.actions.'.str_replace('-', '_', $action))
```

✅ Good — 16 keys per language (10 module labels + 6 action labels) covering 38 of the 42 permissions, the hyphen mapped to an underscore at the point of lookup, and a new seeded module needing exactly one new key.
❌ Bad — `'sales-regions' => …` as a literal kebab-case key leaf (violates the rule above), one key per permission (42+ keys, and a catalog addition silently renders a raw key), or renaming the permission itself to match the key. The permission name is the database's, not the translation file's.

> **⚠️ Corrected 2026-08-29 — this warning said story 0019 had shipped the ❌, and it had not.** As written, it claimed that story appended the tenth module slug `media` and *"did **not** add a `roles.modules.media` leaf to either locale"*, so the Roles matrix rendered the raw key in both languages. That is false: `lang/en/roles.php` carries `'media' => 'Media'` and `lang/es/roles.php` carries `'media' => 'Medios'`, both present in story 0019's own tree and untouched by any story since — verified by reading both files, which is what the original claim says it did. It is the second false "verified" finding from that pass; [errors-log.md](../errors-log.md#one-docs-pass-reported-two-gaps-that-were-not-there-both-marked-verified--2026-08-29) records why one docs pass produced two. **Everything below the correction is the rule, and the rule is unchanged** — it is exactly *because* nothing fails that it is worth stating: the four `media.*` permissions would be seeded, grantable and enforced correctly with or without the leaf, `__()` returning its own key is not an error condition, and the seeder tests assert names and counts rather than rendered copy, so a missing leaf is **only** visible by looking at the screen. `media` is now the evidence the rule is followable, not the evidence it gets missed. Two rules follow. **(a) Adding a module slug means adding its `roles.modules.<slug>` leaf to `lang/en/` *and* `lang/es/` in the same change** — the label is not derived, and `__()` returning its own key is not an error condition. **(b) The `roles.actions.*` half needs nothing**, because a new module reuses the four existing CRUD verbs; only a genuinely new *action* segment would need a leaf there. Note `media` is a single lowercase word, so its leaf is `media` with no mapping — the `str_replace('-', '_', …)` step only matters for a kebab-case slug like `sales-regions`.

**When a lang file exists to supply copy for a registry, its key structure mirrors the registry's own keys exactly — one leaf per registry key, no extras, no renames.** [`lang/en/navigation.php`](../../lang/en/navigation.php) (task 0013) is the first such file, and the mirroring is what makes it reviewable. Its original shape, at task 0013, was `config/modules.php`'s `groups.platform` / `groups.settings` / `groups.taxes` and `items.dashboard` / `items.users` / `items.roles` / `items.sales_regions` — seven leaves under two headings. **That is no longer the registry's current shape.** Story 0080's registry restructuring retired `groups.platform` and `groups.taxes` entirely (not merely renamed them) and added a third registry array, `clusters`, alongside `groups` and `items` — a lang file supplying copy for a registry still mirrors it exactly, now across all three arrays: `groups.store` / `groups.settings`, `clusters.products` / `clusters.store_settings`, and `items.dashboard` / `items.users` / `items.roles` / `items.sales_regions` / `items.product_categories` / `items.products` / `items.product_attribute_types`. See [`config/modules.php`](../../config/modules.php) and [architecture/authorization.md](../architecture/authorization.md#the-second-half-of-a-module-gate-the-sidebar-registry) for the real, shipped shape — the mirroring rule itself, and the rest of this section, are unchanged by the restructuring.

```php
// lang/en/navigation.php — the leaves are config/modules.php's own array keys
'groups' => ['platform' => 'Platform', 'settings' => 'Settings', 'taxes' => 'Taxes'],
'items' => ['dashboard' => 'Dashboard', 'users' => 'Users', 'roles' => 'Roles & permissions', 'sales_regions' => 'Sales Regions'],
```

✅ Good — a registry key is simultaneously the translation leaf and the rendered `data-test` hook (`data-test="sidebar-link-roles"`), so one identifier connects the config entry, its copy, and the test that asserts on it. Adding a module means adding the same leaf to `lang/en/` and `lang/es/`, and nothing else.
❌ Bad — writing the copy into the registry itself (`'label' => 'Roles & permissions'` in `config/modules.php`), which puts a literal English string somewhere `lang/es/` cannot reach; or naming the leaf differently from the registry key (`items.roles_and_permissions` for the `roles` entry), which breaks the one-identifier property for no gain. Note this rule does **not** conflict with the snake_case rule above.

**Since task 0018 the multi-word case is shipped rather than hypothetical, and this paragraph is the sentence it was written against.** From task 0013 until then, every registry key was a single lowercase word (`dashboard`, `users`, `roles`), so nothing forced the decision and this text read forward: *"a future registry key that is genuinely multi-word is snake_case on both sides (`items.sales_regions`), never kebab-case."* The Sales Regions entry is that key, and it went in as `sales_regions` — verified in `config/modules.php`, both `navigation.php` files, and the rendered `data-test="sidebar-link-sales_regions"` hook that `tests/Feature/Navigation/SidebarModuleGatingTest.php` selects. Three identifiers move together, so getting the key wrong breaks all three at once.

The **values** inside that same entry stay kebab-case, and the distinction is the whole rule: `'permissions' => ['sales-regions.view']` is a seeded permission name, `'route' => 'sales-regions.index'` a route name, `'current_when' => 'sales-regions.*'` a route pattern. None of the three is a registry *key*, and each is owned by something outside this file — the seeded catalog and `routes/sales-regions.php` — exactly like the *permission* names above, whose kebab-case is imposed by the catalog and mapped at lookup.

Note `APP_LOCALE=en` today, so everything renders in English until the interface language switcher exists — an accepted, documented consequence of the English-source decision, not a defect. Adding a key means adding it to **both** `lang/en/` and `lang/es/` in the same change.

**Exception: a validation `attributes` block's leaf is the field name, byte-for-byte, even when that name is camelCase.** [`lang/en/sales-regions.php`](../../lang/en/sales-regions.php) (task 0017) is this repo's first `attributes` block — the array Laravel's `validate(..., attributes: __(...))` uses to substitute a human label for `:attribute` in a validation message. Its `replacementDefaultId` leaf is camelCase because it must equal `App\Livewire\SalesRegions\Index::$replacementDefaultId`'s own property name exactly, or Laravel silently fails to find the override and falls back to the raw field name. This is not a violation of the snake_case-leaf rule above — it is a different kind of key entirely, one Laravel itself defines the shape of, the same way a route parameter name or a Blade component prop name is never snake_cased just because it appears in a `lang/` file. Do not "fix" a camelCase `attributes` leaf to snake_case; doing so breaks the substitution instead of correcting a style slip.

## Boolean properties

Livewire component boolean properties are named as a predicate, prefixed `can`/`is`/`show`/`requires` — never a bare noun. Verified in `app/Livewire/Settings/Security.php`:

```php
public bool $canManageTwoFactor;
public bool $canManagePasskeys;
public bool $twoFactorEnabled;      // present-tense state, not prefixed — see note below
public bool $requiresConfirmation;
public bool $showModal;
public bool $showVerificationStep;
public bool $showDeleteModal;
```

Two patterns coexist in this file: `can*`/`requires*`/`show*` for capability/UI-state flags, and a bare past-participle (`twoFactorEnabled`) for a fact about the authenticated user's current state. Follow whichever of the two fits: use `can`/`requires`/`show` for UI/permission flags you're introducing, and a plain past-participle only for a mirrored model/domain fact (as `twoFactorEnabled` mirrors `User::hasEnabledTwoFactorAuthentication()`).

**The same rule binds a `#[Computed]` boolean method**, which is what a modern Livewire screen actually exposes to its view. `App\Livewire\Users\Index` carries four (task 0015a): `requiresPasswordConfirmation()`, `isEditingOwnRow()`, `isDeletingOwnRow()`, `isAdministratorRoleSelected()` — a predicate name, never a noun (`passwordConfirmation()`), and never a `get*` prefix.

**Name the predicate so it is unambiguous read *out* of its class.** `App\Actions\Auth\EnsureRecentPasswordConfirmation`'s non-throwing method is `isRecentlyConfirmed()`, deliberately not `isConfirmed()`: at the call site (`app(EnsureRecentPasswordConfirmation::class)->isRecentlyConfirmed()`) the class name supplies "password", but *recency* is the whole content of the check and a bare `isConfirmed()` reads as a yes/no about whether the password was ever confirmed at all. The invokable's own name follows the existing imperative-verb-phrase rule for actions (`Ensure…`, no `Action`/`Service` suffix), so the throwing and non-throwing halves of one rule read as a command and a question respectively.

_Last updated: 2026-09-11 — Story 0044 (Customers — list + create/edit UI). Added `App\Livewire\Customers\Index` → `resources/views/livewire/customers.blade.php` as the sixth row in the **Livewire components and views** `Index`-in-a-subfolder exception table — the ordinary flat-path case, nothing new about the mechanism. No trait, permission, boolean-property or `trans_choice()` convention changed by this story.

_Previously: 2026-09-11 — Doc growth management pass (docs/contracts.md#doc-growth-management-rule), not a story. Condensed the **Traits and their methods** section's accumulated "is the Nth trait/confirming instance" narrative (`ProductAttributeValidationRules`, `ProductVariantValidationRules`, `ShippingZoneValidationRules`, `ShippingRateValidationRules`, `CustomerValidationRules`, and the "note on the sequential numbering" paragraph) into tighter prose that keeps every trait/method name, every naming rule and every ✅/❌ example — no fact was removed, only the repeated ordinal-counting framing. Did the same for the **Permission names** section's "second/third/fifth/sixth follower" policy narrative. Left every heading, every `<Noun>ValidationRules` fact, and every block explicitly marked "quoted rather than silently rewritten" (the story 0027/0026 corrections) untouched, since those are load-bearing per this project's audit-authored-page convention and other docs/`ai-spec/tasks/` files link into this file's headings by anchor. Earlier history folded per [contracts.md](../contracts.md#doc-growth-management-rule) — see git history if needed._
