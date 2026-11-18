# [0108] Regenerate invoices after an order edit (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0101b](0101b-issue-standard-invoice-backend.md) (the out-of-date column, `AssertInvoicesAreCurrent`,
> `CreateStandardInvoice`), [0106](0106-invoice-collection-order-debt-partially-paid-backend.md)
> (`SyncOrderCollection`, `AfterOrderTotalsChanged`) and
> [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md) (`VoidLiveInvoices`).
> Closes the stale-totals limitation of
> [0105](0105-automatic-invoice-on-order-creation-backend.md). Dependents:
> [0109a](0109a-orders-list-invoice-send-ui.md) (the describer reads the out-of-date state) and
> [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md) (banner, regenerate button and its preview dialog).

## Description
Editing an invoiced order marks it "invoice out of date" instead of issuing anything per change.
While marked, money actions on its invoices are blocked. The manager presses "Regenerate invoice":
every live invoice is voided by a corrective and new invoices are issued for the real total,
keeping what was already collected. An edit that would leave the order below what the customer
already paid is refused. A read-only preview shows the manager exactly what regeneration will do.

Request item served (owner, 2026-11-14, translated):
> 4. Editing an order must produce a corrective invoice that voids the previous one, plus a new
> invoice with the real content.

Binding owner decisions:
- U-4 (owner decision, 2026-11-14): "Editing an invoiced order marks it 'invoice out of date'. The
  manager presses 'Regenerate invoice': one corrective invoice per live invoice, then one new
  invoice for the real total. Nothing is issued per line change."
- U-10 (owner decision, 2026-11-15): "Regeneration keeps what was collected. Example: order 1000
  with invoices 600 (collected) and 400 (not), edited down to 800: both are corrected, then two
  new invoices are issued, 600 marked collected and 200 uncollected. An edit that would leave the
  order total below the collected amount is refused: the manager records a refund first.
  Collection stays a whole-invoice fact (no partial amount per invoice)."
- U-12 / 0107 (owner decision, 2026-11-15, as settled in 0107): cancelling is allowed while an order
  is out of date; the cancellation voids every live invoice and clears the mark.

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- `AddOrderItem`, `RemoveOrderItem`, `UpdateOrderItemQuantity` lock the order, run their guards,
  move stock through `AdjustOrderStock` before locking lines, then call `RecalculateOrderTotals`
  (which after 0106 calls `AfterOrderTotalsChanged(Order $lockedOrder)`). Their refusals are
  validation errors on `items`.
- `ResolveOrderTaxRegion` and `ResolveVirtualOrderSalesRegion` write `tax_rate`, `tax_amount` and
  `total` in their own transaction, without locking the order and without the totals
  recalculation; they have no caller today. Orders carry `flagged_for_review` and a free-string
  `flag_reason` whose label comes from `orders.flag_reasons.*`.
- `orders.invoice_out_of_date_at`, `Order::isInvoiceOutOfDate()` and
  `AssertInvoicesAreCurrent(Order $lockedOrder, string $errorKey = 'invoice')` exist from 0101b;
  issuing (0101b), sending (0104), collecting and undoing (0106), Mark as paid (0106a) and refunds
  (0107) call the guard; `CancelOrder` (0107) does not and clears the mark.
- 0107's `VoidLiveInvoices` issues one full-remaining corrective per live invoice, copying the
  corrected invoice's identity and look, mirroring itemised lines; 0101b's `CreateStandardInvoice`
  accepts an optional collection record.

## Decisions
- **FD-1 Setting the mark.** `MarkInvoicesOutOfDate(Order $lockedOrder)` sets
  `invoice_out_of_date_at = now()` only when it is null and the order has a live standard invoice,
  and never clears it. `AfterOrderTotalsChanged` widens to `(Order $lockedOrder, bool
  $invoicedContentChanged = true)`: it runs `SyncOrderCollection`, then the mark when the flag is
  true. Line edits always pass true (the invoiced lines differ even if the total does not); the
  two resolvers pass true only when `tax_amount` or `total` changed. Refunds, status transitions,
  customer edits, payment edits and collections never set it. A cancelled order or an order without
  a live standard invoice is never marked. Later edits keep the original timestamp.
