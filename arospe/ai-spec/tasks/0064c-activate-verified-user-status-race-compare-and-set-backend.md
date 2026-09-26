# [0064c] Activate-verified-user — a suspension that lands mid-request must not be overwritten by the activation (backend)

## Description
`App\Listeners\ActivateVerifiedUser` decides on the **in-memory** `User` instance a caller hands it and then
writes `status = Active` with a blind `save()`. If an administrator suspends the account between the moment
that instance was loaded and the moment the listener's `save()` runs, the listener **overwrites `Suspended`
with `Active`**: a privilege-grant race — a suspended account becomes active. This is **R-2** of story
[0064a](in-progress/0064a-activate-verified-user-listener-idempotent-and-single-registration.md), which
recorded it, declined to fix it under that story's **D-2** ("prove and pin, do not change the listener's
write"), and required it to be logged as its own story (**OQ-3**). This is that story.

The fix makes the persisted row the authority for `status`: the write becomes a **guarded compare-and-set**
— `UPDATE users SET status = 'active', updated_at = ? WHERE id = ? AND status = 'inactive'` — and the
listener acts on the affected-row count. The decision "was this the user's *first* email verification" still
comes from the caller's instance through `getPrevious()` (that value is the caller's own just-made write and
is the only place it survives); only `status` moves to the database. The write lives in a new single-purpose
action, `App\Actions\Users\ActivateInactiveUser`, which the listener receives by constructor injection.

Backend only: no screen, no route, no migration, no schema change. One new action, one modified listener,
and tests and docs. **Nothing a user can observe changes**, except inside the race window, where a suspended
account now stays suspended instead of becoming active.

