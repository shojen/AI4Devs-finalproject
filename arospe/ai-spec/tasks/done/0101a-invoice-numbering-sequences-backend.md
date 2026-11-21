# [0101a] Invoice numbering sequences (backend)

> **Status: Done (closed 2026-11-18, Phase 7).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](0100-invoice-settings-backend.md) (settings, `ComposeInvoiceNumber`, `InvoiceType`,
> `InvoiceSettingPolicy`). Dependents: [0101b](0101b-issue-standard-invoice-backend.md) (issues
> standard invoices with these numbers), [0103](0103-invoice-settings-screen-ui.md) (preview and
> starting counter on the screen),
> [0107](../0107-corrective-invoice-on-refund-and-cancellation-backend.md) and
> [0108](../0108-regenerate-invoices-after-order-edit-backend.md) (corrective numbers).

## Description
Two gap-free counters, one for standard and one for corrective invoices, with the optional
restart every 1 January. This story ships the counter table, the allocator that hands out the
next number inside the caller's transaction, a read-only preview of the next number (for saved
or candidate settings) and the one-time starting counter. It issues no invoice: the `invoices`
table, `UNIQUE(number)` and the "next number must not already exist" settings check belong to
0101b.

Request items served (owner, 2026-11-14, translated):
> 1. ... how invoices are numbered, and a checkbox so the numbering restarts every 1 January.
> 3. A return or refund produces a corrective invoice with its own numbering, different from the
> invoice numbering.

Binding rules from the epic (settled 2026-11-15): numbering is gap-free (a failed issue consumes
nothing); a format change mid-year continues the count; toggling the yearly reset mid-year keeps
the current count and the reset first happens at the next year change; the starting counter can
be set only before a series has allocated anything (accepted default of owner question Q-0100-2,
option (a)).

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- 0100 provides `InvoiceSetting` (singleton, `current()` returns defaults when no row exists,
  `reset_numbering_yearly` default true), `ComposeInvoiceNumber::__invoke(InvoiceType $series,
  InvoiceSetting $settings, CarbonInterface $at, int $counter): string`, `InvoiceType`
  (`standard`, `corrective`) and `InvoiceSettingPolicy` (`viewAny` = `invoice-settings.view`,
  `update` = `invoice-settings.manage`). `SaveInvoiceSettings` locks the settings row
  `FOR UPDATE`.
- House rules: refusals are logged through `LogRefusedPrivilegedAttempt::authorize()` as the
  action's first statement; the project-wide lock order (story 0088a FD-7) is `orders` ->
  `products` -> `product_variants` -> stock -> `order_items`; deadlock 1213 is not retried.
- New domain tables use UUIDv7 primary keys (`HasUuids`).

## Table created

`invoice_sequences` (migration `<ts>_create_invoice_sequences_table.php`):

| Column | Type | Null / default | Notes |
| --- | --- | --- | --- |
| `id` | uuid PK | not null | UUIDv7 |
| `series` | varchar(20) | not null, UNIQUE | `InvoiceType` value |
| `last_number` | int unsigned | not null, default 0 | last value handed out |
| `last_year` | smallint unsigned | null | calendar year (app timezone) of the last allocation; null = never allocated |
| `last_issued_at` | dateTime | null | moment of the last allocation, stored in the app timezone (D-2: not `timestamp`) |
| `created_at`, `updated_at` | timestamp | null | |

The migration itself inserts the two rows (`standard`, `corrective`; `last_number` 0, nulls) with
`Str::uuid7()` ids, so they exist in every environment and after `RefreshDatabase`. No FK, no
other index. `down()`: `Schema::dropIfExists`.

