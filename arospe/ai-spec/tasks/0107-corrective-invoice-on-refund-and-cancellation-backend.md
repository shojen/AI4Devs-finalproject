# [0107] Corrective invoice on refund and on cancellation (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0101b](done/0101b-issue-standard-invoice-backend.md) (invoices, lines, `voided_at`, `reason`,
> `corrects_invoice_id`), [0101a](done/0101a-invoice-numbering-sequences-backend.md) (corrective
> series) and [0106](done/0106-invoice-collection-order-debt-partially-paid-backend.md)
> (`collected_amount`, `SyncOrderCollection`, `PartiallyPaid`). Dependents:
> [0108](0108-regenerate-invoices-after-order-edit-backend.md) (reuses `CreateCorrectiveInvoice`
> and `VoidLiveInvoices`), [0109a](0109a-orders-list-invoice-send-ui.md) (the describer reads the
> refund and cancel rules), [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md) (refund
> limit). Related: [0109b](0109b-order-detail-invoices-panel-ui.md) shows the cancel rules through
> 0109a's describer (not a direct dependency).

## Description
Refunds and cancellations now produce corrective invoices in their own numbering series, each
naming the invoice it corrects. A refund issues correctives against the order's collected
invoices, never more than what was collected; a refund that leaves the order fully refunded also
voids its uncollected invoices. Cancelling an order voids every live invoice with a corrective
and is refused while any invoice is collected. This story also opens refunds to "Partially paid"
orders and cancellation to "Partially refunded" orders with nothing collected.

Request item served (owner, 2026-11-14, translated):
> 3. A return or refund produces a corrective invoice ("factura rectificativa") with its own
> numbering, different from the invoice numbering, which states the invoice it belongs to.

Binding owner decisions:
- U-9 (owner decision, 2026-11-15): "Cancelling an order with live invoices issues one corrective
  invoice per live invoice. The cancellation is refused when any live invoice is collected: the
  manager records the refund first."
- U-11 (owner decision, 2026-11-15): "A 'Partially paid' order CAN be refunded, capped at its net
  collected amount."
- U-12 (owner decision, 2026-11-15): "A 'Partially refunded' order CAN be cancelled once nothing
  collected remains; that cancellation corrects its uncollected live invoices. A bounded exception
  to the existing 'never cancel while Partially refunded' rule." The PRD 3.2 criterion is amended
  by the docs sync.
- Accepted defaults (owner, 2026-11-15; overturnable, recorded below): correctives are allocated
  newest collected invoice first (Q-0107-1 (a)); a refund on an order without invoices gets no
  corrective (Q-0107-2 (a)); refund correctives are tax-exclusive (Q-0107-5 (a)).

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- `RecordRefund` (`orders.refund`) refunds units: each `refunds` row has `quantity` and `amount` =
  quantity x `unit_price` (tax-exclusive, merchandise only); it locks `orders` then `order_items`,
  derives `Refunded` / `PartiallyRefunded`, increments `orders.refunded_amount`, and after commit
  dispatches `OrderFullyRefunded` when the order is fully refunded; the listener runs
  `AutoCancelFullyRefundedOrder`, which bypasses `CancelOrder`. Its refusals are validation errors
  on `items` (`orders.refunds.*`).
- `CancelOrder(Order, bool $adjustStock = false)` authorizes `cancel` (`orders.edit` and
  `orders.refund`), locks the order, refuses `Cancelled` and blocked states (Shipped, Delivered,
  PartiallyRefunded), optionally returns stock through `AdjustOrderStock` before locking the
  lines, then writes `Cancelled`.
- `Order::isRefundable()` = `Paid` or `PartiallyRefunded`; `isManuallyCancellable()` blocks
  Shipped, Delivered and PartiallyRefunded.
- 0101b: `invoices` with `voided_at`, `reason` (`InvoiceCorrectionReason`), `corrects_invoice_id`,
  look and identity snapshots, signed money, only `UNIQUE(number)`; `invoice_lines` with `kind`,
  `order_item_id`. 0106: correctives are collected exactly when the invoice they correct is;
  `SyncOrderCollection` recomputes `collected_amount` and the payment status (refund states win).

