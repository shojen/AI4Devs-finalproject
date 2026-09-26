# Testing a Scheduled Command

Story 0064's `blog:publish-scheduled-posts` is this app's first scheduled command, so this is the project's first guidance on testing one. The shape it settled — four layers, one test file each — is the one the next scheduled job should copy. For *when to fake* a boundary see [mocking-and-fakes.md](mocking-and-fakes.md); for the database this runs against see [database-strategy.md](database-strategy.md).

## Four layers, one test file each

| Layer | What it is | What its test asserts | Where |
| --- | --- | --- | --- |
| The **action** | `App\Actions\Blog\PublishScheduledBlogPost` — the transition for **one** subject, returning the changed model or `null` | Every behavioural case: the transition, what must not be touched, the boundary from both sides, idempotency | `tests/Feature/Blog/PublishScheduledBlogPostTest.php` |
| The **contract** | The event the action dispatches for a later story to consume | Dispatched once per subject, never for an untouched one, never on a restore, never on a failed write — and **only** the dispatch, never what a listener does with it | `tests/Feature/Blog/ScheduledBlogPostPublishedEventTest.php` |
| The **command** | `App\Console\Commands\PublishScheduledBlogPosts` — selection, the per-subject loop, the output | That it delegates (one integration assertion), reports a count and the empty case, survives one failing subject, and honours `--dry-run` | `tests/Feature/Console/Commands/PublishScheduledBlogPostsTest.php` |
| The **schedule entry** | The `Schedule::command(...)` line in `routes/console.php` | That it is registered, at the intended frequency, with the overlap guard | `tests/Feature/Console/ScheduleRegistrationTest.php` |

Keep the command test **thin**: it proves the console entry point delegates, not that the transition is right through a second door. `tests/Feature/Console/Commands/` mirrors `app/Console/Commands/`; the schedule test mirrors nothing in `app/`, so it sits one level up. Fixtures shared by the three files are static methods in `tests/Support/Blog/` (`ScheduledPosts`, `FailingBlogPostWrites`), not global Pest functions — a global function redeclared by a second file is a fatal error.

## Rules

- **Freeze the clock in every case** with `Carbon::setTestNow()` (and reset it in `afterEach`). A sweep is time-dependent by definition, and an unfrozen `now()` comparison is non-deterministic by construction.
- **Assert the boundary from both sides**, plus a "no gap" test that ties it to the boundary of whatever *creates* the state: creation refuses `now()` (strictly `>`), the sweep publishes at `now()` (`<=`), and only a test that schedules at `now() + 1s` through the real creation action, advances the clock and sweeps proves the two interlock.
- **Do not call `actingAs()` for a system-triggered action** — the inverse of the usual authorization warning. The action must succeed with no authenticated user, so assert `Auth::check()` is `false` at the point of call; a test that authenticates out of habit passes while proving nothing about the property that matters. (The one test that needs an actor to *create* the state logs out with `Auth::forgetGuards()` before the sweep.)
- **Seed states the application cannot legitimately produce** with the factory or a raw `DB::table()->update()`: a `Scheduled` row whose date has already passed is exactly what time produces, and a `Draft` with a past date can only be reached by a bad backfill — and is the only row that exposes a sweep missing its `status` predicate.
- **Assert the whole row when the claim is "only these columns changed"**: read the stored row raw before and after and diff the arrays. A hand-picked list of columns cannot catch a copy-adapted full `save()`, and `updated_at` is what tells "returned early" from "rewrote the same value" — move the clock between the two calls.
- **Assert through `withTrashed()` for soft-deleted subjects.** A default query cannot see the row, so an `assertDatabaseMissing`-style check passes for entirely the wrong reason.
- **Force a write to fail with `Tests\Support\Blog\FailingBlogPostWrites::next()`**, which throws from `DB::connection()->beforeExecuting()` before the `UPDATE` runs. That proves both "nothing was announced" and "one bad row does not stop the run" without mocking the model. A swallowed exception must still reach the log: assert `Log::shouldHaveReceived('error')`.
- **Fake only the event under test**: `Event::fake([ScheduledBlogPostPublished::class])`, so every other event still fires normally.
- **Log lines are the only record of a write with no actor and no UI**, so assert them (`Log::spy()` + `shouldHaveReceived`), and assert that an empty run logs nothing — the command runs every minute.

## The schedule-registration test

Without it, a `routes/console.php` that never loads, or an entry deleted in a refactor, leaves a scheduler that silently does nothing — and `php artisan list` still shows the command, because it is auto-discovered from `app/Console/Commands/` independently of the schedule.

Read the entry from the container: `app(Schedule::class)->events()` returns `Event[]`, and `command`, `getExpression()`, `withoutOverlapping` and `expiresAt` are all public, so each assertion can genuinely go red. Assert the **literal** cron string and expiry, never a value re-derived from the helper that set it, and assert the entry count so an empty filter cannot pass vacuously.

**Prove it can fail before trusting it**: comment the `Schedule::command(...)` line out and confirm the assertions go red, then revert. Story 0064 also mutated the frequency (`everyFiveMinutes()`) and the expiry (`withoutOverlapping()` with no argument) and confirmed one test dies for each.

## What is not testable here, stated rather than faked

- **Real concurrent overlap.** `withoutOverlapping()`'s mutex is only consulted by `schedule:run`'s own dispatch — never by `Artisan::call()` or a direct action call — and the suite is one synchronous process. `phpunit.xml` also pins `CACHE_STORE=array`, so even the mutex's store differs from production's `database`. Test the *configuration shape* (the flag and the expiry) and review the rest.
- **The scheduler itself** (`schedule:run`, cron parsing) — vendor. That the deployment actually runs `schedule:run` every minute is a runbook obligation with no application-level detection.
- **The composite index's query plan.** A functional test cannot tell a correct result reached by an index seek from one reached by a scan; run `EXPLAIN` once at Phase 3 and record the access type in the task file.