## Decisions
- **FD-1 One row per series (the redesign).** No per-year buckets and no seeding. Allocation,
  inside the caller's transaction: the issue moment `$at` is first converted to
  `config('app.timezone')` (`$at->setTimezone(config('app.timezone'))` on an immutable copy), and
  that converted moment is used **both** for `year` and for composing the printed number (so the
  year token can never disagree with the counter's year); `year` = year of the converted moment;
  if yearly reset is on **and** `last_year` is not null **and** `year > last_year`, next = 1;
  otherwise next = `last_number + 1`; then write `last_number = next`, `last_year = year`,
  `last_issued_at = issue moment`. Reason: a bucket-per-year table needs a seed rule when a bucket
  is created and reuses a stale bucket after a reset flip; QA showed reset ON, OFF, ON within one
  year re-issued 11..15 after 15 had been issued. Consulting the flag only at a year change
  removes that class of bug, and "switching reset off continues" means the latest counter (2024
  at 500, 2025 restarted to 30, reset off -> 31, never 501).
- **FD-2 Gap-free by transaction.** The counter row is updated in the same transaction that
  will insert the invoice (0101b); a rollback restores `last_number`, `last_year` and
  `last_issued_at`, so the next issue reuses the number. Rejected: `MAX()+1` on invoices (gap
  locks, duplicates under READ COMMITTED), `AUTO_INCREMENT` or a cache counter (not rolled back,
  burns numbers).
- **FD-3 Lock order, owned by the allocator.** `lock()` requires an open transaction and takes
  `invoice_settings` `FOR SHARE` first (so a concurrent settings save cannot flip the reset flag
  mid-allocation), then the needed sequence rows with one `orderBy('series')->lockForUpdate()`
  query (corrective before standard), always as the last locks of any issuing transaction (after
  the order and invoice locks of 0101b/0107/0108). A missing series row is a `LogicException`;
  nothing is ever inserted at runtime.
- **FD-4 The locked settings decide.** `__invoke` takes no settings parameter: the reset flag and
  the format come from the settings read under the share lock in `lock()`, which `lock()` also
  returns so 0101b snapshots the same state. A caller-held `InvoiceSetting` can never decide the
  year rule. When no settings row exists yet, the documented defaults apply (0101b refuses issuing
  without issuer data, so in practice the row exists).
- **FD-5 Clock moved back is refused.** An allocation whose issue moment is earlier than
  `last_issued_at` is a `ValidationException` on `invoice` (`invoice-settings.numbering.clock_skew`)
  and consumes nothing; an equal moment is accepted. Reason: numbers must stay monotonic in date
  order. 0101b takes the issue moment after the lock.
- **FD-6 Overflow guard.** A counter that would pass `int unsigned` (4294967295) is a
  `ValidationException` on `number`, nothing consumed. A counter wider than the padding is
  never truncated (`F2026-100000`).
- **FD-7 Preview is read-only and gated by `invoice-settings.view`.** `PeekNextInvoiceNumber`
  authorizes `viewAny` (logged), accepts unsaved candidate input as a **raw array** (never a
  model: a model with an enum cast such as `standard_year_format` throws `ValueError` on
  `forceFill(['standard_year_format' => 'XX'])` before the action could run, so an invalid
  candidate could never become a `ValidationException` and the screen would answer 500),
  validates it through 0100's `invoiceSettingRules()` (so a candidate prefix cannot inject
  markup) **before** any `InvoiceSetting` is built, then composes `nextCounter()` with the
  candidate format: no lock, no write. `invoice-settings.manage` alone does not grant preview
  (the screen is unreachable without `view` anyway). Details in FD-12.
- **FD-8 Starting counter.** `SetInvoiceSequenceStart` authorizes `update` (logged) **before**
  reading any state (a refusal must not reveal whether the series has issued), validates
  `1 <= startAt <= 4294967294`, then in a transaction locks settings `FOR SHARE` and the series
  row `FOR UPDATE`, refuses when `last_issued_at` is not null (`numbering.start_already_issued`),
  and sets `last_number = startAt - 1`. A start already set does not count as an allocation, so
  it can be changed again until the first number is issued. Because `last_year` stays null, the
  first allocation never restarts, even with reset on and in a later year.
- **FD-9 G-10 settled: a flip OFF -> ON is consulted at the next year change.** Reset off in
  2026, turned on during 2027 after 2027 numbers were issued: the count continues in 2027 and
  restarts on the first 2028 issue. Turned on in 2027 before any 2027 issue (last year 2026):
  the first 2027 issue restarts at 1; while reset was off the format may have had no year token,
  but turning reset on forces one (BR-0100-7), so the new numbers print differently. Any residual
  printed collision caused by a format change is caught by 0101b's `UNIQUE(number)` and the
  BR-0100-9 settings check (accepted risk, recorded for 0101b).
