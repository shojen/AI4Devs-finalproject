# [0064a] Activate-verified-user listener — idempotent, and every listener registered exactly once

## Description
Two things about the listeners that run when a user confirms an email address or signs in, both found
while closing story [0064](done/0064-scheduled-post-auto-publish-backend.md) and both raised by
the human owner.

1. **`App\Listeners\ActivateVerifiedUser` must be idempotent** — receiving `Verified` twice for the
   same user must leave exactly the state one delivery would (**D-0**). The listener is **already**
   idempotent in effect (**D-2**), so this story's work is to **prove and pin** that with tests, not to
   change its logic.
2. **Every listener must be registered exactly once.** `php artisan event:list` shows
   `Illuminate\Auth\Events\Verified` bound to `ActivateVerifiedUser` **and** `ActivateVerifiedUser@handle`,
   and `Authenticated` bound to `RejectNonActiveUserLogin@handleAuthenticated` **twice**: the explicit
   `Event::listen()` lines in `AppServiceProvider::configureEventListeners()` sit on top of Laravel 13's
   listener auto-discovery of `app/Listeners`. This story removes the explicit lines and adopts one
   policy — **discovery only** (**D-1**) — guarded by a registry test that fails if any `App\` listener is
   ever bound twice, or if one of the four known bindings disappears.

Backend only: no screen, no route, no migration, no new production class. The production change is one
deletion in `AppServiceProvider`; the rest is tests and docs.

Why it matters beyond tidiness: today both duplicated listeners run their logic twice per event. For
`ActivateVerifiedUser` the second run returns early, so nothing visible happens. For
`RejectNonActiveUserLogin` — the sign-in safety net of story 0007 — the duplicate runs a logout path
twice; it is harmless today, but a listener that *sends* something (story
[0065](0065-blog-post-published-notification-backend.md)'s notification) would send twice. The same
mechanism will bite the next listener unless one policy and one guard exist.

Raised by the human owner while closing 0064 (2026-09-26); found by 0064's Phase 5 work, which already
carries a warning about it into 0065 (see [Hand-off to 0065](#hand-off-to-0065)). No story changes any of
0064's files, and this story touches none of them.

## Type
backend | includes database-expert: **no** (no schema change; no query is added or altered)

## Three Amigos participants
`product-owner` (lead/facilitator) + `backend-expert` (files and approach) + `backend-qa` (test design).
`database-expert` was **not convened**: no table, column, index or query changes. The design below is
the debate's agreed outcome; each decision records the alternative that was rejected. See
[Provenance](#provenance).

## Gherkin

Every scenario carries exactly one `When` and opens with a named business-role actor, per
[gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3.
*"A listener runs once"* is an implementation property, not business language, so it gets **no
scenario**; it is pinned by tests (**D-3**, **D-4**). The four scenarios below state the business
outcome the idempotent listener must keep true however many times the event arrives.

> Scenario 2 is reachable **only at event level**. Fortify's HTTP verification route returns early for
> an already-verified user without dispatching `Verified`
> (`vendor/laravel/fortify/src/Http/Controllers/VerifyEmailController.php`, the `hasVerifiedEmail()`
> branch, and pinned by `tests/Feature/Auth/EmailVerificationTest.php:70`), so no browser flow can
> confirm the same address twice. It is still a real state to defend: the event can be dispatched
> again by any other caller (`ConfirmEmailChange`, `ResetUserPassword`, a retried job, a future flow).

```gherkin
Feature: Confirming an email address activates only the accounts that should be activated

  Scenario: An invited user is activated when they confirm their email address
    Given an invited user whose account is inactive and who has never confirmed an email address
    When they confirm their email address
    Then their account becomes active

  Scenario: Confirming an already-active user's email again changes nothing
    Given a user whose account is active and whose email address is already confirmed
    When their email address is confirmed a second time
    Then their account is still active
    And nothing about their account is rewritten

  Scenario: A deactivated user is not reactivated by a later email confirmation
    Given an administrator who has deactivated a user that had confirmed an email address before
    When that user confirms a new email address
    Then their account is still inactive

  Scenario: A suspended user is not activated by confirming an email address
    Given an administrator who has suspended a user
    When that user confirms their email address
    Then their account is still suspended
