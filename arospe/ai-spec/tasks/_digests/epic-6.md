# Epic 6 decision digest (Invoicing)

Append-only. See [workflow.md#decision-digest-per-epic](../../../docs/workflow/agents-and-epic-digests.md#decision-digest-per-epic)
for what belongs here and what doesn't. Created by story 0101a's docs pass; story 0100 closed earlier and is not
backfilled here (read its `done/` file or `docs/database/schema-other/invoice-settings.md`).

## Story 0101a — Invoice numbering sequences (backend)

- FD-1: one `invoice_sequences` row per series (`standard`, `corrective`), no per-year buckets. The issue instant is converted to `config('app.timezone')` once (`AllocateInvoiceNumber::toAppTimezone()`, public) and that moment gives both the year and the printed number; the counter restarts at 1 only when reset is on, `last_year` is not null and the year grew; the reset flag is consulted only at a year change — story 0101a.
- FD-3: lock order inside an issuing transaction is `invoice_settings` FOR SHARE, then `invoice_sequences` `ORDER BY series FOR UPDATE` (corrective before standard), always the last locks after order/invoice locks — story 0101a.
- FD-4: `AllocateInvoiceNumber::__invoke(InvoiceType, CarbonInterface)` takes no settings; the settings read under the share lock in `lock(list<InvoiceType>, CarbonInterface)` decide and are returned to the caller to snapshot — story 0101a.
- FD-12: `PeekNextInvoiceNumber::__invoke(InvoiceType, ?array $candidate = null, ?CarbonInterface $at = null)` takes raw partial input, validates field rules only (no cross-field rules; Save is the gate) before any model exists; `attributeNames()` now lives in the `InvoiceSettingValidationRules` trait — story 0101a.
- I1: `lock()` and `__invoke()` must run at the same `DB::transaction` nesting level. The allocator caches locked rows per `DatabaseTransactionRecord` and discards them when the record object changes; no record or an unlocked series (while others are) is a `LogicException`. Matters to 0101b, 0107, 0108 — story 0101a.
- Error keys: `invoice` (`numbering.clock_skew`), `number` (`numbering.overflow`), `start_at` (`numbering.start_already_issued`, `numbering.start_invalid`) — story 0101a.
- D-1: no `InvoiceSequenceFactory`; `UNIQUE(series)` plus migration-created rows make `create()` fail, so fixtures use `Tests\Support\Invoices\Numbering::arrange()` (updates the existing row) — story 0101a.
- D-2: `last_issued_at` is `dateTime`, not `timestamp` (app-timezone wall clock, no session-timezone conversion, no 2038 limit); DST fall-back ambiguity accepted, it can only refuse — story 0101a.
- No new permission: preview reuses `invoice-settings.view`, starting counter reuses `invoice-settings.manage` — story 0101a.