- **FD-12 Partial candidate and error keys (screen contract).** `PeekNextInvoiceNumber` takes
  `?array $candidate` (`array<string, mixed>`, raw screen input, partial allowed). Steps, in
  order: (1) authorize `viewAny` (logged); (2) when `$candidate` is null, compose from
  `InvoiceSetting::current()` and skip 3-5; (3) the subset keys are the series' four numbering
  fields (`<series>_prefix`, `<series>_year_format`, `<series>_separator`, `<series>_padding`)
  plus `reset_numbering_yearly`; candidate keys outside the subset are ignored; (4) overlay
  `Arr::only($candidate, <subset>)` on `Arr::only(InvoiceSetting::current()->attributesToArray(),
  <subset>)` (saved values as plain scalars, enums as their string values), so fields absent from
  the candidate are taken from the saved settings; pass the result through
  `normalizeInvoiceSettingAttributes()` for parity with Save (subset-safe; it currently changes
  no numbering key); (5) validate with `Validator::make($data, Arr::only($this->invoiceSettingRules(),
  <subset>))->validate()` — a failure is a `ValidationException` keyed by the offending field
  (e.g. `standard_year_format`), and no model is built; (6) only then build the model to compose
  (`InvoiceSetting::current()` replicated, or `new InvoiceSetting`, `forceFill()` of the validated
  subset; never saved) and return the composed `nextCounter()` number.
  **Intentionally skipped:** the partial preview validates field rules only, not 0100's cross-field
  rules performed by `SaveInvoiceSettings` (reset on requires a year token, i.e. year `none` with
  reset on; corrective prefix must differ from the standard prefix case-insensitively). A
  candidate with reset on and year `none` previews a number without a year; Save refuses it. This
  is by design, not a bug, and 0103 QA must not file it as one (Save remains the single gate for
  cross-field consistency).
  `SetInvoiceSequenceStart` raises `invoice-settings.numbering.start_already_issued` and
  `start_invalid` on the key `start_at`. The settings screen reads the sequence state through its
  own read action (`ReadInvoiceSequenceState`, owned by
  [0103](0103-invoice-settings-screen-ui.md)), never the table directly.
- **FD-10 No new permission or policy.** Both public actions reuse `InvoiceSettingPolicy`.
- **FD-11 Out of this story:** `AssertNumberingFormatIsFree` and the "next number already exists"
  settings refusal (0101b, it reads `invoices.number`; it calls `nextCounter()`), the
  collision-error message, and the end-to-end rollback with a real invoice insert (0101b repeats
  it).

## Deviations recorded in Phase 3
- **D-1 No `InvoiceSequenceFactory`.** `UNIQUE(series)` and the two migration-created rows make a
  factory `create()` impossible, and a factory that only updates a row is not a factory. Fixtures
  use `Tests\Support\Invoices\Numbering::arrange()` (and `row()`, `snapshot()`, `allocate()`, the
  probe-connection helpers). The Files list and Technical tasks above are amended accordingly.
- **D-2 `last_issued_at` is `dateTime`, not `timestamp`.** The allocator stores and compares an
  app-timezone wall-clock value; `timestamp` would convert through the MySQL session timezone.
  A DST fall-back hour is ambiguous for a wall-clock column (accepted, I2); the effect is a refusal,
  never a duplicate.

## Class contracts
- `App\Models\InvoiceSequence`: `HasUuids`, `#[Fillable([])]`, casts (`last_number` int,
  `last_year` int, `last_issued_at` immutable datetime), no relations; written only by
  `AllocateInvoiceNumber` and `SetInvoiceSequenceStart` via `forceFill()`.
- `App\Actions\Invoices\AllocateInvoiceNumber` (collaborator, authorizes nothing; callers do):
  - `lock(list<InvoiceType> $series, CarbonInterface $at): array{settings: InvoiceSetting, sequences: array<string, InvoiceSequence>}`
    — `LogicException` outside a transaction or for a missing row;
  - `__invoke(InvoiceType $series, CarbonInterface $issuedAt): array{number: string, series: string, sequence_year: int, sequence_number: int}`
    — reuses the locked state (calls `lock()` itself when the caller has not); `sequence_year` is
    always the calendar year of the allocation in the app timezone, informational only (stored by
    0101b on the invoice, not part of any unique key);
  - `nextCounter(InvoiceType $series, InvoiceSetting $settings, CarbonInterface $at): int` —
    the FD-1 formula, read-only, no lock.
