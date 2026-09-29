# Authorization Patterns — Confirmed-safe patterns

> Part of [Authorization Patterns](../authorization-patterns.md). **Read this part when:** you want the audited-and-confirmed-safe cases: sidebar `Gate::any()`, `can:` 403 wording vs `APP_DEBUG`, role-name collision guards. The other parts are listed in the [hub](../authorization-patterns.md#table-of-contents).

## Confirmed safe: a sidebar built on `Gate::any()` inherits the Super Admin bypass, and both refusal paths fail closed

Recorded from story 0013's Phase 4 audit so a later epic plugging its module into `config/modules.php`
does not re-derive any of it. Every claim below was verified against the installed vendor source plus
a live render, not reasoned from the package's docs.

- **`Gate::any()` runs the whole `before`-callback chain.** `Gate::any()` → `check()` → `inspect()` →
  `raw()`, whose first act is `callBeforeCallbacks()`. So the sidebar filter and the route's `can:`
  middleware traverse the *identical* mechanism — they cannot disagree about guard resolution, about
  wildcard handling, or about the Super Admin. This is what makes the story's "no sidebar-local special
  case for the Super Admin" criterion true rather than coincidental.
- **The two `Gate::before` callbacks compose in either registration order.** Spatie's
  (`PermissionRegistrar::registerPermissions()`) returns `checkPermissionTo($ability) ?: null` — `null`
  on failure, never `false` — so it never short-circuits the chain, and this app's Super Admin closure
  in `AppServiceProvider` still gets its turn. A `before` callback that returned a hard `false` would
  break this; none does.
- **`hasAnyPermission()` would have been the inverse of the requirement**, and this is a correctness
  fork rather than a style preference: it is a `HasPermissions` trait method that queries the model's
  own relations and never reaches the Gate, so the Super Admin — who holds *zero* permission rows by
  design — would see an empty sidebar. Same for `hasPermissionTo()` and `hasRole()`.
- **An ability that is not a seeded permission denies rather than throws.** `checkPermissionTo()`
  catches `PermissionDoesNotExist` and returns `false`. A registry entry naming a permission the
  catalog does not hold therefore hides itself from everyone but the Super Admin — silently, which is
  the safe direction but is worth knowing when an entry mysteriously never appears.
- **A guest never reaches a positive result.** `Gate::any()` resolves a `null` user; Spatie's callback
  is skipped (its first parameter is typed `Authorizable`, non-nullable), this app's closure declines
  on `! $user instanceof User`, and an ability with no registered callback resolves to `null` → denied.
  The layout itself is unreachable while signed out anyway (`auth()->user()->name` on line 22), so this
  is a second line, not the first.
- **The rendered markup is escaped in every position.** Verified by rendering the component with
  hostile values injected into the group `heading`, the item `label`, the `class`, and both the group
  and item **array keys** (which land inside `data-test="…"`): all four emit HTML-entity-escaped output.
  The `wire:navigate` on each item is a bare directive with no interpolated argument, so
  [the `@js()` rule for `wire:*` values](../blade-livewire-output-encoding.md#--inside-a-wire-directive-is-not-escaping--it-is-an-injection-sink)
  is not engaged here — it *would* be the moment an entry needs `wire:click="…($id)"`.
- **`flux:sidebar.group` renders its slot twice when it is both `expandable` and carries an `icon`** —
  exactly the "Settings" group. `data-test="sidebar-link-roles"` therefore appears **2×** in the real
  HTML while `data-test="sidebar-group-settings"` appears 1× (the group's `$attributes` land only on
  the `<ui-disclosure>` wrapper, not on the collapsed-sidebar `<flux:dropdown>` duplicate). Confirmed by
  counting the real render. Presence/absence assertions are unaffected; a **count** assertion would be
  off by a constant and read as correct — see [errors-log.md](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-count-based-assertion-over-rendered-html-counted-a-wrapper-element-it-never-meant-to-include--2026-08-21).

## Confirmed safe: a `can:`-gated route's 403 names no permission — and `APP_DEBUG` is not what makes that true

Established by task 0012's Phase 4 audit, which had to independently verify the "the refusal discloses
no permission name" acceptance criterion every future module route will inherit. The conclusion holds,
but **not for the reason it is intuitive to assume**, and the difference decides what can break it.

`can:<ability>` is `Illuminate\Auth\Middleware\Authorize`, which throws `AuthorizationException`.
`Handler::prepareException()` converts that to a Symfony `AccessDeniedHttpException` carrying the
generic message `This action is unauthorized.` — the ability string is never part of the exception.
From there:

```php
// vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php — prepareResponse()
if (! $this->isHttpException($e) && config('app.debug')) {
    return $this->toIlluminateResponse($this->convertExceptionToResponse($e), $e)->prepare($request);
}
// ... otherwise: renderHttpException($e) -> the errors::403 view
```

An `AccessDeniedHttpException` **is** an `HttpException`, so that first branch is unreachable for it:
a 403 is rendered by `errors::403` at *every* debug setting, and that view's whole body is
`@section('message', __($exception->getMessage() ?: 'Forbidden'))`. Verified by rendering the real
handler against the real `users.index` route rather than by reading: the HTML response is
**byte-identical (6605 bytes) with `app.debug` true and false**, and contains neither `users.view` nor
`can:users.view` nor the word `middleware` in either case.

That last point matters because the framework's debug error page **does** render the route's middleware
list — `Exception::applicationRouteContext()` returns `'middleware' => implode(', ', $route->gatherMiddleware())`,
which for this route is the literal `web, auth, verified, can:users.view`. It is simply never reached
by a 403. So:

- ❌ Do **not** write a test (or a comment) claiming `config(['app.debug' => false])` is what keeps the
  ability name out of a 403 body. It is inert on this path, and stating it hides the real mechanism.
- ✅ Do assert the **positive** half as well as the negative one. `assertForbidden()` plus
  `assertDontSee('users.view')` are *both* satisfied by an empty body, so the pair proves nothing on
  its own; add `assertSee('This action is unauthorized.')` so the test pins that the generic error page
  rendered and that the message is still the generic one.

**Rule.** The guarantee rests on exactly two things, and a change to either is what a future audit must
look for — not at `APP_DEBUG`:

1. **No app-owned `resources/views/errors/403.blade.php` exists.** `getHttpExceptionView()` prefers an
   application view over the framework's. A branded 403 that renders anything beyond the message (route
   context, a "you need X" hint, a debug dump) reopens the disclosure, and it is a *frontend* story that
   would do it.
2. **No gate on these routes returns `Response::deny('…')` with a message naming an ability.** The 403
   body is the exception message, so a custom deny string is printed verbatim to the refused actor. The
   two `assertDontSee` tests in `tests/Feature/Authorization/ModuleRouteAccessTest.php` do catch this
   one — keep them when adding a module gate.

One genuinely debug-dependent path remains, and it is not a permission disclosure:
[`bootstrap/app.php`](../../../bootstrap/app.php) registers
`shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())`, and
`convertExceptionToArray()` adds `exception`, `file`, `line` and a full `trace` to a JSON 403 when
`app.debug` is true. Frame **arguments are stripped**, so the ability string still does not appear
(verified) — but absolute paths and vendor frames do. `.env.example` ships `APP_DEBUG=true`, so this is
one more reason a deployment must pin `APP_DEBUG=false`; it is not a reason to treat the HTML 403 as
debug-sensitive.

## Confirmed safe: role-name collision is closed by a creation/rename guard, not by the database alone

Worth recording because the reasoning is non-obvious and someone will re-open the question. The
Super Admin bypass keys on a **name string**, which invites the question "can an attacker create a
colliding role?". Two facts about string comparison still hold and are worth keeping in mind:

- **PHP-side comparisons are byte-exact.** `==`/`===` against a non-numeric string in PHP 8 is
  case-sensitive with no normalisation. `super admin`, `SUPER ADMIN`, and `Super Admin ` (trailing
  space) all **fail** to match `'Super Admin'`.
- **Database-side, `roles` carries `unique(name, guard_name)`** under `utf8mb4_unicode_ci`
  (`config/database.php`), which is case- **and** accent-insensitive. Any variant that PHP *would*
  match must be byte-identical, and any byte-identical row on the *same* `(name, guard_name)` pair is
  rejected as a duplicate.

**What this does NOT close on its own — corrected during task 0008's Phase 4 re-audit (finding F3).**
An earlier version of this section concluded "the only string that grants the bypass is already
occupied by the seeded row," reasoning from the unique index alone. That conclusion stopped being true
the moment `config('auth.super_admin.role')` became overridable (task 0008): the unique index only
forbids a *second* row sharing an already-occupied `(name, guard_name)` pair — it says nothing about a
role being created or renamed to match whatever name `superAdminName()` currently resolves to, and
before task 0008's Phase 3 landed, nothing else stopped that either. Verified directly: with no guard
in place, both `Role::create(['name' => 'Super Admin', ...])` and renaming an ordinary role into that
name succeeded and the resulting role inherited the full `Gate::before` bypass — reachable on a fresh
install before seeding, or whenever the config names a role that hasn't been seeded yet.

**What actually closes it today.** `App\Models\Role::boot()` registers a `creating` listener and the
post-mutation half of its `updating` listener (`guardAgainstAssumingSuperAdminName()`), both of which
throw `ImmutableRoleException` when a role's in-memory `name` equals `Role::superAdminName()` at save
time — refusing the role from ever being *created with*, or *renamed into*, the currently-configured
Super Admin name. The one sanctioned exception is `Role::firstOrCreateSuperAdminRole()`
(`RolePermissionSeeder`'s call site), which bypasses model events via `withoutEvents()` specifically to
create the real row. The database's `unique(name, guard_name)` index remains a second, independent
backstop for the narrower case these two facts above describe (an exact-byte-match duplicate on an
*already-occupied* pair) — it is not what stops acquisition of a currently-unoccupied configured name.
Do not "harden" the name comparisons themselves by lowercasing or trimming — that would *widen* the set
of matching names and break the property the byte-exact match above relies on. The remaining hardening
is guard-scoping (see [Always pass the guard](bypass-cache-and-guards.md#always-pass-the-guard-to-hasrole--hasanyrole)).

_Last updated: 2026-08-24 — Task 0015 (Users CRUD security hardening): added **"A rate limit keyed on the target alone becomes an attack on the target the moment a second caller exists"** (finding F6 part 2) — the `RequestEmailChange` limiter that was correct while `Settings\Profile` was its only caller and became a quota-burn vector when task 0004 added a cross-user one, with the table showing why **neither** obvious fix works alone, the four decisions in the shipped two-limiter shape (check the narrower key first because `RateLimiter::attempt()` consumes on success; `Auth::id() ?? 'unauthenticated'` and never `$user->getKey()`; the aggregate ceiling must exempt the target's **own** request, re-audit finding F-A; and an exemption that removes a backstop needs a dedicated test for the surviving control, re-audit finding L-1). Added a **Confirmed safe** block to **"Authorization that consults a relation must reload it before the first check reads it"**: this story's audit-log capture (finding F5) makes `App\Livewire\Users\Index::updateExistingUser()` a **second** caller that pre-hydrates `roles` one statement above the `UpdateUser` call, safe **only** because `__invoke()` uses `load()` rather than `loadMissing()` — so that clause is now load-bearing against this repo's own code rather than against a hypothetical caller. Nothing else on this page changed meaning; the disclosure-gate rule this story also produced belongs to [livewire-authorization.md](../livewire-authorization/entry-point-and-method-gates.md#the-shipped-disclosure-gates-and-why-the-disclosure-check-is-the-stronger-ability) and is not duplicated here._

_Earlier revision notes: [security--authorization-patterns--confirmed-safe.md](../../history/security--authorization-patterns--confirmed-safe.md)._
