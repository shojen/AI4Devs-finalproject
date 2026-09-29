# Livewire Component Authorization — Authorization in the action, not only the component

> Part of [Livewire Component Authorization](../livewire-authorization.md). **Read this part when:** you extract or call an action and must ensure the rule binds every other caller (jobs, Artisan, tests). The other parts are listed in the [hub](../livewire-authorization.md#table-of-contents).

## Authorization that lives only in the component is bypassed by every other call site of the action

The rule for this repo: **a privilege rule about *which* role may be assigned belongs in the action,
the policy, or the validation rule set — not in the screen that happens to be the only caller today.**
A Livewire component is a delivery mechanism; an Artisan command, a queued job, a future REST
controller or a sibling screen calling the same action inherits none of its `Gate::authorize()` calls.

**Task 0004's audit recorded this as an open gap on the Users screen; task 0008a closed it.** The
before/after is worth keeping, because the *shape* of the gap recurs on every module screen:

| Control | Where it lived (task 0004) | Where it lives now (task 0008a) |
| --- | --- | --- |
| "Super Admin is never assignable" | `UserValidationRules::roleRules()` — survives, if the caller validates | **also** a direct `throw` in `CreateUser` / `UpdateUser`, which survives regardless |
| "a Super Admin holder is not editable" | `UserPolicy::update()` — survives, if the caller gates | **also** a direct `throw` in `UpdateUser`, which additionally binds a Super Admin actor |
| "adding/removing `Administrator` needs `roles.manage-administrators`" | ❌ `App\Livewire\Users\Index::authorizeRoleChange()` only | ✅ `UpdateUser` / `CreateUser` themselves; the component method is deleted |
| "changing an `Administrator`'s `status`/`email` needs `roles.manage-administrators`" | ⚠️ the ability travelled (`UserPolicy::updateSensitiveAttributes()`) but the **decision that a change occurred** stayed in the component | ✅ both live in `UpdateUser`, which compares against `getRawOriginal()` |

That last row was the finer-grained case and the instructive one: pushing the *rule* into a policy is
not enough while the **trigger** — comparing the submission against stored state to decide whether the
ability applies at all — stays in the caller. A second caller inherits an ability nothing ever asks.

Two rules to carry forward, both proven by how this was closed:

- **Move the check, don't duplicate it.** The component still authorizes `create` / `update` / `delete`
  at its own call sites (defence in depth, and `deleteUser()` calls no action at all), but there is
  exactly **one** implementation of each tier rule. Two copies drift.
- **A caller-supplied flag is not a guard.** `UpdateUser` used to receive the self-lockout boolean from
  the component; it now derives it from `Auth::user()` itself. See
  [conventions/base-standards.md](../../conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)
  for the convention, and
  [authorization-patterns.md](../authorization-patterns/ability-coverage-and-guards.md#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check)
  for why the two Super Admin refusals are direct throws rather than `Gate` checks.

_Last updated: 2026-09-04 — Story 0027 (Products list + editor UI). Four additions across three sections. [The routeless case](entry-point-and-method-gates.md#the-routeless-case-a-component-with-no-route-has-no-per-request-backstop-at-all) gains a new confirming instance that is genuinely new in shape, not merely a third repeat: `App\Livewire\Products\Editor` **is** routed (`can:products.view` replays on every round trip), and its two `#[On]` gallery-selection listeners still needed their own gate (F-6, code review re-audit) — the generalised rule recorded is that a route's `can:` replay covers only the ability it names, and a method inside that route asking a *different, finer* ability (`media.view` here) still needs its own check regardless of whether the component has a route at all. [`#[Locked]` is what makes `Rule::unique()->ignore()` safe here](locked-properties.md#locked-is-what-makes-ruleunique-ignore-safe-here) gains its fifth confirming instance (`$productId`, written only from the route-model-bound `$product->id`). [Every server-derived property is `#[Locked]`](locked-properties.md#every-server-derived-property-is-locked-not-just-the-ids) gains a ✅ for the editor's four imagery properties (`$featuredMediaId`/`$featuredPreview`/`$galleryMediaIds`/`$galleryPreviews`, all derived server-side and never from the `#[On]` payload) contrasted directly against `$regionIds`, deliberately unlocked as the `SearchableMultiSelect` child's `#[Modelable]` binding target — the same "is this ever legitimate request input" test answered oppositely for two properties on one screen. **No new `docs/errors-log.md` entry** — this story's Phase 4/5 findings (F-1 through F-6, N-1, R-1) are mechanical rules about specific methods, already recorded in the task file's own Phase 4/5 records and in [security/array-validation-bounds.md](../array-validation-bounds.md), matching this project's precedent for not duplicating audit findings here. **Verified as unchanged rather than assumed:** the `PersistentMiddleware` allow-list table, the `$toggle` confirmed-safe subsection, and the save-time-gate/display-twin section.

_Earlier revision notes: [security--livewire-authorization--action-level-authorization.md](../../history/security--livewire-authorization--action-level-authorization.md)._