- `App\Actions\Invoices\PeekNextInvoiceNumber::__invoke(InvoiceType $series, ?array $candidate =
  null, ?CarbonInterface $at = null): string` — `$candidate` is `array<string, mixed>` raw partial
  input (FD-12); the class `use`s `InvoiceSettingValidationRules`; `$at` defaults
  to now and is converted to the app timezone as in FD-1.
- `App\Actions\Invoices\SetInvoiceSequenceStart::__invoke(InvoiceType $series, int $startAt): void`.

## Gherkin
```gherkin
Feature: Invoice numbering sequences

  Scenario: Consecutive invoices get consecutive numbers
    Given a store manager whose store has issued standard invoices up to F2026-00002
    When the next standard invoice is issued in 2026
    Then it is numbered F2026-00003

  Scenario: The two series count independently
    Given a store manager whose store has issued standard invoices up to F2026-00005 and no corrective invoice
    When the first corrective invoice is issued
    Then it is numbered R2026-00001
    And the next standard invoice is still numbered F2026-00006

  Scenario: Yearly reset restarts on 1 January
    Given a store manager with yearly numbering reset on and the last invoice numbered F2026-00007
    When the next invoice is issued on 1 January 2027 in the store's time zone
    Then it is numbered F2027-00001

  Scenario: Without yearly reset the count continues across years
    Given a store manager with yearly numbering reset off and the last invoice numbered F2026-00007
    When the next invoice is issued in 2027
    Then it is numbered F2027-00008

  Scenario: Toggling the reset within a year never repeats a number
    Given a store manager who issued 10 invoices in 2026 with reset on, then 5 more with reset off,
      and has switched reset on again
    When the next invoice is issued in 2026
    Then its counter is 16

  Scenario: A format change keeps the count
    Given a store manager whose last invoice was numbered F2026-00007
    When she changes the standard prefix to "INV" and the next invoice is issued
    Then it is numbered INV2026-00008

  Scenario: A failed issue consumes no number
    Given a store manager whose last invoice was numbered F2026-00007
    When an invoice fails while being issued after taking its number
    Then the next successful invoice is numbered F2026-00008

  Scenario: Setting the starting counter of a fresh series
    Given a store manager with permission to manage invoice settings, whose standard series has never issued
    When she sets the standard starting counter to 1500
    Then the next standard invoice number ends in 01500

  Scenario: The starting counter cannot be changed after issuing
    Given a store manager with permission to manage invoice settings, whose standard series has issued an invoice
    When she tries to set the standard starting counter
    Then it is refused
    And the counter is unchanged

  Scenario: Previewing the next number consumes nothing
    Given a store manager with permission to view invoice settings, typing a new numbering format
    When she previews the next numbers
    Then she sees the next standard and corrective numbers in that format
    And no number is consumed

  Scenario: A viewer without permission cannot set the starting counter
    Given a sales assistant who may view but not manage invoice settings
    When she tries to set the standard starting counter
    Then it is refused and the attempt is logged
```

## Files to create/modify
- `database/migrations/<ts>_create_invoice_sequences_table.php` — table and the two rows.
- `app/Models/InvoiceSequence.php`. **No factory (deviation D-1, below):** fixtures are
  `Tests\Support\Invoices\Numbering::arrange()`, which updates the existing migration-created row
  (`forceFill()->save()` on `InvoiceSequence::where('series', ...)->sole()`) and never `create()`s one,
  which would fail on `UNIQUE(series)`.
- `app/Concerns/InvoiceSettingValidationRules.php` — `attributeNames()` moved into the trait so
  Save and Peek share it.
- `app/Actions/Invoices/AllocateInvoiceNumber.php`, `PeekNextInvoiceNumber.php`,
  `SetInvoiceSequenceStart.php`.
- `lang/{en,es}/invoice-settings.php` — `numbering.start_already_issued`,
  `numbering.start_invalid`, `numbering.clock_skew`, `numbering.overflow`.
- `tests/Support/Invoices/Numbering.php` — helper running a closure inside a transaction that
  locks and allocates (no `invoices` table exists yet).
- Tests listed below. No seeder, route, config or permission change.

