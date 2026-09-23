# Naming Conventions — Route and permission names

> Part of [Naming Conventions](../naming.md). **Read this part when:** you name a route or a permission, or need the validation-trait pointer. The other parts are listed in the [hub](../naming.md#table-of-contents).

## Traits and their methods

Split out into its own file, per [contracts.md](../../contracts/token-and-doc-rules.md#doc-growth-management-rule)'s doc growth management rule — this was the largest section in this file. See **[Validation Trait Naming Conventions](../naming-validation-traits.md)** for the full `<Noun>ValidationRules` trait-and-method naming convention: the base pattern, the entity-prefix collision exception, and the file-by-file trait roster.

## Route names

Dot notation, `<resource>.<action>`, verified in `routes/settings.php`:

```php
// routes/settings.php
Route::livewire('settings/profile', Profile::class)->name('profile.edit');
Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');
Route::livewire('settings/security', Security::class)->name('security.edit');
```

Full real route names, including the ones Fortify registers, are listed in [api/routes.md](../../api/routes.md).

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

/**
 * Non-CRUD permissions on the orders module that sit outside the module x action grid.
 *
 * @var array<int, string>
 */
public const ORDER_PERMISSIONS = ['orders.refund'];
```

✅ Good — `sales-regions.delete`, `payment-methods.view`, `roles.manage-administrators`, `orders.refund`: kebab-case slug, dot separator, verb (or verb phrase, itself kebab-cased) after the dot.
❌ Bad — do not write `salesRegions.delete`, `sales_regions.delete`, `delete-sales-regions`, or a prose form like `'manage administrator-level roles/users'`. A permission name that isn't in the seeded catalog makes `can()` / `hasPermissionTo()` throw `PermissionDoesNotExist` at runtime, so this is a correctness rule, not just a style preference.

A permission that a new feature needs is added to the constants above — never as a string only one component knows about. The catalog, the two seeded roles, and which of them hold what are documented in [architecture/authorization.md](../../architecture/authorization/overview-catalog-seeding.md#permission-catalog).

`'media'` (story 0019) is the **only** slug appended since task 0002 wrote this constant, and the one-line diff above is genuinely the whole production change — but what that one line costs elsewhere, and the one thing it silently breaks, are in [The `media` module, and what a catalog amendment costs](../../architecture/authorization/overview-catalog-seeding.md#the-media-module-and-what-a-catalog-amendment-costs). Read it before adding the eleventh. `orders.refund` (story 0051) is a **different** kind of amendment from `media` — it adds no new module slug at all, instead following `ROLE_PERMISSIONS`'s own shape (a new constant of non-CRUD permissions on an existing module), the catalog's first entry of that shape on a module other than `roles`.

**Where a permission name is written in PHP, name it once on the class that owns the rule.** Task 0009 established this for the two role-management names, as `public const` on the policy that decides with them — read by the policy itself, by `App\Actions\Roles\EnforceAdministratorPermissionGrant`, and by both classes' tests:

```php
// app/Policies/RolePolicy.php
public const ADMINISTRATOR_LEVEL_PERMISSION = 'roles.manage-administrators';
public const ROLE_MANAGEMENT_PERMISSION = 'roles.manage';
```

[`App\Policies\SalesRegionPolicy`](../../../app/Policies/SalesRegionPolicy.php) (task 0017), [`App\Policies\MediaPolicy`](../../../app/Policies/MediaPolicy.php) (story 0019), `App\Policies\ProductCategoryPolicy` (story 0023), [`App\Policies\ProductPolicy`](../../../app/Policies/ProductPolicy.php) (story 0024) and [`App\Policies\ProductAttributeTypePolicy`](../../../app/Policies/ProductAttributeTypePolicy.php) (story 0028) all follow the same shape — one `public const <VERB>_PERMISSION` per ability the policy defines. `MediaPolicy` is the first to carry all four (`VIEW`/`CREATE`/`EDIT`/`DELETE`); the others carry only the ones they need — `SalesRegionPolicy` has one ability per verb, so `VIEW_PERMISSION`/`EDIT_PERMISSION` alone are unambiguous read as `SalesRegionPolicy::EDIT_PERMISSION`. `ProductPolicy` and `ProductCategoryPolicy` gate on the identical `products.*` strings (a category is a product sub-resource sharing the module), so their same-named constants on two different classes deliberately equal the same string — two policies agreeing on one catalog permission, not duplication to collapse. `App\Policies\UserPolicy` is the sole outlier among these seven policies, still re-typing its literals at four call sites (task 0009's Phase 4 finding **F5**, deliberately deferred) — a cleanup candidate, not the pattern to copy. The rule is that the constant names the **rule**, at whatever precision the class requires, not that every policy shares the same constant names.

✅ Good — `$user->hasPermissionTo(self::ADMINISTRATOR_LEVEL_PERMISSION)`, and `RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION` from a collaborator.
❌ Bad — re-typing `'roles.manage-administrators'` at each call site. This is exactly what `App\Policies\UserPolicy` still does at four of its own call sites (task 0009's Phase 4 finding **F5**, pre-existing and deliberately deferred); it is a known cleanup candidate, not the pattern to copy. The constant name describes the *rule* (`ADMINISTRATOR_LEVEL_PERMISSION`), not the string, so a future catalog rename touches one line.