Which callers are exposed (**verified**, see [Findings](#verified-findings-2026-09-26)): Fortify's
`VerifyEmailController` (a request-start instance) and `App\Actions\Fortify\ResetUserPassword` (no
transaction, no lock) are exposed. `App\Actions\Users\ConfirmEmailChange` already re-reads under
`lockForUpdate()` inside its own transaction and fires `Verified` on that locked instance, so no suspension
can land in that flow; it is covered here as **defence in depth**, not as a fix.

## Type
backend | includes database-expert: **yes, query only** (a guarded `UPDATE` and a one-row `SELECT` are added;
**no** table, column, index or migration changes — confirmed by the database-expert, see **D-1**)

## Three Amigos participants
`product-owner` (facilitator) + `backend-expert` (approach and files) + `backend-qa` (test design) +
`database-expert` (the query and InnoDB semantics). Convened as three parallel contributions from one shared
brief; the facilitator reconciled them. **Disagreement, recorded rather than smoothed over:** the
`database-expert` preferred **B** (re-read under `lockForUpdate()` in the listener's own transaction — the
repo's established shape) and accepted **A** as correct; `backend-expert` and `backend-qa` both chose **A**.
See **D-1** and **OQ-1**. See [Provenance](#provenance).

## Gherkin

Every scenario carries exactly one `When` and opens with a named business-role actor, per
[gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3. *"Compare-and-set"*
and *"guarded UPDATE"* are mechanism, so they get no scenario (**D-9**).

> Scenarios 2, 5 and 6 are reachable in production only inside a race window that a single PHP process cannot
> hold open; they are reproduced deterministically (**D-6**). Scenario 6 (email change) is **defence in
> depth**: that flow's row lock already makes the race impossible there.

```gherkin
Feature: A suspension is never overwritten by an email confirmation that was already under way

  Scenario: An invited user is activated when they confirm their email address
    Given an invited user whose account is inactive and who has never confirmed an email address
    When they confirm their email address
    Then their account becomes active

  Scenario: A suspension that lands while the confirmation is under way wins
    Given an invited user whose account is inactive and who has never confirmed an email address
    And an administrator suspends that account after the confirmation request started but before it finished
    When the confirmation completes
    Then their account is still suspended

  Scenario: A user suspended before they open the link stays suspended
    Given an administrator who has suspended a user that never confirmed an email address
    When that user confirms their email address
    Then their account is still suspended

  Scenario: A confirmation that arrives after another one already activated the account changes nothing
    Given another request has already activated an invited user's account
    When that user's slower confirmation completes
    Then their account is still active
    And nothing about their account is rewritten

  Scenario: A user removed while their confirmation is under way is not brought back
    Given an administrator deletes an invited user's account after their confirmation request started
    When the confirmation completes
    Then the account remains deleted and no error is shown

  Scenario: A suspension that lands while a password reset is under way wins
    Given an invited user who has never confirmed an email address is completing a password reset
    And an administrator suspends that account after the reset request started but before it finished
    When the password reset completes
    Then their account is still suspended
```

## Files to create/modify

### Production — one new action, one modified listener

| Path | What & why |
| --- | --- |
| `app/Actions/Users/ActivateInactiveUser.php` | **New.** `__invoke(User $user): bool`. Runs `User::query()->whereKey($user->getKey())->where('status', UserStatus::Inactive->value)->update(['status' => UserStatus::Active->value, 'updated_at' => $now])` with `$now = $user->freshTimestamp()` (through the Eloquent builder, so `SoftDeletes` scoping applies and `updated_at` is set explicitly). **1 row:** sync the caller's instance without a second write — `setAttribute('status', Active)`, `setAttribute('updated_at', $now)`, then `syncOriginalAttributes(['status', 'updated_at'])` (public, leaves `previous`/`changes` alone, so `getPrevious()` still carries the old `email_verified_at`, and the instance ends **clean**, not dirty); return `true`. **0 rows:** change nothing on the instance and return `false`; then one plain `SELECT status` to tell the causes apart — row **absent or soft-deleted** → silent; row **`Active`** (a concurrent double delivery) → silent; any **other** status (in practice `Suspended`) → `LogRefusedPrivilegedAttempt` with a short snake_case reason (**D-4**). Constructor-injects `App\Actions\Auth\LogRefusedPrivilegedAttempt`. **Never** `refresh()` (replaces every attribute and calls `syncOriginal()`), **never** `forceFill()->save()` (a second write). |
| `app/Listeners/ActivateVerifiedUser.php` | **Modify.** Add `public function __construct(private readonly ActivateInactiveUser $activateInactiveUser) {}`; keep the in-memory `instanceof User` / `status !== Inactive` guard and the `getPrevious()` fail-closed guard **byte-for-byte**; replace the last two statements (`$user->status = Active; $user->save();`) with `($this->activateInactiveUser)($user);`. Keep the docblock's `getPrevious()`-not-`getOriginal()` reasoning **exactly**, and add one paragraph: the persisted row is now the authority for `status`, the instance for "never verified". The listener stays **synchronous** — no `ShouldQueue` (a queued listener loses `getPrevious()` through `SerializesModels`). |

**Not touched, deliberately:** the three callers (`ConfirmEmailChange`, `ResetUserPassword`, Fortify's
controller — their "the `save()` writing `email_verified_at` must be the last dirty write before the event"
comments stay true), `App\Actions\Users\UpdateUser` (the suspending side — see **R-2**),
`app/Models/User.php`, `app/Providers/**`, `bootstrap/app.php`, `database/**`, `routes/**`, `config/**`,
`app/Listeners/RejectNonActiveUserLogin.php`, and the registry test from 0064a.

### Tests

| Path | What & why |
| --- | --- |
| `tests/Unit/Listeners/ActivateVerifiedUserTest.php` | **Modify** (needs owner sign-off — **OQ-2**). The listener no longer calls `$user->save()`, so the `save()`-counting `ActivateVerifiedUserTrackedUser` double stops measuring the activation. The unit tests keep their **names and rules**; the listener is built with a recording fake of `ActivateInactiveUser` and they count its invocations instead of `saveCallCount`. The fake emulates the real contract (returns `true` and sets `status = Active` on the instance) or the same-instance idempotency test would call it twice. Add one case: a `false` return leaves the instance untouched. The refusal tests also keep proving that the in-memory guards run **before** any database access (no app is booted in `tests/Unit`). |
| `tests/Feature/Actions/Users/ActivateInactiveUserTest.php` | **New.** The action against the real database: win, lost to a suspension, lost to a concurrent activation, absent row, soft-deleted row, one guarded `UPDATE` (**D-3**, **D-4**, **D-5**). |
| `tests/Feature/Auth/ActivateVerifiedUserSuspensionRaceTest.php` | **New.** The listener through the **real dispatcher** (never `Event::fake()`), stale-instance technique, once outside a transaction and once inside `DB::transaction` (the `ConfirmEmailChange` shape) (**D-6**). |
| `tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Auth/PasswordResetTest.php`, `tests/Feature/Settings/EmailChangeTest.php` | **Extend.** One interleave test each, through the real route/action (**D-6**). Existing tests untouched. |

**Kept unchanged:** `tests/Feature/Auth/ActivateVerifiedUserIdempotencyTest.php` and
`tests/Feature/Providers/EventListenerRegistrationTest.php` (0064a) — the first must still show exactly one
`UPDATE` on `users` and an unchanged `updated_at` on repeats (the guarded `UPDATE` **is** that one statement);
the second is why a constructor dependency on the listener cannot break registration.

### Docs (docs-keeper, Phase 6)

| Path | Change |
| --- | --- |
| `docs/security/login-status-enforcement.md` | Note that the suspended-to-active race is closed (near the `ActivateVerifiedUser` constraints); keep its single `_Last updated_` line. |
| `docs/architecture/authentication/features-registration-and-status.md` | The `ActivateVerifiedUser` snippet and the state description: the write is now the guarded action. |
| `docs/architecture/authentication/two-factor-passkeys-logout-and-map.md` | File map: the new action and tests. |
| `docs/security/model-instance-trust.md` | A short section: a decision that is a **single predicate on one row** may collapse to a guarded `UPDATE` plus an instance sync, and why that differs from the `SalesRegion` multi-step cases that need a row lock; state that it is safe **only while `User` has no `saving`/`updated` model hooks**. |
| `docs/architecture/authorization/step-up-and-refusal-logging.md` | The new snake_case refusal reason (`activation_refused_status_changed`, name to confirm at Phase 3). |
| `docs/conventions/directory-structure/app-layers.md`, `docs/conventions/naming/classes.md` | Only if they enumerate actions. |
| 0064a's task file | **Done in the story-creation pass:** its R-2/OQ-3 DoD item now points here; the loose D-2 sentence "needs a compare-and-set on a different condition" (R-2 itself names `WHERE status = 'inactive'`; D-2 rejected that SQL only as *write avoidance*) is corrected by a note in the hand-off, not rewritten. |
| Every touched doc's footer, `docs/README.md` | **Fetch the base branch first.** One `_Last updated_` line per doc, no `_Previously:` chain. No new doc file, so the index changes only if a summary became wrong. |

## Tests to perform

> **Read this before writing any test in this story.**
> **(a) Some tests can be red first; some cannot — the task says which.** Red first (against today's
> listener): the stale-instance test **F1**/**F1b**, the soft-deleted test **F5**, the three caller
> interleave tests, and the action test file (the class does not exist yet). **Cannot** be red first:
> **F3**, **F4**, **F6**, **F7**, **F8** and the kept 0064a idempotency test — the listener already behaves
> correctly there; they earn their place by the **mutations** in **D-8**.
> **(b) Never `Event::fake()`** where the real dispatcher is the point (F-tests, caller tests): it replaces
> the dispatcher and measures the fake.
> **(c) Explicit `id` on every `User::factory()->make()`** (`HasUuids` assigns it in a `creating` hook, so a
> `make()`d user has `null` — see 0064a's reading note).
> **(d) Never `refresh()`/`fresh()` the stale instance under test** and never use it as anything but the
> subject: the whole point is that it is stale.
> **(e) Never test lock blocking or run a second real connection.** `RefreshDatabase` keeps data
> uncommitted (a second connection would see nothing and wait out InnoDB's 50 s lock timeout), and a
> `pcntl_fork` test would test MySQL, not this code, and flake under load (story 0061's ShippingTest
> timeout). The header of the race test says so plainly.

**Feature — `tests/Feature/Auth/ActivateVerifiedUserSuspensionRaceTest.php`** (new), listener level

*Stale-instance technique* (**D-6**): persist an `Inactive`, unverified user; load instance **A**;
`A->forceFill(['email_verified_at' => now()])->save()` so `getPrevious()` is populated exactly as the callers
leave it; suspend through `User::query()->whereKey($id)->update(['status' => Suspended])`; dispatch
`event(new Verified($A))`; never refresh `A`.

- [ ] **F1** — the persisted status is still `Suspended`, `A->status` is not `Active`, `A->isDirty('status')`
      is `false`. *Kills:* A1, A3.
- [ ] **F1b** — a **second** delivery on the same stale `A` leaves it `Suspended`. *Kills:* A1, A9.
- [ ] **F1c** — the same as F1 inside an outer `DB::transaction` (the `ConfirmEmailChange` shape).
- [ ] **F3** — suspended **before** the request: unchanged, `updated_at` of the row unchanged.
      *Kills:* changing the in-memory guard from `!== Inactive` to `=== Active`.
- [ ] **F4** — persisted already `Active`, in-memory `Inactive` (a concurrent activation): stays `Active`, the
      row's `updated_at` unchanged, **no** refusal logged. *Kills:* A1, logging when the row is `Active`.
- [ ] **F5** — soft-deleted in between: no exception, `deleted_at` still set, status still `Inactive`, no
      log. **Red first** — today's `save()` writes the trashed row. *Kills:* `withTrashed()`, A6.
- [ ] **F6** — hard-deleted in between (`DB::table` delete): no exception, no row, no log. *Kills:* a
      `findOrFail()` in the diagnosis `SELECT`.
- [ ] **F7** — other `Inactive` users are untouched (status and `updated_at`). *Kills:* dropping `whereKey`.
- [ ] **F8** — legitimate activation: persisted `Active`, `updated_at` advanced, `A->status` `Active`,
      `A->isDirty()` `false`, `A->getPrevious()` still holds the old `email_verified_at`. *Kills:* A5, A7, A9.

**Feature — `tests/Feature/Actions/Users/ActivateInactiveUserTest.php`** (new), action level

- [ ] **Win:** returns `true`; row `Active`; instance clean; exactly **one** `UPDATE` on `users` whose SQL
      binds `status = 'inactive'` (`DB::listen`) — this is the predicate's structural pin.
- [ ] **Lost to a suspension** (stale `Inactive` instance + query-builder suspension): returns `false`,
      instance untouched, one warning logged with the expected context (actor = the user, reason, target).
- [ ] **Lost to a concurrent activation:** returns `false`, **no** log.
- [ ] **Absent / soft-deleted row:** returns `false`, no exception, no log.

**Callers — one interleave test each** (**D-6**, *technique 2*): register a one-shot `DB::listen` that
matches the caller's **own** `update users set …` statement (by table and column, not by exact text) and, in
its callback, suspends the user with `DB::table('users')->where('id', …)->update(['status' => 'suspended'])`,
guarded by a `$fired` flag against recursion. That lands the suspension exactly between the caller's write
and the `Verified` dispatch, through the real route/action.

- [ ] `EmailVerificationTest` — Fortify's verification route with the hook: user stays `Suspended`,
      `email_verified_at` was written (non-vacuity anchor), `$fired` is `true`. **Check at Phase 3 that the
      request survives `RejectNonActiveUserLogin`** for a suspended user; if it is refused before the
      listener runs, the test is vacuous — drop it and say so in the file.
- [ ] `PasswordResetTest` — the reset with the hook on the password `UPDATE`: stays `Suspended`, the password
      changed (anchor).
- [ ] `EmailChangeTest` — the confirmation with the hook: stays `Suspended`. **Defence in depth**: the row
      lock makes it impossible in production, so this uses one connection and says so in a comment.

**Unit — `tests/Unit/Listeners/ActivateVerifiedUserTest.php`** (modified — **OQ-2**)

- [ ] The five refusal/idempotency cases from 0064a keep their names and rules, counting fake-action
      invocations. The fake mirrors the real contract.
- [ ] **New:** a `false` return leaves the instance untouched and does not throw.
- [ ] **Mutation "move the database access above the in-memory guards":** killed by the refusal unit tests
      **by error**, not by assertion — with no app booted, any database touch throws. Say so in the file.

**Explicitly not tested**
- **Lock blocking / a real two-connection race** (see reading note (e)).
- **InnoDB's current-read semantics themselves** — vendor/engine behaviour; the tests assert *this app's*
  outcome.
- **`UpdateUser`'s own stale write** — out of scope (**R-2**).

## Expected outcome

A `Verified` event delivered on a stale `Inactive` instance for a user who has since been suspended leaves
the account **Suspended**, the caller's instance unchanged, and (when the row is not simply already active or
gone) one warning in the log. A legitimate first verification still activates the account, leaves the
instance clean with `updated_at` advanced, and issues exactly one `UPDATE`. A repeated delivery still writes
nothing. `ActivateVerifiedUser`'s in-memory guards and its `getPrevious()` docblock are unchanged, and it is
still synchronous. No route, screen, schema or caller changes.

## Acceptance criteria
- [ ] `App\Actions\Users\ActivateInactiveUser` exists, writes through one guarded `UPDATE … WHERE id AND
      status = 'inactive'` via the Eloquent builder, syncs the caller's instance only on a win (targeted
      sync, no `refresh()`, no second write) and never on a loss.
- [ ] `ActivateVerifiedUser` keeps its in-memory guards and `getPrevious()` docblock, calls the action in
      place of `save()`, stays synchronous, and `getOriginal()` is not used in it.
- [ ] A stale `Inactive` instance for a since-suspended user is **never** activated, on the Fortify, password
      reset and email-change paths, outside and inside a transaction.
- [ ] A lost race against a suspension logs one refusal; a concurrent activation, an absent row and a
      soft-deleted row log nothing and throw nothing.
- [ ] The three callers, `UpdateUser`, `User`, `bootstrap/app.php`, `database/**` and `routes/**` are unchanged.
- [ ] 0064a's idempotency and registry tests pass **unmodified**; the unit tests keep their names and rules.
- [ ] Every mutation in **D-8** was seen red against a named test and recorded in this file; the tests that
      can be red first were.
- [ ] No other task file is edited by this story beyond the link-integrity re-points and the 0064a DoD
      pointer.

## Definition of Done
- [ ] Tests written and green, plus the **full** existing suite in a **single isolated run**, per
      [contracts.md](../../docs/contracts.md)'s Full Test Suite Gate Rule.
- [ ] All **three** quality gates run **unscoped**, each result recorded explicitly *including any that was
      not run*: `php artisan test` (or `vendor/bin/pest -d memory_limit=-1` where the artisan child cannot
      raise its limit), `vendor/bin/pint --format agent` (not `--dirty`), and **Larastan level 7**
      (`vendor/bin/phpstan analyse`).
- [ ] The red-then-green sequence and every **D-8** mutation recorded in the task file.
- [ ] Code reviewed (code-reviewer). **Point the review at D-2 and D-3**: that the in-memory guards and the
      `getPrevious()` docblock are untouched, and that no path writes `status` except the guarded `UPDATE`.
- [ ] No security findings (appsec-auditor). **Point the audit at** `ActivateVerifiedUser` /
      `ActivateInactiveUser`: is there any caller path on which a suspended or previously-verified inactive
      account can still become active; does the logged refusal leak anything; is the CAS safe given `User`
      has no model hooks today (**R-3**).
- [ ] Documentation updated (docs-keeper) — every entry in the *Docs* table, in one pass, with **one**
      `_Last updated_` line per touched doc and the base branch fetched first.
- [ ] Task-coordination files regenerated when this file is created and again when it moves
      (`ai-spec/tasks-map.md`, `ai-spec/tasks-status.json`), and the two-direction link-integrity check run at
      each stage move, per
      [task-files-links-and-ordering.md](../../docs/workflow/task-files-links-and-ordering.md).
- [ ] The `UpdateUser` stale-write hole (**R-2**) is recorded as a risk here and its owner decision (**OQ-5**)
      answered.
- [ ] Acceptance criteria met.

## Documented functional decisions

### D-1 — A guarded compare-and-set `UPDATE`, in its own action
The decision collapses to **one predicate on one row** (`status = 'inactive'`), so one statement makes the
decision and the write atomic. InnoDB evaluates an `UPDATE … WHERE` as a **current read**: if a concurrent
`UpdateUser` transaction has already suspended the row, the guard matches 0 rows even under REPEATABLE READ,
and it blocks on that transaction's row lock until it commits. The result does not depend on where the caller
sits — inside `ConfirmEmailChange`'s open transaction (the statement re-locks a row the same connection
already holds: no wait, no savepoint) or in the transaction-less `ResetUserPassword`/Fortify callers, and it
would hold if someone later made the listener run after commit. Lock order is trivial: `UpdateUser` takes the
`users` row and then `model_has_roles`; this touches only the `users` row and holds nothing while it waits.
`status` carries no index (`docs/database/schema-users-auth.md`), the lookup is by primary key: no gap lock,
no migration. `CLIENT_FOUND_ROWS` is not set (`config/database.php`), so MySQL reports **changed** rows, and
`inactive → active` always changes the row — a count of `1` means "we activated", `0` means "the guard lost".

*Rejected — **B**, re-read the row with `lockForUpdate()` in the listener's own `DB::transaction`, decide on
the fresh row, then `save()`.* It is correct and it is this repo's established shape
([model-instance-trust.md](../../docs/security/model-instance-trust.md), "A guard must re-read its subject
under lock, inside its own transaction"), and it is the accepted **fallback** (**OQ-1**). It loses here
because that convention exists for **multi-step read-decide-write and multi-row invariants** (`SalesRegion`'s
"exactly one default"), not for one predicate on one row; because it costs four round trips (begin, locking
`SELECT`, `UPDATE`, commit) and a redundant re-lock inside `ConfirmEmailChange`; because it needs the same
instance sync and the same unit-test seam as A; and — the deciding testing argument — because **a
single-process behavioural test cannot detect a missing lock**: drop `lockForUpdate()` and the stale-instance
test still passes, so B needs an extra *structural* SQL/transaction-level test (with the RefreshDatabase
baseline `transactionLevel()` of 1 to allow for) that is implementation-coupled. A's guarded `WHERE` is
behaviourally visible, so every A mutation is killed by an ordinary assertion. The `database-expert`'s
preference for B is on record; its one advantage — `save()` runs model events — is theoretical today
(**R-3**).

*Rejected — **C**, fix it at the callers, or at both layers.* Fortify's controller is vendor code;
`ResetUserPassword` could lock, but the listener is the **single activation point** (its docblock), and three
callers are three chances to forget; `ConfirmEmailChange` is already race-free. A second, redundant lock
buys nothing once the write itself is guarded.

### D-2 — The listener keeps every in-memory guard; only the write moves
The `instanceof User`, `status !== Inactive` and `getPrevious()` fail-closed guards stay **byte-for-byte**,
and so does the docblock's `getPrevious()`-never-`getOriginal()` reasoning (a re-read row has an **empty**
`getPrevious()`, so "never verified" cannot be decided from the database). The listener stays synchronous.
This is why 0064a's **D-2** is *amended in spirit, not contradicted*: 0064a declined to change the write
under an **idempotency** story; this story changes exactly and only the write.

### D-3 — Sync the caller's instance without a second write
On a win: `setAttribute('status', Active)`, `setAttribute('updated_at', $now)`, then
`syncOriginalAttributes(['status', 'updated_at'])`. `syncOriginalAttributes` leaves `previous` and `changes`
alone, so `getPrevious()` still carries the pre-save `email_verified_at` (the invariant the three callers'
comments protect) and the instance ends **clean** — not dirty, so no later `save()` can rewrite it.
`updated_at` is passed **explicitly** so the row and the instance carry the same value and `travel()` tests
stay honest. Through the Eloquent builder (not `DB::table`), so `SoftDeletes` scoping applies.

*Rejected:* `refresh()` (replaces every attribute and calls `syncOriginal()`); `forceFill()->save()` (a second
write); leaving the instance stale (0064a's D-2 objection to a query-builder write, which this answers).

### D-4 — The lost race: leave the instance alone, keep the caller's own writes, log a suspension-loss only
On `0` rows nothing on the caller's instance changes. `email_verified_at` and (on the reset path) the new
password **stay** — reverting them is not this story's job and matches what the callers do today. One plain
`SELECT status` then separates: **absent/soft-deleted** → silent; **`Active`** (a concurrent double delivery
— e.g. a double-click on the link) → silent; **anything else** (in practice `Suspended`) → one refusal via the
existing `App\Actions\Auth\LogRefusedPrivilegedAttempt` (never throws, accepts a null actor) with the user as
actor and a short snake_case reason. **Not** logged: the listener's existing in-memory `Suspended`/previously-
verified early returns — that is today's silent no-op, and logging it would be noise and a behaviour change
(**OQ-3** (c)).

### D-5 — A soft-deleted or absent user is a silent no-op — a behaviour change to state plainly
Today `save()` on a trashed instance writes the trashed row. With the scoped builder a soft-deleted row
matches 0 rows and the diagnosis `SELECT` finds nothing. That is intended (a removed account must not be
brought back to life by a late confirmation), but it **is** a behaviour change for that one case (**OQ-6**).

### D-6 — Reproduce the race deterministically; never test lock blocking
*Technique 1, the stale instance* (listener level): persisted `Inactive` user → instance `A` → `A`'s own
`forceFill(email_verified_at)->save()` → suspend via a query-builder write → `Verified` on the never-refreshed
`A`. It closes the **wide** window (request load → listener), which is the real exploit. *Technique 2, an
interleave hook* (caller level): a one-shot `DB::listen` that suspends the user the instant the caller's own
`update users …` statement runs, so the suspension lands exactly between the caller's write and the event,
through the real route — the closest one PHP process gets to the real window. **What neither can show** is
the *narrow* window (listener read → listener write); for A that window does not exist, because check and
write are one statement — which is the third argument for A in **D-1**. No two-connection or lock-blocking
test (reading note (e)). The `ConfirmEmailChange` test is defence in depth and says so.

### D-7 — A constructor-injected action is the seam that keeps the unit tests alive
A synchronous listener is built by the container (`Dispatcher::createClassCallable` →
`$container->make($class)`), so its dependency arrives through the constructor. Inlining `User::query()` in
the listener would crash `tests/Unit` (no application or database is booted there — `tests/Pest.php`) and
force **moving or deleting** the activation unit tests, which needs explicit approval in this repo. With the
seam they keep their names and rules and only change what they count (**OQ-2**). 0064a's registry test and
runs-once test are unaffected: resolving the listener now also resolves the action, once.

### D-8 — The mutations each test must kill
Each applied alone to production code, the named test seen red, the change reverted:

| Mutation | Expected red |
| --- | --- |
| **A1** drop `where('status', 'inactive')` | F1, F1b, F1c, the action's lost-to-suspension test, the three caller tests |
| **A2** drop `whereKey(...)` | F7 |
| **A3** ignore the affected-row count and always sync `Active` | F1 (in-memory status) |
| **A4** sync the attributes without `syncOriginalAttributes` | F8 (`isDirty()`), the action's win test |
| **A5** write through `DB::table` (no `updated_at`) | F8 (`updated_at` not advanced) |
| **A6** `withTrashed()` / `DB::table` | F5 |
| **A7** bind the wrong enum value | F8 |
| **A8** invert the affected-rows test | F8, F1 |
| **A9** never sync the instance | F8, F1b |
| **A10** remove the listener's in-memory `status !== Inactive` guard | the same-instance idempotency unit test (fake invoked twice) |
| **A11** log when the row is `Active`, or when it is absent | F4, F6 |
| **A12** `refresh()` instead of the targeted sync | F8 (`getPrevious()` lost) |
| **A13** move the database access above the in-memory guards | the refusal unit tests, **by error** |
| **A14** `findOrFail()` in the diagnosis `SELECT` | F6 |

### D-9 — Business-language boundary of the Gherkin
The six scenarios state outcomes an owner recognises (activated, suspension wins, unchanged, not brought
back). *"Compare-and-set"*, *"guarded UPDATE"* and *"stale instance"* are mechanism, so they are tests and
decisions, not scenarios.

## Dependencies, risks and open questions

### Verified findings (2026-09-26)
- **`ActivateVerifiedUser`** (`app/Listeners/ActivateVerifiedUser.php`): one persisted write — `$user->status =
  Active; $user->save()` after the in-memory guards. `save()` writes only the dirty set, so callers cannot
  clobber `status` themselves.
- **Writers of `users.status` after create:** only `ActivateVerifiedUser` and `UpdateUser` (grep of `app/`);
  `CreateUser` inserts it.
- **Callers of `Verified`:** Fortify's `VerifyEmailController` (`markEmailAsVerified()` then
  `event(new Verified($request->user()))`, request-start instance); `ResetUserPassword::reset` (no
  transaction/lock); `ConfirmEmailChange::__invoke` (`DB::transaction`, `lockForUpdate()` re-read, event on the
  locked instance).
- **`Dispatcher::createClassCallable`** builds the listener with `$container->make($class)`
  (`vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:529-548`), so dependencies are constructor
  dependencies.
- **`User`** has no observer, `booted()` or `static::saved` hook; it uses `SoftDeletes` and `HasUuids`.
- **Test database is MySQL** (`phpunit.xml:29`), so InnoDB current-read semantics apply in CI.
- **`UpdateUser`** loads no lock and re-reads nothing before `$user->status = $status; $user->save()`; its
  Livewire caller loads the target with `findOrFail()` outside the action.

### Dependencies
- **Hard dependency on [0064a](in-progress/0064a-activate-verified-user-listener-idempotent-and-single-registration.md)**
  (open as PR #37 at the time of writing): this story **modifies** the unit test file 0064a extends and keeps
  0064a's idempotency and registry tests as its regression net, so it cannot start before 0064a lands.
  (`0064a` < `0064c`: the ordering rule is satisfied.) The debate started from "no dependency beyond the same
  listener"; reading the test files showed the edit overlap, so the dependency is recorded.
- No other pending story modifies `ActivateVerifiedUser`, its callers or `LogRefusedPrivilegedAttempt`
  (grep of `ai-spec/tasks/`: only mentions) — **no `conflict_risk_with`** entry.

### Risks
- **R-1 — The unit tests change mechanics.** The `save()`-counting double stops measuring the activation
  (**D-7**, **OQ-2**). Mitigated by keeping names and rules and an emulating fake.
- **R-2 — `UpdateUser` has its own stale-write hole; recorded, not fixed.** `UpdateUser` decides
  `$statusChanged` from `getRawOriginal('status')` on a caller-loaded instance and writes `status` with no
  lock, re-read or compare-and-set, so two administrators are last-writer-wins and the sensitive-attribute
  gate and step-up are evaluated on a stale persisted status. It is an authorized administrator's own action,
  not a self-grant, so a lower-severity, different class — and it **cannot re-open** this hole: once the
  listener is a CAS, a suspension that commits first makes the listener refuse, and if the listener commits
  first `UpdateUser`'s write still lands and ends `Suspended`. Owner decision in **OQ-5**.
- **R-3 — The CAS bypasses model events.** Safe **only while `User` has no `saving`/`updated` hooks**; stated
  in the docs change (`model-instance-trust.md`) and put to the appsec audit.
- **R-4 — A future queued listener.** A queued `ActivateVerifiedUser` would lose `getPrevious()` through
  `SerializesModels`; do not add `ShouldQueue`. (`ShouldHandleEventsAfterCommit` would stay race-safe with a
  CAS, but there is no reason to add it.)
- **R-5 — Vacuous interleave tests.** If the hook never fires the outcome is `Active` and the tests fail
  loudly; `$fired` is still asserted, and a per-caller anchor (the caller's own write happened) is required.
  The Fortify test may be vacuous if `RejectNonActiveUserLogin` refuses the suspended user first — checked at
  Phase 3 (**Tests to perform**).

### Open questions
- **OQ-1 — Mechanism.**
  - **(a) Guarded CAS `UPDATE` in `ActivateInactiveUser` (recommended)** — one atomic statement,
    behaviourally falsifiable, indifferent to the caller's transaction (**D-1**).
  - (b) Re-read under `lockForUpdate()` in the listener's own transaction (the repo idiom, and the
    `database-expert`'s preference) — same behaviour, four round trips, needs a structural test; the accepted
    fallback if a reviewer insists on the convention.
- **OQ-2 — The seam and the existing unit tests.**
  - **(a) Constructor-injected `ActivateInactiveUser` + a recording fake; the existing unit tests keep their
    names and rules and only change what they count (recommended)** — but it edits the assertions of existing
    tests, so it needs your sign-off.
  - (b) Inline `User::query()` in the listener and move the activation unit tests to Feature — deletes/moves
    tests, which this repo does not do without approval.
- **OQ-3 — Log the lost race?**
  - **(a) Log one refusal when the row exists and is not `Active` (recommended)** — an activation refused on a
    privilege-granting control; the pipeline already exists; costs one test and a mutation. (`backend-qa` would
    defer it to keep the story small.)
  - (b) No log — no attacker-controlled attempt is involved.
  - (c) Also log the listener's existing silent `Suspended` early return — a behaviour change; advised against.
- **OQ-4 — The caller's instance after a lost race.**
  - **(a) Leave it untouched (recommended)** — clean, cannot cause a later write; it still does **not** claim
    `Active`, which is what the Gherkin needs.
  - (b) Sync it to the persisted status — needs the extra `SELECT`'s result and adds little.
- **OQ-5 — `UpdateUser`'s stale write (R-2).**
  - **(a) Record it here as a risk only; raise a story if you want it (recommended).**
  - (b) Raise it now as its own Three Amigos story.
  - (c) Fold it into this story — mixes two actions and enlarges a security-adjacent change.
- **OQ-6 — A soft-deleted user's late confirmation (D-5).**
  - **(a) Silent no-op, no error, no log (recommended).**
  - (b) Keep writing to the trashed row as today — leaves a removed account able to change state.

### Not verified in this pass
- **No test or query was run** by this Phase 1 pass: the InnoDB behaviour in **D-1** is reasoned from engine
  semantics and vendor/config reading, not executed. The Phase 3 tests are what confirm it.
- **`LogRefusedPrivilegedAttempt`'s exact signature and the refusal-reason naming convention**
  (`step-up-and-refusal-logging.md`) — cited by the `backend-expert`, to be confirmed against the file at
  Phase 3.
- **That Fortify's verification route reaches the listener for a `Suspended` user** (**R-5**).

## Provenance

- **Raised by:** story 0064a, **R-2** and **OQ-3** (found by the same debate that reframed 0064a as "prove and
  pin what already is"); the human owner asked for it to be generated with its own Phase 1 debate,
  2026-09-26.
- **Debate:** `product-owner` (facilitator) with `backend-expert`, `backend-qa` and `database-expert`, each
  contributing once from a shared brief, in parallel. The project's agent definitions in `.claude/agents/`
  were **not registered** as `subagent_type`s in the session that ran this debate, so each role was played by
  a general-purpose agent instructed to read and follow its own definition file — a substitution, recorded
  here rather than hidden. Agreed design **D-1** through **D-9**; the `database-expert`'s dissent (**B**) is in
  **D-1** and **OQ-1**.
- **Facilitator findings that changed the document:** (1) the debate began from "no dependency beyond the same
  listener"; the test-file overlap with 0064a made it a **hard dependency**; (2) `backend-qa` showed the
  existing activation unit tests cannot survive an inline query, which produced the **D-7** seam and **OQ-2**;
  (3) `backend-qa` showed a missing `lockForUpdate()` is invisible to a single-process behavioural test, which
  became the deciding argument for A; (4) `ConfirmEmailChange` already locks, so it is defence in depth, not
  a fix; (5) 0064a's **D-2** wording ("needs a compare-and-set on a different condition") is loose — R-2 itself
  names `WHERE status = 'inactive'`.
- **Models followed for tone and structure:** [0064a](in-progress/0064a-activate-verified-user-listener-idempotent-and-single-registration.md)
  and [0064](done/0064-scheduled-post-auto-publish-backend.md).
- **Status:** Phase 1 output (new stage). Phase 2 (INVEST validation) not yet run.