## Tests to perform
Files: `tests/Feature/Invoices/{AllocateInvoiceNumberTest, AllocateInvoiceNumberConcurrencyTest,
AllocateInvoiceNumberGuardsTest, PeekNextInvoiceNumberTest, SetInvoiceSequenceStartTest,
InvoiceSequenceSchemaTest, InvoiceSequenceModelTest}.php`; lang parity (existing test). Time with
`travelTo` in `config('app.timezone')`; concurrency with a second connection and a short
`innodb_lock_wait_timeout` (deterministic, no sleeps).

Note: the QA case list was first written for the bucket-per-year design; the cases that design
needed (lazy bucket creation, seeding, `insertOrIgnore` placement, midnight bucket mismatch) are
removed and the rest rewritten below.

Happy path
- [ ] T-1 Three committed allocations: `F2026-00001..3`; row `last_number 3`, `last_year 2026`,
  `last_issued_at` = last moment.
- [ ] T-2 Standard and corrective allocated alternately (dataset over order): each counts 1, 2, 3.
- [ ] T-5 Format change (prefix, separator, padding, year token; dataset) continues at the next
  counter.
- [ ] T-6 Start 1500 on a fresh series: first number `...01500`, `last_issued_at` still null after
  the start.
- [ ] T-7 Preview for saved settings: exact next number of each series; row unchanged.
- [ ] T-8 Preview for a raw candidate array (`['standard_prefix' => 'INV', 'standard_year_format'
  => 'YY', 'standard_padding' => 3]`): candidate format with the real next counter; nothing
  persisted (settings row and sequence rows unchanged).
- [ ] R-1 Schema: exactly two rows after migrate, `last_number 0`, `last_year`/`last_issued_at`
  null, UNIQUE on `series` only, no `sequence_year` column; `down()` drops the table.

Year rule
- [ ] R-2 Reset on, `last_year 2026`, `last_number 7`: dataset of five instants (2026-12-31
  23:59:59, 2027-01-01 00:00 in the app timezone, and the 22:59/23:00 UTC pair around it, plus a
  mid-2026 instant) -> 1 for 2027 instants, 8 for 2026 instants; the year follows the app
  timezone, not UTC. The UTC pair also asserts the full printed number (`F2026-00008` for 22:59
  UTC, `F2027-00001` for 23:00 UTC with app timezone `Europe/Madrid`), proving the year token is
  composed from the converted moment, not the raw UTC one.
- [ ] R-3 Reset off across a year turn continues; `last_year` still updated. First ever
  allocation with reset on is `last_number + 1` (no restart).
- [ ] R-4 ON (10 issued in 2026) -> OFF (5 more) -> ON: next is 16; the whole run is
  duplicate-free. Highest-risk case of the redesign.
- [ ] R-5 2024 at 500, 2025 restarts and reaches 30, reset off: next 31; switching on again
  mid-2026 keeps counting until the first 2027 issue.
- [ ] R-6 Issue moment earlier than `last_issued_at` (also across a year turn): refused on
  `invoice`, nothing consumed; equal moment accepted.
- [ ] R-11 Candidate-format preview with reset on across a year turn shows 1, equal to what an
  allocation at that instant takes, without lock or write.
- [ ] FD-9 Reset off in 2026, switched on in 2027 before any 2027 issue: first 2027 issue is 1;
  switched on after 2027 issues: continues, restarts on the first 2028 issue.

Concurrency, locking, rollback
- [ ] T-11 / R-10 Exception after the counter write (dataset of throw points): `last_number`,
  `last_year`, `last_issued_at` restored; the next allocation reuses the number.
- [ ] T-12 Second connection holds the series row `FOR UPDATE`: the allocation fails with 1205;
  after release it takes the next number (no duplicate, no skip).
- [ ] T-13 Settings row held `FOR SHARE` by an in-flight allocation: `SaveInvoiceSettings` waits
  (1205 with the short timeout).
- [ ] R-8 `DB::listen`: settings `FOR SHARE`, then `invoice_sequences ... ORDER BY series FOR
  UPDATE` (corrective before standard when both), then the update; no insert anywhere in the
  path.
- [ ] R-7 A caller-held settings object with a different reset flag does not decide; `__invoke`
  has no settings parameter (reflection assertion).