```

## Files to create/modify

### Production — one deletion

| Path | What & why |
| --- | --- |
| `app/Providers/AppServiceProvider.php` | **Modify (delete).** Remove `configureEventListeners()`, its call in `boot()` (line 38), and the imports that become unused (`ActivateVerifiedUser`, `RejectNonActiveUserLogin`, `Authenticated`, `Login`, `Verified`, `Event`). Grep confirmed the method has no other caller in `app/`, `tests/`, `routes/`, `bootstrap/` or `config/`; the only other mentions are docs and story 0065's task file (see the hand-off). Keep the Story 0007 comment about *why both `RejectNonActiveUserLogin` handlers exist* — it already lives in that class's docblock, so nothing is lost by dropping the copy here. Pint will flag any import left behind. |

**Not touched, deliberately:** `app/Listeners/**` (the listener logic and its docblocks stay exactly as
shipped — **D-2**), `bootstrap/app.php` (**D-1** rejects `withEvents(discover: false)`),
`app/Actions/Blog/PublishScheduledBlogPost.php`, `app/Console/**`, `routes/console.php`,
`app/Events/Blog/**` (story 0064's files), `database/**`, `routes/**`, `resources/**`, `config/**`.

### Tests

| Path | What & why |
| --- | --- |
| `tests/Feature/Providers/EventListenerRegistrationTest.php` | **New** (and the new `tests/Feature/Providers/` folder, which mirrors `app/Providers/`). Two things in one file: the **registry guard** (**D-4**) and the **runs-exactly-once** test over `Verified`/`Login`/`Authenticated` (**D-3**). |
| `tests/Unit/Listeners/ActivateVerifiedUserTest.php` | **Extend.** Four idempotency cases on the existing tracked-user double, which gains an `int $saveCallCount` beside the existing `bool $saveWasCalled` (**D-5**). Existing tests are untouched. |
| `tests/Feature/Auth/ActivateVerifiedUserIdempotencyTest.php` | **New.** The one real-database characterisation test (**D-6**, **OQ-4**). |

### Docs (docs-keeper, Phase 6)

| Path | Change |
| --- | --- |
| `docs/conventions/directory-structure/app-layers.md` | The `Listeners/` entry (currently lines 66-71) says *"ActivateVerifiedUser is registered in AppServiceProvider; the new listener is NOT"*. Replace with the **discovery-only policy**: no listener is registered by hand, and **`handle*` method naming is load-bearing** (rename `handleAuthenticated` and the sign-in safety net is silently unregistered; a stray typed `handleX(Event $e)` becomes a live listener). Point at the registry test. |
| `docs/architecture/authentication/sign-in-block-and-email-change.md` | Around lines 75-80: replace the `Event::listen` code block under *"3. `RejectNonActiveUserLogin`"* with a short explanation that both handlers are registered by discovery (the `Login` handler by `handle(Login $event)`, the `Authenticated` handler by `handleAuthenticated(Authenticated $event)`) and that the two-events reasoning that follows is unchanged. |
| `docs/architecture/authentication/features-registration-and-status.md` | Around lines 112-119: the `configureEventListeners()` code block under *Account status and activation* is replaced by one sentence — `ActivateVerifiedUser` is wired to `Verified` by discovery. |
| `docs/architecture/authentication/two-factor-passkeys-logout-and-map.md` | Lines 111 and 114 (the file map) say both listeners are *"registered in `app/Providers/AppServiceProvider.php`"*. Found while writing this story; reword to *"auto-discovered"*. |
| `docs/security/login-status-enforcement.md` | Lines 316-318 say *"Single guard, no event auto-discovery. … `bootstrap/app.php` never calls `withEvents()`, so the explicit `Event::listen()` registrations in `AppServiceProvider` are the only ones — the listener is not double-fired."* **That claim is false**: `Application::configure()` calls `->withEvents()` itself (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:243`). Correct it, and — per this repo's convention for audit-authored pages — quote what it used to say rather than silently rewriting it. Keep the true half (single `web` guard). |
| `docs/conventions/naming/classes.md` | One line, in the paragraph at line 17 that names listeners: listeners are auto-discovered from `app/Listeners`; do not register one by hand. |
| `docs/errors-log/2026-09-10-to-2026-09-23.md` | One entry (the current file — its row in [`docs/errors-log.md`](../../docs/errors-log.md) covers *2026-09-10 to 2026-09-24*): **an explicit `Event::listen()` on top of Laravel 13's listener auto-discovery double-registers a listener**, why nothing failed (the second run returned early), and the rule adopted. Add the topic-index line in `docs/errors-log.md`. |
| `docs/README.md` and every touched doc's footer | **Fetch the base branch first** so the docs are edited against current content (a conflicting docs PR silently gets no CI). Each doc keeps **one** `_Last updated_` line — no `_Previously:` chain. No new doc file is created, so the index changes only if an entry's summary became wrong. |

## Tests to perform

> **Read this before writing any test in this story.**
> **(a) Prove the double execution first (red), then fix (green).** The two new resolution/registry
> tests must be seen failing against today's `AppServiceProvider` for the right reason before the
> deletion. The **unit and characterisation idempotency tests cannot be red first** — the listener is
> already correct (**D-2**) — so they earn their place by **mutation** (**D-8**), not by an initial
> failure, and the task file says so rather than pretending otherwise.
> **(b) Never `Event::fake()`** in the registry or resolution test: it *replaces* the dispatcher, so
> `getRawListeners()` would inspect a fake and a resolution count would count nothing.
> **(c) An explicit `id` on every `User::factory()->make()` here.** `HasUuids` assigns the UUID in a
> `creating` hook (`app/Models/User.php:45`), so a `make()`d user has a `null` id, and
> `RejectNonActiveUserLogin::handleAuthenticated()` compares a per-request flag with
> `getAuthIdentifier()` — `null !== null` is `false`, which would **force a logout** and make the test
> pass or fail for the wrong reason.

**Feature — `tests/Feature/Providers/EventListenerRegistrationTest.php`** (new)

*The registry guard (**D-4**)*
- [ ] Loop `Event::getRawListeners()`; normalise each entry to `Class@method` (a bare class name means
      `@handle`; an array means `[0]@[1]`; skip closures); restrict to classes in the `App\` namespace.
      Assert **(a) no event lists the same `Class@method` twice** and **(b) each of the four known
      bindings appears exactly once**:
      `Login` → `RejectNonActiveUserLogin@handle`, `Authenticated` → `RejectNonActiveUserLogin@handleAuthenticated`,
      `Verified` → `ActivateVerifiedUser@handle`, `OrderFullyRefunded` → `CancelFullyRefundedOrder@handle`.
      Failure messages **name the event and the entry**, so a red run says what to delete.
- [ ] **Proven able to fail, twice, and recorded:** (1) temporarily re-add one `Event::listen(...)` line
      to `AppServiceProvider` → assertion (a) goes red naming the event and the entry; (2) temporarily
      rename `handleAuthenticated` to `onAuthenticated` → assertion (b) goes red for the
      `Authenticated` binding. Both are the standard this repo's
      [vacuous-`arch()`-rule entry](../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-pest-arch-rule-over-an-array-of-namespaces-shipped-green-while-proving-nothing--2026-08-18)
      demands of any assertion that passes by default.

*Each listener runs once per dispatch (**D-3**)*
- [ ] **One dataset-driven test over three events** — `Verified` (for `ActivateVerifiedUser`), `Login`
      and `Authenticated` (both for `RejectNonActiveUserLogin`). For each: count container resolutions with
      `app()->resolving(<ListenerClass>::class, function () use (&$resolved) { $resolved++; })`, dispatch
      the **real** event with `event(...)` for `User::factory()->make([...])` — **Active**, with an
      explicit `id` (so neither listener does anything observable) — and assert the count is exactly
      **1**. **Expect 2 before the deletion and 1 after.** The dispatcher builds one listener object per
      registration per dispatch, so a double registration is a double resolution.

**Unit — `tests/Unit/Listeners/ActivateVerifiedUserTest.php`** (extended) — idempotency (**D-5**)

The double's `save()` **never calls `syncChanges()`**, so in these tests `getPrevious()` keeps its
pre-save value across the whole test and the second delivery is stopped by **the status check alone**.
That is deliberate: it makes the `status !== Inactive` guard independently falsifiable, which the real
database cannot.

- [ ] **Same instance, `Verified` twice** (first-ever verification: previous `email_verified_at` is
      `null`): exactly **one** `save()`, and the user is still `Active`. *Kills:* removing the
      `status !== Inactive` guard.
- [ ] **Reloaded `Inactive` user, empty `getPrevious()`**, `Verified` delivered: stays `Inactive`, **0**
      saves — the fail-closed branch. *Kills:* replacing the `array_key_exists('email_verified_at', …)`
      guard with `($previous['email_verified_at'] ?? null) === null`, which would activate a user whose
      last save never touched that column.
- [ ] **Previously-verified `Inactive` user** (administrator-deactivated; previous `email_verified_at`
      non-null), `Verified` twice: stays `Inactive`, **0** saves. *Kills:* dropping the previous-value
      null check (a deactivated user would be reactivated).
- [ ] **`Suspended` user whose previous `email_verified_at` is `null`** (the would-be first verification),
      `Verified` twice: stays `Suspended`, **0** saves. *Kills:* changing the guard from
      `!== Inactive` to `=== Active`, which lets a suspended user through.

**Feature — `tests/Feature/Auth/ActivateVerifiedUserIdempotencyTest.php`** (new) — characterisation (**D-6**)

- [ ] A persisted `Inactive` user; `forceFill(['email_verified_at' => now()])->save()`; start
      `DB::listen`; dispatch `Verified` twice on that instance; then `User::find()` and dispatch a third
      time on the reloaded instance. Assert: `Active`, **exactly one** `UPDATE` on `users` in the whole
      sequence, and `updated_at` unchanged after the first delivery.
- [ ] **State plainly in the test's comment that this is a characterisation test, not independently
      falsifiable**: with the status guard removed, the second delivery is still stopped in the real
      database because the first `save()` re-runs `syncChanges()` and `getPrevious()` then no longer
      carries `email_verified_at`. The unit test above is what kills that mutation; this test pins the
      end-to-end behaviour a future refactor of *how* the listener persists must preserve.

**Existing test kept:** `tests/Feature/Orders/AutoCancelFullyRefundedOrderTest.php:213-215`
(`Event::getListeners(OrderFullyRefunded::class)` → `toHaveCount(1)`) **stays** — the registry
generalises it, and this repo never deletes a test without approval (**OQ-2**).

**Explicitly not tested**
- **Laravel's discovery mechanism itself** (`DiscoverEvents`, `Str::is('handle*', …)`) — vendor. The
  registry test asserts *this app's outcome* (which bindings exist), never how the framework computes it.
- **Query counting to show a double run.** It cannot: the second run returns early, so it issues no
  query. Resolution counting is the only measurement that sees the duplicate (**D-3**).
- **A single-target `arch()` rule** for listeners — rejected as blunt (**D-4**).

## Expected outcome

`php artisan event:list` lists each `App\` listener **once** per event: `Verified` →
`ActivateVerifiedUser@handle`, `Login` → `RejectNonActiveUserLogin@handle`, `Authenticated` →
`RejectNonActiveUserLogin@handleAuthenticated`, `OrderFullyRefunded` → `CancelFullyRefundedOrder@handle`.
`AppServiceProvider` no longer registers any listener. Delivering `Verified` to the same user twice leaves
the account exactly as one delivery would, and this is pinned by tests that were each shown to fail under
a named mutation. A future listener that is added both by discovery **and** by hand — or one whose
`handle*` method is renamed out of discovery — fails the suite instead of shipping. Nothing a user or an
administrator can observe changes.

## Acceptance criteria
- [ ] `AppServiceProvider::configureEventListeners()`, its call in `boot()` and every import it alone
      used are removed; no listener is registered by hand anywhere in `app/`.
- [ ] `bootstrap/app.php` is unchanged (discovery stays on, as story 0052 shipped it).
- [ ] `tests/Feature/Providers/EventListenerRegistrationTest.php` asserts no duplicate binding per event
      **and** the four positive bindings, with failure messages naming the event and the entry, and was
      **proven able to fail** by re-adding an explicit `Event::listen` and by renaming `handleAuthenticated`.
- [ ] The runs-once test resolves each of `ActivateVerifiedUser` (on `Verified`) and
      `RejectNonActiveUserLogin` (on `Login` and `Authenticated`) exactly **once** per dispatch, was seen
      at **2** before the deletion, and uses the real dispatcher (no `Event::fake()`).
- [ ] The four unit idempotency cases and the one real-database characterisation test exist, each with the
      mutation it kills recorded in the task file; the characterisation test is honestly labelled.
- [ ] `app/Listeners/**` is unchanged: `ActivateVerifiedUser`'s logic and its `getPrevious()` docblock are
      intact, and `getOriginal()` is not used anywhere in it.
- [ ] `php artisan event:list` (run once, by hand, at Phase 3) shows one entry per listener per event.
- [ ] None of story 0064's files, and no other task file, is edited by this story.

## Definition of Done
- [ ] Tests written and green, plus the **full** existing suite in a **single isolated run**, per
      [contracts.md](../../docs/contracts.md)'s Full Test Suite Gate Rule.
- [ ] All **three** quality gates run **unscoped**, each result recorded explicitly *including any that
      was not run*: `php artisan test` (not `--filter`), `vendor/bin/pint --format agent` (not
      `--dirty`), and **Larastan level 7** (`vendor/bin/phpstan analyse`). A record naming two of three
      is a record of two gates — see
      [errors-log.md](../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-verification-record-that-lists-two-of-three-quality-gates-is-a-record-of-two-gates--2026-08-26).
- [ ] The red-then-green sequence and every mutation in **D-8** are recorded in the task file with the
      test that went red.
- [ ] Code reviewed (code-reviewer). **Point the review at D-1 and D-2**: that no listener logic was
      changed under cover of an "idempotency" story, and that nobody re-added an explicit registration
      "to be safe".
- [ ] No security findings (appsec-auditor). **Point the audit at
      [`RejectNonActiveUserLogin`](../../app/Listeners/RejectNonActiveUserLogin.php)**: it is the
      sign-in safety net for remember-me recall and the two-factor mid-challenge race, and this story
      changes *how it is registered*. Questions to answer: is it still bound to `Login` **and**
      `Authenticated` after the deletion, does the registry test genuinely fail if either binding is
      lost, and is there any deployment path (a cached event manifest — **R-3**) on which discovery
      could leave it unregistered while the suite is green?
- [ ] Documentation updated (docs-keeper) — every entry in the *Docs* table above, in one pass, with
      **one** `_Last updated_` line per touched doc and the base branch fetched first.
- [ ] Task-coordination files regenerated when this file is created and again when it moves
      (`ai-spec/tasks-map.md`, `ai-spec/tasks-status.json`), and the two-direction link-integrity check
      run at each stage move, per
      [task-files-links-and-ordering.md](../../docs/workflow/task-files-links-and-ordering.md).
- [ ] **Hand-off to 0065 recorded** exactly as stated below.
- [ ] The compare-and-set race (**R-2**) is logged as a **separate follow-up story** (**OQ-3**), not fixed here.
- [ ] Acceptance criteria met.

## Documented functional decisions

### D-0 — `ActivateVerifiedUser` must be idempotent *(the owner's instruction, decision 3A)*
Recorded first because it is a product-side instruction, not an inference: while closing 0064 the human
owner asked that the activation handler be idempotent — receiving `Verified` twice must not change the
outcome — and that the double registration be fixed in one consistent way. Decision **3A** is the owner's
choice of *"make it idempotent"* over merely fixing the registration and trusting the early return. It is
why this story carries idempotency tests at all, even though **D-2** finds nothing to change.

### D-1 — One policy: listeners are registered by discovery only
Delete `configureEventListeners()`; let Laravel 13's auto-discovery (`Application::configure()` →
`->withEvents()`, `vendor/laravel/framework/src/Illuminate/Foundation/Application.php:243`) be the single
mechanism, exactly as story [0052](done/0052-order-auto-cancel-full-refund-backend.md) already ships
`CancelFullyRefundedOrder`. Discovery registers every **public** method of a class in `app/Listeners`
whose name matches `handle*` (or is `__invoke`) and whose **first parameter is a typed event**
(`vendor/laravel/framework/src/Illuminate/Foundation/Events/DiscoverEvents.php:87`).

*Rejected:* **explicit-only, via `->withEvents(discover: false)` in `bootstrap/app.php`.** It would also
fix the duplicate, but it reopens 0052's shipped design and its docs (`CancelFullyRefundedOrder` would
need a hand-written line), touches `bootstrap/app.php` — which no module story edits (0064's **D-2**) —
and story 0065 would *still* need a manual line, so the "forgot to register" failure mode would replace
the "registered twice" one.

*The price of discovery, stated rather than hidden* (**R-1**): registration becomes **implicit**, and the
`handle*` method name is **load-bearing**. Renaming `handleAuthenticated` to anything else silently
unregisters the sign-in safety net; a stray public `handleX(SomeEvent $e)` becomes a live listener. The
**positive** assertions in the registry test are what guard the first; **OQ-1** discusses the second.

### D-2 — The listener is already idempotent; the story proves it, it does not change it
Read against the shipped code (`app/Listeners/ActivateVerifiedUser.php`): a second delivery on the **same
instance** sees `Active` and returns at the `status !== Inactive` guard; on a **reloaded** instance
`getPrevious()` is empty, so the `array_key_exists('email_verified_at', $previous)` fail-closed guard
returns without acting. `getPrevious()` is the **load-bearing** read — never `getOriginal()`, which
`syncOriginal()` has already overwritten by the time the listener runs (the docblock explains this and
must not be "fixed").

*Rejected:* a write-avoidance conditional `UPDATE users SET status = 'active' WHERE id = ? AND status =
'inactive'`. It would change how callers observe the in-memory instance (its `status` would not be
updated by a query-builder write) and it buys nothing: the early return already avoids the write. It is
also not the cure for **R-2**, which needs a compare-and-set on a *different* condition.

### D-3 — Prove a double run by counting container resolutions, with the real dispatcher
The dispatcher constructs a fresh listener object for every registration on every dispatch, so a
double registration is a double resolution: `app()->resolving(ActivateVerifiedUser::class, fn () =>
$resolved++)` counts it while `event(new Verified($user))` runs the **real** dispatch path. Likewise
`Login` and `Authenticated` for `RejectNonActiveUserLogin`. The user is an **Active**
`User::factory()->make(...)` with an explicit `id` (see the reading note above — an unsaved `null` id
would make `handleAuthenticated()` force a logout), so neither listener does anything observable and the
**only** thing measured is how many times it was built. **Expect 2 before, 1 after.** One dataset-driven
test covers all three events.

*Rejected:* **`Event::getListeners()` alone** — it shows registrations, not runs, and a shape refactor
can keep it at 1 while a second path still dispatches; **query counting** — it cannot show a double run,
because the second run returns early and issues no query (**D-2**); **`Event::fake()`** — replaces the
dispatcher, so it would measure the fake.

### D-4 — A registry guard, not an `arch()` rule
`tests/Feature/Providers/EventListenerRegistrationTest.php` reads `Event::getRawListeners()` (public on
`Illuminate\Events\Dispatcher`, line 899 of the installed v13.19.0), normalises to `Class@method`,
restricts to `App\` classes (so framework and package listeners cannot fail it), and asserts no
duplicates plus four positive bindings — **the positive assertions exist because discovery makes a
rename silent** (**D-1**). Failure messages name the event and the entry. It is proven able to fail
twice, by the two mutations in **D-8**.

*Rejected:* an **optional single-target `arch()` rule** (for example "no class in `App\Providers` calls
`Event::listen`") — it guards one spelling of one mistake, and this repo has already shipped an `arch()`
rule over an array that passed while proving nothing
([errors-log entry](../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-pest-arch-rule-over-an-array-of-namespaces-shipped-green-while-proving-nothing--2026-08-18)).
A runtime read of the real dispatcher is closer to the failure and cannot pass vacuously.

### D-5 — Unit idempotency tests extend the existing double
`tests/Unit/Listeners/ActivateVerifiedUserTest.php` already uses a `User` subclass whose `save()` records
the call rather than persisting (a Mockery partial mock cannot be used where `getPrevious()` semantics
matter — the file's header explains why). The double gains `public int $saveCallCount = 0`, incremented in
`save()`; the existing `bool $saveWasCalled` stays so no existing assertion changes. Because the double's
`save()` never calls `syncChanges()`, the second delivery in the same-instance test is stopped by the
**status check alone** — which is what lets that test kill the "remove the status guard" mutation.

### D-6 — One real-database characterisation test, honestly labelled
The unit double cannot show that a real `save()` followed by a real reload behaves. One Feature test
does: persisted `Inactive` user → `forceFill(email_verified_at)->save()` → `Verified` twice → `find()` →
a third `Verified` → `Active`, exactly one `UPDATE` on `users` (`DB::listen`), `updated_at` untouched
after the first delivery. It is a **characterisation** test, not an independently falsifiable one — with
the status guard removed it would still pass, because the real `save()` re-runs `syncChanges()` and the
`email_verified_at` key leaves `getPrevious()`. The task states that plainly, and points at the unit test
as the one that kills that mutation (**OQ-4**).

### D-7 — Order of work: prove the double execution first (red), then fix (green)
Write the registry test and the runs-once test **first** and watch them fail against today's
`AppServiceProvider` — the runs-once dataset at **2** and the registry at duplicate — then delete
`configureEventListeners()` and see **1** and green. The unit idempotency tests are green from the start
by design (**D-2**) and are validated by mutation instead.

### D-8 — The mutations each test must kill
Every change is made one at a time to production code, the named test is seen red, and the change is
reverted:

| Mutation | Expected red |
| --- | --- |
| Re-add an explicit `Event::listen(...)` for any one of the four bindings | runs-once count is 2; registry duplicate assertion, naming the event and entry |
| Rename `handleAuthenticated` to `onAuthenticated`, or drop the `Verified` type-hint | registry positive assertion for that binding |
| Remove the `status !== Inactive` guard | same-instance unit test (second `save()`) |
| Replace `array_key_exists('email_verified_at', $previous)` with `($previous['email_verified_at'] ?? null) === null` | reloaded-`Inactive` unit test (a never-saved-that-column user would be activated) |
| Drop the previous-value null check | previously-verified-`Inactive` unit test (deactivated user reactivated) |
| Change the `Suspended` guard to `=== Active` | suspended unit test |

### D-9 — Business-language boundary of the Gherkin
The four scenarios describe outcomes a product owner recognises (activated, unchanged, not reactivated,
not activated). *"A listener runs once"*, *"registered exactly once"* and *"discovery"* are mechanism, so
they are tests and decisions, not scenarios. Scenario 2 is stated as reachable only at event level because
Fortify's HTTP route never re-dispatches `Verified` for an already-verified user.

## Dependencies, risks and open questions

### Verified environment findings (2026-09-26)
- **`php artisan event:list`**, run by the human owner and by 0064's Phase 5 work on 2026-09-26, lists
  `Illuminate\Auth\Events\Verified` → `App\Listeners\ActivateVerifiedUser` **and**
  `App\Listeners\ActivateVerifiedUser@handle`, and `Authenticated` → `RejectNonActiveUserLogin@handleAuthenticated`
  twice, while `OrderFullyRefunded` → `CancelFullyRefundedOrder@handle` appears once. *(Not re-run by this
  Phase 1 pass — see "not verified" below.)*
- **Discovery is on by construction:** `Application::configure()` calls `->withEvents()` with no argument
  (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:243`), and
  `ApplicationBuilder::withEvents(iterable|bool $discover = true)` only disables it when passed `false`
  (`Configuration/ApplicationBuilder.php:100-108`). `bootstrap/app.php` never calls it.
- **What discovery registers:** every public method named `handle*` or `__invoke` whose first parameter
  is typed (`Foundation/Events/DiscoverEvents.php:87`), keyed `Class@method` — so
  `RejectNonActiveUserLogin::handle(Login)` and `::handleAuthenticated(Authenticated)` are both picked
  up, while the `private` `forceLogout()` is not.
- **Installed framework:** `laravel/framework` v13.19.0.
- **`AppServiceProvider::configureEventListeners()` has one caller** — `boot()` line 38 — in `app/`,
  `tests/`, `routes/`, `bootstrap/`, `config/`. The only other references are docs
  (`features-registration-and-status.md:116`) and 0065's task file (lines 433, 808, 1385).
- **`User` ids are UUIDs** (`HasUuids`, `app/Models/User.php:45`), assigned on `creating`, so a
  `make()`d user has a `null` id (**reading note (c)**).
- **Fortify skips `Verified` for an already-verified user** (`VerifyEmailController::__invoke`), pinned by
  `tests/Feature/Auth/EmailVerificationTest.php:70`.
- **`Dispatcher::getRawListeners()`** is public (`Illuminate/Events/Dispatcher.php:899`).
- **The existing single-registration assertion** is at
  `tests/Feature/Orders/AutoCancelFullyRefundedOrderTest.php:213-215`.

### Dependencies
- **No blocking dependency.** Independent of [0064](done/0064-scheduled-post-auto-publish-backend.md),
  0063 and [0065](0065-blog-post-published-notification-backend.md). Numbered **0064a** because it was
  found closing 0064; the ordering rule (a dependency's number is lower than its dependents') is satisfied
  since nothing depends on it in either direction.
- **Conflict risk — [0065](0065-blog-post-published-notification-backend.md):** its file list edits
  `AppServiceProvider::configureEventListeners()`, the method this story deletes. Whichever lands second
  reconciles at merge; with the hand-off below, 0065 simply drops that edit.
- Builds on [0052](done/0052-order-auto-cancel-full-refund-backend.md) (which shipped
  `CancelFullyRefundedOrder` by discovery alone) and on story 0007's
  `RejectNonActiveUserLogin`; changes neither.

### Hand-off to 0065
0065's *Registration* section still tells its implementer to add
`Event::listen(ScheduledBlogPostPublished::class, SendBlogPostPublishedNotification::class)` inside
`configureEventListeners()`. It already carries a **2026-09-26 correction block** (added by story 0064)
saying auto-discovery is on and that the explicit line would double-send every notification. **Once this
story lands the rule is simply: do not register a listener by hand — the registry test fails if you do,**
and `configureEventListeners()` no longer exists to edit. 0065 needs to touch the registry test **only if
it wants an extra positive assertion**, which is recommended: it adds
`ScheduledBlogPostPublished` → `SendBlogPostPublishedNotification@handle`. This story does **not** edit
0065's file; that reconciliation belongs to 0065's own Phase 2/3.

### Risks
- **R-1 — Implicit registration; `handle*` naming is load-bearing.** The cost of **D-1**. Renaming
  `handleAuthenticated` silently unregisters the sign-in safety net, and a stray `handleX(Event $e)`
  becomes a live listener. Mitigated by the positive assertions in the registry test (rename → red) and by
  the docs change stating the rule; the stray-listener half is what **OQ-1** weighs.
- **R-2 — A narrow compare-and-set race in `ActivateVerifiedUser`, recorded, out of scope.** A request
  loads the user as `Inactive`, an administrator suspends them, then the request's `save()` writes
  `Active` — a **privilege-grant race** (a suspended account becomes active). It is real but narrow (it
  needs the suspension to land between the request's read and its write), and it is the same shape
  `docs/security/model-instance-trust.md` describes for decisions made on a stale in-memory instance. It
  is **not fixed here**: fixing it means changing the listener's write (a guarded `UPDATE … WHERE status =
  'inactive'`), which **D-2** deliberately declines, and it deserves its own tests. To be logged as a
  separate follow-up story (**OQ-3**).
- **R-3 — A cached event manifest.** Discovery results can be cached (`php artisan event:cache`), and a
  stale cache would leave a listener unregistered while the (uncached) test suite stays green. **Not
  verified against this repository's deploy process** — no doc or workflow in the tree mentions
  `event:cache` or `optimize`. It is a question for the appsec audit's deployment-path check, not a
  reason to keep the explicit lines: they would not survive a cached manifest any better than
  discovery does.
- **R-4 — A future queued or non-`handle*` listener.** Discovery also picks up `__invoke`; a listener
  written that way is registered without any `handle` in its name. The registry test's `@handle`
  normalisation means an `__invoke` listener would appear as `Class@__invoke`, which the positive list does
  not name — see **OQ-1**.

### Open questions
- **OQ-1 — Registry assertion shape.**
  - **(a) No duplicates, plus four positive bindings (recommended)** — guards both failure modes that
    matter (double registration, and a rename that silently unregisters a known listener) and does
    **not** break the day 0065 adds a listener.
  - (b) An **exact set** of every `App\` registration — additionally catches a stray `handle*` becoming a
    live listener (**R-1**, **R-4**), but fails on every legitimate new listener, so each story that adds
    one must edit the test to add its own line. A defensible strictness; rejected as the default because
    the cost lands on every future story.
- **OQ-2 — The existing `toHaveCount(1)` assertion at `AutoCancelFullyRefundedOrderTest.php:213`.**
  - **(a) Keep it (recommended)** — it is 0052's own guard, next to its own tests, and the registry
    merely generalises it; this repo never deletes a test without approval.
  - (b) Fold it into the registry and delete it — one place for the rule, at the price of a deletion
    that needs explicit sign-off and leaves 0052's file with one fewer test.
- **OQ-3 — The compare-and-set race (R-2).**
  - **(a) Record it as a separate follow-up story (recommended)** — the fix changes the listener's write
    and needs its own Gherkin, tests and security review; folding it in would contradict **D-2**.
  - (b) Fold it into this story — closes a real gap sooner, but turns a "prove and de-duplicate" story
    into a behaviour change to a security-adjacent listener and enlarges the audit.
- **OQ-4 — Should the real-database characterisation test exist (D-6)?**
  - **(a) Yes (recommended)** — the only test that exercises a real `save()` and a real reload, with the
    limitation stated honestly in the test.
  - (b) No, rely on the unit tests — cheaper, but nothing then pins the end-to-end behaviour a future
    refactor of the listener's persistence must preserve.

### Not verified in this pass
- **`php artisan event:list` output** — taken from the owner's report and 0064's Phase 5 record; not re-run
  here (this Phase 1 pass ran no artisan command). Phase 3 re-runs it as an acceptance criterion.
- **That resolution counting yields 2 before / 1 after** — it follows from the dispatcher constructing one
  listener per registration per dispatch, but no test was run; the red-first step (**D-7**) is what
  confirms it.
- **Deployment-time event caching** (**R-3**).

## Provenance

- **Raised by:** the human owner, 2026-09-26, while closing story 0064; the duplicate was found by 0064's
  Phase 5 work. The owner's instruction — make the handler idempotent (decision 3A, **D-0**) and fix the
  double registration in one consistent way — is the whole scope.
- **Debate:** `product-owner` (facilitator) with `backend-expert` (files and approach: the deletion, the
  listener's existing behaviour, the discovery rules) and `backend-qa` (test design: resolution counting,
  the registry, the double's `saveCallCount`, the mutation table). `database-expert` not convened — no
  schema. Agreed design recorded as **D-1** through **D-8**, each with its rejected alternative.
- **Facilitator findings that changed the document:** (1) `two-factor-passkeys-logout-and-map.md` lines
  111 and 114 also claim explicit registration and were added to the docs list; (2) `login-status-enforcement.md`
  lines 316-318 assert that discovery is off, which the installed framework contradicts — it is corrected,
  not merely reworded; (3) the idempotency work is reframed from "make it idempotent" to "prove and pin
  what already is" once the listener was read against its own guards (**D-2**).
- **Models followed for tone and structure:** [0064](done/0064-scheduled-post-auto-publish-backend.md)
  and [0061a](done/0061a-blog-post-publish-with-future-date-schedules.md).
- **Status:** Phase 1 output (new stage). Phase 2 (INVEST validation) not yet run.
