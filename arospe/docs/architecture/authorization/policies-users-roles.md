# Authorization — Policies: overview, User and Role

> Part of [Authorization](../authorization.md). **Read this part when:** you need the policy roster overview, `UserPolicy` abilities or `RolePolicy`. The other parts are listed in the [hub](../authorization.md#table-of-contents).

## Policies

Permissions answer "may this actor do this *kind* of thing at all". A **policy** answers the question a permission cannot: "may this actor do it *to this particular record*". [`App\Policies\UserPolicy`](../../../app/Policies/UserPolicy.php) (task 0004) is the first one in the app, and the template for the rest. There are **fourteen** today: `UserPolicy`, [`RolePolicy`](#rolepolicy--the-second-policy) (task 0008), [`SalesRegionPolicy`](policies-sales-media-categories.md#salesregionpolicy--the-third-policy-and-the-first-with-no-target-branch) (task 0017), [`MediaPolicy`](policies-sales-media-categories.md#mediapolicy--the-fourth-policy-and-the-first-behind-no-route-at-all) (story 0019), [`ProductCategoryPolicy`](policies-sales-media-categories.md#productcategorypolicy--the-fifth-policy-and-the-first-to-gain-its-call-site-in-a-later-story-than-the-one-that-created-it) (story 0023), [`ProductPolicy`](policies-products.md#productpolicy--the-sixth-policy-and-the-second-built-entirely-for-a-screen-that-does-not-exist-yet) (story 0024), [`ProductAttributeTypePolicy`](policies-products.md#productattributetypepolicy--the-seventh-policy-and-the-first-whose-no-policy-recommendation-was-reversed-at-phase-2) (story 0028), [`ShippingZonePolicy`](policies-shipping-payment.md#shippingzonepolicy--the-eighth-policy-and-the-one-d-9-uses-to-reconcile-the-policy-vs-permission-check-divergence-with-0035) (story 0033), [`ShippingRatePolicy`](policies-shipping-payment.md#shippingratepolicy--the-ninth-policy-and-the-first-with-real-call-sites-from-day-one) (story 0036), [`PaymentMethodPolicy`](policies-shipping-payment.md#paymentmethodpolicy--the-tenth-policy-and-the-one-whose-sole-writer-was-corrected-mid-audit-rather-than-designed-self-authorizing-from-phase-1) (story 0038), [`CustomerPolicy`](policies-customers-orders.md#customerpolicy--the-eleventh-policy-and-the-first-a-passive-record-with-no-privilege-tier-of-its-own) (story 0041), [`OrderPolicy`](policies-customers-orders.md#orderpolicy--the-twelfth-policy) (story 0045), and the two blog taxonomy policies, `BlogCategoryPolicy` (story 0058) and `BlogTagPolicy` (story 0059) — [documented together](policies-sales-media-categories.md#the-blog-policies--blogcategorypolicy-and-blogtagpolicy-the-thirteenth-and-fourteenth).

**Registration: none.** Laravel 13 auto-discovers `App\Policies\<Model>Policy` for `App\Models\<Model>`, so `UserPolicy` is wired to `User` — `RolePolicy` to `App\Models\Role`, `SalesRegionPolicy` to `App\Models\SalesRegion`, `MediaPolicy` to `App\Models\Media` — by naming alone. This repo has **no `AuthServiceProvider`**, and one should not be added to register a conventionally-named policy.

> Auto-discovery is also why nothing registers `RolePolicy` for the *package's* `Spatie\Permission\Models\Role`. `Gate::getPolicyFor()` walks the inheritance chain with `is_subclass_of`, which matches a **subclass of** a registered class — and the package class is the **parent** of `App\Models\Role`, not a child. A raw package instance would go unmatched with or without an explicit `Gate::policy()` line, so adding one buys nothing. That gap is closed by the [one-role-model convention and its `arch()` test](super-admin.md#one-role-model-class-in-application-code) instead.

### `UserPolicy` abilities

| Ability | Signature | Rule |
| --- | --- | --- |
| `viewAny` | `(User $actor)` | holds `users.view` |
| `create` | `(User $actor)` | holds `users.create` |
| `update` | `(User $actor, User $target)` | `false` if `$target` holds `Super Admin`; otherwise holds `users.edit` |
| `updateSensitiveAttributes` | `(User $actor, User $target)` | passes `update`, **and** — if `$target` holds `Administrator` — holds `roles.manage-administrators` |
| `promoteToAdministrator` | `(User $actor, ?User $target = null)` | holds `roles.manage-administrators` |
| `downgrade` | `(User $actor, User $target)` | `true` if `$target` does **not** hold `Administrator`; otherwise holds `roles.manage-administrators` |
| `delete` | `(User $actor, User $target)` | `false` if `$target` holds `Super Admin`; `false` if `$target` is already soft-deleted; holds `users.delete`, **plus** `roles.manage-administrators` when `$target` holds `Administrator` |

**Who calls what changed in task 0008a, and again in task 0015.** `viewAny` / `create` / `update` / `delete` are authorized by [`App\Livewire\Users\Index`](../../../app/Livewire/Users/Index.php); `create` and `update` are *additionally* authorized inside the actions themselves. Two of the three tier-specific abilities — `promoteToAdministrator` and `downgrade` — are authorized **only** in [`CreateUser`](../../../app/Actions/Users/CreateUser.php) / [`UpdateUser`](../../../app/Actions/Users/UpdateUser.php), so a non-dashboard caller inherits them (see [The guard belongs to the action, not to the caller](administrator-tier.md#the-guard-belongs-to-the-action-not-to-the-caller)). **`updateSensitiveAttributes` is the exception since task 0015:** it is still enforced on the write path by `UpdateUser` — *conditionally*, once a status or email change is detected — and is **additionally** asked by the component's `openEditModal()`, *unconditionally*, because that method discloses the target's `pending_email` and `status` before any change has been decided. That is not the pattern task 0008a removed: the component asks the ability directly and branches only on `$target->is(Auth::user())`, an identity check, never on role membership — the tier branch lives inside the policy method itself. See [security/livewire-authorization.md](../../security/livewire-authorization/entry-point-and-method-gates.md#the-shipped-disclosure-gates-and-why-the-disclosure-check-is-the-stronger-ability).

**The trashed-target refusal (task 0005) is the newest branch, and it guards a write rather than a read.** `delete()` returns `false` for a `$target->trashed()`, because [`App\Models\User::delete()`](../../../app/Models/User.php) rewrites the row's email to a placeholder on the way out (see [database/schema.md](../../database/schema-users-auth.md#soft-deletes)) — so without this branch a `withTrashed()` call site could re-run that write against an already-trashed row. Note what it is *not*: being policy-level, it sits **behind** [the `Gate::before` bypass](super-admin.md#the-super-admin-bypass), so a `Super Admin` still reaches `delete` on a trashed target. That is accepted rather than a gap — the placeholder is derived from the immutable UUID, so the re-write is idempotent — and it is the general shape of every policy rule in this app: a `Gate::before` grant is decided before any policy method runs, so a rule that must bind the Super Admin too belongs in the model or action, not here. The rest of the Administrator-level delete/downgrade matrix is unchanged by 0005; the story only re-proved it under the new `SoftDeletes` global scope.

Four properties of this policy are load-bearing and generalize to every policy added later:

**1. The policy calls `hasPermissionTo()`, and that is correct here** — even though [the bypass table](super-admin.md#bypass-coverage--what-it-does-not-cover) marks `hasPermissionTo()` as *not* reaching `Gate::before`. A policy method is only ever reached *through* the Gate, and `Gate::before` runs first: a Super Admin is granted before `UserPolicy` is consulted at all, so the direct query inside it never runs for them. This is why `tests/Feature/Policies/UserPolicyTest.php` can assert a Super Admin passes every ability while holding **zero** permission rows.

> The "gate on permissions, never role names" convention still governs the **call sites** (`Gate::authorize(...)`, `can:` middleware). Inside a policy body, both `hasPermissionTo()` and `hasRole()` are appropriate — the latter for asking a literal question about the *target*, which is exactly what the Super Admin and Administrator exclusions do.

**2. `hasRole()` is always passed the guard, and the name is never a literal.** All five `hasRole()` calls in `UserPolicy` pass `'web'` explicitly, never the one-argument form, per [security/authorization-patterns.md](../../security/authorization-patterns/bypass-cache-and-guards.md#always-pass-the-guard-to-hasrole--hasanyrole). Since task 0008a they also resolve the *name* through the two centralized identities rather than writing it inline — the config-driven one for the Super Admin tier, the locked enum case for the Administrator tier:

```php
// app/Policies/UserPolicy.php — update(); delete() carries the identical pair
if ($target->hasRole(Role::superAdminName(), 'web')) {
    return false;
}
// ... and, in updateSensitiveAttributes() / downgrade() / delete():
if (! $target->hasRole(RoleName::Administrator->value, 'web')) {
```

These are `hasRole()` checks against a **user**, not a `Role` row, which is why they read the name rather than calling `Role::isAdministratorRole()`. Both shapes compare against the same single literal, so they cannot drift — see [The Administrator tier's identity](administrator-tier.md#one-predicate-two-shapes).

**3. `promoteToAdministrator()`'s `$target` is nullable, and that is not decoration.** It is invoked two ways — with an instance on the edit path, and **class-level** on the create path, where no target exists yet:

```php
// app/Actions/Users/CreateUser.php — the create path
Gate::authorize('promoteToAdministrator', User::class);
```

`Gate::callPolicyMethod()` **drops the first argument when it is a class-string**, so the class-level call reaches the method with `$actor` alone. A non-nullable `User $target` parameter would throw `ArgumentCountError` at runtime rather than allowing or denying anything — and it would pass every instance-level test, failing only at that one call site.

**4. `updateSensitiveAttributes` exists because a rule keyed on the *operation* was incomplete.** The Administrator-level guard originally covered only the *role* change; a security audit (task 0004, finding F1) found that `status` and `email` reach the same effect without passing any guard — an actor holding `users.edit` but not `roles.manage-administrators` could suspend another Administrator, or seize their account by pointing its email at an address they control. The general rule this established, with the real ✅/❌ pair, is in [security/authorization-patterns.md](../../security/authorization-patterns/ability-coverage-and-guards.md#an-ability-must-cover-every-attribute-that-achieves-its-effect-not-only-the-operation-it-is-named-after) — it is not repeated here.

### `RolePolicy` — the second policy

[`App\Policies\RolePolicy`](../../../app/Policies/RolePolicy.php) (task 0008, extended by tasks 0009 and 0010) has **five** abilities, and since task 0010 it has a real call site: [`App\Livewire\Roles\Index`](../../../app/Livewire/Roles/Index.php) authorizes against all five. It was built two stories ahead of that consumer, so the Super Admin refusal would be independently effective there from day one, and (since 0009) so the Administrator tier is protected on the *role* side the way 0008a protected it on the *user* side:

| Ability | Signature | Rule | Authorized from |
| --- | --- | --- | --- |
| `viewAny` | `(User $actor)` | holds `roles.manage` | `Roles\Index::mount()` |
| `create` | `(User $actor)` | holds `roles.manage` | `Roles\Index::openCreateModal()`, `saveRole()`'s create branch |
| `update` | `(User $actor, Role $role)` | `false` if `$role` is the Super Admin role; then, if `$role` is the seeded `Administrator` role, holds `roles.manage-administrators`; otherwise holds `roles.manage` | `Roles\Index::openEditModal()`, `saveRole()`'s edit branch |
| `delete` | `(User $actor, Role $role)` | `false` if `$role` is the Super Admin role **or** the seeded `Administrator` role; otherwise holds `roles.manage` | `Roles\Index::confirmDeleteRole()`, `deleteRole()` |
| `grantAdministratorPermission` | `(User $actor)` | holds the `Super Admin` role on the `web` guard — nothing else grants it | `Roles\Index::mount()`, via `Gate::allows()`; enforced in `EnforceAdministratorPermissionGrant` |

**`viewAny` and `create` take no `Role` argument, and that is why the component branches.** `Gate::authorize('update', Role::class)` would resolve the policy from the class string and then call `update($actor)` with **no** second argument — the class name finds the policy, it is not passed to the method — so the shipped `update(User $user, Role $role)` signature raises `ArgumentCountError` rather than denying. `saveRole()` therefore authorizes `create` on the create branch and `update` on the edit branch, mirroring `Users\Index::save()`.

```php
// app/Policies/RolePolicy.php
public function update(User $user, Role $role): bool
{
    if (Role::isSuperAdminRoleRow($role)) {
        return false;
    }

    return Role::isAdministratorRole($role)
        ? $user->hasPermissionTo(self::ADMINISTRATOR_LEVEL_PERMISSION)
        : $user->hasPermissionTo(self::ROLE_MANAGEMENT_PERMISSION);
}
```

Seven notes, the first three of which differ from `UserPolicy` above:

- **This is a complement to, not a substitute for, the model-level guards.** A policy only fires where someone calls `authorize()`; [layers 1 and 2](super-admin.md#three-guard-layers) catch the code paths that don't. Neither layer is redundant.
- **Unlike every `UserPolicy` rule, the Super Admin branch binds the Super Admin actor too** — because the bypass [defers when the target is the Super Admin role](super-admin.md#the-super-admin-bypass). Compare `UserPolicy::delete()`'s trashed-target refusal, which a Super Admin still sails past. The Administrator branch is the opposite: it *is* behind the bypass, which is exactly how a Super Admin edits the seeded `Administrator` role while holding zero permission rows — and, for `delete()`, why that method's categorical Administrator refusal never runs for that actor (finding N3; the model guard refuses instead — see [The Administrator tier's immutability](administrator-tier.md#the-administrator-tiers-immutability-name-locked-undeletable-permissions-still-editable)).
- **`delete()`'s Administrator branch is categorical; `update()`'s is permission-gated.** That divergence is task 0010's finding F1 and is deliberate: the row's permission set is editable, the row itself is not. Do not normalise the two methods back into the identical shape they used to share.
- **Branch order is load-bearing, and is pinned by a test.** The categorical Super Admin refusal runs **first and unconditionally**; the Administrator branch is appended below it. A rewrite that puts the tier branch first would let an actor holding `roles.manage-administrators` edit the Super Admin role. `RolePolicyTest` asserts precisely that ordering rather than only the two happy paths.
- **Both tier identities come from `App\Models\Role`, never from a comparison written here.** `Role::isSuperAdminRoleRow()` and `Role::isAdministratorRole()` are the [one predicate in two shapes](administrator-tier.md#one-predicate-two-shapes); the policy defines neither. A content-scan test (`tests/Feature/Users/AdministratorRoleLiteralContentScanTest.php`) covers this file and fails if a `'Administrator'` / `'Super Admin'` literal reappears in it.
- **The two permission names are class constants, not repeated literals.** `RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION` (`roles.manage-administrators`) and `RolePolicy::ROLE_MANAGEMENT_PERMISSION` (`roles.manage`) are read by this policy, by `EnforceAdministratorPermissionGrant`, and by both classes' tests. Known, deliberately-deferred inconsistency (task 0009 Phase 4 finding **F5**, pre-existing): `UserPolicy` still writes the `roles.manage-administrators` literal at four call sites of its own. Point those at these constants when that cleanup happens — do not assume it already did.
- **"Administrator-level" is name-scoped by design and stays that way.** A custom role *granted* `roles.manage-administrators` does not itself become protected the way the seeded `Administrator` role is — only the literally-named seeded role is. This is the PRD's explicit scope (findings F15/F16 from task 0004's re-audit, reconfirmed by 0009), not an oversight; switching to permission-set-based matching needs a new product decision.

`RolePolicy` calls `hasPermissionTo(...)` directly, matching `UserPolicy`'s six call sites. Consequence, accepted knowingly: on a database with the permission tables migrated but **not seeded**, that throws `PermissionDoesNotExist` (→ 500) rather than denying (→ 403). Switching this one policy to `$user->can(...)` was rejected as a one-off deviation from the codebase's single established pattern for this check; the fix belongs in one pass across all **six** policies — `SalesRegionPolicy` (task 0017), `MediaPolicy` (story 0019), `ProductCategoryPolicy` (story 0023) and `ProductPolicy` (story 0024) all inherited the same shape, `MediaPolicy` with the consequence spelled out on its own section below.