- [ ] T-15 Year boundary in `Europe/Madrid` vs UTC (covered by R-2's dataset).
- [ ] T-19 99999 with padding 5 -> `F2026-100000`; at 4294967295 -> `ValidationException` on
  `number`, counter unchanged.

Guards and negative
- [ ] T-29 Allocation or `lock()` outside a transaction -> `LogicException`; a deleted series row
  -> `LogicException`, nothing inserted.
- [ ] T-20 / T-21 / R-9 Start dataset `1`, `1500`, `4294967294` accepted; a second start before
  any issue accepted (last wins); with a start set and reset on, the first issue in a later year
  still uses the start (no restart).
- [ ] T-23 Start refused (`numbering.start_already_issued`) after an allocation this year or in a
  prior year; the other, never-allocated series still accepts a start.
- [ ] T-24 Start `0`, `-1`, `4294967295`, `9999999999999` refused (`numbering.start_invalid`), no
  write.
- [ ] T-25 Without `invoice-settings.manage` (view only, or nothing): `AuthorizationException`
  before any state read; same refusal whether or not the series has issued.
- [ ] T-26 A refusal logs one `Log::warning('Privileged action refused')` (`ability = update`,
  `target_type = invoice_setting`); unauthenticated caller refused and logged.
- [ ] T-27 Preview without `invoice-settings.view` (including `manage` only): refused, one
  warning, nothing read.
- [ ] T-28 Raw candidate array with an invalid prefix (`F.`, `<b>`, 11 chars), unknown year
  token (`standard_year_format => 'XX'`) or padding 11: `ValidationException` on that field (never
  a `ValueError` or 500), no preview returned, no model built.
- [ ] T-31 Forcing a second row with the same `series` -> `QueryException` (23000);
  `last_number` rejects negatives.
- [ ] T-33 Partial raw candidate (`['standard_prefix' => 'INV']` only): the preview uses it with
  the saved separator, padding, year token and reset flag; an invalid value in the candidate is
  refused on its own field; keys outside the subset (e.g. an invalid `primary_color`, or the other
  series' fields) are ignored and not validated; reset on with year `none` is previewed, not
  refused (cross-field rules are Save's, FD-12).
- [ ] T-34 Start refusals carry the key `start_at` with the FD-12 messages.
- [ ] T-32 Only `AllocateInvoiceNumber` and `SetInvoiceSequenceStart` write `InvoiceSequence`
  (arch-style assertion); no route or Livewire property exposes it.

## Expected outcome
Any later issuing action can, inside its transaction, lock and take the next gap-free number of
either series, with the yearly restart applied exactly at the first issue of a new year; the
settings screen can preview the next numbers and set a starting counter once.

## Acceptance criteria
- [x] `invoice_sequences` exists as specified with its two rows created by the migration.
- [x] Allocation follows FD-1 and is rolled back with the caller's transaction (FD-2).
- [x] Locks are taken in the FD-3 order; the reset flag comes from the locked settings (FD-4).
- [x] Clock skew and counter overflow are refused without consuming a number (FD-5, FD-6).
- [x] The preview never writes or locks and is gated by `invoice-settings.view` (FD-7).
- [x] The starting counter works only before the first allocation, is gated by
  `invoice-settings.manage` and logs refusals (FD-8).
- [x] en/es lang keys exist with parity.

## Definition of Done
- [x] Tests written and green
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor)
- [x] Documentation updated (docs-keeper): ER diagram (`invoice_sequences`, standalone),
  the invoicing schema doc, `docs/conventions/directory-structure/actions.md`, the lock-order
  rule (sequence rows are always the last locks of an issuing transaction)
- [x] Acceptance criteria met
- [x] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0100](0100-invoice-settings-backend.md). Downstream:
  [0101b](0101b-issue-standard-invoice-backend.md) calls `lock()` and `__invoke()` inside its issuing
  transaction, adds `UNIQUE(number)`, the collision message and `AssertNumberingFormatIsFree`
  (via `nextCounter()`), and repeats the rollback test with a real insert;
  [0103](0103-invoice-settings-screen-ui.md) calls the preview and the starting counter;
  [0107](../0107-corrective-invoice-on-refund-and-cancellation-backend.md) /
  [0108](../0108-regenerate-invoices-after-order-edit-backend.md) lock both series in one call.
- Risk: the whole epic's numbering correctness rests on FD-1..FD-3; the concurrency and rollback
  tests are mandatory, not optional.
- Settled for 0101b (database decision, 2026-11-15): the only unique key on numbering is
  `UNIQUE(invoices.number)`; `series`, `sequence_year` and `sequence_number` are stored but not
  unique, because a composite key could refuse a valid number after an OFF -> ON -> OFF reset
  history while the printed numbers differ.
- A starting counter lower than numbers already used by another tool cannot be detected; that is
  the manager's responsibility, stated on the 0103 screen.
- Inputs: `$startAt` reaches the action from a Livewire property; it is cast and bound-checked
  server-side; `$series` is a closed enum; no raw SQL takes caller input.
- Open questions recorded for the owner (none blocks Phase 3): Q-0100-2 starting counter:
  (a) settable only before a series has issued anything (recommended, accepted default:
  needed for migration from another tool, cannot create gaps or duplicates); (b) not offered.

## Technical tasks
- [ ] Schema test, migration with the two rows, model (no factory, see D-1).
- [ ] `AllocateInvoiceNumber` (`lock`, `__invoke`, `nextCounter`) with the year-rule,
  lock-order, concurrency and rollback tests.
- [ ] `PeekNextInvoiceNumber` with candidate-settings validation.
- [ ] `SetInvoiceSequenceStart` with authorization and refusal-logging tests.
- [ ] Lang keys en/es; test support helper.
- [ ] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
- Phase 2 (INVEST) **FAIL** on 2026-11-17 by code-reviewer — blocking issue B-1:
  `PeekNextInvoiceNumber` took `?InvoiceSetting $settings` as the candidate, but
  `forceFill(['standard_year_format' => 'XX'])` throws `ValueError` (enum cast) before the action
  runs, so FD-7/FD-12/T-28/T-33 were unimplementable and 0103's screen would answer 500. Returned
  to product-owner and fixed on 2026-11-17: the contract now takes `?array $candidate` (raw
  partial input) validated before any model is built (FD-7, FD-12, class contract, T-8, T-28,
  T-33; 0103's contract summary and FD-5 aligned). Non-blocking clarifications folded in: FD-1
  timezone conversion before year and composition (R-2 asserts the printed number), factory
  states update the migration-created rows, preview intentionally skips 0100's cross-field rules.
  Phase 2 re-check pending.
- Phase 2 (INVEST) PASSED 2026-11-17 by code-reviewer
- Phase 3 started 2026-11-17: task moved to `in-progress/` (claimed by `worktree-0101a-invoice-numbering-sequences-backend`); TDD red tests first.
- Phase 3 complete 2026-11-17: tests green (targeted), test-issue loop done (second-session lock tests settled; see the errors-log entry "A test that needs a second session to contend for locks cannot sit on RefreshDatabase's outer transaction"). Deviations D-1 and D-2 recorded above.
- Phase 4 (security audit) PASS 2026-11-17 by appsec-auditor after one return loop: F1 (High) and F2 (Medium), F3 and F5 fixed; L1 fixed in Phase 5. Accepted: L2, L3, I1 (`lock()` and `__invoke()` at the same transaction nesting level, documented for 0101b/0107/0108), I2 (DST fall-back ambiguity of the `dateTime` column), F4.
- Phase 5 (final code review) PASS 2026-11-17 by code-reviewer. Full suite run 2026-11-17/18 in directory chunks, all green: Unit + Orders + Localization + Customers + Invoices + InvoiceSettings 1915/1915; Blog + Products + ProductCategories 2113/2113; Models..Providers 1603 (1598 passed, 5 pre-existing skips); Media..Translations 864/864; Browser 328 (325 passed, 3 skips). Pint unscoped clean; Larastan 0 errors on the touched paths.
- Phase 6 (documentation) done 2026-11-17 by docs-keeper: ER diagram, `docs/database/schema-other/invoice-sequences.md`, actions.md, lock-order rule, authorization catalog note, testing fixtures section, errors-log entry, epic-6 digest.
- Phase 7 (closure) 2026-11-18: all Definition of Done and acceptance boxes ticked; task moved from `in-progress/` to `done/`; link integrity checked in both directions; `ai-spec/tasks-map.md` and `ai-spec/tasks-status.json` regenerated (0101a dropped; 0101b stays blocked on 0100a). PR against the worktree's base branch follows; never merged by the agent.
