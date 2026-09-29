# Authorization Patterns — Super Admin bypass, permission cache and guard arguments

> Part of [Authorization Patterns](../authorization-patterns.md). **Read this part when:** you touch `Gate::before`, permission-cache flushing, `hasRole()` guards, the Super Admin role-name default, or `permission:`/`role:` middleware. The other parts are listed in the [hub](../authorization-patterns.md#table-of-contents).

## The Super Admin bypass does not cover every check

The bypass installed in [`app/Providers/AppServiceProvider.php`](../../../app/Providers/AppServiceProvider.php)
is a **`Gate`** hook:

```php
// app/Providers/AppServiceProvider.php
protected function configureAuthorization(): void
{
    Gate::before(function (mixed $user): ?bool {
        if (! $user instanceof User) {
            return null;
        }

        $superAdminRoleName = config('auth.super_admin.role', 'Super Admin') ?? 'Super Admin';

        return $user->hasRole($superAdminRoleName, 'web') ? true : null;
    });
}
```

`Gate::before` only runs for calls that route through Laravel's Gate. Spatie's own `HasRoles` /
`HasPermissions` methods query the user's relations directly and never consult the Gate. The resulting
coverage matrix is **not** intuitive, and was verified against the vendored middleware:

| Check | Goes through the Gate? | Super Admin bypassed? |
| --- | --- | --- |
| `$user->can('products.delete')` / `Gate::allows(...)` / `authorize(...)` | yes | ✅ |
| `@can('products.delete')` in Blade | yes | ✅ |
| `->middleware('permission:products.delete')` | yes — `PermissionMiddleware` calls `$user->canAny()` | ✅ |
| `->middleware('role_or_permission:Super Admin\|roles.manage')` | yes — calls `$user->canAny()` first | ✅ |
| `->middleware('role:Administrator')` | **no** — `RoleMiddleware` calls `$user->hasAnyRole()` | ❌ **403 for a Super Admin** |
| `$user->hasPermissionTo('products.delete')` | **no** | ❌ |
| `$user->hasRole('Administrator')` | **no** | ❌ |
| `@role('Administrator')` / `@hasPermission(...)` Blade directives | **no** | ❌ |

✅ Good — gate a route or component on a **permission**, so the Super Admin bypass applies:

```php
Route::livewire('roles', Index::class)->middleware('can:roles.manage')->name('roles.index');
```

❌ Bad — gating on a role name locks the Super Admin out of the screen it is supposed to own:

```php
// anti-pattern — a Super Admin holds no Administrator role and hasAnyRole() ignores Gate::before
Route::livewire('roles', Index::class)->middleware('role:Administrator');
```

**Rule.** Gate on permissions (`can:`, `permission:`, `$user->can()`), not on role names. If a role
check is genuinely required, it must explicitly admit the Super Admin as well —
`role_or_permission:Super Admin|<permission>` is the safe form, because its `canAny()` call reaches the
Gate. Never "fix" a Super Admin lockout by granting the Super Admin a second role or by weakening the
gate: that dissolves the single-source-of-truth property the bypass exists to provide.

## Flush the permission cache after the transaction commits, never inside it

`config/permission.php` caches the whole roles+permissions graph for **24 hours** on the
**`database`** cache store (`CACHE_STORE=database`), which is **shared across every web worker** —
it is not a per-process cache. `PermissionRegistrar::loadPermissions()` fills that cache with
`Cache::remember(...)`, so whichever process misses first writes the snapshot every other process
then reads for 24 hours.

That makes the *ordering* of `forgetCachedPermissions()` relative to a transaction commit
security-relevant:

❌ Bad — flushing inside the transaction leaves a window in which a concurrent web request can miss
the cache, read the **pre-commit** (old) rows, and cache them for 24 hours:

```php
// anti-pattern
DB::transaction(function (): void {
    // ... write roles/permissions/grants ...
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}); // <-- a concurrent request between the flush and this COMMIT re-caches the OLD graph
```

This fails **open** whenever the write was a *revocation*: a re-seed that strips an over-granted
permission from a role appears to succeed, while every worker keeps honouring the escalated grant
until the cache expires.

✅ Good — flush again *after* the commit, so the re-populated cache can only ever be filled from
committed state:

```php
DB::transaction(function (): void {
    // ... write roles/permissions/grants ...
    // An in-transaction flush is still needed here so syncPermissions() resolves the
    // permissions this transaction just inserted rather than a stale in-memory collection.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // ...
});

app(PermissionRegistrar::class)->forgetCachedPermissions();
```

**Rule.** Any transaction that writes `roles`, `permissions`, or `role_has_permissions` must call
`app(PermissionRegistrar::class)->forgetCachedPermissions()` **after** `DB::transaction()` returns
(or via `DB::afterCommit()`), in addition to any flush it needs *inside* the transaction for its own
correctness.

Three real call sites hold this shape today: [`database/seeders/RolePermissionSeeder.php`](../../../database/seeders/RolePermissionSeeder.php)
(the ✅ above is its structure), and — since task 0012's Phase 4 audit found them missing it —
`saveRole()` and `deleteRole()` in [`app/Livewire/Roles/Index.php`](../../../app/Livewire/Roles/Index.php).

> ⚠️ **The flush this rule is about is usually one nobody wrote.** Both roles-screen methods were
> already correct before task 0010 wrapped them in `DB::transaction()` for an unrelated finding: the
> only flush on either path is the vendor's own, fired from inside `syncPermissions()` and from
> `Role`'s `deleted` event. Introducing the transaction moved *that* flush pre-commit without any line
> being added, removed or reordered — the diff contained no flush at all, so a review looking for one
> found nothing to check. **Adding a `DB::transaction()` around existing code relocates every side
> effect that code already performed**, so treat it as a change to each of them. See
> [errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#wrapping-existing-code-in-a-dbtransaction-moved-a-cache-flush-nobody-had-written--2026-08-21).

**Testing caveat.** `phpunit.xml` sets `CACHE_STORE=array`, so the permission cache is per-process in
tests. No test in this suite can reproduce the cross-worker window above — it must be prevented by
construction, not caught by a test.

## Always pass the guard to hasRole() / hasAnyRole()

`Spatie\Permission\Traits\HasRoles::hasRole()` only filters by guard when a guard is **explicitly
passed**:

```php
// vendor/spatie/laravel-permission/src/Traits/HasRoles.php
if (is_string($roles)) {
    return $guard
        ? $this->roles->where('guard_name', $guard)->contains('name', $roles)
        : $this->roles->contains('name', $roles);   // <-- no guard filter
}
```

A role row named `Super Admin` on **any** guard therefore satisfies `hasRole('Super Admin')`. The
`roles` table's unique index is on `(name, guard_name)`, so a second `('Super Admin', 'api')` row is
creatable at the database level.

**Rule.** Security-critical role checks pass the guard explicitly:

```php
$user->hasRole(config('auth.super_admin.role', 'Super Admin'), 'web');
```

Correspondingly, any code path that creates roles must pin `guard_name` to `web` and must not expose
it as user input.

## Read the Super Admin role name with a literal default

`config('auth.super_admin.role')` returns `null` if the key is absent — most realistically when a
deployment serves a `bootstrap/cache/config.php` built **before** the `super_admin` block was added to
`config/auth.php`. `hasRole(null)` matches none of the trait's type branches and reaches its final
`throw new TypeError(...)`, so **every** authorization check in the app throws — a full outage, not a
degraded mode.

**`config()`'s own `$default` argument is not enough.** This is the non-obvious part, and it is why the
shipped code carries what looks like a redundant second fallback. `config($key, $default)` delegates to
`Arr::get()`, which substitutes `$default` only when a key segment is **missing** — a segment that
*exists* and holds `null` is returned as `null`:

```php
// vendor/laravel/framework/src/Illuminate/Collections/Arr.php
foreach (explode('.', $key) as $segment) {
    if (static::accessible($array) && static::exists($array, $segment)) {
        $array = $array[$segment];   // <-- present-but-null returns null, NOT $default
    } else {
        return value($default);
    }
}
```

`Arr::exists()` is `array_key_exists()`, so `'role' => null` counts as present. A config file (or a
cached config, or a test's `config(['auth.super_admin.role' => null])`) that sets the key explicitly to
`null` therefore still reaches `hasRole(null, 'web')` and still throws.

**Rule.** When reading a config value that must never be `null` at the call site, supply **both**
fallbacks — `config()`'s default for the missing-key case and `??` for the present-but-null case:

```php
$superAdminRoleName = config('auth.super_admin.role', 'Super Admin') ?? 'Super Admin';

$user->hasRole($superAdminRoleName, 'web');
```

Do not "simplify" this to one of the two. They cover different failure modes.

Since task 0008 the shipped instance of this pattern lives in exactly one place —
`App\Models\Role::superAdminName()` — and `AppServiceProvider`'s `Gate::before` bypass calls it rather
than inlining its own copy, so the snippet above is now the *rule*, not a quotation of the code. That
centralisation is itself the point: with two copies, dropping the `??` from one would have left the
bypass granting the role while every guard, scope, policy and seeder protected nothing.

## Gate::before closures must tolerate any authenticatable

Laravel decides whether to invoke a before-callback **before** PHP type-checks it:

```php
// vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php
protected function canBeCalledWithUser($user, $class, $method = null)
{
    if (! is_null($user)) {
        return true;   // <-- the callback's parameter type is never consulted here
    }
    // ... guest handling ...
}
```

So a closure typed `fn (User $user)` is still called for *any* non-null authenticatable and raises a
`TypeError` if a second guard/provider ever resolves a different model.

The parameter type **does** still decide one thing: whether the callback runs for **guests**.
`callbackAllowsGuests()` reflects on the first parameter and skips the callback when no user is
authenticated *unless* the type allows null. `mixed` allows null, so the shipped closure is also invoked
with `$user === null` on every guest check — which is harmless precisely because the `instanceof` guard
is the first statement and returns `null` for it. Do not rely on the type hint to keep any class out of
the body; guard inside it.

```php
// app/Providers/AppServiceProvider.php — the shipped form
Gate::before(function (mixed $user): ?bool {
    if (! $user instanceof User) {
        return null;
    }

    $superAdminRoleName = config('auth.super_admin.role', 'Super Admin') ?? 'Super Admin';

    return $user->hasRole($superAdminRoleName, 'web') ? true : null;
});
```

**Rule.** A `Gate::before` callback in this project returns only `true` or `null` — **never `false`**,
which would hard-deny every other user before their real permissions were consulted — and never
assumes the authenticatable is `App\Models\User`. Enforce that with an `instanceof` check in the body,
not with a parameter type hint.

## permission: and role: middleware are not a substitute for auth

Spatie's middleware throws `UnauthorizedException::notLoggedIn()` for an unauthenticated request,
which renders as a bare **403** rather than a redirect to the login screen. It also resolves the user
from `Auth::guard($guard)` independently of any `auth` middleware.

**Rule.** Every route or route group gated with `permission:`, `role:`, or `role_or_permission:` must
also carry `auth` (and `verified`, matching `routes/web.php`'s existing dashboard group). Apply the
gate on the group, not per-route, so a route added later cannot be forgotten:

```php
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::middleware('permission:products.view')->group(function (): void {
        // ...
    });
});
```
