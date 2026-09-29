# Authorization — Grant meta-rules and UI hints

> Part of [Authorization](../authorization.md). **Read this part when:** you touch who may grant a permission, `Gate::authorize` at the call site, or `Gate::allows()` used as a list-query UI hint. The other parts are listed in the [hub](../authorization.md#table-of-contents).

### Who may *grant* a permission — the meta-rule layer

Task 0009 introduced a category the two policies above do not cover. `roles.manage-administrators` answers "may this actor manage administrator-level roles and users". It does **not** answer "may this actor hand that ability to somebody else" — and deliberately so: holding a permission must never confer the right to grant it, or a single administrator-level holder could bootstrap an unbounded number of peers.

The rule has two halves, and only the second is a security control:

| Half | Where | What it does |
| --- | --- | --- |
| Visibility | `RolePolicy::grantAdministratorPermission` | `Gate::allows('grantAdministratorPermission', Role::class)` — asked once in `Roles\Index::mount()` and exposed as the `#[Locked] $canGrantAdministratorLevel` flag the roles view reads (task 0011) to decide whether to render the toggle **at all**: absent from the DOM, not merely disabled |
| Enforcement | [`App\Actions\Roles\EnforceAdministratorPermissionGrant`](../../../app/Actions/Roles/EnforceAdministratorPermissionGrant.php) | refuses a save payload that *newly grants* the permission unless the actor passes that same ability, and **preserves** an existing grant a non-Super-Admin's payload merely omitted |

`grantAdministratorPermission` takes no target: it is a property of the actor, asked class-level (`Role::class`). It is also deliberately **not** gated by any permission — making it grantable would recreate the escalation it exists to prevent.

The action is where the non-obvious design sits, and all of it comes from that first half. Because the toggle is never rendered to a non-Super-Admin, and because `Role::syncPermissions()` **replaces a role's entire permission set**, a broad administrator editing an unrelated field of a role that legitimately holds `roles.manage-administrators` submits a payload that *omits* it — not as a decision, but because the field was never in their form. Reading that omission as a revoke would let any `roles.manage` holder strip a Super Admin's grant by saving an unrelated change:

```php
// app/Actions/Roles/EnforceAdministratorPermissionGrant.php — __invoke()
$wasGranted = in_array(RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION, $currentNames, true);
$isSubmittedGranted = in_array(RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION, $submittedNames, true);

if ($isSubmittedGranted && ! $wasGranted) {
    Gate::forUser($actor)->authorize('grantAdministratorPermission', Role::class);
}

if ($wasGranted && ! $isSubmittedGranted && Gate::forUser($actor)->denies('grantAdministratorPermission', Role::class)) {
    $submittedPermissions[] = RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION;
}
```

Five properties, each of which a Phase 4 round put there:

- **It diffs before-vs-after; it does not test the payload alone.** Only a *genuine new grant* (absent before, present after) requires the ability. An omission is preserved rather than read as a revoke — **unless the actor can actually revoke it**, which keeps the Super Admin's own "remove it by omitting it" path working. Preserve-not-deny was a **human product decision** taken mid-audit, not a derivation: silently stripping and hard-refusing are both defensible, and the wrong one either loses a grant invisibly or blocks routine edits.
- **It reads the "before" state itself, from the `Role` instance, reloaded fresh** — `$role->load('permissions')`, never a caller-supplied array of current names and never a possibly-stale cached relation. A caller-supplied snapshot is the same class of hole one level up: asserting an untrue "before" makes any new grant look pre-existing.
- **`?Role $role` is nullable but has no default.** `null` means role *creation*, where nothing can currently be granted. A `= null` default would make a forgotten third argument at a future call site silently mean "nothing is currently granted" for what could be an existing, already-granted role.
- **The membership check normalises every shape the write itself accepts.** `syncPermissions()` takes names, ids, `Permission` instances, and arrays/Collections of any of those, flattening them via `HasPermissions::collectPermissions()`. `normalizeNames()` applies the *identical* flattening, so nothing can be invisible to the check while still being honoured by the sync that follows.
- **It throws rather than silently stripping a new-grant attempt**, matching Epic 1's "the action is denied server-side" and never returning HTTP 200 for a refused request.

> **✅ Resolved by task 0010 — the transformer-not-writer limitation was decided, not inherited (0009's Phase 4 finding F3 / Phase 5 finding F-E).** The action returns the permission list to sync rather than performing the sync, so a caller could drop the return value or sync a *different* role than the one it authorized against. Task 0010, which wired the first real call site, chose **option G2: keep the split.** Reopening a three-round-audited, already-closed class to save one statement was not worth it. The two safeguards it carries instead are implementation rules on `saveRole()`, pinned by review rather than by the type system: the return value is **always** assigned back before use, and the `$role` instance the authorization branch resolved (or the row just created) is the **same** instance passed to the action and later synced — never a second, independently-fetched one.

### The second grant meta-rule: you cannot grant what you do not hold

Task 0010's Phase 4 finding **F2** (High, human-confirmed decision) found a second, wider hole in the same place: `roles.manage` authorizes *managing roles*, and nothing stopped a holder of it from rewriting any role's permission set — **including their own role's** — to the full 43-permission catalog. Verified live during the audit against an actor holding two permissions.

[`App\Actions\Roles\EnforceGrantorPermissionScope`](../../../app/Actions/Roles/EnforceGrantorPermissionScope.php) closes it, with the same shape as its sibling: same `(User $actor, array $submittedPermissionNames, ?Role $role)` signature, same "read the before-state from the model, never from the caller" rule, same `AuthorizationException` on refusal. Four properties are specific to it:

- **It diffs, then checks only the *newly granted* names against `$actor->getAllPermissions()`.** Revoking is never refused (see the asymmetry note below).
- **It excludes `roles.manage-administrators` from its own scope entirely** (`->reject(...)`), deferring that one permission to `EnforceAdministratorPermissionGrant`. Without the exclusion the two actions would contradict each other: this action's rule is "do you hold it?", and `RolePolicy::grantAdministratorPermission()`'s rule is that holding it never confers the right to grant it onward. **The exclusion is the mechanism, not the call order** — verified by running the two in reverse, which refuses identically.
- **A Super Admin actor is exempt outright.** They hold zero permission rows by design (the bypass is their authorization), so a literal "do you hold what you're granting" reading would refuse them from granting anything at all.
- **A grant-scope rule is one-directional by construction.** It restricts granting, not revoking, so a `roles.manage` holder can still strip permissions from a role they neither hold nor created — privilege *consolidation*, not escalation, and always repairable by a Super Admin. Accepted deliberately (round-2 finding N1). The actor's **own** access is protected separately, by `saveRole()`'s self-lockout guard, which refuses a save that would strip `roles.manage` from a role the actor currently holds.

⚠️ **The two actions treat an omission in opposite ways, and that is safe only because of a third file.** `EnforceAdministratorPermissionGrant` **preserves** an omitted-but-already-granted permission; `EnforceGrantorPermissionScope` **ignores** the omission and lets the sync revoke. The combination works because `Roles\Index::permissionOptions()` returns the **unfiltered** `web` catalog and the paired view renders essentially all of it, so nothing a role holds is ever invisibly absent from the payload. Filtering that catalog down to what the actor may grant would turn the second action into a silent-revoke bug.

Since task 0011 shipped the view, that "essentially" is load-bearing and worth stating exactly: [`resources/views/livewire/roles.blade.php`](../../../resources/views/livewire/roles.blade.php) withholds **one** checkbox — `roles.manage-administrators`, for an actor failing `grantAdministratorPermission` — which is safe *only* because that is the one permission whose guard preserves an omission. Nothing else may be added to that filter without giving `EnforceGrantorPermissionScope` a matching preserve branch first. Both halves of the rule, with the shipped ✅ (a single `->reject()` before the `groupBy()`) and the ❌ per-item form it replaced, are in [security/authorization-patterns.md](../../security/authorization-patterns/payload-omission-and-registries.md#a-control-omitted-from-the-dom-is-safe-only-for-the-one-value-whose-guard-preserves-an-omission), alongside [the two-guards rule itself](../../security/authorization-patterns/payload-omission-and-registries.md#two-guards-on-one-payload-must-agree-on-what-an-omission-means).

The general rules this layer produced — preserve-don't-revoke on a partially-visible full-set sync, normalise every shape the downstream write accepts, and the two-guards-one-payload rule above — are in [security/authorization-patterns.md](../../security/authorization-patterns/ability-coverage-and-guards.md#a-full-set-sync-behind-a-partially-visible-form-must-preserve-what-the-actor-cannot-see).

### `Gate::authorize` at the call site, not only at the route

`can:users.view` on the route proves only the **page-level** ability. Every method of `App\Livewire\Users\Index` that mutates re-authorizes as its **first statement** (`Gate::authorize('create', User::class)`, `Gate::authorize('update', $target)`, `Gate::authorize('delete', $target)`), and `mount()` re-checks `viewAny` on its own. That is mandatory rather than defensive: `Livewire::test()` and the `/livewire/update` endpoint both reach the component **without ever running route middleware**.

Since task 0008a the two write actions authorize `create` / `update` again on their own (see [The guard belongs to the action, not to the caller](administrator-tier.md#the-guard-belongs-to-the-action-not-to-the-caller)), which makes the component's calls genuine defence in depth rather than the only layer — but does **not** make them removable: `deleteUser()` calls no action at all, and a component that stopped authorizing would be relying on every future collaborator to do it instead. The full rule set — including which route middleware silently does *not* follow a component, and why `#[Locked]` is what keeps the authorized identity and the written identity the same — is in [security/livewire-authorization.md](../../security/livewire-authorization.md).

[`App\Livewire\Roles\Index`](../../../app/Livewire/Roles/Index.php) (task 0010) follows the identical shape and extends it in two ways worth copying on the next module screen:

- **The disclosure paths authorize too, not just the mutations.** `openEditModal()` and `confirmDeleteRole()` hand the client a role's name and permission set, so each resolves its target and `Gate::authorize()`s `update` / `delete` before writing anything to the component's public state. `openCreateModal()` authorizes `create` even though it neither mutates nor discloses — deliberately, so no reader has to work out which method is the one exception. **Task 0015 brought the older Users screen up to the same standard** (finding F7): its three openers had shipped with no check at all since task 0004, and now authorize `create` / `updateSensitiveAttributes` / `delete` respectively. Note the Users screen's edit opener asks a **stronger** ability than its own `save()` does, which is correct rather than inverted — the reasoning, and why `confirmDelete()` gets no self-row exemption while `openEditModal()` does, is in [security/livewire-authorization.md](../../security/livewire-authorization/entry-point-and-method-gates.md#the-shipped-disclosure-gates-and-why-the-disclosure-check-is-the-stronger-ability).
- **Every role resolution is `where('guard_name', 'web')`-scoped**, matching the validation rules (task 0010 Phase 4 finding F5). Defence in depth rather than a live gap — this app defines only the `web` guard — but leaving resolution unscoped while validation is scoped would let a rename pass validation and then hit the composite unique index as a raw, unhandled `23000`.

### `Gate::allows()` in a list query is a UI hint, not a layer

A policy is also consulted **per row while rendering**, which is a different job from the mandatory checks above and must not be confused with them. `App\Livewire\Users\Index::loadUsers()` asks, once per user in the list, exactly what the guarded call it hints at asks:

```php
// app/Livewire/Users/Index.php — loadUsers()
'canEdit' => $user->is(Auth::user()) || Gate::allows('updateSensitiveAttributes', $user),
'canDelete' => Gate::allows('delete', $user),
```

The view renders that row's edit/delete action `disabled` when the flag is `false` (see [api/routes.md](../../api/users-and-roles.md#usersindex--the-first-permission-gated-route)). Five things make this safe and worth copying on the next module screen:

- **The hint mirrors the call it guards, not the write further down.** Until task 0015 `canEdit` read `Gate::allows('update', $user)`, matching `save()`. It now matches **`openEditModal()`** instead — the same ability, and the same `$user->is(Auth::user())` identity idiom, because the edit button's first effect is to open that modal. Two consequences that are behaviour changes, not restatements: an **Administrator-holding other target** now renders `disabled` for an actor holding only `users.edit` (it used to render enabled and permit a rename), and the actor's **own** row always renders enabled regardless of permissions. Copy the principle rather than the ability: **ask what the click actually invokes.**
- **The same policy method decides both the hint and the outcome**, so the disabled state matches what a click would do for every actor/target combination but the two named below — a `Super Admin` target, an Administrator-holding target without `roles.manage-administrators`, an already-trashed target: each resolves `false` here for exactly the reason it would 403 there. Deriving the hint from a *re-stated* rule ("hide it when the actor lacks `users.edit`") is the anti-pattern this avoids; that copy goes stale the first time the policy grows a branch.
- **Two combinations have drifted, and both are known accepted gaps — because both are guarded by rules that deliberately live outside `Gate`.** This is the recurring cost of [the direct-throw pattern](administrator-tier.md#a-rule-that-must-bind-a-super-admin-actor-cannot-go-through-gate): a `Gate::allows()` hint is structurally blind to any rule the `Gate` does not decide.
  1. **`canEdit`, since task 0008a.** For a **Super Admin actor** viewing a **Super Admin-holding target**, `Gate::allows(…)` returns `true` (the bypass grants it), so the row renders enabled — but `UpdateUser`'s direct-throw guard refuses the save on click.
  2. **`canDelete`, since task 0015.** For any actor `UserPolicy::delete()` allows — a **Super Admin** (via the bypass), or a non-`Administrator` actor holding `users.delete` directly — **their own row** renders enabled, but `deleteUser()`'s self-delete guard makes the confirm click a no-op that just closes the modal. That guard is a direct `$target->is(Auth::user())` check placed *above* `Gate::authorize('delete', …)`, for the same reason `UpdateUser`'s Super Admin refusal is a direct throw: a `UserPolicy::delete()` rule would be undone by the `Gate::before` bypass for exactly the actor it most needs to bind. Do **not** "fix" the drift by moving it into the policy.
  Note the direction in both cases: always *enabled-then-refused* (or enabled-then-no-op), never disabled-then-permitted, so it costs a confusing click and never leaks an action. The no-op case is why `deleteUser()` closes the confirmation modal before returning (task 0015 Phase 5 finding A-1) — a silent guard still owes the user feedback.
- **It adds nothing to the security posture and must never be treated as if it did.** `save()` and `deleteUser()` still re-authorize independently as their first statement — unchanged by this — because the client can call either without the list ever having been rendered (see [the section above](#gateauthorize-at-the-call-site-not-only-at-the-route)). A disabled attribute is a courtesy to the user, not a control.
- **`Gate::allows()`, never `Gate::authorize()`.** Rendering a list must not throw on the rows the actor cannot touch; `allows()` returns a `bool` and `authorize()` raises `AuthorizationException`.
- **The per-row cost is bounded.** `UserPolicy` asks the *target* about roles (`hasRole(Role::superAdminName(), 'web')`), which the list's `with('roles')` eager load already satisfies in memory, and asks the *actor* about permissions, which `spatie/laravel-permission` serves from its 24-hour cache — so N rows do not mean N queries. Two notes specific to this list: `delete()`'s `trashed()` branch is unreachable from here (the `SoftDeletingScope` already excluded those rows), and a `Super Admin` actor sees every action enabled because [the bypass](super-admin.md#the-super-admin-bypass) grants before any policy method runs — which matches the mutating path in every case except the one named in the bullet above.

**Task 0011 copied it to the second module screen, which is what "worth copying" was meant to produce.** `App\Livewire\Roles\Index::roles()` appends the same two flags per row, against `RolePolicy` instead of `UserPolicy`:

```php
// app/Livewire/Roles/Index.php — roles()
->each(function (Role $role): void {
    $role->canEdit = Gate::allows('update', $role);
    $role->canDelete = Gate::allows('delete', $role);
});
```

Two differences from the Users screen worth knowing, neither of them a change to the rule:

- **The flags are appended as pseudo-attributes on the `Role` model, not projected into an array.** `Users\Index::loadUsers()` builds an `array<int, array{…}>`; `roles()` returns a real `EloquentCollection` because the view still needs `users_count` and the eager-loaded `permissions` relation off each row. `App\Models\Role` carries a `@property` docblock for both so Larastan resolves them.
- **This screen's accepted drift is on `canDelete`, not `canEdit`** — a Super Admin actor viewing the seeded `Administrator` row, per [the ⚠️ above](administrator-tier.md#the-administrator-tiers-immutability-name-locked-undeletable-permissions-still-editable). Same direction as the Users screen's (enabled-then-refused, never the reverse) and same cause (a categorical rule the `Gate::before` bypass sits in front of), on a different ability.