## Column added
`invoice_lines.refund_id` char(36) null, FK `refunds.id` `restrictOnDelete()`, after
`order_item_id`, non-unique FK index (one refund row can be settled on two invoices). Null on
voiding lines. `down()`: drop the FK, then the column. Nothing else in the schema changes
(`reason` and `voided_at` exist since 0101b).

## Decisions
- **FD-1 Collaborators.** `CreateCorrectiveInvoice` (one corrective against one locked live
  standard invoice, amount <= its remaining), `IssueRefundCorrectives` (refund allocation),
  `VoidLiveInvoices` (one full-remaining corrective per live invoice), `AllocateRefundToInvoices`
  (pure). All transaction-bound and ungated; 0108 reuses `VoidLiveInvoices` unchanged.
- **FD-2 Identity and look copied (QA G-3).** A corrective copies the issuer and recipient
  snapshots and the look (template, colours, logo) of the invoice it corrects; only its number
  comes from the current corrective format (0101a). Signed negative money, `corrects_invoice_id`,
  `reason`. It never checks issuer completeness, so a refund or cancellation is never blocked by
  settings.
- **FD-3 Voiding.** `voided_at` is set on the corrected invoice whenever its remaining reaches
  0.00, by any corrective.
- **FD-4 Refund correctives are tax-exclusive (C-15, QA G-1).** A refund corrective has base =
  minus the refund, tax 0.00, shipping 0.00, matching today's merchandise-only `refunds.amount` and
  `billable = total - refunded_amount`. **Consequence, stated plainly: a refund corrective carries
  no tax amount today**; on a taxed order the tax of refunded goods stays invoiced and collected.
  Making refunds tax-inclusive would redefine `refunds.amount`, `refunded_amount`, billable, the
  dashboard and the refund screen: a separate story (Q-0107-5).
- **FD-5 Refund lines.** If the corrected invoice is itemised and one corrective takes the whole
  refund, its lines are the refunded items with negative quantity, positive unit price and
  `refund_id`. Otherwise (concept-line invoice, or a spill over two invoices) one `adjustment`
  line per refund-row slice: quantity -1, unit price = slice, `refund_id`, description "Refund on
  order N".
- **FD-6 Allocation (U-11).** Candidates are the order's **collected** live standard invoices,
  newest first (`issued_at desc, id desc`); each corrective takes at most the invoice's remaining
  and spills to the next older one; one corrective per corrected invoice; the sum equals the
  refund exactly. Uncollected live invoices are never touched by a refund (they keep their
  amounts, debt and uninvoiced). Refund correctives are collected by derivation, so collected
  falls by the refund and billable by the same amount: debt is unchanged.
