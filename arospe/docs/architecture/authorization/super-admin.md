# Authorization — Super Admin bypass and role invariants

> Part of [Authorization](../authorization.md). **Read this part when:** you touch `Gate::before`, the Super Admin bypass and its coverage gap, or the Super Admin role's structural invariants. The other parts are listed in the [hub](../authorization.md#table-of-contents).

## The Super Admin bypass

```php
// app/Providers/AppServiceProvider.php
protected function configureAuthorization(): void
{
    Role::superAdminName();

    Gate::before(function (mixed $user, string $ability, array $arguments = []): ?bool {
        if (! $user instanceof User) {
            return null;
        }

        $target = $arguments[0] ?? null;
        if ($target instanceof Role && Role::isSuperAdminRoleRow($target)) {
            return null;
        }

        return $user->hasRole(Role::superAdminName(), 'web') ? true : null;
    });
}
```

(The real file carries five inline comments on these lines — F5/F6/F7 audit markers from task 0008, plus 0009's F4 and F-C — trimmed here; read the file for them.)

The closure returns `true` or `null` — **never `false`**, which would hard-deny every other user before their real permissions were consulted. The `instanceof User` guard and the explicit `'web'` guard each close a distinct failure mode; the vendor-source reasoning lives in [security/authorization-patterns.md](../../security/authorization-patterns.md).

The bare `Role::superAdminName();` call on the first line is **not** dead code: it is a deliberate boot-time assertion that `auth.super_admin.role` is not misconfigured, added by task 0009 (Phase 5 finding F-C). See [One name, one resolution path](#one-name-one-resolution-path).

Two things changed here in task 0008:

- **The role name is resolved by [`Role::superAdminName()`](#one-name-one-resolution-path), not by an inlined `config()` expression.** The behaviour is identical (the method carries the same double fallback the inline expression did); what is gone is a *second* implementation of the resolution. The role that bypasses every permission check is now provably the same role that is protected and hidden, and the two cannot drift when `config('auth.super_admin.role')` is overridden.
- **The bypass now defers when the check's own target is the Super Admin role** (`return null` instead of `true`). Without this, a Super Admin actor's `Gate::authorize('delete', $superAdminRole)` was granted here before [`RolePolicy`](policies-users-roles.md#rolepolicy--the-second-policy) was ever consulted, so the policy layer was not independently effective for that one actor. The model-level guards refused the mutation either way; this closes the policy-layer half.

And one more in task 0009 (Phase 4 finding F4):

- **That deferral now identifies its target with `Role::isSuperAdminRoleRow($target)`, not `$target->name`.** The attribute read was the *other half* of the residual `RolePolicy` carried: a partially-hydrated (`select('id')`) or mid-rename Super Admin role short-circuited the bypass to `true` here, while `RolePolicy::update()`/`delete()` — reached only when this closure defers — would have returned a categorical `false`. Two layers disagreeing on one row shape. Both now read the same `persistedName()`-backed helper, so the deferral and the policy branch cannot diverge. See [Known limitations](#known-limitations--what-is-not-closed), where this residual is now recorded as closed.

Consequence, deliberately accepted: apart from that one deferral, the bypass short-circuits `denies()` / `cannot()` and every Policy, which is what "the Super Admin bypasses permission checks entirely" means.

> **The deferral is keyed on the target, not on the ability — and task 0010 verified what that means in practice.** Any `RolePolicy` ability invoked against a Super Admin role *instance* is **denied by default for a Super Admin actor** when `RolePolicy` has no matching method, because the bypass has already stepped aside and `Gate` falls through to "no method, no grant". Fail-closed, and not a security concern — but a surprise if you expected the Super Admin to pass everything, so add the ability to `RolePolicy` explicitly rather than relying on the bypass.
>
> **It does not bite the two abilities task 0010 added.** `viewAny` and `create` are asked **class-level** — `Gate::authorize('viewAny', Role::class)` — and the deferral's condition is `$target instanceof Role`. A class *string* is not an instance, so the closure never defers for them and a Super Admin passes normally. Verified during that story's Phase 5 review rather than assumed. The rule generalizes: this deferral can only ever fire for an ability that carries a resolved `Role` row, which today means `update` and `delete`.

### Bypass coverage — what it does *not* cover

`Gate::before` fires only for authorization routed through Laravel's Gate. Spatie's own `HasRoles` methods query the user's relations directly and never consult the Gate:

| Check | Reaches `Gate::before`? | Why |
| --- | --- | --- |
| `$user->can('products.delete')` | ✅ yes | goes through the Gate |
| `$this->authorize('products.delete')` | ✅ yes | Gate |
| `@can('products.delete')` | ✅ yes | Gate |
| `permission:products.delete` middleware | ✅ yes | resolves via `canAny()` → Gate |
| `role_or_permission:Super Admin\|roles.manage` middleware | ✅ yes | resolves via `canAny()` → Gate |
| `role:Administrator` middleware | ❌ **no** | calls `hasAnyRole()` directly |
| `$user->hasRole('Administrator')` | ❌ **no** | direct model query |
| `$user->hasPermissionTo('products.delete')` | ❌ **no** | direct model query |
| `@role('Administrator')` | ❌ **no** | direct model query |

So a Super Admin **is refused (403)** by a route or Blade block gated on a bare role name, even though they bypass every permission. That is the specified behavior — pinned by a regression test in [`tests/Feature/Authorization/PermissionMiddlewareTest.php`](../../../tests/Feature/Authorization/PermissionMiddlewareTest.php) — not a defect to be "fixed" by teaching the bypass about roles. Doing that would mean intercepting `hasRole()` itself, which breaks any legitimate "does this user literally hold role X" query the roles UI needs.

> **Hard convention — gate on permissions, never on role names.** Every route, middleware, component and Blade gate in this app must be keyed on a **permission** (`can:` / `permission:`). Where a role check is genuinely unavoidable, write `role_or_permission:Super Admin|<permission>` rather than bare `role:`, so the Super Admin is admitted by the role branch and everyone else by the permission branch. This applies to PRD Epics 2–5 as much as to Epic 1 — a bare `role:` gate is invisible until it silently locks the Super Admin out of a screen.

## The Super Admin role's invariants

Task 0008. The bypass above makes the `Super Admin` role the most privileged object in the system, and until this story it was an ordinary `roles` row: deletable, renameable, and offerable in any role dropdown. It is now a **fixed point** — categorically undeletable, unmodifiable in every direction, unacquirable by any other role, and invisible to roles-list queries.

All of it lives on one class, [`App\Models\Role`](../../../app/Models/Role.php), which `extends Spatie\Permission\Models\Role`. A subclass is required because a scope, model events, and method overrides all need one; `config/permission.php` repoints the package at it:

```php
// config/permission.php
'models' => [
    'role' => Role::class,   // App\Models\Role, not Spatie\Permission\Models\Role
],
```

### One role model class in application code

**No application file may import `Spatie\Permission\Models\Role`.** The single legitimate exception is the `models.role` binding above, which is where the two classes are deliberately joined.

This is a correctness rule, not tidiness: the two are different Eloquent classes over the *same* `roles` table, and only `App\Models\Role` carries the guards below — so `Spatie\Permission\Models\Role::find($id)->delete()` is a live bypass of everything this section describes. It was also the real state of the code before 0008: `App\Livewire\Users\Index` imported the package class directly, which is why repointing `models.role` alone was not sufficient.

Enforced by two Pest architecture tests rather than by convention alone:

```php
// tests/Unit/ArchitectureTest.php
arch('no application code imports the raw Spatie role model directly')
    ->expect('App')
    ->not->toUse(Role::class)
    ->ignoring('App\Models\Role');

arch('no seeder imports the raw Spatie role model directly')
    ->expect('Database\Seeders')
    ->not->toUse(Role::class);
```

✅ Good — **two separate single-namespace rules.** ❌ Bad — the obvious-looking `expect(['App', 'Database\Seeders'])->not->toUse(...)`: Pest evaluates an array of targets **disjunctively**, passing as soon as any one target satisfies the rule, so a combined rule stays green while `Database\Seeders` alone violates it. That is how this test first shipped vacuous; the file records the verification.

`App\Models\Role` itself is `->ignoring()`'d — it legitimately extends the package class. Test files are deliberately out of scope: a test proving one of the [documented bypasses](#known-limitations--what-is-not-closed) legitimately needs the package class.

### One name, one resolution path

Everything below answers "is this the Super Admin role?" through a single `public static` method, never by re-deriving the config read and never by comparing against the enum:

```php
// app/Models/Role.php
public static function superAdminName(): string
{
    $name = config('auth.super_admin.role', RoleName::SuperAdmin->value) ?? RoleName::SuperAdmin->value;

    throw_if(
        Str::lower($name) === Str::lower(RoleName::Administrator->value),
        RuntimeException::class,
        'auth.super_admin.role cannot be configured to "'.RoleName::Administrator->value.'" -- '.
        'that name is reserved for the locked, uneditable Administrator tier.',
    );

    return $name;
}
```

Callers: the [`Gate::before` bypass](#the-super-admin-bypass), all three guard layers, `scopeSelectable()`, [`RolePolicy`](policies-users-roles.md#rolepolicy--the-second-policy), [`UserValidationRules::roleRules()`](../../../app/Concerns/UserValidationRules.php), and `RolePermissionSeeder`. Before 0008 the bypass read config while **three** other sites wrote the literal `'Super Admin'` independently — the seeder, `Index::roleOptions()` and `roleRules()` — so overriding `config('auth.super_admin.role')` split them apart. The sharpest case was `roleRules()`, whose `Rule::exists(...)->whereNot('name', 'Super Admin')` would have gone on excluding an ordinary role while **permitting** a forged submission to assign the real, config-resolved Super Admin role.

Four properties are load-bearing and must not be "simplified":

- **`public static`** — `RolePolicy` and `RolePermissionSeeder` are separate classes with no `Role` instance in hand, and must call this implementation rather than re-derive it.
- **Both fallbacks.** `config()`'s default covers a *missing* key; `??` covers a key that is *present but `null`* (an unset env var feeding the value, or a stale `bootstrap/cache/config.php`). Dropping the `??` would let the bypass still resolve `'Super Admin'` and grant it, while the guards, scope, policy and seeder all resolved `null` and therefore protected, hid and seeded **nothing**. See [security/authorization-patterns.md](../../security/authorization-patterns/bypass-cache-and-guards.md#read-the-super-admin-role-name-with-a-literal-default).
- **The read happens in the method body**, i.e. at query/guard/policy time — never in a constructor or property initialiser, which could run before config is loaded.
- **The `throw_if` refusing a collision with the locked `Administrator` name** (task 0009, Phase 4 finding F6). The two protected tiers must never resolve to the *same* name, and only one of them is configurable, so the only way they can collide is an operator setting `auth.super_admin.role` to `Administrator`. Nothing else in the codebase would catch it: `RolePolicy` would stay fail-closed (its Super Admin branch runs first), but the `Gate::before` bypass would then hand unrestricted access to **every `Administrator` holder**. Two details are deliberate: the comparison is **case-insensitive**, wider than every other comparison in this file, because its job is catching an operator's typo rather than deciding role identity; and it is invoked **eagerly**, by the bare `Role::superAdminName();` at the top of [`AppServiceProvider::configureAuthorization()`](#the-super-admin-bypass) (Phase 5 finding F-C). This method sits on the hottest authorization path in the app — `Gate::before` runs it on nearly every check — so lazy detection would turn a deploy-time configuration mistake into an arbitrary user's request failing with a stack trace pointing at a policy instead of at the config key. Do not delete that call as dead code.

[`App\Enums\RoleName`](../../../app/Enums/RoleName.php) holds both well-known role names, and **its two cases are resolved through deliberately different mechanisms** — the rule is per case, not per enum, and the enum's own class docblock states it that way:

| Case | What it is | How a guard reads it |
| --- | --- | --- |
| `SuperAdmin` | **only** the compiled-in default, here and in `config/auth.php`. The operator-configurable `auth.super_admin.role` key is the source of truth | never compared against directly — always resolved through `Role::superAdminName()` |
| `Administrator` | the **locked identity itself** — no config key exists and none may be added (task 0008a) | compared against directly, via `Role::isAdministratorRole()` and `RoleName::Administrator->value` |

The `SuperAdmin` half of that rule is what task 0008 exists to protect: comparing a role row against `RoleName::SuperAdmin` would re-introduce exactly the config-vs-literal split described above. The `Administrator` half is safe for the opposite reason — there is no config value that could disagree with the literal. Why the two tiers are asymmetric on purpose is in [The Administrator tier's identity](administrator-tier.md#the-administrator-tiers-identity).

### Three guard layers

Each catches a class of code path the others cannot. All three are needed.

| # | Layer | Catches | Why the others miss it |
| --- | --- | --- | --- |
| 1 | `creating` / `deleting` / `updating` listeners in `Role::boot()` | any Eloquent save or delete, including code that never touches `Gate` | the policy only fires for `authorize()` call sites |
| 2 | Overrides of `givePermissionTo()` / `syncPermissions()` / `revokePermissionTo()`, and `assignToModels()` / `removeFromModels()` / `syncModels()` | pivot mutations on `role_has_permissions` and `model_has_roles` | these fire **no model event at all** — layer 1 cannot see them |
| 3 | [`RolePolicy::update()` / `delete()`](policies-users-roles.md#rolepolicy--the-second-policy) | dashboard call sites that `authorize()` | layers 1–2 only fire once a mutation is actually attempted, so without this a screen would have to provoke an exception to learn the answer |

Layers 1 and 2 refuse a **privilege** violation by throwing [`App\Exceptions\ImmutableRoleException`](../../../app/Exceptions/ImmutableRoleException.php), which carries a `render()` returning **403** — converging on the same status layer 3's policy denial produces, so the outcome is indistinguishable to a caller regardless of which layer caught it. The one deliberate exception is task 0010's holder-count guard, which throws `RoleInUseException` → **409**: refusing to delete a role that is still in use is not an authorization decision, and flattening it into 403 would tell the actor they lack a permission they actually hold.

#### Layer 1: registration order is the whole point

The listeners are registered in an overridden `boot()`, **before** `parent::boot()`. Task 0010 added three more of them (two Administrator-tier guards and the holder-count guard) into the *same* method for exactly this reason — a second registration point would be a second, invisible ordering decision:

```php
// app/Models/Role.php
protected static function boot(): void
{
    static::creating(function (self $role): void {
        $role->guardAgainstAssumingSuperAdminName();
        $role->guardAgainstAssumingAdministratorName();
    });

    static::deleting(function (self $role): void {
        $role->guardAgainstSuperAdminMutation();
    });

    static::deleting(function (self $role): void {
        $role->guardAgainstAdministratorDeletion();
    });

    static::deleting(function (self $role): void {
        $role->guardAgainstHolders();
    });

    static::updating(function (self $role): void {
        $role->guardAgainstSuperAdminMutation();       // pre-mutation name: refuses editing the role AS IT IS today
        $role->guardAgainstAssumingSuperAdminName();    // post-mutation name: refuses renaming INTO the role's name
        $role->guardAgainstRenamingAdministrator();     // pre-mutation name: refuses renaming the role AS IT IS today
        $role->guardAgainstAssumingAdministratorName(); // post-mutation name: refuses renaming INTO the role's name
    });

    parent::boot();
}
```

`guardAgainstHolders()` is the odd one out: it protects **every** role, not a privileged tier. It refuses a delete while the role still has holders — reading `$this->users()->withTrashed()->exists()` fresh, never a cached relation or a `withCount()` attribute carried over from a listing query, and counting soft-deleted holders because the FK cascade on `model_has_roles` would otherwise destroy a trashed holder's grant with no error anywhere (task 0010 Phase 4 finding F3). It throws [`App\Exceptions\RoleInUseException`](../../../app/Exceptions/RoleInUseException.php), which renders **409** rather than 403 — the request is well-formed and the actor is authorized; the role is simply still referenced.

❌ Bad — `booted()`, the idiomatic-looking choice (adapted to illustrate; deliberately not in the repo):

```php
// anti-pattern — do not register these in booted()
protected static function booted(): void
{
    static::deleting(fn (self $role) => $role->guardAgainstSuperAdminMutation());
}
```

`Spatie\Permission\Traits\HasPermissions::bootHasPermissions()` registers its **own** `deleting` listener, and trait boots run inside `Model::boot()` → `bootTraits()`, which `bootIfNotBooted()` calls **before** `static::booted()`. `fireModelEvent('deleting')` dispatches in registration order, so a `booted()` guard fires *after* the package's listener has already detached every `role_has_permissions` **and** every `model_has_roles` row for the role — and `Model::delete()` opens no transaction, so that detach **persists**. Net effect: the `roles` row survives (a naive "the role still exists" assertion passes) while the Super Admin role has silently lost all its permissions and all its holders.

Registering before `parent::boot()` puts these guards first, and each *throws* rather than returning `false`, halting the dispatch outright so the package's listener never runs. The test that proves it asserts the `role_has_permissions` and `model_has_roles` rows survive a refused delete — not merely that the `roles` row does. **This applies to every one of the four `deleting` listeners, not only the Super Admin one**: task 0010's holder-count and Administrator guards inherit the identical requirement, and `tests/Feature/Models/RoleTest.php` carries the same "the grants survived" assertion for the Administrator case.

#### The two identity helpers read different sources, and must never be merged

This is the subtlest part of the model, and a security re-audit found a working rename bypass in the first version of it.

| Helper | Reads | Answers |
| --- | --- | --- |
| `isSuperAdminRole()` | **persisted** identity only — `getOriginal('name')` when the column was hydrated, otherwise a database read-back | "was this row the Super Admin role *before* the mutation in flight?" |
| `guardAgainstAssumingSuperAdminName()` | the **in-memory / incoming** attribute | "is the name being *written* the Super Admin name?" |

They point in opposite directions on purpose. By the time `updating` fires, Eloquent has already staged the attacker's new name onto the in-memory attribute, so reading it for the first question makes a rename of the Super Admin role invisible to its own guard. And `isSuperAdminRole()` uses `array_key_exists('name', $this->getOriginal())` rather than `??`, because `getOriginal('name')` returns `null` for two different reasons — "never selected" and "selected but null" — that `??` cannot tell apart. The full rule, the executable bypass it came from, and why a test covering only the `guard_name` case passes on the vulnerable code, are in [security/authorization-patterns.md](../../security/authorization-patterns/ability-coverage-and-guards.md#a-guard-that-reads-a-rows-protected-identity-must-distinguish-not-hydrated-from-hydrated-but-null).

`creating` and the second half of `updating` guard a *different* attack than the rest: a role **acquiring** the Super Admin name (`Role::create(['name' => 'Super Admin', ...])`, or a rename into it) would silently inherit the `Gate::before` bypass. `Role::firstOrCreateSuperAdminRole()` — used by [the seeder](overview-catalog-seeding.md#seeded-roles-and-their-grants) and nowhere else — is the one sanctioned exception, bypassing the `creating` guard via `withoutEvents()`.

### `selectable()` — the shared invisibility scope

```php
// app/Models/Role.php
public function scopeSelectable(Builder $query): Builder
{
    return $query->whereNot('name', self::superAdminName());
}
```

**Every roles list and role selector in the app must call it.** Two callers today: `App\Livewire\Users\Index::roleOptions()` (the role *selector*, which previously hardcoded `->whereNot('name', 'Super Admin')`) and, since task 0010, `App\Livewire\Roles\Index::roles()` (the roles *list*), which composes it with a `where('guard_name', 'web')` scope of its own. Story 0011's view consumes that same computed property rather than re-querying.

Two properties:

- **Exact match, never `LIKE`.** A custom role named `Super Admin Assistant` stays fully visible and manageable.
- **Local scope, never global.** A global scope would be inherited by `Role::findByName()` / `findById()` / `findOrCreate()` (all go through `static::query()`) and by `User`'s `roles()` relation, breaking the seeder, `assignRole('Super Admin')`, the permission cache hydration (`select()->with('roles')->get()`), and a Super Admin's own `hasRole()`. Hiding the role from lists must not hide it from authorization.

The cosmetic half is not the enforcement. A forged submission never reads the dropdown, so the server-side exclusion in [`UserValidationRules::roleRules()`](../../../app/Concerns/UserValidationRules.php) — `Rule::exists('roles', 'id')->where('guard_name', 'web')->whereNot('name', Role::superAdminName())` — is what actually stops the Super Admin role being assigned from the Users screen.

### Known limitations — what is *not* closed

Enumerated deliberately rather than left as silent gaps. All of them require code already inside the application doing the thing; none is reachable from an external request. **Code review is the backstop for all of them.**

| Bypass | Why no application-layer mechanism closes it |
| --- | --- |
| `Role::where(...)->delete()`, `Role::query()->delete()`, raw `DB::table('roles')->update()/->delete()` | the query builder never instantiates a model, so no event and no override runs. Only a database trigger would close it — a migration, and out of this story's scope |
| `$role->permissions()->detach(...)`, and the exact analogue `$role->users()->detach(...)` | the overrides guard the *methods*; a parent model cannot guard a bare relation object it has already handed to the caller |
| `Permission::first()->removeRole('Super Admin')` | `Spatie\Permission\Models\Permission` also uses `HasRoles` and mutates the identical `role_has_permissions` pivot from the other side. `App\Models\Role`'s overrides cannot intercept a mutation issued by a different class |
| `saveQuietly()`, `deleteQuietly()`, `Role::withoutEvents(...)` | all three suppress model events wholesale. Worth naming explicitly because `firstOrCreateSuperAdminRole()` normalises `withoutEvents()` as an in-house pattern, making it likelier someone reaches for it elsewhere |
| A `Role::all()` written without `->selectable()` | the scope is a **convention**, not enforcement — the accepted cost of rejecting a global scope |
| Matching ignores `guard_name` | `superAdminName()`, `selectable()`, the guards and `isAdministratorRole()` / `isSuperAdminRoleRow()` all match on `name` alone, while the bypass is explicitly `web`-scoped. A same-named role on another guard is not *plantable* through `App\Models\Role` (the `creating` guard is guard-insensitive too), and grants no bypass if it exists — so this is a hygiene issue, not an escalation. For the two row-shaped helpers it is additionally fail-*closed* in both directions: a foreign-guard match only makes the check stricter, and Spatie's `ensureModelSharesGuard()` refuses assigning a foreign-guard role to a `web` user regardless. Guard-scoping all of them is deferred |

Two more, recorded because they are the ones a future story will actually walk into:

> **✅ Closed by task 0009 — the partial-hydration residual is gone; kept here as a record.** Through task 0008a, `RolePolicy`'s Super Admin branch and the `Gate::before` deferral both identified their target with the in-memory `$role->name` attribute rather than the persisted-identity-safe resolution the model guards use, so a partially-hydrated `Role` (e.g. `Role::query()->select('id')->find($id)`) or a mid-rename one evaded the **policy** layer and returned the actor's ordinary `roles.manage` answer instead of a categorical `false`. Task 0009 routed **both** sites through [`Role::isSuperAdminRoleRow()`](administrator-tier.md#the-administrator-tiers-identity), which reads `persistedName()` — so every identity question at every layer is now hydration-safe by construction, and the policy and the deferral cannot disagree about a row shape. `tests/Feature/Policies/RolePolicyTest.php` pins it with a retargeting test (an edit meant for one role, forged onto the Super Admin role, is refused and neither role is modified).
>
> The generalisable rule — separate "not hydrated" from "hydrated but null", and never read the in-memory attribute for a row's *protected* identity — is in [security/authorization-patterns.md](../../security/authorization-patterns/ability-coverage-and-guards.md#a-guard-that-reads-a-rows-protected-identity-must-distinguish-not-hydrated-from-hydrated-but-null). Any new `RolePolicy` ability that identifies a tier must call one of the two `Role::is*` helpers; it must never re-derive the comparison.

> **⚠️ The update path and the delete path are still not symmetric about a Super Admin-holding target — but the gap is now narrower than it was.** Task 0008a gave `App\Actions\Users\UpdateUser` a **direct throw** (deliberately outside `Gate`) refusing any modification of a user who currently holds the Super Admin role, which binds a Super Admin *actor* too. `App\Livewire\Users\Index::deleteUser()` still has no equivalent **for another holder**: its only Super Admin-target exclusion is `UserPolicy::delete()`'s, which sits behind the `Gate::before` bypass — so a Super Admin actor can still delete *another* Super Admin holder. **The self-targeting half of this was closed by task 0015** (finding F11): `deleteUser()` now returns early, as a silent no-op that closes the confirmation modal, whenever the target `is()` the acting user — a direct identity check above the `Gate::authorize()` call, chosen over a `UserPolicy` rule for precisely the reason this ⚠️ exists. The remaining gap predates 0008a (stories 0005/0008) and was explicitly out of its scope; it is a candidate for a future task, recorded in that story's implementation record as **P2**. See [A rule that must bind a Super Admin actor cannot go through `Gate`](administrator-tier.md#a-rule-that-must-bind-a-super-admin-actor-cannot-go-through-gate).
