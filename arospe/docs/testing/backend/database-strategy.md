# Database Strategy

## What this repo actually does

[`tests/Pest.php`](../../../tests/Pest.php) binds `RefreshDatabase` to every test in `tests/Feature/` and `tests/Browser/`, through a single call:

```php
// tests/Pest.php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');
```

`tests/Unit/` gets no database trait at all — by design (see [unit-tests.md](unit-tests.md)). There is no use of Laravel's transaction-only `DatabaseTransactions` trait anywhere in this codebase today; `RefreshDatabase` is the one strategy in use, and it is deliberately the **same** strategy for both database-backed suites — Pest's browser plugin serves the page under test through the same in-process Laravel kernel, so the test's open transaction is visible to it exactly as it is for a `Feature` test (see [frontend/playwright-setup.md](../frontend/playwright-setup/status-structure-and-syntax.md#folder-structure)).

## What `RefreshDatabase` gives you here

- [`phpunit.xml`](../../../phpunit.xml) sets `DB_DATABASE=testing` for the test run, on top of whatever `DB_CONNECTION` your environment resolves (`config/database.php` defaults to `sqlite` if unset; CI's `.env.example` also sets `sqlite` — see [ci/commands.md](../ci/commands.md)). `RefreshDatabase` migrates that connection fresh before the suite and wraps each test in a transaction it rolls back afterward, so tests are isolated from each other without you managing cleanup by hand.
- This is why `assertDatabaseHas()`, `$model->fresh()`, and `$user->passkeys()->find($id)` (as used in [feature-integration-tests.md](feature-integration-tests.md)) are trustworthy: every `Feature` test starts from the same clean, migrated schema, not from whatever the previous test left behind.

## When to reach for a different strategy — and why we don't, yet

`DatabaseTransactions` (transaction-only, no re-migration) is faster when the schema is large and stable, because it skips re-running every migration per test run. This codebase's schema is still small (`users`, `passkeys`, `sessions`, the `spatie/laravel-permission` tables — see [database/schema.md](../../database/schema.md)), so `RefreshDatabase`'s migration cost is negligible. Don't switch to `DatabaseTransactions` preemptively; revisit only if the migration count grows enough that `RefreshDatabase` measurably slows the suite (per [ci/commands.md](../ci/commands.md) for measuring run time), and raise that as a deliberate decision, not a silent per-file opt-out.

## Don't mix strategies per file

Do not add `uses(DatabaseTransactions::class)` or manually opt a `tests/Feature/` file out of `RefreshDatabase` to "speed things up." A test relying on a clean schema that silently doesn't get one is a source of flaky, order-dependent failures — exactly what [risk-based-testing.md #3](../qa/risk-based-testing.md) asks you to check for ("what happens if this runs twice, out of order, or concurrently?"). If a specific test's setup is expensive, optimize the setup (e.g. fewer factory calls, `make()` instead of `create()` where persistence isn't needed — see [datasets-and-factories.md](datasets-and-factories.md)), not the isolation guarantee.

## Invoice numbering fixtures (story 0101a)

`tests/Support/Invoices/Numbering.php` is the reusable fixture for tests of the invoice counters (and, later, of the issuing actions of 0101b/0107/0108). There is no `InvoiceSequenceFactory`: the two `invoice_sequences` rows are created by the migration and `series` is unique, so `Numbering::arrange($series, $lastNumber, $lastYear, $issuedAt)` **updates the existing row** (`forceFill()->save()`) instead of creating one. Other helpers: `allocate()` / `number()` (lock and allocate inside a transaction, as an issuing action will), `snapshot()` for before/after comparisons, `withoutTransaction()` (zeroes the connection's transaction level to reproduce a caller with no transaction), `numberingStatements()` (the SQL touching the two tables, for lock-order assertions) and `actingWith()`.

**Contending for a lock needs a second session.** `Numbering::probe()` returns a second connection on the same database, and `shortLockWait()` sets `innodb_lock_wait_timeout = 1` so a blocked statement fails fast with error 1205 (never `sleep`). The test's own connection sits inside `RefreshDatabase`'s uncommitted outer transaction, so the probe only sees committed rows (the migration-created sequence rows qualify); see the [errors-log entry](../../errors-log/2026-09-25-to-2026-09-26.md#a-test-that-needs-a-second-session-to-contend-for-locks-cannot-sit-on-refreshdatabases-outer-transaction--2026-11-17) for the rules that follow.

_Last updated: 2026-11-17 — Story 0101a: added the invoice numbering fixtures section._