- **FD-2 Edit guard (U-10).** `AssertOrderEditKeepsCollectedCovered(Order $lockedOrder, string
  $lineTotalDelta)` runs in each line action right after the order lock and its existing guards,
  **before** any stock movement: for a non-cancelled order with invoices it computes the prospective
  subtotal (old + delta), tax (`CalculateTaxAmount` on it), total, and refuses with a validation
  error on `items` (`orders.edit.below_collected`, naming the collected amount) when
  `total' - refunded_amount < collected_amount`. Equality is accepted. Deltas: add = new line
  total; remove = minus the line total; quantity change = new minus old line total. The existing
  guards' messages win when they also apply. Orders without invoices, with only uncollected
  invoices, or cancelled are never refused by it.
- **FD-3 Resolvers (QA G-1).** Both resolvers now lock the order, recompute from the locked row,
  and run the same prospective check: if the new totals would leave billable below collected on an
  invoiced order, they write no new tax or total and flag the order for review with the reason
  `tax_total_below_collected` (new `orders.flag_reasons.*` key, no migration); otherwise they write
  and call `AfterOrderTotalsChanged`. They never throw because of collected amounts. So no code path
  leaves collected above billable; an architecture test lists every writer of `orders.total` and
  fails unless it is the totals recalculation or a resolver using the check and the hook.
