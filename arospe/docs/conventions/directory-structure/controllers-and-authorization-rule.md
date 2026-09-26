# Directory Structure — Controllers in front of actions; authorization belongs to the action

> Part of [Directory Structure](../directory-structure.md). **Read this part when:** you add a controller, or decide where an authorization check for an operation must live. The other parts are listed in the [hub](../directory-structure.md#table-of-contents).

### Controllers sit in front of actions, not instead of them

`App\Http\Controllers\ConfirmEmailChangeController` is this repo's first domain controller, and it exists for a specific reason worth generalizing: **a controller is added only when there is an HTTP-specific concern — route-parameter binding, building a redirect response — that an `app/Actions/` class should not absorb.** The action stays a plain domain operation; the controller adapts HTTP to it.

✅ Good — the real controller: it turns the URL's `{hash}` segment into a verified address, delegates, and branches on the action's `bool` result to pick a redirect:

```php
// app/Http/Controllers/ConfirmEmailChangeController.php
public function __invoke(User $user, string $hash, ConfirmEmailChange $confirmEmailChange): RedirectResponse
{
    if ($user->pending_email === null || ! hash_equals(sha1($user->pending_email), $hash)) {
        return redirect()->route('profile.edit')->with('status', __('users.email_change.refused'));
    }

    if (! $confirmEmailChange($user, $user->pending_email)) {
        return redirect()->route('profile.edit')->with('status', __('users.email_change.refused'));
    }

    return redirect()->route('profile.edit')->with('status', __('users.email_change.confirmed'));
}
```

Note the action is injected as a **trailing container-resolved parameter**, after the route parameters — the same per-method action-injection convention the Livewire components use (see [code-style.md](../code-style.md#inject-single-purpose-actions-per-method)).

❌ Bad — routing the action class directly (adapted to illustrate; this is what the controller exists to avoid):

```php
// anti-pattern — do not do this
Route::get('settings/email/confirm/{user}/{hash}', ConfirmEmailChange::class);
```

`ConfirmEmailChange::__invoke(User $user, string $email)` takes the *address*, while the URL's second segment is `{hash}`. Laravel binds non-class-typed parameters positionally against the remaining route parameters, so the hash would land in `$email` and the equality check could never succeed — silently, with no error. On top of that, the action returns `bool`, which cannot be a response.

Corollary: don't invert this either. A controller that re-implements the domain logic instead of delegating to an action puts business rules somewhere the Livewire components and future admin screens can't reuse them.

### An authorization rule belongs to the action, not to one of its callers

Task 0008a established this by removing a real gap: the Administrator-tier guards lived only in `App\Livewire\Users\Index`, so `CreateUser` / `UpdateUser` were **completely ungated** for any other caller — a future API endpoint, Artisan command or queued job would have inherited nothing. The rule: **if an operation must not happen without a permission, the check lives in the class that performs the operation.** A caller may authorize too (defence in depth), but it may not be the only place the rule exists.

> **The converse is not true, and task 0015 is the case that shows it.** A check that guards something the *caller alone* does — a Livewire opener copying a target's attributes into public component state — belongs in the caller, and there is no action to move it to: no `app/Actions/` class performs that disclosure. `App\Livewire\Users\Index::openEditModal()`'s `Gate::authorize('updateSensitiveAttributes', $target)` is such a check, and it is **not** a regression of the rule above. Read it as: the rule follows the *operation*, and "hand these attributes to the client" is an operation the component owns. What still may not reappear in a component is a re-derivation of *who the target is* — the tier lookup 0008a deleted; see [security/livewire-authorization.md](../../security/livewire-authorization/entry-point-and-method-gates.md#the-shipped-disclosure-gates-and-why-the-disclosure-check-is-the-stronger-ability).
>
> **Task 0015a adds the second, weaker case, and it is weaker on purpose.** Its step-up guard lives in `UpdateUser` and `CreateUser` — the actions — for role, status, email and Administrator-tier creation, exactly as the rule above demands. For **deletion** it lives in `App\Livewire\Users\Index::deleteUser()`, and only because there is no `DeleteUser` action to move it to: that method calls `$target->delete()` on the model directly. That is an accepted placement pending a class to hold it, not a second exception to the rule — **if a later story extracts a `DeleteUser` action, the guard moves with it.** Record a placement like this in the method's own docblock (as `deleteUser()` does) so the next reader can tell "this is where it belongs" from "this is where it is until something better exists".

✅ Good — the action authorizes as its own first statements, before opening any transaction:

```php
// app/Actions/Users/CreateUser.php
public function __invoke(string $name, string $email, string $roleId, UserStatus $status): User
{
    Gate::authorize('create', User::class);
    // ...
}
```

❌ Bad — the shape this replaced (adapted from the deleted `Index::createNewUser()`; the action itself checked nothing):

```php
// anti-pattern — the rule is a property of one caller, not of the operation
if ((int) $validated['roleId'] === $this->administratorRoleId()) {
    Gate::authorize('promoteToAdministrator', User::class);
}

$createUser(/* ... */);
```

Three constraints that come with it, each learned from this story's audits:

- **Move the rule, never copy it.** Two implementations of one rule is drift waiting to happen; `Index::authorizeRoleChange()` and `administratorRoleId()` were *deleted*, not converted.
- **Derive a security-relevant flag internally; never take it as a parameter.** `UpdateUser` used to receive `bool $applyRoleAndStatus` — the self-lockout guard — from its caller. Once an action is independently callable, that is a one-argument bypass, so the action now derives it from `Auth::user()` itself.
- **Authorize before the first write, and re-read what you authorize against.** Every check sits above the action's `DB::transaction()`, and any relation an authorization decision consults is reloaded before the first check that reads it — see [security/authorization-patterns.md](../../security/authorization-patterns/ability-coverage-and-guards.md#authorization-that-consults-a-relation-must-reload-it-before-the-first-check-reads-it).

> **Task 0017 is the first story where this convention cost nothing, because it was applied at Phase 1 rather than found at Phase 4.** All three `app/Actions/SalesRegions/` actions authorize `update` as their own first statement, and `SetSalesRegionActive` additionally authorizes the **replacement default** row — the second row its operation writes — so a non-dashboard caller inherits the whole rule and not just the part about its named target. Two things generalise from it. **(a) Authorize every row the operation writes, not only the one it is named after** — the row-level counterpart of the [attribute-level rule](../../security/authorization-patterns/ability-coverage-and-guards.md#an-ability-must-cover-every-attribute-that-achieves-its-effect-not-only-the-operation-it-is-named-after) task 0004 established. **(b) The component authorizing too is defence in depth, not duplication to remove.** `App\Livewire\SalesRegions\Index` re-checks the same ability on both rows before calling either action; the action's check is what a queued job or Artisan caller inherits, and the component's is what fails fast before a transaction opens and what makes the per-row `canEdit` hint honest. A reviewer who deletes one of the two has removed a layer, not a redundancy.
>
> ⚠️ **"Authorize before the first write" and "re-read what you authorize against" pull in opposite directions once an action locks its own rows,** and task 0017 is where they first meet. These actions authorize against the **caller-supplied** instance, *outside* the transaction — deliberately, so a refusal never opens one — and only then re-fetch the row under `lockForUpdate()`. That is safe only while `SalesRegionPolicy::update()` ignores its target entirely. The day any policy grows a branch that reads a target attribute, that branch must be evaluated against a re-fetched row; see [architecture/authorization.md](../../architecture/authorization/policies-sales-media-categories.md#salesregionpolicy--the-third-policy-and-the-first-with-no-target-branch) and [security/model-instance-trust.md](../../security/model-instance-trust.md).

What the rules themselves say, and why a rule that must bind a Super Admin actor is a direct `throw` rather than a `Gate` check, belongs to [architecture/authorization.md](../../architecture/authorization/administrator-tier.md#the-guard-belongs-to-the-action-not-to-the-caller), not here.


_Last updated: 2026-09-26 — Story 0063 (blog posts list + editor). Added the `BlogTags/`, `BlogCategories/` and `BlogPosts/` Livewire entries, the `blog-*.php` route and lang files and `tests/Browser/BlogPosts/` to the trees, and corrected the `BlogPostStatus` note (it has had `label()` since this story). Earlier, 2026-09-23 — Story 0055 (orders list + detail/editor UI). Added `app/Livewire/Orders/` (Index flat, Show nested), `routes/orders.php`, the `ResolvesFlagReasonLabel` concern, the first two shared non-chrome anonymous components (`money`, `confirm-dialog`), `tests/Browser/Orders/` and `tests/Support/Orders/`, and corrected the `lang/orders.php` entry (it now carries screen copy). Earlier history folded: 0054/0053 added the two tax-region resolver actions and their shared trait; 0052/0051/0050/0049/0048 grew `app/Actions/Orders/`, `app/Exceptions/` and `OrderPolicy`, and corrected the `OrderStatus::label()` note. Each is described in its own entry above._
