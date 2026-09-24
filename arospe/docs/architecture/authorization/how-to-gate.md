# Authorization — Configuration, how to gate, where it lives

> Part of [Authorization](../authorization.md). **Read this part when:** you add a new gated module route/sidebar entry/policy: the copyable module-gate pattern, the sidebar registry, and where each piece lives. The other parts are listed in the [hub](../authorization.md#table-of-contents).

## Configuration

Teams support is **disabled** (single-tenant permission model):

```php
// config/permission.php
'teams' => false,
```

Table names are the package defaults:

| Config key | Table |
| --- | --- |
| `table_names.roles` | `roles` |
| `table_names.permissions` | `permissions` |
| `table_names.model_has_roles` | `model_has_roles` |
| `table_names.model_has_permissions` | `model_has_permissions` |
| `table_names.role_has_permissions` | `role_has_permissions` |

The polymorphic **morph key** is **not** the package default. Because `users.id` is a UUID (v7) string (see [ADR 0001](../../decisions/0001-uuid-primary-keys.md)), the morph-key column on `model_has_roles` / `model_has_permissions` was renamed from the default `model_id` (bigint) to `model_uuid` (UUID-typed):

```php
// config/permission.php
'column_names' => [
    'model_morph_key' => 'model_uuid',
    // ...
],
```

This config change tells the package which column to *query*; the physical column was renamed/retyped by the alteration migration `database/migrations/2026_07_22_100004_convert_model_morph_key_to_uuid_in_permission_tables_table.php`. See [database/schema.md](../../database/schema.md) for the column shapes.

Permission checks are cached for 24 hours (`config/permission.php`, `'cache'` section) on the `database` cache store, which is shared across every worker. The cache is flushed automatically whenever a role/permission changes through the package's own methods — **except** under `WithoutModelEvents`, which is why the seeder flushes explicitly (see [Seeding](overview-catalog-seeding.md#seeding)).

## How to gate something

✅ Good — the real, currently-gated route: a permission gate (so the Super Admin bypass applies), inside its area file's `auth` + `verified` group:

```php
// routes/users.php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('users', UsersIndex::class)
        ->middleware(['can:users.view'])
        ->name('users.index');
});
```

❌ Bad — a bare role gate locks the Super Admin out, because `hasAnyRole()` never reaches the Gate (adapted from the route above to illustrate; not present in the repo):

```php
// anti-pattern — do not do this
Route::livewire('users', UsersIndex::class)->middleware(['role:Administrator']);
```

### Gating a Livewire route: use `can:`, never `permission:`

On a **`Route::livewire(...)` route the two are not interchangeable**, even though they express the same rule. Livewire re-applies route middleware to `/livewire/update` round-trips only for the classes hardcoded in `PersistentMiddleware::$persistentMiddleware`. That allow-list contains Laravel's `Illuminate\Auth\Middleware\Authorize` (which backs `can:`) but **not** Spatie's `PermissionMiddleware`:

```php
// ❌ anti-pattern on a Livewire route — protects only the initial GET /users;
// every save()/deleteUser() round-trip runs unauthorized at the route layer
Route::livewire('users', UsersIndex::class)->middleware(['permission:users.view']);
```

Spatie registers every permission as a Gate ability, so `can:users.view` carries exactly the same meaning — including the Super Admin bypass — and **is** re-applied on every action. This is why [`routes/users.php`](../../../routes/users.php) carries an inline comment warning against the swap — it moved with the route declaration in task 0040, because it documents *this route*, not the file it happens to sit in — and why a later story must not "normalise" this route onto `permission:`. [`routes/roles.php`](../../../routes/roles.php) (task 0010) carries the same comment above `roles.index`, naming the same reason and the same round-trip methods; **every future module route repeats it.** The verified allow-list, plus the three other middlewares that silently do not follow a component (`verified`, `password.confirm`, `throttle:`), are in [security/livewire-authorization.md](../../security/livewire-authorization/entry-point-and-method-gates.md#livewireupdate-is-a-second-entry-point-and-only-an-allow-listed-subset-of-route-middleware-follows-the-component-there).

Route middleware is never the whole story for a Livewire screen regardless — see [`Gate::authorize` at the call site](grant-meta-rules-and-ui-hints.md#gateauthorize-at-the-call-site-not-only-at-the-route).

### The copyable module-gate pattern, and the three alternatives rejected

Task 0012 exists to make the shipped gates reusable rather than incidental: `users.index`, `roles.index` and — since task 0017 — `sales-regions.index` are the module routes today, and the remaining Products / Blog / Shipping screens (PRD Epics 2–4) each gate the same way. **Task 0017 is the pattern's first real test**, and it passed without amendment: [`routes/sales-regions.php`](../../../routes/sales-regions.php) is `routes/roles.php` with three strings changed — the same `['auth', 'verified']` group, the same per-route `can:` alias string, the same aliased `Index` import, the same inline warning comment duplicated verbatim rather than cross-referenced. Nothing below needed to change to accommodate it. The pattern is exactly the route files quoted above — **one `can:<permission>` per route, written as the plain alias string, chained onto `Route::livewire(...)` inside the area file's existing `['auth', 'verified']` group** — in a new `routes/<area>.php` `require`d from `web.php` (see [conventions/base-standards.md](../../conventions/directory-structure.md#directory-structure)). Nothing else is needed: no alias to register, no provider change, no Super Admin special case.

Four properties of that shape, each verified at vendor source rather than assumed:

- **`can` needs no registration.** It is a framework default alias (`'can' => Illuminate\Auth\Middleware\Authorize::class`, in `Illuminate\Foundation\Configuration\Middleware`), and Spatie registers every seeded permission name as a Gate ability through `PermissionRegistrar::registerPermissions($gate)` (`register_permission_check_method => true` in [`config/permission.php`](../../../config/permission.php)). The three Spatie aliases in [`bootstrap/app.php`](../../../bootstrap/app.php) — see [Middleware aliases](administrator-tier.md#middleware-aliases) — are **not** consumed by a module gate, and a story that adds one consumes nothing from the seeding story either beyond the permission *name*.
- **The Super Admin needs no special case.** `Authorize::handle()` is a one-line `$this->gate->authorize(...)`, so [the bypass](super-admin.md#the-super-admin-bypass) runs ahead of the ability check and a Super Admin passes holding zero `role_has_permissions` rows.
- **`can:` gates exactly one ability, and there is no OR form.** `Authorize::handle($request, Closure $next, $ability, ...$models)` treats everything after the first argument as a **model binding**, not a second ability — so `permission:a|b`'s any-of syntax has no `can:` equivalent. A genuine any-of module gate needs either a single ability meaning "may reach this module" (what both shipped routes use) or a composite `Gate::define()` in a provider; the latter was considered for `users.index` and rejected, because it adds an out-of-scope `app/Providers/` change and an ability name outside [the seeded catalog](overview-catalog-seeding.md#permission-catalog).
- **A misspelled ability denies silently.** Spatie's `Gate::before` hook calls `checkPermissionTo()`, which catches `PermissionDoesNotExist` and returns `false` (`vendor/spatie/laravel-permission/src/Traits/HasPermissions.php`), so `can:userss.view` yields an ordinary 403 rather than an error naming the mistake — indistinguishable from a correct refusal. **Consequence: every module gate needs a positive test proving the right holder gets 200.** A negative-only suite passes just as happily against a typo, which is why [`tests/Feature/Authorization/ModuleRouteAccessTest.php`](../../../tests/Feature/Authorization/ModuleRouteAccessTest.php) carries a 200 control beside each 403 rather than asserting refusals alone.

Three alternatives were considered and rejected. Recorded here so they are not re-proposed per module:

| Rejected | Why |
| --- | --- |
| **Spatie's `permission:<name>`** | Off Livewire's `PersistentMiddleware` allow-list, so it protects the initial `GET` only and every `/livewire/update` round-trip runs unauthorized at the route layer — [the subsection above](#gating-a-livewire-route-use-can-never-permission) in full. |
| **A group-level gate** — `Route::middleware(['auth', 'verified', 'can:…'])->group(...)` | Every module needs a *different* ability, so a blanket group forces sub-grouping by ability and moves each route's requirement away from its own declaration. Keep the single `['auth', 'verified']` group per area file and chain `can:` **per route**, exactly as `security.edit` chains `->middleware(['password.confirm'])` in [`routes/settings.php`](../../../routes/settings.php). |
| **Laravel's `->can()` route sugar** — `Illuminate\Routing\Route::can()` | It builds the identical `'can:'.$ability` middleware string, so it adds no capability at all; using it would introduce a second syntax for one thing, against the plain alias string both shipped routes already carry. Same argument against writing the FQCN in place of the alias. |

> ⚠️ **A `.view`-shaped gate lets a role hold `create`/`edit`/`delete` on a module it cannot reach.** `users.index` gates on `users.view`, so a role granted `users.create` + `users.edit` + `users.delete` and *not* `users.view` gets a 403 on `/users`, with no warning anywhere and its three grants unreachable — this app has no route into a module that bypasses the module's own list screen. The refusal is fail-closed and so not a vulnerability (task 0012's Phase 4 audit recorded it as informational, not a finding), but it is the single most likely misconfiguration the [roles screen](../../api/users-and-roles.md#rolesindex--the-second-permission-gated-route)'s permission matrix will produce, since that grid renders the four CRUD actions as four independent checkboxes with nothing coupling them. `roles.manage` does not have this shape — it is one ability covering its whole screen. **Task 0017 is the second route with this shape, and it sharpens the hazard rather than repeating it:** `sales-regions.index` gates on `sales-regions.view` while every mutation on that screen requires `sales-regions.edit`, so a role granted `sales-regions.edit` alone gets a 403 on `/taxes/sales-regions` and its grant is unreachable — and, unlike the Users screen, that role's *only* possible use of the module is behind the very screen it cannot open, since Sales Regions has no create or delete affordance at all. Still fail-closed, still not a vulnerability, still unwarned. Nothing validates the combination today; a story that wants to warn about it owns both the rule and the surface it warns on. **Since task 0013 the same misconfiguration is also silent in the navigation**: [the sidebar registry](#the-second-half-of-a-module-gate-the-sidebar-registry) gates the Users entry on `users.view` — the same single ability, deliberately and test-pinned — so such a role now sees no link at all rather than a link that 403s. That is the correct behaviour (a link the route would refuse must never render), and it makes the dead grants *less* discoverable, not more: the module simply is not there.

### The second half of a module gate: the sidebar registry

A `can:` gate refuses the request; it does not stop the sidebar advertising the link. Task 0013 closed
that half, and it did so with a **declarative registry** rather than per-module Blade conditionals — so
a later epic gates its module's navigation by appending data, never by editing a component. The three
files, all new in that story:

| File | Role |
| --- | --- |
| [`config/modules.php`](../../../config/modules.php) | the registry itself — `groups` (heading/icon/expandable/`expanded_when`/`class`) and `items` (group, label key, icon, route name, `current_when`, `permissions`) |
| [`resources/views/components/sidebar-nav.blade.php`](../../../resources/views/components/sidebar-nav.blade.php) | the one anonymous Blade component that reads it. `resources/views/layouts/app/sidebar.blade.php`'s `<flux:sidebar.nav>` block now contains nothing but `<x-sidebar-nav />`, so that file names no **module** route any more (it still names `dashboard` for the logo href, plus `profile.edit` / `logout` in the personal user menu, which module permissions never gate) |
| [`lang/en/navigation.php`](../../../lang/en/navigation.php), [`lang/es/navigation.php`](../../../lang/es/navigation.php) | the copy; the registry stores the translation **key**, never the string |

**Adding a module is one registry entry plus its translation leaf in each locale** — two more if it also
needs a new group. No component change, no provider change, no new folder.

> ✅ **Task 0018 is the first module added after this pattern was written, and it cost exactly what the
> paragraph above promises.** The Sales Regions screen's whole navigation change is **two array literals**
> in `config/modules.php` (a new `taxes` group plus an `items.sales_regions` entry, 15 lines between them)
> and one leaf per file in `lang/{en,es}/navigation.php`.
> [`sidebar-nav.blade.php`](../../../resources/views/components/sidebar-nav.blade.php) and
> `resources/views/layouts/app/sidebar.blade.php` are **untouched** — verified against the diff
> (`git diff --stat` over `resources/views/components/` and `resources/views/layouts/` returns nothing),
> not assumed. Three things it establishes that the first three entries could
> not, because all three were single lowercase words in groups that already existed:
>
> - **A multi-word item key is `snake_case`, and the same identifier does three jobs.** `sales_regions` is
>   simultaneously the config key, the `navigation.items.sales_regions` translation leaf, and the rendered
>   `data-test="sidebar-link-sales_regions"` hook — so a test selecting the entry, the config declaring it
>   and the copy naming it cannot drift apart. This is [naming.md](../../conventions/naming/translation-keys-and-booleans.md#translation-keys)'s
>   registry-key rule, which named this exact case prospectively; 0018 is where it stops being a
>   hypothetical. Note the entry's three kebab-case **values** — `sales-regions.view`, `sales-regions.index`,
>   `sales-regions.*` — stay kebab, because a permission name, a route name and a route pattern are not
>   registry keys.
> - **Adding a *group* is data too.** `taxes` is the first group added since 0013 and the first that is
>   non-`expandable` while carrying an `icon` (`receipt-percent`) — a combination neither `platform`
>   (no icon) nor `settings` (expandable) exercised. Its `expandable => false` is a one-entry decision
>   recorded in the config's own inline comment, to revisit when a second Taxes screen ships.
> - **The two generic drift guards picked the entry up for free.** Both of 0013's Phase-4 tests iterate
>   `config('modules.items')` rather than naming entries, so the registry↔route cross-check needed **no
>   edit at all** — a fact worth stating because the story's own plan initially assumed the opposite and
>   would have hand-written a redundant copy of a check that already generalises. What genuinely had to be
>   added is the per-entry coverage those tests cannot supply: the holder sees both hooks, a role holding
>   the related-but-different `sales-regions.edit` sees **neither** (the "never advertise a link the route
>   would refuse" case), and the Taxes group vanishes heading-and-all for a role without the ability.

> ✅ **Story 0027 (products list + editor UI) is the fifth entry in a row that costs only data** — after
> 0013's own three, 0018's `sales_regions` and 0025's `product_categories`. `items.products` lands in the
> existing `groups.platform` group (beside `product_categories`, per D-15's own placement note), with
> `permissions` exactly `['products.view']` — the same single ability `routes/products.php`'s `can:`
> middleware enforces on all three of `products.index`/`.create`/`.edit`. Neither `sidebar-nav.blade.php`
> nor `resources/views/layouts/app/sidebar.blade.php` is touched (verified against the diff), and both
> generic Phase-4 drift guards picked the entry up with no edit, exactly as 0018's block above
> established — `tests/Feature/Navigation/SidebarModuleGatingTest.php` adds only the per-entry coverage
> those generic checks cannot supply (a `products.view` holder sees the entry; a role holding only the
> related-but-different `products.edit` sees neither hook).

> ✅ **Story 0044 (Customers screen) is the first module gate written by an epic other than Epic 1/2's
> catalog screens — Epic 3's first — and it confirms the pattern rather than needing a variant of it.**
> `items.customers` costs the same "one registry entry plus its translation leaf" the pattern has promised
> since 0013: `group: null, cluster: null` — the **bare top-level item** shape `items.users` already uses,
> since Customers is a top-level operational module like Users rather than store configuration or a
> sub-resource nested under an existing cluster — `permissions` exactly `['customers.view']`, the same
> single ability `routes/customers.php`'s own `can:` middleware enforces, with matching
> `lang/{en,es}/navigation.php` `items.customers` leaves. Neither `sidebar-nav.blade.php` nor
> `resources/views/layouts/app/sidebar.blade.php` needed an edit, and both generic Phase-4 drift guards in
> `tests/Feature/Navigation/SidebarModuleGatingTest.php` picked the entry up with no change — the same
> "append data, never behavior" confirmation every prior module gate has produced. See
> [api/customers.md](../../api/customers.md#customersindex--the-ninth-permission-gated-route) for the route
> itself, and story 0044's own D-7 for why this screen carries no step-up authentication despite Users
> having one — [step-up authentication](step-up-and-refusal-logging.md#what-it-protects-and-what-it-deliberately-does-not) is a separate
> layer from this registry and does not gate navigation visibility at all.

```php
// config/modules.php — the shape every later epic copies
'roles' => [
    'group' => 'settings',
    'label' => 'navigation.items.roles',
    'icon' => 'shield-check',
    'route' => 'roles.index',
    'current_when' => 'roles.*',
    'permissions' => ['roles.manage'],
],
```

Six rules come with it, each load-bearing:

- **`permissions` must be *exactly* the ability the route's own `can:` middleware enforces** — never a
  broader or related set. `users` is `['users.view']` because `routes/users.php` gates on exactly
  `can:users.view`. A registry entry listing `users.create` as well would render the link for a role the
  route then 403s, breaking the story's central criterion: *never advertise a link the route would
  refuse*. The two gates stay independent — one ability per module, resolved separately — and
  `tests/Feature/Navigation/SidebarModuleGatingTest.php` pins each entry against its route's real
  middleware mechanically, so the registry cannot silently drift from the route. The gate itself remains
  the route's; hiding a link is presentation only, and the enforcement evidence is task 0012's suite.
- **Visibility is resolved through `Gate::any()`, never `hasAnyPermission()`.** This is a correctness
  fork, not a style choice: `Gate::any()` runs the full `before`-callback chain, so it traverses the
  identical mechanism `can:` middleware does and inherits [the Super Admin bypass](super-admin.md#the-super-admin-bypass)
  with no sidebar-local special case. `hasAnyPermission()` is a `HasPermissions` trait method that queries
  the model's own relations and never reaches the Gate — and since the Super Admin holds **zero**
  permission rows by design, a sidebar built on it would show the Super Admin an empty menu, the exact
  inverse of the requirement. Same trap as the `hasPermissionTo()` ❌ [below](#in-php-and-blade).
- **`permissions: []` means "always visible" and must be branched on explicitly**, never handed to
  `Gate::any()`. `Gate::any([])` returns `false` — there is nothing to iterate to `true` — so the naive
  form would hide the ungated Dashboard entry from everyone. The component reads
  `empty($item['permissions']) || Gate::any($item['permissions'])`, and *that* `empty()` is why an
  ungated entry must also be allow-listed in a test: see
  [security/authorization-patterns.md](../../security/authorization-patterns/payload-omission-and-registries.md#a-registry-that-means-ungated-by-absence-fails-open-silently),
  which owns the fail-open rule and both shipped guard tests. Read it before adding an entry with an
  empty `permissions`.
- **Filter first, group second.** `collect(config('modules.items'))->filter(...)->groupBy('group', preserveKeys: true)`
  structurally cannot produce a bucket with zero members, so a group whose every item was filtered out
  is simply absent from the grouped collection and its `<flux:sidebar.group>` never renders. That is what
  makes an emptied group's **heading disappear entirely** rather than render above nothing — a property
  of the data flow, not of a conditional someone has to remember to write. `preserveKeys: true` is
  mandatory: without it each bucket is reindexed `0, 1, 2…` and the `data-test` hooks below become
  `sidebar-link-0`.
- **Every rendered item carries `data-test="sidebar-link-{key}"` and every rendered group
  `data-test="sidebar-group-{key}"`,** keyed by the registry key. Absence assertions must target those
  hooks: `assertDontSee('Settings')` collides with the personal-account Settings item in the user-menu
  dropdown on the same page, and `assertDontSee('Users')` with the page title.
- **A new entry is placed by the PRD's navigation design, never by what is cheapest to append.** Added
  2026-09-07 as a forward-looking rule, ahead of [story 0080](../../../ai-spec/tasks/done/0080-sidebar-navigation-grouping-and-nesting.md)'s
  own implementation, so no module shipping in the meantime would repeat the mistake. **That
  implementation has since landed and story 0080 has closed** (moved to `ai-spec/tasks/done/`), so this
  rule now describes the shipped schema rather than a plan. Before adding a registry entry, check
  [the PRD's dashboard mockup](../../PRD/sections/foundations.md#design-reference--the-dashboard-shell) and the real
  `groups`/`clusters`/`items` shape in [`config/modules.php`](../../../config/modules.php) itself for which
  top-level group the new module belongs under — a flat top-level item is a decision to justify, never the
  default reached for because it needs no new group. **And when the new entry is a sub-resource of an
  already-shipped module** (a second screen belonging to the same conceptual module — e.g. a
  type/category/attribute editor for an existing catalog) **it is nested under that module's own cluster,
  via the item's `cluster` key, rather than added as a flat sibling.** The registry is now three flat
  sibling arrays rather than two — `groups`, `clusters`, `items` — with `clusters` purely presentational
  (no `route`, no `permissions` of its own; its expand/current state derives from its visible children's
  `current_when` values, never a separately-maintained pattern):

  ```php
  // config/modules.php — the real, shipped shape (abbreviated)
  'groups' => [
      'store' => ['heading' => 'navigation.groups.store', 'icon' => 'building-storefront', 'expandable' => false, 'expanded_when' => null, 'class' => null],
      'settings' => ['heading' => 'navigation.groups.settings', 'icon' => 'cog-6-tooth', 'expandable' => true, 'expanded_when' => 'roles.*', 'class' => null],
      'content' => ['heading' => 'navigation.groups.content', 'icon' => 'newspaper', 'expandable' => false, 'expanded_when' => null, 'class' => null],   // story 0060: Blog's group
  ],
  'clusters' => [
      'products' => ['group' => 'store', 'label' => 'navigation.clusters.products', 'icon' => 'cube'],
      'store_settings' => ['group' => 'store', 'label' => 'navigation.clusters.store_settings', 'icon' => 'adjustments-horizontal'],
      'blog' => ['group' => 'content', 'label' => 'navigation.clusters.blog', 'icon' => 'document-text'],   // story 0060; blog categories/posts append items, not clusters
  ],
  'items' => [
      'dashboard' => ['group' => null, 'cluster' => null, /* ... */ 'permissions' => []],
      'users' => ['group' => null, 'cluster' => null, /* ... */ 'permissions' => ['users.view']],
      'roles' => ['group' => 'settings', 'cluster' => null, /* ... */ 'permissions' => ['roles.manage']],
      'sales_regions' => ['group' => null, 'cluster' => 'store_settings', /* ... */ 'permissions' => ['sales-regions.view']],
      'product_categories' => ['group' => null, 'cluster' => 'products', /* ... */ 'permissions' => ['products.view']],
      'products' => ['group' => null, 'cluster' => 'products', /* ... */ 'permissions' => ['products.view']],
      'product_attribute_types' => ['group' => null, 'cluster' => 'products', /* ... */ 'permissions' => ['products.view']],
      'blog_tags' => ['group' => null, 'cluster' => 'blog', /* ... */ 'permissions' => ['blog.view']],
  ],
  ```

  Each item carries two mutually exclusive, independently-nullable keys: both `group` and `cluster` `null`
  is a bare top-level item with no wrapping element (`dashboard`/`users`); `group` set and `cluster` `null`
  is a direct child of that group, unchanged from the original 0013 shape (`roles`); `group` `null` and
  `cluster` set nests the item inside that cluster, which itself renders inside the cluster's own `group`
  (`sales_regions`, `product_categories`, `products`, `product_attribute_types` — all four moved into a
  cluster by story 0080's own restructuring). **The flat `platform` and `taxes` groups this rule's original
  2026-09-07 wording named, and the three ✅ notes above celebrating a "no template change" landing in
  `platform`, no longer exist** — both were retired (not merely emptied) once every member moved into one
  of `store`'s two clusters, per the story's own D-4. Read those three ✅ notes as a historical record of
  what shipped at the time, not as the registry's current shape.

> ⚠️ **Two hazards a later epic will meet first, both currently unexercised.** (1) **`Gate::any()` is
> OR, and nothing in the registry says so.** Every entry today holds a single ability, so the combinator
> is invisible; the first entry needing two will silently get *any-of* semantics when *all-of* may have
> been intended. If a module needs all-of, it needs either a single ability meaning "may reach this
> module" (the shape both shipped routes use) or an explicit combinator key — and the `can:`-side
> constraint that [there is no `permission:a|b` OR form](#the-copyable-module-gate-pattern-and-the-three-alternatives-rejected)
> applies to the route half either way, so the two halves must be designed together. (2) **A `group` key
> that names no entry in `groups` drops the item silently.** `groupBy()` resolves the missing/typo'd key
> through `data_get()` to `''`, and the render loop iterates `config('modules.groups')`, so the orphan
> bucket is never visited. It fails **closed** — the link vanishes rather than leaking — but it produces
> no warning, so a mistyped `group` reads as "my module never shipped". Since story 0080 a `cluster` key
> naming no entry in `clusters` (or a cluster whose own `group` names no entry in `groups`) fails the same
> way — silently closed, no warning — which is why the story added a dedicated drift-guard test asserting
> every item's non-null `cluster` exists in `config('modules.clusters')` and every cluster's `group` exists
> in `config('modules.groups')`, rather than relying on the render loop's own fail-closed behaviour alone.

### In PHP and Blade

```php
$user->can('products.delete');        // ✅ Gate — Super Admin passes
$user->hasPermissionTo('products.delete'); // ❌ direct query — Super Admin fails
```

The one place `hasPermissionTo()` is correct is **inside a policy body**, which is only ever reached through the Gate — see [Policies](policies-users-roles.md#policies).

## Where it lives

| Concern | Path |
| --- | --- |
| Package config, incl. the `models.role` → `App\Models\Role` binding | `config/permission.php` |
| Super Admin role name & bootstrap address | `config/auth.php` (`auth.super_admin.*`), `.env` (`SUPER_ADMIN_EMAIL`) |
| The literal `'Super Admin'` (compiled-in default only) **and** `'Administrator'` (the locked identity itself) | `app/Enums/RoleName.php` |
| Role model, `superAdminName()`, `isAdministratorRole()` / `isSuperAdminRoleRow()` / `persistedName()`, the two `firstOrCreate*Role()` factories, the six guards and `selectable()` | `app/Models/Role.php` |
| Administrator-tier and Super Admin-tier authorization on the write paths | `app/Actions/Users/CreateUser.php`, `app/Actions/Users/UpdateUser.php` |
| The "who may *grant* administrator-level permission" meta-rule | `app/Actions/Roles/EnforceAdministratorPermissionGrant.php` (enforcement), `app/Policies/RolePolicy.php` (`grantAdministratorPermission`, the visibility contract) |
| The "you cannot grant what you do not hold" meta-rule | `app/Actions/Roles/EnforceGrantorPermissionScope.php` |
| Role name / permission-id validation rules, both `web`-guard-scoped | `app/Concerns/RoleValidationRules.php` |
| The guards' 403-rendering exception | `app/Exceptions/ImmutableRoleException.php` |
| The holder-count guard's 409-rendering exception | `app/Exceptions/RoleInUseException.php` |
| The step-up freshness check (the single implementation, throwing and non-throwing) | `app/Actions/Auth/EnsureRecentPasswordConfirmation.php` |
| The step-up guard's 423-rendering exception | `app/Exceptions/PasswordConfirmationRequiredException.php` |
| The step-up guard's call sites | `app/Actions/Users/UpdateUser.php` (role/status/third-party email), `app/Actions/Users/CreateUser.php` (Administrator-tier creation), `app/Livewire/Users/Index.php` (`deleteUser()`) |
| The rate limiter on `password.confirm.store` | `app/Providers/FortifyServiceProvider.php` (`configurePasswordConfirmationRateLimiting()`) |
| The step-up modal notices' copy | `lang/en/users.php`, `lang/es/users.php` (`users.index.step_up_notice_*`) |
| The refusal-logging helper (the single implementation, throwing and non-throwing) | `app/Actions/Auth/LogRefusedPrivilegedAttempt.php` |
| The refusal-logging call sites | `app/Livewire/Users/Index.php`, `app/Livewire/Roles/Index.php`, `app/Livewire/SalesRegions/Index.php`, `app/Actions/Users/{CreateUser,UpdateUser,RequestEmailChange}.php`, `app/Actions/Roles/{EnforceAdministratorPermissionGrant,EnforceGrantorPermissionScope}.php`, `app/Actions/SalesRegions/{UpdateSalesRegion,SetDefaultSalesRegion,SetSalesRegionActive}.php` |
| The step-up refusal's own, separately-shaped log line | `app/Livewire/Users/Index.php` (`Log::warning('Step-up password confirmation required', …)`, task 0015a) |
| Refusal-logging tests, incl. the two shape-equivalence tests | `tests/Feature/Users/RefusalLoggingTest.php`, `tests/Feature/Roles/RefusalLoggingTest.php`, `tests/Feature/Users/ActionRefusalLoggingTest.php`, `tests/Feature/Roles/ActionRefusalLoggingTest.php` |
| Migration | `database/migrations/2026_07_12_181045_create_permission_tables.php` |
| Catalog & role seeding | `database/seeders/RolePermissionSeeder.php` |
| Seeder call order & fixture guard | `database/seeders/DatabaseSeeder.php` |
| Middleware aliases | `bootstrap/app.php` |
| Super Admin bypass | `app/Providers/AppServiceProvider.php` |
| Trait usage | `app/Models/User.php` |
| Policies | `app/Policies/UserPolicy.php`, `app/Policies/RolePolicy.php`, `app/Policies/SalesRegionPolicy.php`, `app/Policies/MediaPolicy.php` (auto-discovered; no provider registration) |
| The one gated surface with **no** route behind it | `app/Livewire/Media/Gallery.php` (`mount()` + `upload()`), `app/Actions/Media/StoreUploadedImage.php` |
| The one-role-model `arch()` rules | `tests/Unit/ArchitectureTest.php` |
| The gated routes (six, not three — this row undercounted before story 0027's pass; the three added since are `product-categories.php`/`sales-regions.php`'s own sibling additions, left uncorrected until touched by a pass that needed them) | `routes/users.php` (`users.index`, `can:users.view`), `routes/roles.php` (`roles.index`, `can:roles.manage`), `routes/sales-regions.php` (`sales-regions.index`, `can:sales-regions.view`), `routes/product-categories.php` (`product-categories.index`, `can:products.view`), `routes/products.php` (`products.index`/`.create`/`.edit`, all `can:products.view`) |
| The Sales Regions **domain invariant** (exactly one default, always active) and its two refusals | `app/Actions/SalesRegions/SetDefaultSalesRegion.php` (`is_default`, the only writer), `app/Actions/SalesRegions/SetSalesRegionActive.php` (`is_active`, the only writer) |
| Sales Regions validation rules, incl. the active-only replacement-default rule | `app/Concerns/SalesRegionValidationRules.php` |
| Sales Regions domain-error copy (the two invariant refusals) and validation attribute names | `lang/en/sales-regions.php`, `lang/es/sales-regions.php` |
| The sidebar module registry, and the one component that reads it | `config/modules.php`, `resources/views/components/sidebar-nav.blade.php` (mounted as `<x-sidebar-nav />` from `resources/views/layouts/app/sidebar.blade.php`) |
| Sidebar navigation copy | `lang/en/navigation.php`, `lang/es/navigation.php` |
| Per-action `Gate::authorize` call sites, and the per-row `Gate::allows` UI hint | `app/Livewire/Users/Index.php`, `app/Livewire/Roles/Index.php`, `app/Livewire/SalesRegions/Index.php` |
| Roles-screen copy (the holder-count refusal and the self-lockout refusal) | `lang/en/roles.php`, `lang/es/roles.php` |
| Tests | `tests/Feature/Seeders/`, `tests/Feature/Authorization/`, `tests/Feature/Policies/`, `tests/Feature/Users/`, `tests/Feature/Roles/`, `tests/Feature/SalesRegions/`, `tests/Feature/Models/RoleTest.php`, `tests/Feature/Actions/Auth/`, `tests/Unit/Actions/Auth/`, `tests/Unit/Exceptions/` |
| Security rules derived from this foundation | [`docs/security/`](../../security/README.md) |

_Last updated: 2026-09-23 — Story 0055 (orders list + detail/editor UI). Added [The order detail screen — the first three-ability screen](domain-invariants.md#the-order-detail-screen--the-first-three-ability-screen) (permission vs state dimensions rendered differently; the Super Admin/Cancel drift), gave `OrderPolicy::viewAny` its Orders callers, marked the 0049/0050 UI-hint forward references as shipped, and recorded the fourth reader of the line-item block (`Order::isLineItemEditable()`/`isRefundable()`). Earlier history folded: 0051 took the catalog to 43 permissions via `ORDER_PERMISSIONS` (`orders.refund`); 0050 added `OrderPolicy::cancel` (two permissions plus the state clause) and its section; 0049 added `transitionStatus` and the regression-confirmation section; 0048 added the order-editability section. Each is described in its own section above._
