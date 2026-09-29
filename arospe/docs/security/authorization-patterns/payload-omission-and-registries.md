# Authorization Patterns — Payload omission, registries and rate limits

> Part of [Authorization Patterns](../authorization-patterns.md). **Read this part when:** you handle identity derived from a mutable column, what an omission means across guards, DOM-omitted controls, submitted-list checks, an ungated-by-absence registry, or a target-keyed rate limit. The other parts are listed in the [hub](../authorization-patterns.md#table-of-contents).

## An identity derived from a mutable column must be locked once code exists that can mutate it

Established by task 0010's Phase 4 finding **F1** (High, two working privilege-escalation paths, both
verified live).

Task 0008a centralized the question "is this role the Administrator tier?" into one predicate,
`Role::isAdministratorRole()`, which compares the row's persisted `name` against
`RoleName::Administrator->value`. That was correct, and it was **safe only for as long as nothing could
change `roles.name`**. Task 0010's roles-management screen is that something. Two consequences arrived
together the moment it shipped:

- **Rename.** A `roles.manage-administrators` holder renames the seeded `Administrator` role. Nothing
  errors. But `isAdministratorRole()` now answers `false` for it, so every consumer of that predicate —
  `UserPolicy`'s Administrator branches, `CreateUser`, `UpdateUser`, `RolePolicy::update()` — silently
  stops protecting the tier. The role keeps its 42 permissions and every holder keeps their access; only
  the *protection* disappears.
- **Delete.** Once the role has no holders, the same actor deletes it outright. The catalog's base role
  is gone, and re-creating it by hand (exact byte-identical name, `web` guard, 42 of 43 permissions)
  is error-prone in a way nothing in the app would detect.

Neither is a bug in the predicate. The predicate reads a column, and the column became writable.

❌ Bad — the shape as found: an identity anchored to a column that application code can now write, with
no guard on the column:

```php
// app/Models/Role.php — as of task 0009: only the Super Admin tier had guards
public static function isAdministratorRole(self $role): bool
{
    return $role->persistedName() === RoleName::Administrator->value;
}
// ...and nothing in boot() refused a rename or a delete of that row.
```

✅ Good — the shipped fix. Three guards, mirroring the Super Admin tier's, registered in the **same**
`boot()` so the ordering decision stays in one place:

```php
// app/Models/Role.php
static::deleting(function (self $role): void {
    $role->guardAgainstAdministratorDeletion();
});

static::updating(function (self $role): void {
    // ...
    $role->guardAgainstRenamingAdministrator();     // pre-mutation name: the row AS IT IS today
    $role->guardAgainstAssumingAdministratorName(); // post-mutation name: renaming INTO the name
});
```

Four properties of the fix that are the actually transferable part:

- **Lock exactly what the identity depends on, and nothing more.** The Administrator row's *name* is
  locked and the row is undeletable; its **permission set stays fully editable**, because
  `syncPermissions()` does not change the answer to "is this the Administrator tier?" — and because
  [`EnforceAdministratorPermissionGrant`](ability-coverage-and-guards.md#a-full-set-sync-behind-a-partially-visible-form-must-preserve-what-the-actor-cannot-see)
  exists precisely so that set *can* be changed. Copying the Super Admin tier's blanket
  `guardAgainstSuperAdminMutation()` here would have broken the feature one story earlier had shipped.
- **Guard both directions of a name.** A rename *of* the protected row and a rename *into* its name are
  different attacks and need different guards, reading different sources — see
  [the hydration rule](ability-coverage-and-guards.md#a-guard-that-reads-a-rows-protected-identity-must-distinguish-not-hydrated-from-hydrated-but-null).
  The pre-mutation guard is additionally scoped to `isDirty('name')`, because ordinary saves of that row
  are legitimate.
- **A `creating`/`updating` guard needs a sanctioned bypass for the one legitimate writer.**
  `Role::firstOrCreateAdministratorRole()` (`withoutEvents()`, plus a byte-exact read-back of the
  persisted name against the case-insensitive `utf8mb4_unicode_ci` collation) is that bypass, and
  `RolePermissionSeeder` now calls it instead of the raw `firstOrCreate()` the new guard would refuse.
  Adding the guard **without** the bypass would have broken seeding, in production, on the next deploy.
- **The policy layer is not the fix; it is the companion.** `RolePolicy::delete()` also refuses the row
  categorically, but that branch is [unreachable for a Super Admin actor](../../architecture/authorization/administrator-tier.md#the-administrator-tiers-immutability-name-locked-undeletable-permissions-still-editable)
  — `Gate::before` only defers on a *Super Admin* target. The model-event guard is what binds every
  actor, for the same reason as
  [the direct-throw rule](ability-coverage-and-guards.md#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check).

**Rule.** When a security decision is derived from a mutable attribute, the story that first ships code
able to mutate that attribute **owns locking it** — and the review question is not "does the new screen
authorize correctly?" but "**which existing invariants does this screen's write surface newly reach?**".
Enumerate every column the new code can write, then, for each, ask what elsewhere in the app reads it as
an identity. A predicate that was safe when it was written stays in the diff untouched while becoming
unsafe, so nothing about the change draws attention to it.

## Two guards on one payload must agree on what an omission means

Established by task 0010's Phase 4 re-audit, as a **forward-looking** rule: no live bypass exists
today, and the condition that would create one is named below so it is caught before it ships.

`App\Livewire\Roles\Index::saveRole()` runs two independent transformers over the same submitted
permission list, in this order:

```php
// app/Livewire/Roles/Index.php
$permissionNames = $enforceGrantorPermissionScope(Auth::user(), $permissionNames, $role);
$permissionNames = $enforceAdministratorPermissionGrant(Auth::user(), $permissionNames, $role);
```

They answer the same question — "may this actor move this permission?" — but they treat an **omission**
in opposite ways, and both are correct *for their own rule*:

| Action | On a new grant the actor may not make | On an omission of something already granted |
| --- | --- | --- |
| [`EnforceAdministratorPermissionGrant`](../../../app/Actions/Roles/EnforceAdministratorPermissionGrant.php) | throws | **preserves** — re-adds the permission to the payload |
| [`EnforceGrantorPermissionScope`](../../../app/Actions/Roles/EnforceGrantorPermissionScope.php) | throws | **ignores** — the sync revokes it |

That divergence is safe only because of a property of a *different* file: `permissionOptions()` returns
the **unfiltered** `web` catalog, so (almost) every permission a role currently holds is rendered as a
checked box and comes back in the payload.

> **Narrowed 2026-08-21 (task 0011's Phase 4 audit), now that the paired Blade view exists.** This
> section originally closed with "the second action never has to preserve anything, because nothing is
> ever invisibly absent" — written while `resources/views/livewire/roles.blade.php` was still 0011's
> unbuilt deliverable. The shipped view omits **exactly one** checkbox, and
> `EnforceAdministratorPermissionGrant`'s preserve branch is therefore live rather than dormant. The
> combination is still safe, for a reason worth stating on its own — see
> [the section below](#a-control-omitted-from-the-dom-is-safe-only-for-the-one-value-whose-guard-preserves-an-omission).

⚠️ **The hazard.** The natural reaction to the finding `EnforceGrantorPermissionScope` closes ("an actor
may not grant a permission they do not hold") is to stop rendering those checkboxes at all. Doing that —
in `permissionOptions()`, or in the paired Blade view — turns the second row of that table into the
exact silent-revoke bug the [section above](ability-coverage-and-guards.md#a-full-set-sync-behind-a-partially-visible-form-must-preserve-what-the-actor-cannot-see)
documents: a narrow `roles.manage` holder editing a role that legitimately holds `products.delete` would
submit a payload omitting it, and `syncPermissions()` would strip it with no error anywhere.

❌ Bad — filtering the catalog to what the actor can grant, while the scope action still only throws:

```php
// anti-pattern — do NOT pair this with a grant-only guard
return Permission::query()
    ->whereIn('name', $actor->getAllPermissions()->pluck('name'))
    ->get(['id', 'name']);
```

✅ Good — either keep the catalog unfiltered (today's shipped state), or, if it is ever filtered, give
`EnforceGrantorPermissionScope` the same preserve branch its sibling already has, conditional on the
actor failing the ability rather than unconditional.

**Rule.** When more than one guard transforms the same full-replace payload, write down what each one
does with an **omission**, not only with an addition — and state the property of the form that makes the
combination safe, in the guard, so that changing the form cannot quietly invalidate it. A guard whose
correctness depends on a view rendering every value is only as safe as that view, and nothing in the
guard's own file or test suite will fail when the view changes.

Two corollaries verified during the same re-audit, both worth knowing before touching this pipeline:

- **Their *ordering* is not the safety mechanism, despite reading like it.** The two were verified to
  refuse identically when reversed. What keeps them from disagreeing about
  `roles.manage-administrators` is that `EnforceGrantorPermissionScope` **excludes that name from its
  own scope entirely** (`->reject(fn ($name) => $name === RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION)`),
  deferring it to the action that owns it. Keep the exclusion; do not rely on call order.
- **A grant-scope rule is one-directional by construction.** `EnforceGrantorPermissionScope` restricts
  *granting* a permission the actor lacks; it deliberately does not restrict *revoking* one. A
  `roles.manage` holder can therefore strip any permission — including `roles.manage` itself — from any
  role they neither hold nor created, which is privilege *consolidation* rather than escalation
  (the Super Admin, who holds no revocable role, can always repair it). The self-lockout guard in
  `saveRole()` keys on `Auth::user()->hasRole($role->name, 'web')`, so it protects the actor's own
  access and nobody else's. If that asymmetry ever stops being acceptable, the fix is a second diff
  direction in the same action, not a new guard elsewhere.

## A control omitted from the DOM is safe only for the one value whose guard preserves an omission

Established by task 0011's Phase 4 audit — the view half of the roles screen, and the first place in
this repo where "do not render this control at all" is a *security* requirement rather than a
convenience.

The requirement has two halves that pull in opposite directions, and both are real:

- **It must be absent, not disabled.** A rendered-but-disabled checkbox tells a broad `roles.manage`
  holder that `roles.manage-administrators` exists and whether this role holds it — the escalation-adjacent
  fact the whole Super-Admin-only meta-rule exists to withhold. HTML also does not submit a disabled
  input, so "disabled" would be a silent revoke on top of a leak.
- **Absence is what a full-replace `sync*()` reads as a revoke.** Per the two sections above, an omitted
  value is destroyed unless some guard puts it back.

✅ Good — the shipped view, which resolves that by filtering the withheld permission out **once, before
grouping**, rather than per-item inside the render loop:

```blade
{{-- resources/views/livewire/roles.blade.php --}}
$visiblePermissions = $this->canGrantAdministratorLevel
    ? $this->permissionOptions
    : $this->permissionOptions->reject(
        fn ($permission) => $permission->name === \App\Policies\RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION
    );

$permissionGroups = $visiblePermissions->groupBy(
    fn ($permission) => explode('.', $permission->name, 2)[0]
);
```

Verified by rendering rather than by reading: a broad `roles.manage` holder editing a role that holds
`roles.manage-administrators` gets **42** `value="…"` checkbox attributes and no matching label; a Super
Admin gets **43**. Saving an unrelated change from that broad actor's payload — which omits the
permission — leaves the role holding `roles.manage-administrators`, because
[`EnforceAdministratorPermissionGrant`](../../../app/Actions/Roles/EnforceAdministratorPermissionGrant.php)
re-adds it. Every *other* permission the actor cannot grant (`products.delete`, say) is still rendered,
so nothing else can be invisibly absent.

❌ Bad — the shape this replaced (Phase 4 finding F2/F4, corrected in the same story before Phase 5):
a per-item condition inside the render loop, applied *after* grouping:

```blade
{{-- anti-pattern — superseded; do not reintroduce --}}
@foreach ($permissionGroups as $module => $permissions)
    @foreach ($permissions as $permission)
        @if ($permission->name === \App\Policies\RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION)
            @if ($this->canGrantAdministratorLevel)
                <flux:checkbox value="{{ $permission->id }}" ... />
            @endif
        @else
            <flux:checkbox value="{{ $permission->id }}" ... />
        @endif
    @endforeach
@endforeach
```

Two independent problems with this shape, not one: it is easy to duplicate or diverge from (a second,
unrelated `@if` added to the same loop — see the next example — reads as "consistent" with this one even
though nothing enforces that), and applying the filter *after* grouping means a module whose only
permission is the withheld one still renders its heading and separator over an empty body — a structural
version of the same disclosure the omission itself exists to prevent. Filtering before `groupBy()` closes
both at once: a module that would end up empty never appears at all.

❌ Bad — a second omission in the same loop, the natural next edit once any per-item condition already
exists, and has no guard behind it:

```blade
{{-- anti-pattern — do NOT add a second condition here --}}
@if ($this->canGrant($permission->name))
    <flux:checkbox value="{{ $permission->id }}" ... />
@endif
```

`EnforceGrantorPermissionScope` **ignores** an omission, so this silently strips every permission the
editing actor happens not to hold, from every role they touch, with no error and no failing test — the
hazard the section above names, now one `@if` away instead of hypothetical.

**Rule.** When a control is deliberately withheld from the DOM, the withheld set must be **exactly**
the set of values some guard preserves on omission, and the code must say which guard that is. Keep the
condition to one expression (ideally applied once, before the grouping/rendering transform, rather than
per-item inside it), and pair it with a test asserting the rendered control count equals *catalog minus
withheld* — a count assertion fails when a second omission appears, whereas an
`assertDontSee()` on the withheld label passes just as happily when half the form has vanished.

**Accepted residual (Low), recorded so it is not rediscovered as new.** Withholding the *control* does
not withhold the *value*: `openEditModal()` assigns `$this->selectedPermissionIds` from the role's real
permission set, and that property is public and not `#[Locked]` (it must be writable — it is the form
field), so the withheld permission's integer **id** still reaches a non-Super-Admin's browser in the
Livewire snapshot. By elimination against the 42 rendered id→label pairs, the actor can infer that the
role holds a permission they are not shown. It confers nothing — granting it is refused with a 403 and
revoking it is undone by the preserve branch, both verified live. Closing it means filtering the id out
of `selectedPermissionIds` when `! $canGrantAdministratorLevel`, which is safe but puts the withholding
rule in a **second** file; if that is ever done, the two must be derived from one expression, because a
filter that also fires for the Super Admin turns preserve into revoke for the one actor allowed to
revoke.

## A check over a submitted list must accept every shape the write accepts, and derive the "before" state itself

Established by task 0009's Phase 4 rounds 1–3 (findings F2, then N1/N2/N3 against the fix for F2, then
NR1). Every round found the same hole one level further up.

**Half one — shape.** `Role::syncPermissions()` accepts names, integer ids, `Permission` model
instances, and arrays or `Collection`s of any of those, flattening the lot through
`HasPermissions::collectPermissions()`. A membership check written against bare name strings therefore
sees a different set than the write does: submitting the protected permission **by id**, or nested one
level deep inside an array element, was proven live to bypass the guard while still being honoured by
the sync.

✅ Good — normalise with the *identical* flattening the write uses, then compare:

```php
// app/Actions/Roles/EnforceAdministratorPermissionGrant.php — normalizeNames()
foreach (Collection::make($permissions)->flatten() as $permission) {
    if ($permission instanceof Permission) {
        $names[] = $permission->name;
    } elseif (is_numeric($permission)) {
        $ids[] = $permission;
    } else {
        $names[] = (string) $permission;
    }
}
```

**Half two — provenance.** The first fix for F1/F2 took the "before" snapshot as a caller-supplied
`array $currentPermissionNames` parameter. That reopened the identical hole at the call site: a caller
asserting an untrue "before" makes a genuine new grant look pre-existing (N2), and the two snapshots
were normalised asymmetrically so the diff silently mismatched (N3). Replacing the parameter with
`?Role $role` — from which the action loads the permissions itself — removed both **structurally**,
not by adding a validation.

```php
// the action reads its own "before" state; a caller cannot assert one
$role->load('permissions');
$currentNames = $role->permissions->pluck('name')->all();
```

**Rule.** A guard must derive every input its decision depends on from an authoritative source it
controls, and must interpret the submitted input **exactly** as the write that follows will. Concretely:

- **Never accept "what the record currently is" as a parameter.** That is the state being guarded; a
  caller that can assert it can defeat the guard. Take the model and read it — with `load()`, not
  `loadMissing()`, per [the reload rule](ability-coverage-and-guards.md#authorization-that-consults-a-relation-must-reload-it-before-the-first-check-reads-it).
- **Read the vendor source for the accepted input shapes rather than assuming the well-behaved one.**
  "Callers pass names" is a convention, and a convention is not a boundary.
- **A nullable parameter that means "nothing yet" must not carry a default** (finding NR1). `?Role
  $role` with `= null` turns a forgotten argument at a future call site into a silent "nothing is
  currently granted" for what may be an existing, already-granted row. Nullable, but required.
- **Re-audit the fix, not just the finding.** Three consecutive rounds here each found a flaw in the
  *previous round's remediation*, all shipped past review because each fix was read against the finding
  it answered rather than as new code with its own attack surface.

## A registry that means "ungated" by *absence* fails open, silently

> **Status: closed, 2026-08-22 (story 0013).** Found as F1 by that story's Phase 4 audit and remediated
> in the same story by shape **(b)** below — the allow-list schema test, which shipped in
> [`tests/Feature/Navigation/SidebarModuleGatingTest.php`](../../../tests/Feature/Navigation/SidebarModuleGatingTest.php)
> and is quoted verbatim under ✅. The ❌ is still the shipped filter expression, because the fix is a
> *test* rather than a rewrite of the component — read it as "the shape the guard test exists to
> protect", not as an outstanding hole. This section was written as a ❌/✅ pair with an explicit status
> banner precisely so this update was a one-line status flip rather than a re-framing, per
> [the audit-authored-page rule](../../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20).

Story 0013 introduced this repo's first **declarative permission registry** — `config/modules.php`,
read by `resources/views/components/sidebar-nav.blade.php` — and the registry is designed to be
*appended to by every later epic*. That makes the shape of its default the durable question, not the
two entries it holds today.

The registry expresses "this entry needs no permission" as an **empty** `permissions` array, and the
component's filter reads that with `empty()`:

❌ The filter as shipped — three distinct developer mistakes all resolve to "visible to everyone", with
no warning, no exception and no log line:

```blade
{{-- resources/views/components/sidebar-nav.blade.php --}}
->filter(fn (array $item): bool => empty($item['permissions']) || Gate::any($item['permissions']))
```

`empty()` is the one PHP construct that reads a missing array key **without emitting a warning**, so
all three of these render the entry unconditionally — verified by execution against the real component
with a `Gate::before` denying everything:

| Registry mistake | `empty()` says | Result |
| --- | --- | --- |
| `permissions` key omitted entirely | `true` | rendered for everyone |
| key misspelled (`'permission' => [...]`) | `true` | rendered for everyone |
| `'permissions' => null` | `true` | rendered for everyone |
| `'permissions' => 'users.view'` (string, not array) | `false` | fails **closed** — `Gate::any()` wraps it |
| `'group' => '<key not in groups>'` | n/a | silently dropped — fails **closed** |

Nothing is directly exploitable by an attacker: the route's own `can:` middleware still refuses the
request ([the module-gate pattern](../../architecture/authorization/how-to-gate.md#the-copyable-module-gate-pattern-and-the-three-alternatives-rejected)),
so the blast radius is an advertised link that 403s, plus disclosure of a module's existence and URL to
a role that may not use it. But it violates the story's own acceptance criterion — *never advertise a
link the route would refuse* — and it violates it **in the direction a registry is most likely to
drift**, because the mistake is an omission rather than a wrong value, and omissions do not appear in a
diff as anything.

Note the asymmetry that makes this worth writing down: the *safe* mistakes above are the loud-looking
ones, and the *unsafe* ones are the quiet ones. A permission name that is real but not in the seeded
catalog also fails closed — `PermissionRegistrar`'s `Gate::before` calls `checkPermissionTo()`, which
catches `PermissionDoesNotExist` and returns `false` — so a typo in the *value* hides the entry, while
a typo in the *key* reveals it.

Two shapes close it: **(a)** an explicit positive declaration at the registry level (`'ungated' => true`,
required whenever `permissions` is empty), or **(b)** a schema test over the real registry that
allow-lists the keys permitted to be ungated. **(b) is what shipped** — it is cheaper, adds no runtime
cost, and puts the allow-list somewhere a reviewer of the *registry* diff cannot avoid updating:

✅ Shipped — `tests/Feature/Navigation/SidebarModuleGatingTest.php`, quoted verbatim:

```php
// tests/Feature/Navigation/SidebarModuleGatingTest.php
test('every ungated registry item is on the explicit allow-list', function () {
    // Epic 1 ships exactly one deliberately ungated entry (Dashboard); this
    // is an allow-list, not a shape check, so a new item can only join it by
    // someone editing this line -- never silently by omission.
    $deliberatelyUngated = ['dashboard'];

    foreach (config('modules.items') as $key => $item) {
        expect($item)->toHaveKey('permissions')
            ->and($item['permissions'])->toBeArray();

        if ($item['permissions'] === []) {
            expect($key)->toBeIn($deliberatelyUngated);
        }
    }
});
```

**It asserts `toHaveKey('permissions')` alone rather than the full `toHaveKeys([...])` shape this
section originally recommended, and that narrowing is correct rather than a residual** — `permissions`
is the *only* registry key whose absence is silent. Verified against the real component and framework
source: `label`, `icon`, `route` and `current_when` are read by direct array access, so a missing one
raises `E_WARNING` "Undefined array key", which
[`HandleExceptions::handleError()`](../../../vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php)
rethrows as an `ErrorException` — a 500 on the dashboard, impossible to miss. A missing `group` is
silent but fails **closed**: `Collection::groupBy()` resolves it through `data_get()` and casts the
resulting `null` to `''`, so the item lands in a bucket no `config('modules.groups')` key ever matches
and is dropped. Only `empty()` both swallows the warning *and* fails open, so only `permissions` needs
pinning.

**Rule: in any registry where a permission list gates rendering, "no permission required" must be an
explicit value or an allow-listed key — never the absence of one. Do not read the gating key with
`empty()` / `??`, both of which erase the difference between "declared as ungated" and "the author
forgot".** This is the same family as [distinguishing "not hydrated" from "hydrated but null"](ability-coverage-and-guards.md#a-guard-that-reads-a-rows-protected-identity-must-distinguish-not-hydrated-from-hydrated-but-null)
and [why `config($key, $default)` alone cannot cover a present-but-`null` key](bypass-cache-and-guards.md#read-the-super-admin-role-name-with-a-literal-default),
arriving through a third door: a config array rather than a model attribute or a config scalar.

**Second half of the same rule: a registry entry's `permissions` must be pinned to its route's real
`can:` middleware by a test, not by a comment.** `config/modules.php` states the requirement in prose
("must be exactly the ability its route's `can:` middleware enforces") and it was correct as written —
but prose does not fail when someone changes one side. Found as F2 in the same audit and **closed in the
same story**; the check needs no fixtures:

✅ Shipped — `tests/Feature/Navigation/SidebarModuleGatingTest.php`, quoted verbatim:

```php
// tests/Feature/Navigation/SidebarModuleGatingTest.php
foreach (config('modules.items') as $item) {
    $route = Route::getRoutes()->getByName($item['route']);
    expect($route)->not->toBeNull();

    $gatedAbilities = collect($route->gatherMiddleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'can:'))
        ->map(fn (string $middleware): string => substr($middleware, strlen('can:')))
        ->values()
        ->all();

    expect($gatedAbilities)->toEqualCanonicalizing($item['permissions']);
}
```

Note it iterates **every** item including the ungated `dashboard`, whose route carries no `can:` gate at
all — `[]` on both sides, which is exactly the assertion that matters there. It also catches the inverse
drift the prose does not mention: an entry whose route *gains* a `can:` gate later while the registry
still lists none.

## A rate limit keyed on the target alone becomes an attack on the target the moment a second caller exists

Established by task 0015's finding **F6 part 2**, against a limiter that had been correct when it was
written and became a denial-of-service vector without a single line of it changing.

`App\Actions\Users\RequestEmailChange` throttled at `'email-change:'.$user->getKey()` — 3 per hour,
keyed on the **target**. That was right while `App\Livewire\Settings\Profile` was the only caller,
because there target ≡ actor and the key is really "this person's own allowance". Task 0004 added a
second, **cross-user** caller (an administrator editing someone else's row) and the same key silently
changed meaning: an administrator could now spend a victim's three attempts and leave them unable to
change their own address for the rest of the hour. Quota is a resource, and a shared key is a resource
one actor can consume on another's behalf.

**Neither obvious fix is sufficient alone**, which is the part worth carrying forward:

| Fix | Fixes the quota burn | Keeps the inbox-flood ceiling |
| --- | --- | --- |
| Re-key composite `(target, actor)` | ✅ | ❌ — N administrators each get their own 3/hour at one inbox |
| Add an actor-scoped limiter beside the unchanged target one | ❌ — the target's own 3 is still burnable | ✅ |
| **Both: composite key, plus a second target-aggregate limiter** | ✅ | ✅ |

✅ Good — the shipped shape ([`app/Actions/Users/RequestEmailChange.php`](../../../app/Actions/Users/RequestEmailChange.php)),
with the four decisions that make it work:

```php
$actorKey = Auth::id() ?? 'unauthenticated';

// (1) narrower first — RateLimiter::attempt() CONSUMES on success
$key = 'email-change:'.$user->getKey().':'.$actorKey;   // 3/hour

$isSelfService = Auth::id() !== null && $user->is(Auth::user());

if (! $isSelfService) {
    $aggregateKey = 'email-change-target:'.$user->getKey();   // 10/hour
}
```

- **Check the narrower limiter first.** `RateLimiter::attempt()` consumes on success, so checking the
  aggregate first would burn shared quota on a request the composite key is about to refuse anyway.
  The residual asymmetry is fail-*closed* and accepted: when (1) passes and (2) refuses, the actor has
  spent one of *their own* attempts on a refused request — never one of the target's.
- **`Auth::id() ?? 'unauthenticated'` groups every session-less caller into one bucket.** This action
  has no `Gate` check of its own and is reachable without a session. Falling back to `$user->getKey()`
  would silently restore the burnable behaviour the composite key exists to remove.
- **The aggregate ceiling must exempt the target's own request** (Phase 4 re-audit finding **F-A**).
  Without the exemption, four administrators each staying inside their own composite cap exhaust the
  shared 10 and lock the target out of `settings/profile` for an hour — administrator activity
  producing exactly the outcome this whole change exists to prevent. The exemption is an **identity**
  check (`$user->is(Auth::user())`), the same idiom the Users screen uses for its own self-row rules.
- **An exemption removes a backstop, so the surviving control needs its own test** (re-audit finding
  **L-1**). Once the aggregate no longer applies to a self-service caller, the composite limiter is the
  *only* thing capping that caller's rate — and nothing in the suite proved it did, because every
  existing "self-service" test exhausted a *different* actor's allowance first, and the pre-existing
  throttle tests in `tests/Feature/Settings/EmailChangeTest.php` run with **no** `actingAs()` (so they
  exercise the `'unauthenticated'` path, where both limiters are live). The fix authenticates as the
  target and drives four real requests through `App\Livewire\Settings\Profile`.

**Rule.** A rate-limit key encodes an assumption about who the callers are. When an action gains a
caller for whom **target ≢ actor**, every key it uses has to be re-derived — and the replacement is
normally *two* limiters, because one key cannot express both "this person's own allowance" and "how
much traffic anyone may aim at this person". Same reasoning applies to any other consumable keyed on a
subject rather than on the actor: verification-code sends, invitation resends, export jobs.
