# [0064d] UpdateUser — a concurrent status/role change must not be decided or overwritten on a stale read (backend)

## Description
`App\Actions\Users\UpdateUser` decides whether the `updateSensitiveAttributes` Gate and step-up
(`EnsureRecentPasswordConfirmation`) must run by comparing the submitted status against
`$user->getRawOriginal('status')` — a value baked into the instance whenever the caller (always
`App\Livewire\Users\Index::save()`) hydrated it, never re-read. The actual write, inside
`DB::transaction()`, then does `$user->status = $status; $user->save();` with no `lockForUpdate()`
re-read of the row. Two administrators racing to change the same user's status therefore have their
authorization *decision* made on data that may already be stale by decision time, and their *write*
is plain last-writer-wins with no detection that anything moved underneath either of them.

This is **R-2** of story [0064c](in-progress/0064c-activate-verified-user-status-race-compare-and-set-backend.md)
(recorded there as a related-but-distinct risk, **not** fixed there — 0064c's own R-2 write-up
explains why this class of bug cannot reopen 0064c's own fix: once `ActivateVerifiedUser` is a
guarded compare-and-set, a suspension that commits first makes it refuse, and if the listener
commits first `UpdateUser`'s write still lands and ends `Suspended` either way). **Lower severity
than 0064c**: this is an authorized administrator's own action against another account, not a
self-grant, and no unauthenticated or unprivileged path reaches it. Raised as its own story per
0064c's **OQ-5**, owner decision **(b)** ("raise it now"), 2026-09-27.

**What actually gets fixed, precisely (see D-1):** not "which write wins" — there is no single
correct winner between two administrators, and last-writer-wins on the *final persisted value* is an
accepted outcome here. What's broken is narrower: the sensitive-attribute/step-up **decision** can be
made against data that no longer matches the row, in either direction — a stale-looking "no change"
that is actually a real transition (under-triggers the gate), or a stale-looking "change" that
already matches what's currently persisted (over-triggers it) — and the eventual **write** can
silently clobber a status a second administrator set in the interim with no signal to either party
that a conflict happened.

Backend only: no screen, no route, no migration, no schema change (`status` carries no index, same
finding 0064c made for the sibling action; the fix is a primary-key `lockForUpdate()` re-read, which
needs no index it doesn't already have via the clustered PK).

## Type
backend | includes database-expert: **yes, query/lock semantics only** — a `lockForUpdate()` re-read
by primary key inside the existing transaction; **no** table, column, index or migration change
(confirmed by the database-expert).

## Three Amigos participants
`product-owner` (facilitator) + `backend-expert` (approach and files) + `backend-qa` (test design) +
`database-expert` (lock mechanics and InnoDB semantics). Convened as three parallel contributions
from one shared brief; the facilitator reconciled them. No disagreement on the core mechanism (all
three independently converged on a `lockForUpdate()` re-read as the right tool, contrasted explicitly
against 0064c's single-predicate CAS, which none of them thought fit this multi-step case). See
[Provenance](#provenance).

## Gherkin

Every scenario carries exactly one `When` and opens with a named business-role actor, per
[gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3.
*"Compare-and-set"*, *"`lockForUpdate()`"* and *"stale instance"* are mechanism, so they get no
scenario (mirrors 0064c's **D-9**).

> Scenarios 2 and 3 are reachable in production only inside a race window a single PHP process
> cannot hold open; they are reproduced deterministically with the same stale-instance technique
> 0064c's D-6 already established and validated for this codebase.

```gherkin
Feature: A concurrent status change is never decided or overwritten on a stale read

  Scenario: An administrator changes another user's status normally
    Given an administrator viewing an active user's profile
    When the administrator suspends that user
    Then the user's status becomes suspended

  Scenario: A second administrator's suspension is not silently undone by a stale resubmission
    Given an administrator has loaded a user's profile while that user was active
    And a second administrator suspends that same user before the first administrator submits
    When the first administrator submits the profile with the status left exactly as they last saw it
    Then the user remains suspended
    And the resubmission is treated as a real, authorization-worthy change

  Scenario: A status change based on an outdated profile is refused instead of silently applied
    Given an administrator has loaded a user's profile while that user was active
    And a second administrator changes that same user's status before the first administrator submits
    When the first administrator submits a different status chosen from the outdated profile
    Then the change is refused as a conflict
    And the second administrator's change is left standing

  Scenario: An administrator editing their own profile is never subject to this guard
    Given an administrator editing their own name on their own profile
    And a second administrator has changed the acting administrator's own status moments earlier
    When the acting administrator saves their own profile
    Then the name change succeeds
    And the acting administrator's status is left exactly as the second administrator set it
```

## Files to create/modify

### Production — one action, no new files

| Path | What & why |
| --- | --- |
| `app/Actions/Users/UpdateUser.php` | **Modify.** Two changes, in the order given. **(A) Harden the pre-transaction decision's inputs:** immediately before `authorizeRoleAndStatusChange(...)` is called (still outside any transaction, still before `RequestEmailChange`), add `$user->refresh(); $user->load('roles');` (extends the existing `load('roles')` staleness guard — already the first statement, already justified for an identical reason — to also refresh scalar attributes, so `getRawOriginal('status')`/`('email')` reflect "now" rather than "whenever the caller hydrated `$user`"). **(B) Make the write a locked, verified compare-and-set**, first statement inside the existing `DB::transaction()` closure (position unchanged — still after `RequestEmailChange`, which stays exactly where it is and is NOT wrapped in the transaction): re-fetch the row with `User::query()->whereKey($user->getKey())->lockForUpdate()->first()`; if `null` (the target was deleted between the form load and submission), throw a distinct, clearly-named conflict exception rather than writing through to a gone row; otherwise compare the locked row's `status` and role-id set against what (A)'s decision was made against — a **narrow CAS on the decision-relevant columns only**, not a re-run of `authorizeRoleAndStatusChange()` (re-running the Gate calls would risk a second, spurious refusal log and a redundant step-up throw for a request that did not actually change) — and if either moved, throw the conflict exception instead of proceeding; if they still match, sync `$user`'s attributes from the locked read (`setRawAttributes()`/`syncOriginal()`, the same primitive `Model::refresh()` uses, but fed from the **locked** row and applied onto the **same instance the caller passed in** — never a new object, since `App\Livewire\Users\Index::updateExistingUser()` mutates and re-reads that same `$target` reference after the call for its own audit-log line) and proceed with the existing `fill()`/`status =`/`save()`/`syncRoles()`, unchanged. |

**Not touched, deliberately:** `ActivateVerifiedUser`, `ActivateInactiveUser` (0064c's own fix —
out of scope and cannot be reopened by this story, see Description), `App\Livewire\Users\Index`,
`app/Models/User.php`, `app/Policies/UserPolicy.php`, `app/Actions/Users/RequestEmailChange.php`
(stays at its current call site, still outside the transaction — see D-2), `database/**`,
`routes/**`, `config/**`.

**Explicitly excluded from this story's scope, recorded as a risk rather than fixed here (D-3):**
the *role*-staleness window between `$user->load('roles')` (now re-run in step (A) above) and
`syncRoles()` inside the transaction — a second administrator changing this same target's roles in
that narrower internal window. Structurally the same class of problem, but a distinct, narrower
window than the caller-hydration staleness this story closes, and closing it needs its own locked
re-read against `model_has_roles` — deliberately deferred rather than folded in, mirroring how
0064c itself deferred this very story rather than enlarging its own scope.

### Tests

| Path | What & why |
| --- | --- |
| `tests/Feature/Users/UpdateUserStatusRaceTest.php` | **New.** Stale-instance technique (0064c's D-6, technique 1 — the only technique this story needs: `UpdateUser` has exactly one caller and no nested-transaction shape, unlike 0064c's three callers). R1 (a genuinely different submitted status is still correctly gated — not red-first), R3 (an unrelated third user is untouched — not red-first), G1 (a same-as-stale-original resubmission, when the row actually changed underneath, still requires step-up — **predicted red-first, confirm at Phase 3**), G2 (the persisted value must never silently revert on a coincidental no-op resubmission — **predicted not-red, confirm at Phase 3**: if this comes back red it is a more severe finding — a silent clobber — than a gate skip, and reclassifies this story), G3 (isolates a Gate refusal from step-up, so a genuine authorization failure is distinguishable from "the gate never ran"), and the new conflict path (a submission based on an outdated profile is refused, not silently applied — new behaviour, red-first since no such check exists today). |
| `tests/Feature/Users/UpdateUserStepUpAuthorizationTest.php` | **Extend.** N1 (self-edit is structurally unaffected — a second administrator's concurrent status change on the *acting* administrator's own row survives a self-edit save untouched; not red-first, `$isSelfEdit` already skips the gate and the status write entirely) and N2 (a plain, no-race no-op status resubmission triggers no gate/step-up/write; not red-first, but not currently pinned by an existing test either — a straight regression net). |

**Kept unchanged:** every existing `UpdateUser*Test.php` file not named above — the happy path,
the Super-Admin-holder/assignment guards, and the promote/downgrade Gate tests are all unaffected by
this change and stay as the regression net.

## Tests to perform

> **Read this before writing any test in this story.**
> **(a) Predictions, not certainties — verify at Phase 3, exactly like 0064c's own correction to
> its "(a)" reading note.** G1 is predicted red-first (today's `$statusChanged` comparison evaluates
> `false` for a resubmission matching the stale original, even when the row has since genuinely
> changed, so the gate is predicted to be silently skipped); G2 is predicted **not** red-first
> (Eloquent's own dirty-tracking should already exclude an unchanged `status` from the `UPDATE`).
> If G2 comes back red instead, stop and report it before writing any fix code — it would mean
> today's code can silently clobber a concurrent administrator's change, which is a more severe,
> differently-scoped finding than "the gate can be skipped."
> **(b) Never `Event::fake()`** — no event this story touches is under test here, but the same
> principle applies to `Gate`: assert on the real `Gate::authorize()`/`AuthorizationException`
> path, never a mocked Gate.
> **(c) Explicit `id` on every `User::factory()->make()`**, same reason as 0064c (`HasUuids`
> assigns it in a `creating` hook).
> **(d) Never `refresh()`/`fresh()` the stale instance under test.**
> **(e) Never test lock blocking or run a second real connection** — same constraint 0064c's
> reading note (e) already established for this codebase; `RefreshDatabase` keeps data uncommitted,
> so a two-connection test would hang on InnoDB's lock timeout, and a `pcntl_fork` test would test
> MySQL, not this code. What CAN be proven by a single-process test: the *behavioral* outcome via
> the stale-instance technique (does the decision/write track the current row, not a hydrated-then-
> stale one), and a *structural* pin via `DB::listen` that a `lockForUpdate()` query actually ran
> against `users` by primary key — never a claim that the lock itself blocked a concurrent writer.

**Feature — `tests/Feature/Users/UpdateUserStatusRaceTest.php`** (new)

- [ ] **R1** — a submission that genuinely differs from both the stale original and the current row is
      still correctly gated (step-up required). *Not red-first.*
- [ ] **R3** — an unrelated third `Inactive` user's status/`updated_at` are untouched by this test
      file's own race fixtures. *Not red-first.* *Kills:* dropping target-scoping from the fix.
- [ ] **G1** — a resubmission matching the stale original, when the row was concurrently changed to
      something else, still requires step-up. **Predicted red-first — confirm at Phase 3.**
- [ ] **G2** — the same fixture, with a fresh confirmation: the row's status is unchanged by the
      resubmission (no silent revert to what the stale screen showed). **Predicted not red — confirm
      at Phase 3; if red, stop and report before implementing.**
- [ ] **G3** — same fixture as G1, actor lacks the sensitive-attribute permission tier: assert
      `AuthorizationException`, distinguishing "the gate never ran" from "the gate ran and refused."
- [ ] **Conflict path** — an administrator submits a status change chosen from an outdated profile
      (the row has since genuinely moved to a third value): the change is refused as a conflict, and
      the concurrent administrator's write is left standing. *Red-first — no such check exists today
      (today's code silently overwrites).*
- [ ] **Locked read is structural, not assumed** — one `DB::listen`-pinned query matching
      `lockForUpdate` against `users`, scoped by primary key, actually runs during a status-changing
      call. *Kills a mutation that silently drops the lock while leaving the rest of the CAS intact.*

**Feature — `tests/Feature/Users/UpdateUserStepUpAuthorizationTest.php`** (extended)

- [ ] **N1** — a self-edit (name-only) succeeds unaffected while a second administrator concurrently
      changes the acting administrator's own status; the concurrently-set status survives exactly.
      *Not red-first* — `$isSelfEdit` already skips the gate and the status write entirely.
- [ ] **N2** — a plain, no-race resubmission of the current status triggers no gate, no step-up, no
      write. *Not red-first*, but not currently pinned by an existing test — a straight regression net
      for status specifically (the role/email no-op cases are already covered elsewhere).

**Explicitly not tested**
- **Lock blocking / a real two-connection race** (reading note (e)).
- **InnoDB's own locking semantics** — vendor/engine behaviour, not this app's outcome.
- **The `roles`-staleness window** (see Files, "Explicitly excluded") — out of scope, recorded as a
  risk, not a story.
- **A target soft- or hard-deleted between form load and submission** (N3, considered and set aside
  by `backend-qa`): the realistic window is milliseconds inside one synchronous request, far narrower
  than 0064c's async email-confirmation window; the locked re-read returning `null` is handled by the
  same conflict-exception path as any other CAS mismatch (see Files), but no dedicated test is
  required to prove a case this unlikely to be reachable in practice — revisit only if it turns out to
  matter operationally.

## Expected outcome

Two administrators racing to change the same user's status no longer have either the authorization
decision or the write itself made against stale data. A resubmission that looks like a no-op against
a stale screen but is actually a real transition against the current row still requires step-up and
authorization; a submission genuinely based on an outdated profile is refused as a conflict instead of
silently overwriting a concurrent administrator's change. A legitimate, uncontested status change is
unaffected. Self-edits, which never touch status, remain structurally untouched by this guard. No
route, screen, schema, or caller change.

## Acceptance criteria
- [ ] `UpdateUser` re-reads the target's scalar attributes and roles immediately before
      `authorizeRoleAndStatusChange(...)` runs, so the sensitive-attribute/step-up decision is made
      against current, not caller-hydration-time, data.
- [ ] `UpdateUser`'s write, inside its existing transaction, re-reads the target row under
      `lockForUpdate()` by primary key and verifies the decision-relevant columns (status, role-id
      set) still match what the pre-transaction decision was made against before writing; a mismatch
      is refused as a distinct conflict, not silently overwritten.
- [ ] A target deleted between form load and submission is refused as a conflict, not silently
      written through to a gone row.
- [ ] `RequestEmailChange`'s call site and non-transactional shape are unchanged; the sensitive-
      attribute/step-up gate still runs before it, and it still runs before the transaction.
- [ ] Self-edits are structurally unaffected — no lock, no re-read, no new exception path on that
      branch.
- [ ] `ActivateVerifiedUser`, `ActivateInactiveUser`, `app/Livewire/Users/Index.php`,
      `app/Models/User.php`, `database/**` and `routes/**` are unchanged.
- [ ] The role-staleness window (roles loaded fresh, but not re-verified again inside the lock) is
      recorded as an explicit, deliberately excluded risk, not silently left undocumented.
- [ ] Every existing `UpdateUser*Test.php` case not touched by this story still passes unmodified.

## Definition of Done
- [ ] Tests written and green, plus the **full** existing suite in a **single isolated run**, per
      [contracts.md](../../docs/contracts.md)'s Full Test Suite Gate Rule.
- [ ] All **three** quality gates run **unscoped**, each result recorded explicitly *including any
      that was not run*: `php artisan test` (or `vendor/bin/pest -d memory_limit=-1`),
      `vendor/bin/pint --format agent` (not `--dirty`), and **Larastan level 7**
      (`vendor/bin/phpstan analyse`).
- [ ] The red-then-green sequence recorded in the task file, including the G1/G2 Phase-3
      confirmation (or correction, mirroring 0064c's own precedent for a wrong "(a)" prediction).
- [ ] Code reviewed (code-reviewer). **Point the review at**: `RequestEmailChange`'s ordering is
      genuinely unchanged; the CAS in step (B) compares only the columns the decision actually
      depended on (no over-broad or under-broad comparison); no path writes `status`/roles except
      through the locked, verified path.
- [ ] No security findings (appsec-auditor). **Point the audit at**: does the new conflict-exception
      path leak anything about the concurrent administrator's change to the losing request; is the
      locked re-read scoped correctly (no IDOR); does this reshaping change anything about the
      Super-Admin-holder/assignment guards' ordering or bypassability.
- [ ] Documentation updated (docs-keeper): `docs/security/model-instance-trust.md` gets a new
      worked example alongside `SalesRegion` (a caller-hydrated, multi-step decision needing a locked
      re-read — the mirror image of 0064c's single-predicate CAS entry on the same page);
      `docs/architecture/authentication/features-registration-and-status.md` or the closest
      equivalent doc covering `UpdateUser` gets its snippet updated if it shows the old,
      unguarded write; every touched doc's footer and `docs/README.md`, base branch fetched first.
- [ ] Task-coordination files regenerated when this file is created and again when it moves, and
      the two-direction link-integrity check run at each stage move, per
      [task-files-links-and-ordering.md](../../docs/workflow/task-files-links-and-ordering.md).
- [ ] Acceptance criteria met.

## Documented functional decisions

### D-1 — Locked re-read plus a narrow CAS, not a single guarded `UPDATE` and not a full re-authorization
This case does not collapse to 0064c's single-predicate-on-one-row shape (`ActivateInactiveUser`'s
`inactive → active`): `UpdateUser` makes several separable decisions (Super-Admin-holder throw,
promote/downgrade Gate, `updateSensitiveAttributes` Gate, step-up trigger) off multiple fields
(status, role set, email), matching `model-instance-trust.md`'s `SalesRegion` shape (multi-step
read-decide-write), not 0064c's. All three participants independently converged on this. The design
is two-phase rather than a single lock-and-decide, to respect an existing, deliberate ordering
constraint: `authorizeRoleAndStatusChange()` must run — and, critically, `RequestEmailChange`'s
non-transactional `Notification::route(...)->notify(...)` side effect must fire or not fire — *before*
the transaction, never inside it (an existing errors-log entry already records what goes wrong when
code is wrapped in a transaction it wasn't designed for). Phase (A) refreshes the instance so the
*decision* is made against current data; Phase (B), inside the existing transaction, re-verifies
under a primary-key `lockForUpdate()` that nothing moved between (A)'s decision and the write, and
refuses as a conflict if it did — narrower and cheaper than re-running the Gate calls a second time
(which would risk a spurious refusal log and a redundant step-up throw for a request that didn't
actually change anything).

*Rejected — re-run the full `authorizeRoleAndStatusChange()` under the lock.* Duplicate refusal
logging and a possible duplicate step-up throw for a request that turns out not to have changed are
worse outcomes than a purpose-built, narrower "did the decision-relevant columns move" check.

*Rejected — move `authorizeRoleAndStatusChange()` (and/or `RequestEmailChange`) inside the
transaction.* Breaks the documented, deliberate ordering that keeps a non-transactional mail side
effect from being silently relocated or duplicated by a rollback/retry.

*Rejected — silently proceed last-writer-wins after a fresh (unlocked) re-read alone (`backend-qa`'s
raw option (a)).* Closes the *decision*-staleness half of the problem but leaves the *write* itself
exactly as racy as today — two administrators' near-simultaneous submissions could still silently
clobber each other with no detection. The chosen design closes both halves.

*Rejected — refuse with new UI messaging asking the administrator to reload (`backend-qa`'s raw
option (b)).* A bigger change than this story's stated backend-only scope; a thrown conflict
exception (surfaced through Livewire's existing error handling) is enough to prevent a silent
clobber without a new screen.

### D-2 — `RequestEmailChange`'s position and shape are unchanged
Its non-transactional side effect and its placement between the gate and the transaction are load-
bearing for a different, already-solved problem (story 0015's finding F10) and are explicitly outside
this story's scope — restated here because it is the constraint that shaped D-1's two-phase design,
not a decision this story makes.

### D-3 — The role-staleness window is recorded, not fixed
`$user->load('roles')`, re-run in step (A), is not itself re-verified again inside the lock in step
(B) — a second administrator changing this same target's roles in the narrower window between (A)'s
refresh and (B)'s lock is not closed by this story. `database-expert` and `backend-qa` independently
flagged this as real but narrower and lower-priority than the caller-hydration staleness this story
does close (it requires a second administrator racing a role change against the *same* target inside
this call's own decision window, not merely any concurrent write anywhere). Closing it fully needs a
second, separate locked read scoped to `model_has_roles`. Deferred rather than folded in, for the
same reason 0064c deferred this entire story rather than enlarging its own scope: mixing two guard
classes into one change makes each harder to review and test independently.

### D-4 — A soft- or hard-deleted target between form load and submission is a refused conflict, not a silent write-through
Today's code has no re-read at all and writes through to whatever `$user` already held, deleted or
not. The locked re-read in step (B) returning nothing for a deleted target is handled by the same
conflict-exception path as any other CAS mismatch — a genuine, narrow behaviour improvement over
today (never resurrects or silently overwrites a deleted account's row), analogous in spirit to
0064c's D-5 finding for the sibling action, though the observable outcome differs deliberately:
0064c's flow is an anonymous confirmation link and stays silent by design; this flow is an
administrator actively submitting a form and is refused with a visible conflict instead, since silent
failure would be worse UX for an active administrative action than for a passive callback.

### D-5 — What a single-process test can and cannot prove here
Same constraint as 0064c's D-6/reading note (e): no real two-connection lock-blocking test, no
`pcntl_fork`. The stale-instance technique (0064c's technique 1) is sufficient on its own — unlike
0064c, `UpdateUser` has exactly one caller and no nested-transaction shape, so technique 2 (the
`DB::listen` interleave hook 0064c needed for its three callers) is not needed here. A structural
`DB::listen` pin proves the locked read executes; it does not and cannot prove the lock itself
blocks a concurrent writer — that remains vendor/engine behaviour, asserted by reasoning, not by a
Pest assertion.

## Dependencies, risks and open questions

### Verified findings (2026-09-27)
- **`UpdateUser`** (`app/Actions/Users/UpdateUser.php`): `authorizeRoleAndStatusChange()`'s
  `$statusChanged`/`$emailChanged` computed from `getRawOriginal()` on the caller-hydrated instance;
  the write (`$user->status = $status; ... $user->save();`) inside `DB::transaction()` with no
  `lockForUpdate()`.
- **Single caller:** `App\Livewire\Users\Index::save()`, which loads the target fresh via
  `findOrFail()` immediately before calling this action — no nested-transaction caller exists, unlike
  0064c's three `Verified`-firing callers.
- **`$user->load('roles')`** already runs fresh as the first statement (story 0008a's finding N1
  already closed the caller-hydration-staleness class for roles specifically); the asymmetry with
  `status`/`email` (not re-read) is what this story closes.
- **`users.status` carries no index** (`docs/database/schema-users-auth.md`); `users.id` is a UUIDv7
  clustered primary key, so a `lockForUpdate()` PK lookup takes an exclusive record lock with no gap
  lock — identical reasoning to 0064c's own D-1.
- **Lock order preserved:** `UpdateUser` already takes the `users` row before touching
  `model_has_roles` via `syncRoles()`; an explicit `lockForUpdate()` on `users` only makes an
  already-first acquisition happen slightly earlier in the same transaction, not a different order.
- **`model_has_roles`** carries a non-unique secondary index on `(model_uuid, model_type)` — if the
  D-3 role-staleness window is ever closed, a `lockForUpdate()` scoped to it would take record locks
  plus a bounded gap lock on that index range (not gap-lock-free the way the PK lookup is), per
  `database-expert`; not needed for this story's own scope.

### Dependencies
- **Depended on [0064c](in-progress/0064c-activate-verified-user-status-race-compare-and-set-backend.md)**
  (closed 2026-09-27; raised this story per its OQ-5, owner decision (b)). No code dependency —
  `UpdateUser` and `ActivateVerifiedUser`/`ActivateInactiveUser` do not share a file — only a
  provenance link.
- No other pending story modifies `UpdateUser`, `App\Livewire\Users\Index`, or
  `RequestEmailChange` (grep of `ai-spec/tasks/`: only mentions) — no `conflict_risk_with` entry.

### Risks
- **R-1 — The role-staleness window (D-3) stays open.** Recorded, not fixed; a possible future
  follow-up story if it turns out to matter in practice.
- **R-2 — A conflict exception is a new failure mode for `App\Livewire\Users\Index`.** The component
  must surface it as a user-facing message rather than an uncaught 500 — confirm at Phase 3 whether
  `Index::save()`/`updateExistingUser()` already has a catch-all for `UpdateUser`'s other exceptions
  (`AuthorizationException`, `PasswordConfirmationRequiredException`) that this new exception type can
  reuse, or whether it needs its own handling. If it needs new Livewire-side handling, that may push
  this story toward being reclassified full-stack — flag at Phase 2/3, don't assume backend-only
  survives contact with the real component.
- **R-3 — `Role::syncModels()`/`assignToModels()`/`removeFromModels()`'s own lock order against
  `model_has_roles`/`users` is unconfirmed** (`database-expert` could not verify it from the files
  read in this debate) — worth a quick check at Phase 3 that nothing in those methods locks
  `model_has_roles` before `users` in a way that could deadlock against this story's `users`-first
  lock, though no evidence of this was found.

### Open questions
- **OQ-1 — Does the new conflict exception need Livewire-side UI work (R-2)?**
  - **(a) No — an existing generic exception handler in `Index` already covers it (recommended,
    pending Phase 3 confirmation).**
  - (b) Yes — needs a new user-facing message; reclassify as full-stack if so.
- **OQ-2 — N3 (soft/hard-deleted target mid-flight): is a dedicated test worth adding given how
  narrow the window is?**
  - **(a) No dedicated test; the conflict-exception path already covers it structurally
    (recommended).**
  - (b) Add one anyway for explicit documentation value.

### Not verified in this pass
- **No test or query was run** by this Phase 1 pass — reasoned from code reading and the same
  engine-semantics argument 0064c's own D-1 already established for this table. Phase 3's tests are
  what confirm it, including the G1/G2 predictions.
- **`Index::save()`/`updateExistingUser()`'s exact current exception-handling shape** (OQ-1) — to be
  confirmed against the file at Phase 3.

## Provenance

- **Raised by:** story 0064c, **R-2** and **OQ-5**; owner decision **(b)** ("raise it now as its own
  Three Amigos story"), 2026-09-27.
- **Debate:** `product-owner` (facilitator) with `backend-expert`, `backend-qa` and `database-expert`,
  each contributing once from a shared brief, in parallel. The project's agent definitions in
  `.claude/agents/` were **not registered** as `subagent_type`s in the session that ran this debate,
  so each role was played by a general-purpose agent instructed to read and follow its own definition
  file — the same substitution 0064c's own provenance recorded, not hidden here either. No
  disagreement recorded: all three independently converged on a locked re-read as the right
  mechanism and explicitly contrasted it against 0064c's single-predicate CAS shape, agreeing it
  doesn't fit this multi-step case.
- **Facilitator findings that changed the document:** (1) `backend-qa`'s raw OQ framing
  ("last-committed-wins-safely" vs. "refuse with new UI") was superseded by `backend-expert`'s
  two-phase synthesis (refresh-then-verify-under-lock, narrow CAS, throw a conflict only on an actual
  mismatch), which closes both halves of the problem `backend-qa`'s two options each only closed one
  half of — recorded as D-1's rejected alternatives rather than left as an open question for Phase 2;
  (2) `database-expert` and `backend-qa` independently flagged the *role*-staleness window as a
  distinct, narrower risk from the *status*-staleness window this story closes — both recommended
  excluding it from scope, adopted as D-3; (3) `backend-expert` surfaced a genuine, previously-absent
  behaviour change (a soft/hard-deleted target now refuses instead of silently writing through) —
  recorded as D-4 rather than silently adopted or silently dropped.
- **Models followed for tone and structure:**
  [0064c](in-progress/0064c-activate-verified-user-status-race-compare-and-set-backend.md), whose D-1/D-6/D-8
  structure this story's D-1/D-5 and testing sections mirror throughout.
- **Status:** Phase 1 output (new stage). Phase 2 (INVEST validation) not yet run.
