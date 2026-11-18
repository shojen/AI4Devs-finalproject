# Database Schema — Payment Methods, Customers, Notifications, Invoice Settings & Invoice Sequences — invoice_sequences

> Part of [Database Schema — Payment Methods, Customers, Notifications, Invoice Settings & Invoice Sequences](../schema-other.md). **Read this part when:** the task touches the invoice counters: `AllocateInvoiceNumber`, the yearly reset, the starting counter, the issuing lock order. The other parts are listed in the [hub](../schema-other.md#table-of-contents).

### `invoice_sequences`

Source: `database/migrations/2026_11_17_230913_create_invoice_sequences_table.php` (story 0101a, Epic 6 — Invoicing). One counter row per invoice series (`standard`, `corrective`), so the two series count independently. The table issues no invoice itself: the `invoices` table and `UNIQUE(number)` belong to story 0101b.

Model: [`App\Models\InvoiceSequence`](../../../app/Models/InvoiceSequence.php) — `HasUuids` (UUIDv7), `#[Fillable([])]`, casts `last_number`/`last_year` to `integer` and `last_issued_at` to `immutable_datetime`, no relations. **There is no `InvoiceSequenceFactory`**: the two rows already exist and `series` is unique, so a factory `create()` would violate `UNIQUE(series)`. Test fixtures update the existing row through `Tests\Support\Invoices\Numbering::arrange()` (see [testing](../../testing/backend/database-strategy.md#invoice-numbering-fixtures-story-0101a)).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `CHAR(36)` PK | UUIDv7 |
| `series` | `VARCHAR(20)`, `UNIQUE` | an `App\Enums\InvoiceType` value |
| `last_number` | `INT UNSIGNED`, default `0` | the last counter handed out (the next number is `last_number + 1`, or `1` after a year restart) |
| `last_year` | `SMALLINT UNSIGNED` nullable | calendar year, **in the application timezone**, of the last allocation; `null` = the series never allocated |
| `last_issued_at` | `DATETIME` nullable | moment of the last allocation, stored in the application timezone; `null` = never allocated |
| `created_at` / `updated_at` | timestamp, nullable | |

The only index is `UNIQUE(series)`; there are **no foreign keys**. `down()` is `Schema::dropIfExists`.

#### The two rows exist from the migration, never at runtime

The migration inserts both rows (`standard`, `corrective`; `last_number` 0, nulls) with `Str::uuid7()` ids, so they exist in every environment and after `RefreshDatabase`. **No application code ever inserts one**: a missing row is a `LogicException` (fail closed), not something to create on demand.

#### Why `last_issued_at` is `DATETIME`, not `TIMESTAMP`

`timestamp` columns are converted through the MySQL session time zone and reject values outside the 1970-2038 range; `datetime` stores the wall-clock value as written. The allocator converts every instant to `config('app.timezone')` first, so the column holds an app-timezone wall-clock value and the clock-skew comparison reads it back the same way. Known limit (accepted, review item I2): a wall-clock `datetime` is ambiguous for the one repeated hour of a daylight-saving fall-back, so two allocations in that hour could compare as "earlier"; the guard is a refusal (see below), never a duplicate.

#### Allocation: [`AllocateInvoiceNumber`](../../../app/Actions/Invoices/AllocateInvoiceNumber.php)

A collaborator that authorizes nothing (its callers, the issuing actions, do). Everything happens in **the caller's transaction**, which is what makes the numbers gap-free: the counter write rolls back with the invoice, so a failed issue consumes nothing.

- **Year rule (FD-1).** The issue instant is converted to the application timezone by the public `toAppTimezone()` (reused by `PeekNextInvoiceNumber`), and that converted moment is used both for the year and for composing the printed number. The counter restarts at `1` only when `reset_numbering_yearly` is on **and** `last_year` is not null **and** the new year is greater than `last_year`; otherwise it is `last_number + 1`. The reset flag is therefore consulted **only at a year change**: toggling it within a year never repeats a number, and a never-allocated series (null `last_year`, for example after a starting counter) never restarts.
- **Locked settings decide (FD-4).** `__invoke(InvoiceType $series, CarbonInterface $issuedAt)` takes no settings parameter; the reset flag and format come from the settings read under the share lock in `lock()`, which returns them so the issuing action snapshots the same state. When no settings row exists yet, `InvoiceSetting::defaults()` applies.
- **Lock order (FD-3).** `lock(list<InvoiceType> $series, CarbonInterface $at)` requires an open transaction, takes `invoice_settings` `FOR SHARE`, then the needed series rows in **one** `ORDER BY series FOR UPDATE` query (corrective before standard). See the [lock-order rule](../../api/orders.md#lock-order-convention-project-wide). The share lock makes a concurrent `SaveInvoiceSettings` (which locks the settings row `FOR UPDATE`) wait, so the reset flag cannot flip mid-allocation.
- **Stateful per transaction (I1).** The allocator caches the locked rows. It remembers the `DatabaseTransactionRecord` they were locked in and **discards the cached state when the current record is a different object** (a rollback or commit ends the locks). It throws `LogicException` when no transaction record exists for the connection (fail closed), and when `__invoke()` names a series that was not locked while other series were (lock every series up front). `lock()` and `__invoke()` must run at the **same `DB::transaction` nesting level**, because the record identity is per level.
- **Clock skew (FD-5).** An instant earlier than `last_issued_at` is refused with a `ValidationException` on key `invoice` (`invoice-settings.numbering.clock_skew`); an equal instant is accepted; nothing is consumed.
- **Overflow (FD-6).** A counter above `AllocateInvoiceNumber::MAX_COUNTER` (4294967295, the `INT UNSIGNED` ceiling) is refused on key `number` (`invoice-settings.numbering.overflow`). A counter wider than the padding is never truncated (`F2026-100000`).
- **Result.** `array{number, series, sequence_year, sequence_number}`; `sequence_year` is informational (stored on the invoice by 0101b, part of no unique key).
- `nextCounter(InvoiceType, InvoiceSetting, CarbonInterface): int` is the same formula, read-only and lock-free, for the preview and for 0101b's collision check.

#### Preview: [`PeekNextInvoiceNumber`](../../../app/Actions/Invoices/PeekNextInvoiceNumber.php)

Logged `viewAny` authorization on `InvoiceSetting` (`invoice-settings.view`), no lock, no write. `?array $candidate` is **raw, possibly partial screen input** (never a model: an enum cast would throw `ValueError` on an unknown year token before any validation could answer). Only the series' four numbering fields and `reset_numbering_yearly` are read, overlaid on the saved values, normalised and validated with the **field rules only** (`InvoiceSettingValidationRules::invoiceSettingRules()` subset). Cross-field rules stay with `SaveInvoiceSettings`, so a candidate with reset on and year `none` previews a number without a year while Save refuses it; that is by design. `attributeNames()` lives in the `InvoiceSettingValidationRules` trait, shared with Save.

#### Starting counter: [`SetInvoiceSequenceStart`](../../../app/Actions/Invoices/SetInvoiceSequenceStart.php)

Logged `update` authorization (`invoice-settings.manage`) **before any state is read**, so a refusal never reveals whether the series has issued. Validates `1 <= startAt <= 4294967294` (`start_at` key), then in a transaction locks settings `FOR SHARE` and the series row `FOR UPDATE` and sets `last_number = startAt - 1`. It is refused while `last_issued_at` is not null (`start_already_issued`); a start already set is not an allocation, so it can be changed again until the first number is issued. `last_year` stays null, so the first allocation never restarts. The info log carries actor id, series and old/new `last_number`.

#### Only two writers

Only `AllocateInvoiceNumber` and `SetInvoiceSequenceStart` write `InvoiceSequence` (both via `forceFill()`); no route or Livewire property exposes it.

#### Messages

`lang/{en,es}/invoice-settings.php`, group `numbering`: `start_already_issued`, `start_invalid` (both on key `start_at`), `clock_skew` (key `invoice`), `overflow` (key `number`). The en/es parity test covers them.

_Last updated: 2026-11-17 — Story 0101a: part created._