- **FD-7 Refund cap from one place.** `Order::maxRefundableAmount(): string` = the lower of the
  order's `collected_amount` and the money value of its still-refundable units (sum over lines of
  outstanding units x unit price). `RecordRefund` computes it on the locked rows through the same
  method and refuses a refund total above it with a validation error on the key **`items`**,
  message `orders.refunds.exceeds_collected` ("Refunds are limited to the amount collected so far
  (maximum :max)", `:max` formatted from the string per locale). A screen that shows the maximum
  calls the same method, so what it shows is exactly what the action enforces. The existing
  per-line unit refusal keeps its message and runs first. On a partly paid order not every unit
  may be refundable until more is collected; that is intended. Legacy orders are covered by the
  same rule (their collected amount equals billable).
- **FD-8 Predicates (moved from 0106).** `isRefundable()` = `Paid`, `PartiallyRefunded` or
  `PartiallyPaid` (U-11). `isManuallyCancellable()` keeps the Shipped/Delivered block and lifts
  the `PartiallyRefunded` block when `collected_amount` is 0.00 (U-12); still false for
  `Refunded` and `Cancelled`.
- **FD-9 Refund sequence.** Authorization and payload validation unchanged -> transaction: order
  `FOR UPDATE` -> order items `FOR UPDATE` -> existing guards (now admitting `PartiallyPaid`) ->
  out-of-date refusal (`AssertInvoicesAreCurrent` with the error key `items`, so the refund modal
  shows it) -> unit cap -> money cap (FD-7) -> refund rows,
  line counters, `refunded_amount` as today -> if the order has live invoices: live invoices
  `FOR UPDATE` (by id) -> settings `FOR SHARE` -> corrective sequence `FOR UPDATE` ->
  `IssueRefundCorrectives` -> if the order is now `Refunded`: `VoidLiveInvoices(order_cancelled,
  onlyUncollected: true)` in the same transaction -> existing status write -> `SyncOrderCollection`
  -> commit -> `OrderFullyRefunded` after commit as today. A failure anywhere (including a number
  collision, which shows the "change the prefix" message) rolls the whole refund back.
  `AutoCancelFullyRefundedOrder` and its listener are unchanged and issue nothing. The refund log
  line now includes the corrective numbers.
- **FD-10 Cancel sequence (U-9, U-12).** Authorization unchanged -> transaction: order
  `FOR UPDATE` -> existing guards (with the FD-8 predicate) -> if any live standard invoice is
  collected: validation error on `status` with `orders.cancellation.collected_invoices` = "This
  order has collected invoices. Record a refund of the collected amount, or undo the collection if
  it was recorded by mistake." — before any stock movement, nothing changes -> stock return when
  opted in (existing order) -> order items lock and re-verification (existing) -> live invoices
  `FOR UPDATE` -> settings `FOR SHARE` -> corrective sequence `FOR UPDATE` ->
  `VoidLiveInvoices(order_cancelled, onlyUncollected: false)` (all uncollected, so born
  uncollected; itemised invoices mirrored line by line, concept-line invoices one line) -> clear
  `invoice_out_of_date_at` -> `SyncOrderCollection` -> status `Cancelled`. Afterwards billable,
  net invoiced, collected, debt and uninvoiced are 0.00; the payment status stays `PendingPayment`,
  or `PartiallyRefunded` for U-12. Orders without invoices behave exactly as today.
- **FD-11 Cancelling an out-of-date order is allowed (QA G-2).** Voiding every live invoice does
  not depend on the edited content, so `CancelOrder` does not require current invoices and clears
  the mark in the same transaction; regeneration first is not needed. This amends the 0108 list of
  actions blocked while out of date (cancellation removed).
- **FD-12 Actor (QA G-7).** `issued_by` of every corrective issued inside `RecordRefund` or
  `CancelOrder`, including the full-refund voiding, is the acting user.
- **FD-13 Consequences stated.** A `Paid` order with invoices can no longer be cancelled manually
  (refund first); a legacy `Paid` order without invoices still can. A fully refunded order keeps
  the shipping/tax share of its collected invoices billed and collected and is auto-cancelled with
  debt 0.00. With tax-exclusive refunds, the U-12 exception (collected 0.00) is reachable mainly on
  orders without tax or shipping; nobody should expect the Cancel button on a taxed order whose
  merchandise was refunded.
- **FD-15 Error keys (screen contract).** `RecordRefund` raises the out-of-date refusal and the
  money cap on `items` (`orders.refunds.exceeds_collected` with `:max`); `CancelOrder` raises the
  collected-invoice refusal on `status` and does not call `AssertInvoicesAreCurrent` (FD-11). The
  order screens describe these states through `DescribeOrderInvoicing` (owned by
  [0109a](0109a-orders-list-invoice-send-ui.md)), whose parity test checks it against these actions.
- **FD-14 No new permission.** The refund's and the cancellation's own gates cover the
  correctives (`orders.refund` without any `invoices.*` permission still refunds).

## Class contracts
- `App\Actions\Invoices\CreateCorrectiveInvoice::__invoke(Invoice $lockedCorrected, string
  $amount, InvoiceCorrectionReason $reason, ?User $actor, ?array $lines = null): Invoice`.
- `App\Actions\Invoices\IssueRefundCorrectives::__invoke(Order $lockedOrder, list<Refund>
  $refunds, ?User $actor): list<Invoice>`.
- `App\Actions\Invoices\VoidLiveInvoices::__invoke(Order $lockedOrder, InvoiceCorrectionReason
  $reason, bool $onlyUncollected, ?User $actor): list<Invoice>`.
- `App\Actions\Invoices\AllocateRefundToInvoices::__invoke(list<array{invoice_id: string,
  remaining: string}> $collectedNewestFirst, list<array{refund_id: string, amount: string}>
  $rows): list<array{invoice_id: string, refund_id: string, amount: string}>` (pure).
- `App\Models\Order`: `maxRefundableAmount(): string`, `isRefundable()`, `isManuallyCancellable()`.
- `App\Models\InvoiceLine::refund(): BelongsTo`.

## Gherkin
```gherkin
Feature: Corrective invoices on refund and cancellation

  Scenario: A refund corrects the newest collected invoice
    Given a store manager with permission to refund, and an order of 1000.00 EUR with invoices of
      600.00 and 400.00 EUR both collected
    When she refunds 200.00 EUR of items
    Then a corrective invoice of -200.00 EUR in the corrective series names the 400.00 EUR invoice
    And the order's debt stays 0.00 EUR

  Scenario: A refund of a partially paid order corrects only collected invoices
    Given a store manager with permission to refund, and an order of 1000.00 EUR with a collected
      600.00 EUR invoice and an uncollected 400.00 EUR invoice
    When she refunds 200.00 EUR of items
    Then a corrective invoice of -200.00 EUR names the 600.00 EUR invoice
    And the 400.00 EUR invoice is untouched
    And the order still owes 400.00 EUR

  Scenario: A refund above what was collected is refused
    Given a store manager with permission to refund, and a partially paid order with 600.00 EUR collected
    When she tries to refund 700.00 EUR of items
    Then it is refused naming 600.00 EUR as the maximum
    And nothing is recorded

  Scenario: A refund corrective carries no tax
    Given a store manager with permission to refund, and an invoiced and collected order taxed at 21 %
    When she refunds 100.00 EUR of items
    Then the corrective invoice shows a base of -100.00 EUR and no tax

  Scenario: A full refund voids the uncollected invoices
    Given a store manager with permission to refund, and an order with a collected invoice and an uncollected invoice
    When she refunds every line of the order
    Then the uncollected invoice is voided by a corrective in the same operation
    And the order is cancelled automatically

  Scenario: Cancelling voids every live invoice
    Given a store manager with permission to cancel orders, and a pending order with two uncollected invoices
    When she cancels it
    Then each invoice is voided by its own corrective invoice
    And the order owes nothing

  Scenario: An order with a collected invoice cannot be cancelled
    Given a store manager with permission to cancel orders, and an order with a collected invoice
    When she tries to cancel it
    Then she is told to record a refund of the collected amount or undo the collection
    And the order, its stock and its invoices stay as they were

  Scenario: A partially refunded order with nothing collected can be cancelled
    Given a store manager with permission to cancel orders, and a "Partially refunded" order whose
      collected amount was fully refunded and that has an uncollected invoice
    When she cancels it
    Then the uncollected invoice is voided by a corrective invoice
    And the order is cancelled

  Scenario: An out-of-date order can be cancelled
    Given a store manager with permission to cancel orders, and an invoiced order edited afterwards and marked out of date
    When she cancels it
    Then its live invoices are voided and the out-of-date mark is cleared
```

## Files to create/modify
- `database/migrations/<ts>_add_refund_id_to_invoice_lines_table.php`.
- `app/Actions/Invoices/{CreateCorrectiveInvoice, IssueRefundCorrectives, VoidLiveInvoices,
  AllocateRefundToInvoices}.php`.
- `app/Actions/Orders/RecordRefund.php`, `CancelOrder.php` — FD-9, FD-10.
- `app/Models/Order.php` — predicates and `maxRefundableAmount()`; `app/Models/InvoiceLine.php` —
  `refund()`.
- `database/factories/{InvoiceLineFactory, InvoiceFactory}.php` — corrective state completed.
- `lang/{en,es}/orders.php` — `refunds.exceeds_collected` (`:max`),
  `cancellation.collected_invoices`; `lang/{en,es}/invoices.php` — corrective line descriptions.
- `AutoCancelFullyRefundedOrder` and its listener: unchanged (pinned).
- New tests below. No permission, route, seeder or config change.

## Tests to perform
Real `RecordRefund`, `CancelOrder`, `AutoCancelFullyRefundedOrder` and `OrderFullyRefunded` end
to end (no event fake on the full-refund path). Orders built with tax rate null and 21, shipping,
invoices through `IssueInvoice`, collections through `ApplyInvoiceCollection`. Money as strings.

Happy path
- [ ] T-1 1000 = 600 + 400 both collected, refund 200: one -200 corrective on the newer invoice,
  corrective series, reason `refund`, `issued_by` the actor, collected by derivation; billable
  800, collected 800, debt 0.00, `PartiallyRefunded`.
- [ ] T-2 Worked example 600 collected + 400 uncollected, refund 200: corrective on the 600 only;
  billable 800, collected 400, debt 400, uninvoiced 0.00, `PartiallyRefunded`.
- [ ] T-3 Allocation dataset (invoices 300/300/400; refunds 100, 300, 350, 700, 1000, a row split
  across two invoices, 0.01, prior correctives): each slice <= remaining, newest first, exact sum,
  no 0.00 corrective. Unit and end-to-end.
- [ ] T-4 Itemised invoice taking the whole refund: refunded items with negative quantity and
  `refund_id`; concept-line invoice or spill: one adjustment line per slice.
- [ ] T-5 Tax 21 and null (dataset): refund 100 -> base -100.00, tax 0.00, shipping 0.00; the
  corrected remaining falls by 100.00 only; a test named after C-15 pins it.
- [ ] T-6 Consecutive correctives numbered `R2026-00001`, `-00002`; standard counter unaffected.
- [ ] T-7 Legacy order refund: exactly as today, no corrective, no number; the cap applies.
- [ ] T-8 Cancellation per state (pending with two uncollected invoices; pending without invoice;
  legacy paid without invoice; U-12 order): cancelled; one full-remaining corrective per live
  invoice (base, tax and shipping exact; itemised mirrored), reason `order_cancelled`, uncollected,
  `voided_at` set; all figures 0.00; payment status unchanged; no-invoice cases as today.
- [ ] T-9 Full refund with an 800 collected and a 200 uncollected invoice: the 200 voided inside
  the refund transaction; collected invoice keeps its shipping/tax remainder; `OrderFullyRefunded`
  once; the real listener cancels without issuing anything.
- [ ] T-10 U-12: allowed only with collected 0.00 and not Shipped/Delivered; collected > 0.00
  refused with today's message.
- [ ] T-11 Refund log line includes the corrective numbers.

Edge
- [ ] T-12 Cap dataset over each refundable status (paid, partially refunded, partially paid,
  legacy paid, legacy partially refunded): accepted at the maximum, refused 0.01 above with the
  error on `items` and the maximum formatted per locale; nothing written; a second refund is capped
  by the remaining collected.
- [ ] T-12b `Order::maxRefundableAmount()` equals the cap the action enforces for every dataset
  row (the same method on the locked rows).
- [ ] T-13 Unit cap and money cap both violated: the unit message wins (pinned), one exception.
- [ ] T-14 Forced failures (number collision, counter overflow, failure after the first of two
  correctives, in `VoidLiveInvoices`, in `SyncOrderCollection`): the whole refund rolls back, no
  event dispatched, corrective counter unchanged, validation message shown. Highest risk.
- [ ] T-15 Cleared legal name, changed fiscal address, changed look after issue: refund and cancel
  not blocked; the corrective copies identity and look from the corrected invoice.
- [ ] T-16 `voided_at`: refund of the full remaining voids; 0.01 left does not; two correctives
  summing to the total void on the second; a voided invoice is never a target again.
- [ ] T-17 Mixed collected flags and voided invoices: only collected live invoices are candidates.
- [ ] T-18 Refund above the newest invoice's remaining: two correctives, two numbers.
- [ ] T-19 Two uncollected and one voided invoice cancelled: two correctives in invoice order;
  all voided -> nothing issued.
- [ ] T-20 Cancel with stock return when a corrective fails: stock, status and invoices roll back;
  lock order `orders`, products, variants, stock, `order_items`, invoices, settings, sequence.
- [ ] T-21 Refund lock order: `orders`, `order_items`, invoices, settings, corrective sequence;
  nothing locked after the sequence row.
- [ ] T-22 Refund racing collect, undo, a second refund, cancel, line edit, regenerate (second
  connection): serialised; never over-refunds, never two correctives for one slice.
- [ ] T-23 Auto-cancel with a collected remainder is not blocked by U-9; the listener's behaviour is
  unchanged.
- [ ] T-24 Invariant loop over 30 seeded orders: 0 <= collected <= net invoiced <= billable,
  uninvoiced <= debt, after every refund or cancel.
- [ ] T-25 U-12 cancel: `Cancelled`, payment status `PartiallyRefunded`, uncollected invoices
  voided, Shipped/Delivered still blocked.
- [ ] T-25b Out-of-date order cancelled: invoices voided and the mark cleared (FD-11).

Negative
- [ ] T-26 Any collected live invoice (paid; partially paid; only a carried-over legacy invoice
  collected): refused on `status` with exactly the owner's text in en and es; nothing moved,
  stock included.
- [ ] T-27 Without the cancel permission: existing refusal first; with `orders.refund` but no
  `invoices.*` a refund issues its correctives.
- [ ] T-28 Without `orders.refund`: refused first, logged as today.
- [ ] T-29 Client-supplied amount, invoice id, reason or actor in the payload: ignored; foreign
  items still refused.
- [ ] T-30 Out-of-date order refunded: refused on `items`; nothing written.
- [ ] T-31 Legacy refund then a first invoice: a later refund corrects that invoice (concept line);
  the old refund gets no corrective.
- [ ] T-32 Predicate matrices over all payment and order statuses with collected 0 and > 0
  (FD-8).
- [ ] T-33 Correctives are immutable, never corrected, never refund targets, never collected.
- [ ] T-34 `refund_id`: restrict delete, non-unique, null on voiding lines; `down()` order.

### Existing tests to change (owner approval required in Phase 3)
Project rule: no test is deleted or weakened without the owner's approval. U-9, U-11 and U-12
intentionally change these behaviours; each file is edited in place, none is deleted, and the
implementer must obtain the owner's explicit approval before applying the changes in Phase 3:
- `tests/Feature/Orders/CancelOrderTest.php` — "cancelling is blocked by PartiallyRefunded even
  from Pending and from Processing" now holds only when `collected_amount > 0` (U-12); cancelling
  a Paid invoiced order is now refused (U-9).
- `tests/Feature/Orders/ShowCancellationTest.php`, `tests/Browser/Orders/OrderCancellationTest.php`
  — cancel visibility for a `PartiallyRefunded` order with nothing collected and the new refusal
  message.
- `tests/Feature/Orders/ShowRefundTest.php`, `tests/Browser/Orders/OrderRefundVisibilityTest.php`,
  `tests/Feature/Policies/OrderPolicyTest.php`, `tests/Unit/Models/OrderTest.php` —
  `isRefundable()` admits `PartiallyPaid`.
- `tests/Feature/Orders/{RecordRefundTest, RecordRefundStockTest, RecordRefundLockOrderTest,
  AutoCancelFullyRefundedOrderTest, OrderStockCountersTest, CancelOrderStockTest}.php` — lock
  order now includes invoices, settings and the sequence; money cap with factory orders' collected
  amounts; the auto-cancel test verified unchanged.
- `tests/Feature/Orders/{MarkOrderAsPaidRefundInteractionTest, Datasets, TransitionOrderStatusTest,
  OrdersListStatusBadgeTest}.php` — only where datasets list refundable or `PartiallyRefunded`
  states.
- `tests/Unit/Enums/PaymentStatusTest.php` and dashboard tests found by searching
  `PartiallyRefunded` — verify; change only if they arrange refunds or cancellations under the new
  rules.

## Expected outcome
Every refund and every cancellation of an invoiced order leaves a correct chain of corrective
invoices, numbered in their own series and naming the corrected invoice; refunds never exceed
what was collected, uncollected invoices are voided when an order is fully refunded or cancelled,
and the order's figures stay consistent.

## Acceptance criteria
- [ ] `invoice_lines.refund_id` exists as specified.
- [ ] Refunds issue correctives per FD-4..FD-7, capped by `maxRefundableAmount()` with the error on
  `items`.
- [ ] A full refund voids uncollected invoices in its own transaction; the auto-cancel listener is
  unchanged.
- [ ] Cancellation follows FD-10/FD-11, including the owner's refusal text and U-12.
- [ ] Correctives copy identity and look, use the corrective series, set `voided_at` and carry the
  acting user (FD-2, FD-3, FD-12).
- [ ] Predicates follow FD-8.
- [ ] The existing-test changes are applied only with the owner's recorded approval.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): ER (`invoice_lines.refund_id`), the orders API doc
  (`RecordRefund`, `CancelOrder` contracts), PRD 3.2 cancellation criterion (U-12), authorization
  note, the invoices API doc, the tax-exclusive refund limitation
- [ ] Acceptance criteria met
- [ ] Owner approval for the existing-test changes recorded in this file
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Story 0101a note (I1): call `AllocateInvoiceNumber::lock()` and `__invoke()` at the **same `DB::transaction` nesting level**; lock both series in one `lock()` call. See [0101a](done/0101a-invoice-numbering-sequences-backend.md).
- Upstream: [0101a](done/0101a-invoice-numbering-sequences-backend.md),
  [0101b](done/0101b-issue-standard-invoice-backend.md),
  [0106](done/0106-invoice-collection-order-debt-partially-paid-backend.md). Downstream:
  [0108](0108-regenerate-invoices-after-order-edit-backend.md),
  [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md) (shows `maxRefundableAmount()`), [0109b](0109b-order-detail-invoices-panel-ui.md) (cancel rules).
- Security: entry gates unchanged and first; amounts come from locked rows; a holder of
  `orders.refund` now writes fiscal documents (accepted, no `invoices.*` check). Fail-closed on a
  number collision is intended.
- Concurrency: every writer locks the order first; the refund transaction grows by the invoice and
  sequence locks (short waits on the single corrective sequence row).
- Open technical: confirm `refunds.id` is char(36) and the non-unique `refund_id` index.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3; the existing-test approval above is required during Phase 3):
  - Q-0107-5 (C-15) Tax on refund correctives: (a) tax-exclusive, base only, so **a refund
    corrective carries no tax amount today** and the tax of refunded goods stays invoiced and
    collected (recommended: consistent with today's refunds, no change to refund amounts);
    (b) tax-inclusive refunds, a separate larger story redefining refund amounts, billable, the
    dashboard and the refund screen.
  - Q-0107-1 Which invoice a refund corrects: (a) newest collected first, spilling to older ones
    (recommended, accepted default); (b) always the first invoice; (c) the manager chooses.
  - Q-0107-2 Refund of an order without invoices: (a) no corrective, recorded as today
    (recommended, accepted default); (b) refused until an invoice exists.
  - Q-0107-6 Cancelling an out-of-date order: (a) allowed, voids the live invoices and clears the
    mark (recommended); (b) refused until the invoices are regenerated.

## Technical tasks
- [ ] Migration and `InvoiceLine::refund()`; factory states.
- [ ] `AllocateRefundToInvoices` with unit tests.
- [ ] `CreateCorrectiveInvoice` (copy of identity and look, sign, `voided_at`, own series,
  rollback).
- [ ] `IssueRefundCorrectives`, `VoidLiveInvoices`.
- [ ] `RecordRefund`: out-of-date refusal, money cap, correctives, full-refund voiding, sync,
  concurrency.
- [ ] `CancelOrder`: collected-invoice refusal, voiding, mark clearing, U-12; stock-atomicity
  regression.
- [ ] `Order` predicates and `maxRefundableAmount()`.
- [ ] Obtain the owner's approval, then amend the listed existing tests.
- [ ] Lang en/es; Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