- **FD-4 Blocked while out of date (BR-0108-3 as amended).** Blocked: issue (manual and "Issue and
  send"), send (single and order-level), collect, undo, Mark as paid, refund. Allowed: view,
  download, further line edits (the mark stays), status transitions, customer edits, cancellation
  (voids and clears, 0107) and regeneration. This story owns the enumeration test; each row names
  the owning story (issue 0101b, send 0104, collect/undo 0106, Mark as paid 0106a, refund and
  cancel 0107) and the test fails if an action lacks its guard call.
- **FD-5 Shared plan.** `PlanRegeneration` is pure and computes, from the order and its live
  invoices: live invoice count, the total being voided, C (net collected), B (billable), the new
  collected amount (C) and the new uncollected amount (B - C), whether `invoices.collect` is needed
  (C > 0), and a blocked reason key (not out of date, collected above billable, issuer data
  incomplete). `RegenerateOrderInvoices` and `PreviewRegeneration` both use it, so the dialog can
  never show other arithmetic than the action performs.
- **FD-6 Authorization (QA G-2, G-6).** Regeneration requires `invoices.create`, and also
  `invoices.collect` when C > 0 (the carried-over invoice is a collection recorded under the
  regenerator's name). A plain pre-read of `collected_amount` decides whether to authorize `collect`
  before the transaction; inside the transaction a race that made C > 0 is re-checked. Refusals
  are logged with `target_type = order` and the order id. Preview requires `invoices.view`
  (logged `authorize('view', Invoice::class)`).
- **FD-7 Regenerate, step by step.** Authorize (FD-6) -> transaction: order `FOR UPDATE` -> refuse
  unless out of date (`invoices.errors.not_out_of_date`) -> plan -> refuse when C > B
  (`invoices.errors.collected_exceeds_billable`, "raise the order total or ask an administrator to
  correct it"; reachable only on corrupted data after FD-3) -> refuse when the current issuer data
  is incomplete (`invoices.errors.issuer_incomplete`; the new invoices carry the current issuer) -> live invoices `FOR UPDATE` by id ->
  settings `FOR SHARE` -> both sequence rows `FOR UPDATE` (corrective first) ->
  `VoidLiveInvoices(order_edited, onlyUncollected: false, actor)`: one corrective per live invoice
  for its full remaining, in corrected-invoice number order; a corrective of a collected invoice is
  collected by derivation -> if C > 0: one new standard invoice of C, **born collected**
  (carried over, FD-8) -> if B - C > 0: one new standard invoice of B - C, uncollected -> clear the
  mark -> `SyncOrderCollection` (status re-derived: `Paid` when B = C, else `PartiallyPaid`, refund
  states win) -> commit. B = 0.00 issues only correctives. Every number is consumed in this one
  transaction, so any failure un-consumes all of them.
- **FD-8 Carried-over record (QA G-5).** Copies, from the voided collected invoice with the latest
  `collected_at` (tie: highest invoice number), the collection date, payment method and payment
  type (nulls copied as nulls); `collected_by` = the regenerating user;
  `collection_carried_over = true`. Several collected invoices consolidate into this one invoice;
  their individual histories stay on the immutable voided invoices.
- **FD-9 Lines and look.** A new invoice is itemised only when it alone covers B and the order has
  no refund; otherwise a concept line (BR-0101-7), with shares summing exactly. Correctives carry
  the look of the invoice they correct; the new invoices use the current settings, so a
  regenerated set can mix looks.
- **FD-10 Accepted consequences.** An edit that restores the original lines followed by a
  regeneration still voids and reissues, consuming numbers visibly with reason "order edited" (not
  detected; QA G-7). A legacy paid order without invoices can be edited with nothing to regenerate;
  its collected amount follows its billable amount (QA G-4).

## Class contracts
- `App\Actions\Invoices\RegenerateOrderInvoices::__invoke(Order $order): Order`.
- `App\Actions\Invoices\PlanRegeneration::__invoke(Order $order): array{liveInvoiceCount: int,
  voidedTotal: string, collected: string, billable: string, newCollectedAmount: string,
  newUncollectedAmount: string, requiresCollect: bool, blockedReasonKey: ?string}` (pure).
- `App\Actions\Invoices\PreviewRegeneration::__invoke(Order $order): array{liveInvoiceCount: int,
  voidedTotal: string, collected: string, billable: string, newCollectedAmount: string,
  newUncollectedAmount: string, requiresCollect: bool, blockedReasonKey: ?string}` (read-only,
  logged `authorize('view', Invoice::class)`; used by the 0109c dialog).
- `App\Actions\Invoices\MarkInvoicesOutOfDate::__invoke(Order $lockedOrder): void`.
- `App\Actions\Invoices\AssertOrderEditKeepsCollectedCovered::__invoke(Order $lockedOrder, string
  $lineTotalDelta): void`.
- `App\Actions\Invoices\AfterOrderTotalsChanged::__invoke(Order $lockedOrder, bool
  $invoicedContentChanged = true): void` (widened).

## Gherkin
```gherkin
Feature: Regenerate invoices after an order edit

  Scenario: Editing an invoiced order marks it out of date
    Given a store manager with permission to edit orders, and an order with a live invoice
    When she changes the quantity of one of its lines
    Then the order is marked "invoice out of date"
    And no invoice is issued

  Scenario: Regeneration keeps what was collected (owner's worked example)
    Given a store manager with permission to create and collect invoices, and an order of
      1000.00 EUR with a collected 600.00 EUR invoice and an uncollected 400.00 EUR invoice,
      edited down to 800.00 EUR and marked out of date
    When she regenerates the invoices
    Then corrective invoices of -600.00 EUR and -400.00 EUR void the two invoices
    And a new collected invoice of 600.00 EUR and a new uncollected invoice of 200.00 EUR are issued
    And the order owes 200.00 EUR and is no longer out of date

  Scenario: An edit below the collected amount is refused
    Given a store manager with permission to edit orders, and an invoiced order of 1000.00 EUR with 600.00 EUR collected
    When she removes lines bringing the total to 500.00 EUR
    Then the edit is refused with a message to record a refund first
    And no stock is moved

  Scenario: Money actions wait for regeneration
    Given a store manager with permission to collect invoices, and an order marked out of date
    When she tries to mark one of its invoices as collected
    Then it is refused until the invoices are regenerated

  Scenario: Regenerating with a carried-over collection needs the collect permission
    Given a store manager with permission to create but not to collect invoices, and an
      out-of-date order with 600.00 EUR collected
    When she regenerates the invoices
    Then it is refused
    And the attempt is logged

  Scenario: The preview shows what regeneration will do
    Given a store manager with permission to view invoices, and the out-of-date order of the worked example
    When she opens the regeneration preview
    Then it shows 2 invoices to void for 1000.00 EUR, 600.00 EUR to carry as collected and 200.00 EUR to issue uncollected

  Scenario: A tax resolution that would undercut the collected amount flags the order
    Given a store manager whose invoiced order has 600.00 EUR collected
    When its tax region is resolved to totals below 600.00 EUR
    Then the order's totals are left unchanged
    And the order is flagged for review
```

## Files to create/modify
- `app/Actions/Invoices/{RegenerateOrderInvoices, PlanRegeneration, PreviewRegeneration,
  MarkInvoicesOutOfDate, AssertOrderEditKeepsCollectedCovered}.php`.
- `app/Actions/Invoices/AfterOrderTotalsChanged.php` (0106) — widened signature.
- `app/Actions/Orders/{AddOrderItem, RemoveOrderItem, UpdateOrderItemQuantity}.php` — the guard
  call before stock moves.
- `app/Actions/Orders/{ResolveOrderTaxRegion, ResolveVirtualOrderSalesRegion}.php` — lock,
  recompute, check, flag or write and hook.
- `lang/{en,es}/orders.php` — `edit.below_collected` (`:collected`),
  `flag_reasons.tax_total_below_collected`; `lang/{en,es}/invoices.php` —
  `errors.not_out_of_date`, `errors.collected_exceeds_billable`.
- Tests under `tests/Feature/Invoices/Regeneration/`. No migration, route, permission, seeder or
  config change.

## Tests to perform
Real actions and database; second connection for concurrency; money as strings.

Happy path
- [ ] T-1 **Worked example** (1000.00 = F1 600.00 collected + F2 400.00 uncollected; lines edited to
  800.00): correctives -600.00 (collected by derivation) and -400.00 (uncollected), reason
  `order_edited`, both corrected invoices voided; new 600.00 born collected (carried over,
  `collected_by` the regenerator, F1's date, method and type) and new 200.00 uncollected; collected
  600.00, billable 800.00, debt 200.00, `PartiallyPaid`, mark cleared.
- [ ] T-2 No collected invoice: one corrective per live invoice, one new uncollected invoice for B
  (itemised with the current lines when no refunds), debt B.
- [ ] T-3 Collected 300 (Card, 1 Jan), 200 (Transfer, 5 Jan), 100 (Paypal, 9 Jan) plus 100
  uncollected: one carried-over invoice of 600.00 with 9 Jan and Paypal, plus one for B - C.
- [ ] T-4 Total raised 1000 -> 1200 with 600 collected: 600 collected + 600 uncollected, debt 600;
  invariants hold and net invoiced = B exactly.
- [ ] T-5 B = C = 600.00: only the carried-over invoice; `Paid`, debt 0.00.
- [ ] T-6 B = 0.00: only correctives; mark cleared; figures 0.00.
- [ ] T-7 Refunds present: correctives for each remaining; C after refund correctives; concept
  lines; `PartiallyRefunded` kept; earlier refund correctives untouched.
- [ ] T-8 Each line action (and several in a row, and one leaving the total unchanged) sets the
  mark at the first edit and keeps its original timestamp.
- [ ] T-9 `invoices.create` alone regenerates an order with nothing collected; Super Admin allowed;
  without `invoices.create` refused.
- [ ] T-9b Preview equals the plan the action executes for every dataset row (same numbers,
  `requiresCollect` true when C > 0); preview writes nothing and needs `invoices.view`.
- [ ] T-10 Resolvers on an invoiced order changing tax or total set the mark; no change, no live
  invoice or cancelled: no mark; they never throw because of collected amounts.

Edge
- [ ] T-11 Guard dataset on the three line actions (collected 600.00 on 1000.00; tax null and 21;
  with and without refunds): resulting billable 600.01 and 600.00 accepted, 599.99 refused on
  `items`; no stock moved on refusal.
- [ ] T-12 Tax rounding at the boundary: the guard uses the recomputed total, consistent with what
  the recalculation would persist.
- [ ] T-13 No invoice, only uncollected invoices, legacy paid, cancelled: never refused by the guard.
- [ ] T-14 Already out of date: an edit below collected is still refused; raising back is allowed;
  only regeneration clears the mark.
- [ ] T-15 Out-of-date matrix (one dataset, owning story per row): blocked = issue manually,
  issue-and-send, send single, send order-level, collect, undo, Mark as paid, refund; allowed =
  view, download, line edits, status transitions, customer edit, cancel (clears the mark),
  regenerate. Fails if any blocked action lacks its guard.
- [ ] T-16 Numbers: one corrective per live invoice plus up to two standard numbers, consecutive in
  their series (correctives by corrected number, then carried-over, then remainder), `issued_at`
  monotonic.
- [ ] T-17 Failure at each step (after the first corrective, after the last, carried-over,
  remainder, clearing the mark, sync, number collision, overflow): everything rolls back, both
  counters unchanged, mark still set.
- [ ] T-18 Double click (second connection holds `orders FOR UPDATE`): 1205, then refused
  `not_out_of_date`, nothing consumed.
- [ ] T-19 Edit, refund, collect and cancel racing regenerate: serialised; an edit after regenerate
  marks again against the new invoices; C is read at the lock, never stale.
- [ ] T-20 Lock order: orders, live invoices by id, settings `FOR SHARE`, both sequences (corrective
  first), then inserts.
- [ ] T-21 Two new invoices with concept lines: shares sum exactly on 6 cent-edge totals.
- [ ] T-22 Correctives carry the corrected invoice's identity and look; new invoices the current
  issuer, template and colours.
- [ ] T-23 Collected invoices with null method or type: copied as nulls; carried-over invoice obeys
  0106's undo limits.
- [ ] T-23b Resolver whose totals would drop billable below collected: totals unchanged, order
  flagged `tax_total_below_collected`, no mark, no exception; architecture test over every writer
  of `orders.total`.

Negative
- [ ] T-24 Not out of date (never edited, already regenerated, no live invoice, cancelled): refused,
  nothing consumed.
- [ ] T-25 Without `invoices.create` (none, view, send, collect, `orders.edit`): refused before any
  state read; one warning with `target_type = order`; unauthenticated refused and logged.
- [ ] T-25b `invoices.create` without `invoices.collect` on an order with C > 0: refused before the
  transaction; with C = 0 allowed; a race making C > 0 is refused inside and rolls back.
- [ ] T-26 Forced C > B (corrupted data): refused with the "raise the total or ask an
  administrator" message; nothing changes.
- [ ] T-27 Incomplete current issuer data: refused atomically, mark kept.
- [ ] T-28 A refused edit: the existing guards' messages win over `below_collected` (order pinned).
- [ ] T-29 Refunds, status transitions, customer, payment and collection changes never set the mark.
- [ ] T-30 Only voided invoices or only correctives: no mark; one live plus a voided: mark set.
- [ ] T-31 Signature takes only the order (reflection); the order is re-read server-side.
- [ ] T-32 The carried-over invoice behaves as any collected invoice afterwards (refundable, undo
  refused once corrected).

### Existing tests to change (owner approval required in Phase 3)
Project rule: no test is deleted or weakened without the owner's approval. None is deleted; each is
edited in place for the new lock, guard and hook positions, and the implementer must obtain the
owner's explicit approval before applying the changes in Phase 3:
- `tests/Feature/Orders/{ResolveOrderTaxRegionTest, ResolveVirtualOrderSalesRegionTest,
  NothingFalselyResolvedTest, ShowTaxDisplayTest}.php` — the resolvers now lock the order,
  recompute from the locked row, may flag instead of writing, and call the hook; tests passing
  stale in-memory orders or counting queries change.
- `tests/Feature/Orders/{AddOrderItemTest, AddOrderItemStockTest, RemoveOrderItemTest,
  RemoveOrderItemStockTest, UpdateOrderItemQuantityTest, UpdateOrderItemQuantityStockTest}.php` —
  the extra guard call and statement positions in lock-order assertions;
  `RecordRefundLockOrderTest.php` and `ShowLineItemsTest.php` only where they pin SQL order or
  messages.
- The 0106 and 0107 tests of `RecalculateOrderTotals` and `AfterOrderTotalsChanged` (signature
  widened).

## Expected outcome
Editing an invoiced order never issues documents per change: the order is marked out of date, its
money actions wait, and one regeneration voids the old invoices and issues new ones for the real
total, keeping collected money as a collected invoice; no path can leave the customer having paid
more than the order is worth.

## Acceptance criteria
- [ ] The mark is set and kept per FD-1, by line edits and changed resolver totals only.
- [ ] Edits and resolvers that would leave billable below collected are refused or flagged
  (FD-2, FD-3), with no stock moved.
- [ ] The out-of-date matrix holds for every action (FD-4).
- [ ] Regeneration follows FD-7/FD-8 exactly, including the owner's worked example.
- [ ] Preview and action share `PlanRegeneration` (FD-5).
- [ ] Authorization follows FD-6, refusals logged with target `order`.
- [ ] The existing-test changes are applied only with the owner's recorded approval.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): the orders API doc (line-edit refusal, the mark, the
  resolver locking and flag), the invoices API doc (regeneration, preview), errors-log note on the
  resolvers' former unlocked writes
- [ ] Acceptance criteria met
- [ ] Owner approval for the existing-test changes recorded in this file
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Story 0101a note (I1): call `AllocateInvoiceNumber::lock()` and `__invoke()` at the **same `DB::transaction` nesting level**; lock both series in one `lock()` call. See [0101a](done/0101a-invoice-numbering-sequences-backend.md).
- Upstream: [0101b](0101b-issue-standard-invoice-backend.md),
  [0106](0106-invoice-collection-order-debt-partially-paid-backend.md),
  [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md). Downstream:
  [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md) (banner, button with the "needs invoices.collect"
  hint, preview dialog).
- Security: only the order id reaches the action; permissions are checked first and logged.
- A holder of refund or collect rights without `invoices.create` is stuck behind an out-of-date
  order; 0109b and 0109c must explain why the controls are disabled.
- A regeneration with many live invoices issues many correctives in one request (bounded by the
  order's invoice count).
- The resolver change touches actions with no caller today; tests build orders directly; a future
  checkout must call them on an existing order.
- Open technical: whether regeneration should also require `orders.edit` (not required by the
  rules; kept out).
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3; the existing-test approval above is required during Phase 3):
  - Q-0108-3 Carried-over collection record: (a) the date, method and type of the latest
    collection, collector = the regenerator (recommended: keeps when and how the money came in);
    (b) date = regeneration moment, method and type unknown.
  - Q-0108-4 Regeneration with a carried-over collection: (a) also requires `invoices.collect`
    (recommended: the new collected invoice is recorded under the regenerator's name); (b)
    `invoices.create` only.
  - Q-0108-5 A resolved tax that would undercut the collected amount: (a) totals unchanged and the
    order flagged for review (recommended: a system resolution must not fail and must not leave the
    customer overpaid); (b) apply the totals and let regeneration refuse until a refund.

## Technical tasks
- [ ] Worked-example test first.
- [ ] `MarkInvoicesOutOfDate`; widen `AfterOrderTotalsChanged`.
- [ ] `AssertOrderEditKeepsCollectedCovered` in the three line actions, with no-stock-moved tests.
- [ ] Resolvers: lock, recompute, check, flag or write, hook; writers architecture test.
- [ ] `PlanRegeneration`, `PreviewRegeneration`, `RegenerateOrderInvoices`.
- [ ] Out-of-date matrix, numbering, rollback and concurrency tests.
- [ ] Obtain the owner's approval, then amend the listed existing tests.
- [ ] Lang en/es; Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, backend-qa. Phase 2 (INVEST) pending.
