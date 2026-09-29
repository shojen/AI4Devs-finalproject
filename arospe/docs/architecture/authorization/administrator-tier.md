# Authorization — Administrator tier and middleware aliases

> Part of [Authorization](../authorization.md). **Read this part when:** you touch the Administrator tier's identity/immutability rules or the route middleware aliases. The other parts are listed in the [hub](../authorization.md#table-of-contents).

## The Administrator tier's identity

Task 0008a, extended by task 0010. Where the section above makes the `Super Admin` **role** immutable, this one answers a narrower question that had five independent answers before 0008a landed: *is this role the Administrator tier?* The literal `'Administrator'` was written in `App\Livewire\Users\Index`, three times in `UserPolicy`, and in the seeder — so the tier's identity could be half-changed. It is now written **once**, and the authorization built on it moved out of the component and into the actions. Task 0010 then closed the gap that centralized identity left open: the row carrying that identity is now **immutable in name and undeletable**, because a screen that could rename or delete it finally exists.

### Why this tier is *not* config-driven, unlike the Super Admin one

The asymmetry with [`superAdminName()`](super-admin.md#one-name-one-resolution-path) is a decision, not an oversight, and re-adding symmetry would be a regression:

- `auth.super_admin.role` exists because the `Gate::before` bypass **already read a config key**. Leaving the guards on an independent literal meant an override could split them apart — the whole point of task 0008.
- The Administrator role's name is **locked and uneditable** by product decision (recorded across Epic 1's stories), so there is no second source that could disagree. `RoleName::Administrator` *is* the source of truth. Since task 0010 that lock is **enforced in code**, not merely asserted — see [the next subsection](#the-administrator-tiers-immutability-name-locked-undeletable-permissions-still-editable).

**Do not add `config('auth.administrator.role')`, an `'administrator'` block in `config/auth.php`, or an `administratorName()` resolver.** A config key is an override capability, and the locked-name decision rules one out. A content-scan test (`tests/Feature/Users/AdministratorRoleLiteralContentScanTest.php`) fails if one reappears, alongside asserting no `'Administrator'` / `'Super Admin'` literal survives in the guard path.

### The Administrator tier's immutability: name locked, undeletable, permissions still editable

Task 0010, Phase 4 finding **F1** (human-confirmed decision). Through task 0009, the Administrator tier had a centralized *identity* but a fully mutable *row*: nothing locked its name and nothing blocked its deletion, because until 0010 no application code could reach either operation. The roles-management screen is that code, and the audit demonstrated both paths live — a `roles.manage-administrators` holder could **rename** the seeded role (silently demoting it for every `isAdministratorRole()` check in the app: `UserPolicy`, `CreateUser`, `UpdateUser`), or **delete** it once it had no holders.

The protection is deliberately **narrower than the Super Admin role's**, and the difference is the whole point of the tier:

| Operation | `Super Admin` | `Administrator` |
| --- | --- | --- |
| Delete the row | ❌ refused | ❌ refused (`guardAgainstAdministratorDeletion()`) |
| Rename the row | ❌ refused | ❌ refused (`guardAgainstRenamingAdministrator()`) |
| Create/rename another role **into** the name | ❌ refused | ❌ refused (`guardAgainstAssumingAdministratorName()`) |
| `syncPermissions()` / `givePermissionTo()` / `revokePermissionTo()` | ❌ refused in every direction | ✅ **allowed** |
| Assign the role to a user | ❌ refused | ✅ allowed, gated by `roles.manage-administrators` |

The permission row is the load-bearing asymmetry: [`EnforceAdministratorPermissionGrant`](grant-meta-rules-and-ui-hints.md#who-may-grant-a-permission--the-meta-rule-layer) exists precisely so a Super-Admin-authorized actor **can** change what `Administrator` grants. That is why `guardAgainstAdministratorDeletion()` is its own guard rather than a branch folded into `guardAgainstSuperAdminMutation()` — the latter also blocks every permission-pivot mutation, which must stay open here.

Three implementation details that are easy to get wrong:

- **`guardAgainstRenamingAdministrator()` is scoped to `isDirty('name')`**, unlike the Super Admin mutation guard, which fires on any update. Without that scope it would refuse the ordinary saves that legitimately touch the row.
- **The rename guard reads the row's *persisted* name** (`isAdministratorRole()` → `persistedName()`), while `guardAgainstAssumingAdministratorName()` reads the *in-memory* one. Same "two helpers pointing in opposite directions" rule as the Super Admin pair — see [the two identity helpers](super-admin.md#the-two-identity-helpers-read-different-sources-and-must-never-be-merged).
- **`RolePolicy::delete()` diverges from `update()` for this tier.** `update()` gates the Administrator row on `roles.manage-administrators`; `delete()` refuses it **categorically**, like the Super Admin row, because it is never deletable at all. Do not "restore symmetry" between the two methods.

⚠️ **`RolePolicy::delete()`'s Administrator branch is unreachable for a Super Admin actor** (task 0010 Phase 4 round-2 finding N3). The `Gate::before` closure only defers when the ability's *target* is the **Super Admin** role, so for a Super Admin actor targeting the Administrator row it returns `true` unconditionally and the policy method never runs — the **model-event guard** is what actually refuses that delete. Both paths render 403, so the behaviour is correct; the consequence, **shipped and test-covered since task 0011**, is that the roles list's per-row `Gate::allows()` UI hint renders that one action enabled for that one actor. It is the same accepted enabled-then-refused drift already documented for the Users screen (see [`Gate::allows()` in a list query](grant-meta-rules-and-ui-hints.md#gateallows-in-a-list-query-is-a-ui-hint-not-a-layer)), and the general rule behind it is that [a rule which must bind a Super Admin actor cannot go through `Gate`](#a-rule-that-must-bind-a-super-admin-actor-cannot-go-through-gate).

The three actor tiers this produces on the `Administrator` row are pinned as a dataset in `tests/Feature/Roles/IndexUiTest.php`, and the middle one's two abilities deliberately **disagree** — do not "fix" the policy to make them symmetric:

| Actor | `canEdit` | `canDelete` | Why |
| --- | --- | --- | --- |
| plain `roles.manage` holder | `false` | `false` | `update()` requires `roles.manage-administrators`; `delete()` refuses categorically |
| `roles.manage-administrators` holder, not the Super Admin | **`true`** | **`false`** | `update()`'s tier branch passes; `delete()`'s categorical refusal has no permission escape hatch |
| Super Admin | `true` | `true` | `Gate::before` bypasses both — and the delete then 403s on click at the model guard (the drift above) |

The durable, generalizable rule this produced — an identity derived from a mutable column must be made immutable at the model layer as soon as code exists that can mutate it — is in [security/authorization-patterns.md](../../security/authorization-patterns/payload-omission-and-registries.md#an-identity-derived-from-a-mutable-column-must-be-locked-once-code-exists-that-can-mutate-it).

### One predicate, two shapes

```php
// app/Models/Role.php
public static function isAdministratorRole(self $role): bool
{
    return $role->persistedName() === RoleName::Administrator->value;
}

public static function isSuperAdminRoleRow(self $role): bool
{
    return $role->persistedName() === self::superAdminName();
}
```

| Input in hand | Read it as | Call sites |
| --- | --- | --- |
| a `Role` **row** | `Role::isAdministratorRole($role)` / `Role::isSuperAdminRoleRow($role)` | `CreateUser`, `UpdateUser`, and — since task 0009 — [`RolePolicy`](policies-users-roles.md#rolepolicy--the-second-policy)'s two branches plus the [`Gate::before` deferral](super-admin.md#the-super-admin-bypass), all of which consume these helpers rather than defining a comparison of their own |
| a role **name string** | `RoleName::Administrator->value` / `Role::superAdminName()` | `UserPolicy`'s five `hasRole()` calls, `RolePermissionSeeder` |

Four properties are load-bearing:

- **`public static`, on the model.** `UserPolicy`, both user actions and (since 0009) `RolePolicy` and `AppServiceProvider` are separate classes with no shared base; a private policy-local helper would leave two independent comparisons for one concept, which is the duplication this story removed.
- **Exact `===`, never `LIKE`, `strcasecmp`, or a "contains" match.** `Administrador Regional`, a lowercase `administrator`, and a custom role holding *every* permission the seeded `Administrator` holds are all ordinary roles, freely assignable with `users.edit` alone. Administrator-level is defined by the role's name, not by its permission set — a deliberate, PRD-scoped limitation pinned by tests rather than left to accident.
- **They take a `Role`, so a name string cannot be passed by mistake.** An action holding a `string $roleId` resolves it with a full `Role::query()->find((int) $roleId)` — never a `select('id')`, and never the id-to-id comparison against a `where('name', …)->value('id')` lookup that `Index::administratorRoleId()` used to do (that method is deleted). A `null` row is not administrator-level; nothing can be promoted into a role that does not exist, and `syncRoles()` fails on its own afterwards.
- **Both read `persistedName()`, so a partially-hydrated row answers protectively.** That method is the extraction of what `isSuperAdminRole()` already did (see [the two identity helpers](super-admin.md#the-two-identity-helpers-read-different-sources-and-must-never-be-merged)), so there is one implementation of "read this row's real name". Consequences: `Role::query()->select('id')->find($id)` on the seeded Administrator role still answers `true`, and a row renamed *in memory* but not saved still answers by what is persisted — the rename-in-flight case resolves protectively. The alternative, documenting a "callers must fully hydrate" obligation, was rejected: unenforceable, invisible at the call site, and fails **open** when forgotten.

### The guard belongs to the action, not to the caller

Before this story the Administrator-level authorization lived only in `App\Livewire\Users\Index`, so a future API endpoint, Artisan command or queued job calling `CreateUser` / `UpdateUser` would have been completely ungated. Both actions now authorize **the whole operation** themselves, before any write:

| Action | Authorizes |
| --- | --- |
| `CreateUser` | `Gate::authorize('create', User::class)`; a direct throw if the submitted role is the Super Admin role; `Gate::authorize('promoteToAdministrator', User::class)` (class-level) if the submitted role is the Administrator role |
| `UpdateUser` | `$user->load('roles')`, then `Gate::authorize('update', $user)` unconditionally — including on a self-edit, so a name-only edit can no longer reach a write unauthorized. Then, for a non-self-edit: direct throws if the target *currently holds* or the submission *assigns* the Super Admin role, `promoteToAdministrator` / `downgrade` on an actual tier change, and `updateSensitiveAttributes` when the email or status actually changed |

The component keeps its own `Gate::authorize()` calls in `save()` / `deleteUser()` / `mount()` (see [Gate::authorize at the call site](grant-meta-rules-and-ui-hints.md#gateauthorize-at-the-call-site-not-only-at-the-route)) — that is now defence in depth rather than the only layer. What it no longer holds is a *second implementation* of the tier rules: `authorizeRoleChange()` and `administratorRoleId()` were deleted, not converted.

Four semantics survived the relocation unchanged and are pinned by tests, because each is easy to lose in transit: a **no-op** role re-save is neither a promotion nor a downgrade and needs no extra gate; the target's current role is read **fresh**, never from a possibly-stale cached relation; `updateSensitiveAttributes` fires only on an *actual* email or status change, compared against `getRawOriginal()` rather than the in-memory attribute; and **all authorization runs before the first write** — `UpdateUser`'s writes are additionally wrapped in `DB::transaction()`.

`UpdateUser` also **derives the self-edit guard itself** (`Auth::user()?->is($user) ?? false`) instead of accepting the `bool $applyRoleAndStatus` parameter the component used to pass. A caller-supplied self-lockout guard is only a guard while every caller computes it correctly; once the action is independently callable, `applyRoleAndStatus: true` on a self-targeting update is a one-argument bypass.

> **Known design consequence, verified non-reachable today.** These actions authorize against the *authenticated* user, and `Gate::authorize()` with no resolved user **denies** — fail-closed, and therefore safe. A genuinely unauthenticated caller (a seeder, a console command provisioning a first Administrator) cannot use them as-is and would need an explicit, deliberately-designed bypass rather than a quietly-relaxed guard. Nothing in `database/seeders/` or `app/Console/Commands/` calls either action today.

### A rule that must bind a Super Admin actor cannot go through `Gate`

The Super Admin refusals in both actions are **direct `throw new AuthorizationException(...)` statements, never `Gate::authorize()`** — and that is the single most important line to preserve if either action is ever refactored:

```php
// app/Actions/Users/UpdateUser.php — authorizeRoleAndStatusChange()
if ($currentRoles->contains(fn (Role $role): bool => Role::isSuperAdminRoleRow($role))) {
    throw new AuthorizationException('A Super Admin holder cannot be modified through this action.');
}

$submittedRole = Role::query()->find((int) $roleId);

if ($submittedRole !== null && Role::isSuperAdminRoleRow($submittedRole)) {
    throw new AuthorizationException('The Super Admin role cannot be assigned.');
}
```

[The `Gate::before` bypass](super-admin.md#the-super-admin-bypass) grants a Super Admin actor **before any policy method runs**, so routing either refusal through the Gate would make it inert for exactly the actor it most needs to bind. This is the same reasoning that puts the role model's own invariants in [layers 1 and 2](super-admin.md#three-guard-layers) rather than in `RolePolicy`. The general rule, with the ✅/❌ pair, is in [security/authorization-patterns.md](../../security/authorization-patterns/ability-coverage-and-guards.md#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check).

Two consequences worth stating so neither is rediscovered as a surprise:

- **One dashboard behaviour changed deliberately.** Before 0008a, a Super Admin actor editing another Super Admin-holding user through the Users screen **succeeded** (`Gate::before` bypassed `UserPolicy::update()`'s exclusion and nothing else checked). It now throws. That is the intended outcome of the hardening, not a regression.
- **The delete path has one such guard now, and it covers only the self case.** Task 0015 gave `App\Livewire\Users\Index::deleteUser()` a direct `$target->is(Auth::user())` no-op — same reasoning, different rule: it binds a Super Admin actor because it is not a `Gate` question. `UserPolicy::delete()`'s Super Admin-*target* exclusion is still policy-level only, so a Super Admin actor can still delete **another** Super Admin holder. See the second ⚠️ in [Known limitations](super-admin.md#known-limitations--what-is-not-closed); the asymmetry is accepted and was out of 0008a's scope, not an oversight.

### The seeder writes the same name the guards read

Task 0008a moved this line off the `'Administrator'` literal and onto `RoleName::Administrator->value`, with a `throw_unless()` read-back beside it in the seeder. **Task 0010 relocated both onto the model**, because its own `creating` guard would otherwise have refused the seeder's `firstOrCreate()` outright — the seeder now calls a sanctioned factory method that carries the read-back internally:

```php
// database/seeders/RolePermissionSeeder.php
$administratorRole = Role::firstOrCreateAdministratorRole();
```

```php
// app/Models/Role.php
public static function firstOrCreateAdministratorRole(): self
{
    $role = static::withoutEvents(fn (): self => static::firstOrCreate(
        ['name' => RoleName::Administrator->value, 'guard_name' => 'web'],
    ));

    throw_unless(
        $role->getRawOriginal('name') === RoleName::Administrator->value,
        ImmutableRoleException::class,
        // ...
    );

    return $role;
}
```

The read-back is the compensating control for a real collation hazard, and it is why the seeder cannot simply write the enum value and move on: `roles.name` carries `utf8mb4_unicode_ci` (case- and accent-**insensitive**), so `firstOrCreate()` would silently **adopt** a pre-existing row named e.g. `administrator` and grant it all 42 Administrator permissions — while every identity check in the app is a byte-exact PHP comparison and would treat that same full-privilege row as an ordinary role, assignable with a bare `users.edit`. `Role::firstOrCreateSuperAdminRole()` is the exact mirror image, for the same reason.

Two consequences of the 0010 relocation worth stating, since both changed:

- **The exception type is now `ImmutableRoleException` on both paths**, not the `RuntimeException` the Administrator line used to throw. Both render 403.
- **`withoutEvents()` is now mandatory here**, not merely tidy. `guardAgainstAssumingAdministratorName()` refuses any role whose in-memory name is the Administrator name, and it does not — and must not — carry an exception for "but this one is the seeder". Suppressing events for the one sanctioned creation is how that exception is expressed, exactly as it already was for the Super Admin role.

The same collation is why the "a lowercase `administrator` role is an ordinary role" scenario cannot be exercised through role *creation* in this schema — the unique index refuses the row while the seeded `Administrator` exists. The `===` exactness is still tested, against a **not-yet-persisted** instance, because it is the correct guard if the collation is ever changed and because it is what makes the two read-back assertions meaningful.

## Middleware aliases

Registered in [`bootstrap/app.php`](../../../bootstrap/app.php):

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'role' => RoleMiddleware::class,
        'permission' => PermissionMiddleware::class,
        'role_or_permission' => RoleOrPermissionMiddleware::class,
    ]);
})
```

| Alias | Class | Notes |
| --- | --- | --- |
| `permission` | `Spatie\Permission\Middleware\PermissionMiddleware` | the default choice — reaches the Gate, so the Super Admin passes |
| `role_or_permission` | `Spatie\Permission\Middleware\RoleOrPermissionMiddleware` | use when a role check is unavoidable |
| `role` | `Spatie\Permission\Middleware\RoleMiddleware` | registered for completeness; **does not** admit the Super Admin |

All three throw `UnauthorizedException` for an unauthenticated request, which renders as a bare 403 rather than a redirect to login — so a gated route must **also** carry `auth` (and `verified`, matching the existing groups in `routes/web.php`). See [security/authorization-patterns.md](../../security/authorization-patterns/bypass-cache-and-guards.md#permission-and-role-middleware-are-not-a-substitute-for-auth).
