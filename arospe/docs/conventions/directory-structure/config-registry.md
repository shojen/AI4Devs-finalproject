# Directory Structure — An app-owned config file is a registry

> Part of [Directory Structure](../directory-structure.md). **Read this part when:** you edit `config/modules.php` or `config/html-sanitizer.php`, or add any app-owned config file (no closures, keys not copy). The other parts are listed in the [hub](../directory-structure.md#table-of-contents).

### An app-owned config file is a registry, and must survive `config:cache`

Every other file in `config/` is Laravel's or a package's. [`config/modules.php`](../../../config/modules.php) (task 0013) is the first one this app wrote itself, and it establishes when that shape is right: **a config file is for a declarative registry that a later story extends by appending data — never for behavior, and never as a home for a value that has one caller.** The alternative considered and not taken was a PHP class or a service-provider `Gate::define()` loop; config won because appending an entry must not require reading code.

Two hard constraints come with it, both cheap to violate:

- **No closures, ever.** `php artisan config:cache` serialises the merged config with `var_export()`, which cannot represent a `Closure` — one closure anywhere in `config/` makes the command fail and, in a deployment that caches config, takes the whole app down. Every value must be a scalar, array, or `null`. Where a closure is the obvious reach (`'expanded_when' => fn () => request()->routeIs('roles.*')`), store the **data** instead (`'expanded_when' => 'roles.*'`) and let the consumer apply it. `tests/Feature/Navigation/SidebarModuleGatingTest.php` runs `config:cache` as an actual assertion rather than trusting review.
- **Store keys, not copy.** A registry entry holds a translation key (`'label' => 'navigation.items.users'`), resolved with `__()` at render. A literal English string in `config/` is unreachable from `lang/es/` — see [naming.md](../naming/translation-keys-and-booleans.md#translation-keys).

✅ Good — the real registry entry, quoted verbatim; every value is a scalar or array, `label` is a translation key rather than copy, and `current_when` is the *pattern* (the consumer applies `request()->routeIs()` to it at render):

```php
// config/modules.php
'roles' => [
    'group' => 'settings',
    'label' => 'navigation.items.roles',
    'icon' => 'shield-check',
    'route' => 'roles.index',
    'current_when' => 'roles.*',
    'permissions' => ['roles.manage'],
],
```

❌ Bad — the same entry written the way it is tempting to (adapted to illustrate; not present in the repo). It breaks `config:cache` outright, and hardcodes English into a file `lang/es/` cannot reach:

```php
// anti-pattern — do not write this in any config/ file
'roles' => [
    'label' => 'Roles & permissions',
    'current_when' => fn () => request()->routeIs('roles.*'),
    'visible' => fn () => auth()->user()?->can('roles.manage'),
],
```

> ✅ **Task 0018 is the first story to extend this file, and it is the evidence for the paragraph above.** The Sales Regions screen's whole navigation change is two array literals appended to `config/modules.php` — a `groups.taxes` group and an `items.sales_regions` entry — plus one leaf per locale in `lang/{en,es}/navigation.php`. **No PHP class changed, and neither did the component that reads the registry**: `resources/views/components/sidebar-nav.blade.php` and `resources/views/layouts/app/sidebar.blade.php` are untouched by the story, verified against the diff. Both constraints above held on first contact — every appended value is a scalar or array (the entry's `expanded_when` is a literal `null`, never a closure over `request()`), and both `heading` and `label` are translation keys rather than copy, so `lang/es/` reaches them. The one thing the story had to *decide* rather than copy is the entry's key: `sales_regions` is this registry's first genuinely multi-word key, and [naming.md](../naming/translation-keys-and-booleans.md#translation-keys) owns why it is snake_case on both sides.

> ⚠️ **[`config/html-sanitizer.php`](../../../config/html-sanitizer.php) (story 0024a) is the app's second app-owned config file, and it does not fit the "registry a later story extends by appending data" shape this section describes — say so explicitly rather than forcing it in.** `config/modules.php` exists to be *appended to*: every later epic adds its own group/item entry, and the file's whole value is that appending never touches behavior. `config/html-sanitizer.php` is the opposite kind of thing — a **fixed security allow-list**. Its own task file states the rule directly: when Epic 4's blog body needs the identical sanitizer, it must **reuse this configuration exactly, not fork or extend it** with a second allow-list, because two allow-lists for the same trust boundary drift apart silently. There is no "later story adds a row" shape here at all — a later story is a *second consumer* of the whole file, never a *second contributor* to it. Both hard constraints from the paragraph above still hold and were verified rather than assumed: **no closures** — every value in the file is a scalar, string, or array of scalars/strings (`allowed_elements` maps tag names to attribute-name arrays, `dropped_elements`/`allowed_link_schemes`/`allowed_media_schemes` are plain string lists, `default_action` and `max_input_length` are a string and an int) — and **no user-facing copy**, which the file's own top-of-file comment states explicitly does not need the "translation key, not literal copy" half of the rule at all, since this file carries no copy of any kind, translatable or not. `App\Actions\Products\SanitizeProductDescription` is the one class that reads it, matching the "one config file, one reading component" shape `config/modules.php` established.

What this particular registry *means* — the gating rules, the per-entry ability requirement, and how a later epic plugs its module in — belongs to [architecture/authorization.md](../../architecture/authorization/how-to-gate.md#the-second-half-of-a-module-gate-the-sidebar-registry), not here.
