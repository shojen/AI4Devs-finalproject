# Authorization Patterns — Ability coverage, protected identity and relation reloads

> Part of [Authorization Patterns](../authorization-patterns.md). **Read this part when:** you write or review an ability's coverage, a guard reading a row's protected identity, a Super-Admin-binding rule, a relation reload before a check, or a full-set sync behind a partial form. The other parts are listed in the [hub](../authorization-patterns.md#table-of-contents).

## An ability must cover every attribute that achieves its effect, not only the operation it is named after

Established by finding **F1** of task 0004's Phase 4 audit, and the most transferable rule that audit
produced. `App\Policies\UserPolicy` gates three *operations* on an `Administrator`-holding target
behind `roles.manage-administrators` — `promoteToAdministrator`, `downgrade`, `delete`. The Users
editor enforced all three faithfully. It was still bypassable, because two plain **columns** on the
same edit form reach the same outcome without going through any of them:

- `status` → set an `Administrator` to `Suspended` and story 0007 refuses their sign-in. Functionally
  a `delete`, reached through `users.edit`.
- `email` → park a new address in `pending_email`; the attacker controls the mailbox the confirmation
  link is sent to, so completing it hands them the account. Functionally a takeover, again through
  `users.edit`.

The guard set was drawn around the *verbs the policy names* rather than around the *effects the
policy exists to prevent*. The rule:

> When you write an ability that protects a class of target, enumerate every writable attribute of
> that target and ask, for each one, "does changing this achieve what the ability forbids?" Every
> `yes` belongs behind the same ability.

✅ Good — the shipped fix, an ability that composes the base check and then applies the same
target-class rule the operation-shaped abilities apply:

```php
// app/Policies/UserPolicy.php
public function updateSensitiveAttributes(User $actor, User $target): bool
{
    if (! $this->update($actor, $target)) {
        return false;
    }

    if (! $target->hasRole(RoleName::Administrator->value, 'web')) {
        return true;
    }

    return $actor->hasPermissionTo('roles.manage-administrators');
}
```

Two details of the call site that are load-bearing, not incidental. Task 0008a moved that call site out
of the Livewire component and into the action itself
([`app/Actions/Users/UpdateUser.php`](../../../app/Actions/Users/UpdateUser.php),
`authorizeRoleAndStatusChange()`), and both details survived the move — they are properties of the
*comparison*, not of where it lives:

- **The change-detection comparison must use the same normalisation on both sides, and the same one
  the writer uses to decide whether it is writing.** The guard fires only when an attribute actually
  changed, so a comparison that is *stricter* than the writer's leaves an unguarded write:

  ```php
  // app/Actions/Users/UpdateUser.php
  $emailChanged = $email !== Str::lower((string) $user->getRawOriginal('email'));
  $statusChanged = $status->value !== $user->getRawOriginal('status');
  ```

  Both sides are lowercased (`$email` is normalised at the top of `__invoke()`), and `getRawOriginal()`
  is used rather than `$user->email` / `$user->status` for two reasons that both produce a false
  "unchanged" verdict: `User`'s `email` accessor lowercases on read, so reading through the accessor on
  one side and the raw column on the other manufactures a mismatch — and, since the comparison now runs
  *inside* the action, a caller that had already staged `$user->status = $status` before invoking it
  would otherwise make the status comparison silently false, skipping the gate for a change that is
  about to be persisted regardless (task 0008a's finding F2). The email comparison is byte-identical to
  the one `UpdateUser` makes before delegating to `RequestEmailChange`, which is what guarantees the
  guard cannot disagree with the write.

- **The guard runs before the first write, so a denial cannot leave a partial write.** Every
  authorization check in `UpdateUser` precedes its `DB::transaction()` block, and `CreateUser`'s
  precede its own.

❌ Bad — the pre-fix shape (adapted from the deleted `Index::updateExistingUser()`): only the role
comparison is gated, so an unchanged `roleId` — which is what the edit modal prefills — short-circuits
the guard and nothing else is checked:

```php
// anti-pattern — the shape F1 found
if ($applyRoleAndStatus) {
    $this->authorizeRoleChange($target, (int) $validated['roleId']);
}

$updateUser($target, $name, $email, $roleId, $status, $applyRoleAndStatus, $requestEmailChange);
```

That snippet carries a second, independent flaw task 0008a closed: the whole guard hangs off
`$applyRoleAndStatus`, a **caller-supplied** boolean. See
[the direct-throw rule below](#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check).

**Known residual, now a confirmed product decision rather than a deferral** (findings F2/F3; settled by
story 0008a): the predicate is *role*-shaped (`hasRole(RoleName::Administrator->value, 'web')`) while
the privilege it protects is *permission*-shaped. A target holding `roles.manage-administrators`
through a direct `model_has_permissions` grant, or through a custom role that is not literally named
`Administrator`, is **not** covered by any of these four abilities. That is inert today — the seeded
catalog grants no permission directly to a user and ships only two roles — and becomes live the moment
story 0009 lets operators build administrator-equivalent roles.

Story 0008a centralised the rule (one predicate, `App\Models\Role::isAdministratorRole()`) and
deliberately **kept it keyed on the name**: administrator-level is defined by the role's identity, not
by its permission set. That is a PRD-scoped limitation with the human decision recorded on story 0009,
and it is pinned by a test — a custom role holding *every* permission the seeded `Administrator` holds
is assignable with a bare `users.edit`. Re-opening it is a deliberate, visible change to that test, not
a silent redefinition. See
[architecture/authorization.md](../../architecture/authorization/administrator-tier.md#one-predicate-two-shapes).

## A guard that reads a row's protected identity must distinguish "not hydrated" from "hydrated but null"

Established by task 0008's Phase 4 **re-audit** (finding R1), which was a working, executable bypass of
the Super Admin role's immutability guard — found *after* a first fix for the adjacent case had already
shipped and been reviewed. The rule generalises to every model-level guard this repo adds from here on.

A model event guard answers "is the row being mutated the protected one?" by reading an attribute. Three
different sources are available at guard time and they disagree with each other in exactly the situation
an attacker controls:

| Source | On a rename, mid-`updating` | On a partially-hydrated instance |
| --- | --- | --- |
| `$this->getAttribute('name')` | the **new**, attacker-supplied name | the new name |
| `$this->getOriginal('name')` | the persisted name ✅ | `null` — the column was never selected |
| database read-back | the persisted name ✅ | the persisted name ✅ |

`getOriginal('name')` is the right source, but it returns `null` for **two different reasons** — "the
persisted value is null" and "the column was never hydrated" — and `??` cannot tell them apart. That is
what made the first fix wrong:

❌ Bad — the shipped-then-fixed form. The database read-back was added deliberately for the unhydrated
case, and `??` short-circuits before ever reaching it:

```php
// anti-pattern — this is the exact code finding R1 bypassed
$name = $this->getOriginal('name') ?? $this->getAttribute('name');
```

On `Role::query()->select('id')->whereKey($id)->firstOrFail()->update(['name' => 'Pwned'])`, `fill()`
has already run by the time `updating` fires, so `getOriginal('name')` is `null` (never selected) while
`getAttribute('name')` is `'Pwned'` — non-null, so the `??` returns the attacker's own new name, the
comparison against `superAdminName()` fails, and the rename of the Super Admin role succeeds. Note the
near miss: the same partially-hydrated instance mutating **`guard_name`** *is* caught by the broken form,
because `getAttribute('name')` is then `null` and the `??` falls through. A test covering only the
`guard_name` case passes on the vulnerable code — the identifying attribute must be the one under test.

✅ Good — the current form in [`app/Models/Role.php`](../../../app/Models/Role.php). `array_key_exists()`
asks the question `??` cannot, and the in-memory attribute is never consulted for a persisted row.
Task 0008a extracted this resolution into `persistedName()` so that **every** identity check on this
model shares one implementation — the Super Admin guards, the row-shaped `isSuperAdminRoleRow()`, and
the Administrator tier's `isAdministratorRole()`:

```php
private function persistedName(): ?string
{
    if ($this->exists && $this->getKey() !== null) {
        return array_key_exists('name', $this->getOriginal())
            ? $this->getOriginal('name')
            : static::query()->whereKey($this->getKey())->value('name');
    }

    return $this->getAttribute('name');
}
```

**Extending a guard is the moment to extract, not to copy.** A second tier needing the same
"read this row's real name" logic is a second chance to get it subtly wrong, and the wrong version
passes every test that doesn't specifically load a partial row.

Four things about this shape are load-bearing:

- **`array_key_exists`, not `isset`/`??`.** All three of `isset()`, `?:` and `??` collapse "absent" and
  "null" into one branch; only `array_key_exists()` separates them.
- **The `$this->exists && $this->getKey() !== null` gate is what keeps the `creating` path from
  attempting a keyless lookup.** Verified by query log: creating an ordinary role issues an `INSERT` and
  the permission-cache flush, and no `SELECT`.
- **Keep the "what name is being written" check as a separate method.** `guardAgainstAssumingSuperAdminName()`
  deliberately *does* read the in-memory attribute, because its job is refusing a create/rename **into**
  the protected name. Two guards, two sources, opposite directions — merging them reintroduces R1.
- **Verify with a rename, not with a delete.** Delete and `guard_name` mutation both pass on the
  vulnerable form.

The same trap has now bitten this repo twice from different directions: see also
[login-status-enforcement.md](../login-status-enforcement.md)'s `getPrevious()`-not-`getOriginal()` rule,
where the pre-save value a listener needed had already been overwritten by `syncOriginal()`. Whenever a
security decision depends on a model's **pre-mutation** state, name the exact source and prove it holds
on a partially-hydrated instance — Eloquent offers several plausible-looking readers and they diverge
precisely under attacker control.

Residual, **narrowed by task 0008a and closed by task 0009** (finding F4). Through 0008a,
`App\Policies\RolePolicy`'s Super Admin branch and the `Gate::before` deferral in `AppServiceProvider`
both compared `$role->name` — the in-memory attribute — so a *partially-hydrated* or mid-rename Super
Admin role passed to `Gate::authorize()` was not recognised at the policy layer and the check returned
the actor's ordinary `roles.manage` answer instead of a categorical `false`. It was never exploitable
(the model-level guard above still refused the mutation, and no call site passed a `Role` to
`authorize()`), but it left two layers disagreeing about one row shape.

Both sites now call `Role::isSuperAdminRoleRow($role)`, which reads `persistedName()` — so every
identity question in the app is hydration-safe by construction. The generalisation worth keeping:
**a fix for this class of bug is not finished until every layer that answers the same identity
question has been converted.** 0008a fixed the model guard and extracted the helper; the policy and
the `Gate::before` deferral kept the old attribute read for a further story, and a partial conversion
is exactly the state in which two layers can be pointed at the same row and return different answers.
When you extract an identity helper, grep for every remaining comparison against the same attribute
in the same pass.

## A rule that must bind a Super Admin actor must be a direct throw, not a `Gate` check

Established by task 0008a's Phase 4 audit (finding F1) and its re-audit (finding N2), both of which
were live privilege paths through `App\Actions\Users\CreateUser` / `UpdateUser`.

`Gate::before` runs **before any policy method**, and this app's bypass returns `true` for a Super
Admin. So every `Gate::authorize()` / `Gate::allows()` / `$user->can()` call is, for that one actor, a
guaranteed grant — no matter what the policy behind it says. A rule written as a `Gate` check is
therefore a rule that **does not apply to the most privileged actor in the system**, which is usually
the exact actor a categorical invariant exists to bind.

❌ Bad — the shape N2 found. The intent is "nobody may demote the platform's own Super Admin", and it
is inert for a Super Admin actor, who is the only actor who could otherwise reach it:

```php
// anti-pattern — Gate::before grants before UserPolicy::update() is ever consulted
if ($target->hasRole(Role::superAdminName(), 'web')) {
    Gate::authorize('update', $target);   // returns true for a Super Admin actor
}
```

✅ Good — the shipped form in [`app/Actions/Users/UpdateUser.php`](../../../app/Actions/Users/UpdateUser.php).
The refusal is a statement, not a question, so nothing can grant past it:

```php
if ($currentRoles->contains(fn (Role $role): bool => Role::isSuperAdminRoleRow($role))) {
    throw new AuthorizationException('A Super Admin holder cannot be modified through this action.');
}
```

Three corollaries:

- **Decide "must this bind the Super Admin too?" before choosing where the rule lives.** If yes, it
  belongs in the model (a `boot()` guard or a method override) or in the action — never in a policy.
  This is the same reasoning that puts the role model's own invariants in
  [layers 1 and 2](../../architecture/authorization/super-admin.md#three-guard-layers) rather than in `RolePolicy`,
  and why `UserPolicy::delete()`'s trashed-target refusal is knowingly *not* binding on a Super Admin.
- **Throw `AuthorizationException`, so the refusal is indistinguishable from a policy denial** — the
  caller still sees a 403, and a Livewire action still fails the same way at the same point.
- **A `Gate::allows()`-driven UI hint cannot see such a rule**, so the disabled state of a row action
  can legitimately drift from what a click does. Accept the drift in the *enabled-then-refused*
  direction only, and record it; never "fix" it by moving the rule back under `Gate`. The live
  instance is documented in
  [architecture/authorization.md](../../architecture/authorization/grant-meta-rules-and-ui-hints.md#gateallows-in-a-list-query-is-a-ui-hint-not-a-layer).

The mirror-image mistake is just as costly and was the *other* half of F1/N2: checking only the value
being **submitted** and never the target's **current** state. `UpdateUser` originally refused assigning
the Super Admin role but happily *removed* it, because `syncRoles()` replaces the entire role set — an
irrecoverable lockout, since `Gate::before` is the only route to unrestricted access. **A guard on a
protected identity must check both directions: what is being written, and what the row already is.**

## Authorization that consults a relation must reload it before the first check reads it

Established by task 0008a's Phase 4 re-audit (finding N1), which appeared *while fixing* an earlier
finding — the reload existed, but it sat below the `Gate::authorize()` call that needed it.

`$target->hasRole(...)` reads the roles collection **already loaded on the instance** when there is
one, and only queries when there is not. A policy that identifies its target by role therefore reads
whatever the caller hydrated. That is attacker-influenced input the moment the action is callable from
anywhere but one trusted component: `->with('roles')` is the natural, performance-motivated idiom
(`App\Livewire\Users\Index::loadUsers()` already uses it), so handing the action a deliberately stale
collection is a one-line evasion of `UserPolicy::update()`'s Super Admin-target exclusion.

✅ Good — the shipped form. The reload is the **literal first statement**, above even the `Gate` call:

```php
// app/Actions/Users/UpdateUser.php — __invoke()
$user->load('roles');

Gate::authorize('update', $user);
```

❌ Bad — the same two statements in the opposite order (adapted to illustrate; this is the ordering N1
found):

```php
// anti-pattern — the Gate call resolves the policy against the caller's stale collection
Gate::authorize('update', $user);

$user->load('roles');   // too late: the exclusion has already been evaluated
```

**Rule.** In an action or policy-calling method, reload every relation an authorization decision
depends on **before the first check that consults it** — not merely somewhere before the write. Two
things make this easy to get wrong: the reload is usually added for a *different* reason (here, making
"is the target an Administrator?" read the whole collection rather than an unordered `first()`), so its
placement is chosen for that purpose and never re-examined against the checks above it; and the stale
path fails **open** and silently, since a missing role is indistinguishable from a role the target
genuinely does not hold. `load()` (not `loadMissing()`) is what the rule needs — `loadMissing()` is
precisely a no-op when the caller already supplied the stale collection.

> **Confirmed safe (task 0015, Phase 4) — and the reason the `load()`-not-`loadMissing()` clause above
> is now load-bearing rather than defensive.** Task 0015's audit-log work (finding F5) gave this action
> a **second pre-hydrating caller**, in the same request, one statement above the call:
> `App\Livewire\Users\Index::updateExistingUser()` captures the "before" role for its `Log::info` line
> by reading `$target->roles` — which hydrates the relation on the very instance it then hands to
> `UpdateUser`. The audit confirmed no finding: `__invoke()`'s `$user->load('roles')` **forces** a
> re-query, so the pre-hydrated collection is discarded before any check consults it, and this caller's
> collection was never stale to begin with (it comes from a fresh `User::findOrFail()` in the same
> request).
>
> The durable point is what would break it. When N1 was written, `loadMissing()` looked like a
> harmless micro-optimisation only a hypothetical attacker-controlled caller would punish. It is now
> one edit away from a real regression through **this repo's own code**: swapping `load()` for
> `loadMissing()` would make `UpdateUser` authorize against whatever the component happened to hydrate
> for a *logging* purpose. **Rule: a forced reload is not redundant merely because every caller you can
> see is trustworthy — count the callers that hydrate the relation before the call, and treat that
> count going up as a reason to re-read this section, not as a reason to skip the reload.** The
> "before"-state capture that pre-hydrates it must also stay above the call for its own reason (it
> reads pre-write values), so the two constraints are stable together rather than in tension.

## A full-set sync behind a partially-visible form must preserve what the actor cannot see

Established by task 0009's Phase 4 audit (finding F1), whose correct resolution required a **human
product decision** rather than a derivation.

Two facts that are individually reasonable combine into a privilege *loss*:

- `Role::syncPermissions()` — like `syncRoles()`, and like every `sync*()` in Eloquent — **replaces the
  entire set**. What is absent from the payload is removed, not left alone.
- The `roles.manage-administrators` toggle is rendered **only** to the Super Admin
  ([`RolePolicy::grantAdministratorPermission`](../../architecture/authorization/grant-meta-rules-and-ui-hints.md#who-may-grant-a-permission--the-meta-rule-layer)),
  and rendered as *absent from the DOM*, not merely disabled.

So a broad administrator holding `roles.manage`, editing an unrelated field on a role that legitimately
holds the administrator-level permission, submits a payload that **omits** it — not as a decision, but
because the field was never in their form. A guard that only asks "does the payload contain the
protected permission?" passes that request happily, and the sync silently revokes a Super Admin's
grant. Verified against the real vendor code, not reasoned about: `syncPermissions()` detaches
everything not present.

❌ Bad — the shape F1 found. It reads as a complete guard, and covers only half the diff:

```php
// anti-pattern — nothing here notices a REMOVAL the actor was never shown
if (in_array($administratorLevelPermission, $submittedNames, true)) {
    Gate::forUser($actor)->authorize('grantAdministratorPermission', Role::class);
}
```

✅ Good — the shipped form in
[`app/Actions/Roles/EnforceAdministratorPermissionGrant.php`](../../../app/Actions/Roles/EnforceAdministratorPermissionGrant.php),
which diffs both directions and re-adds what an unprivileged actor could not have meant to remove:

```php
if ($isSubmittedGranted && ! $wasGranted) {
    Gate::forUser($actor)->authorize('grantAdministratorPermission', Role::class);
}

if ($wasGranted && ! $isSubmittedGranted && Gate::forUser($actor)->denies('grantAdministratorPermission', Role::class)) {
    $submittedPermissions[] = RolePolicy::ADMINISTRATOR_LEVEL_PERMISSION;
}
```

**Rule.** Whenever a **full-replace** write is driven by a form that shows the actor only *part* of the
set, absence in the payload does not mean "remove this" — it means "the actor had no opinion". Diff
the submitted set against the current one and authorize **each direction separately**: adding a value
the actor may not add, and removing a value the actor may not remove, are two different abilities that
happen to share one form.

Three corollaries:

- **Which behaviour is correct is a product decision, not a security one.** *Preserve* (keep the value,
  let the rest of the save succeed) and *deny* (refuse the whole request) are both defensible: the
  first can hide from the actor that their submission was partly ignored, the second blocks routine
  edits on any role that happens to hold a protected value. Task 0009 stopped mid-audit and asked the
  human, who chose **preserve**. Record the choice next to the code — an unrecorded one reads as an
  oversight to the next auditor, who will "fix" it in whichever direction they'd have picked.
- **Preserve must not become "nobody can ever revoke it".** The re-add is conditional on the actor
  *failing* the grant ability, so the Super Admin's own "remove it by omitting it" path still works.
  A guard that preserves unconditionally converts a permission into an irrevocable one.
- **The same shape applies to `syncRoles()`, and it has already bitten this repo once** — see the
  mirror-image note under [A rule that must bind a Super Admin actor](#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check),
  where `UpdateUser` refused *assigning* the Super Admin role while happily removing it.
