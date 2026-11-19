# [0106a] "Mark as paid" as the invoice shortcut, and the dashboard Real income (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0106](0106-invoice-collection-order-debt-partially-paid-backend.md) (`ApplyInvoiceCollection`,
> `SyncOrderCollection`, `collected_amount`, `PartiallyPaid`) and
> [0101b](done/0101b-issue-standard-invoice-backend.md) (`IssueInvoiceForOrder`). Ships in the same
> release as 0106. Dependent: [0109a](0109a-orders-list-invoice-send-ui.md). Related:
> [0109b](0109b-order-detail-invoices-panel-ui.md) builds the invoices panel next to the payment line
> this story changes (not a dependency).

## Description
Rework the existing "Mark as paid" action so it settles an order through invoices: it issues an
invoice for whatever is not yet invoiced and marks every live uncollected invoice as collected,
in one transaction, ending with the order "Paid". It stops writing the legacy payment record.
The dashboard's Real income measure becomes the sum of what was actually collected. The two
existing buttons (orders list and order detail) keep working with the new rule; the order
detail's payment line now reads the latest collection.

Request item served (owner, 2026-11-14, translated):
> 5. ... The manager can keep issuing invoices until the order's debt is 0.

Binding owner decisions:
- U-6 (owner decision, 2026-11-14): "'Mark as paid' on the order stays as a shortcut: it invoices
  the outstanding amount if needed and marks every live invoice of the order as collected. The
  order's payment status becomes derived from its collected invoices."
- U-8 (owner decision, 2026-11-15): the new "Partially paid" status; this story's part is the
  dashboard Real income measure.
- Accepted default (owner, 2026-11-15; Q-0106-3 (a), overturnable): the shortcut needs the
  current `orders.edit` gate plus `invoices.collect`, plus `invoices.create` when it must issue.

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- `MarkOrderAsPaid::__invoke(Order, PaymentMethod, OrderPaymentType): Order` (story 0084): one
  transaction with a status-aware compare-and-set `UPDATE orders`, then an `order_payments`
  insert; refusals are `ValidationException`s on `payment_status`.
- Callers: `Orders\Index::markAsPaid()` (the list's only mutating method, pinned by a reflection
  test) and `Orders\Show::markAsPaid()`; both pass the server-resolved bank-transfer method and
  `OrderPaymentType::Transfer`, and show `errors()->first()`. Button visibility uses
  `isAwaitingPayment()` per row / computed.
- The order detail shows "payment recorded by ... on ..." from `$order->payment`
  (`order_payments`).
- `GetSalesSeries` Real income today = `SUM(total - refunded_amount)` over orders `paid` or
  `partially_refunded`, not cancelled; bucketed by `orders.created_at`; one aggregate query
  (pinned by `DashboardActionsQueryCountTest`).
- After 0106: `orders.collected_amount` (backfilled so legacy paid orders equal
  `total - refunded_amount`), `ApplyInvoiceCollection`, `SyncOrderCollection`,
  `Order::latestCollectedInvoice()`, `isAwaitingPayment()` = `PendingPayment` or `PartiallyPaid`.

## Decisions
- **FD-1 Same signature.** `MarkOrderAsPaid::__invoke(Order, PaymentMethod, OrderPaymentType):
  Order` is kept, so both callers compile unchanged; new collaborators are injected
  (`IssueInvoiceForOrder`, `ApplyInvoiceCollection`, `ComputeOrderInvoiceBalance`).
- **FD-2 Order of checks.** (1) Logged `authorize('markPaid', $order)` — policy now `orders.edit`
  AND `invoices.collect`. (2) Plain pre-read of state: cancelled -> `cancelled_blocked`; out of
  date -> `out_of_date`; refund state -> `refunded_blocked` (FD-5); not `PendingPayment` /
  `PartiallyPaid` or debt 0.00 -> `already_paid`; if an invoice must be issued, logged
  `authorize('create', Invoice::class)` **before** the transaction, so an actor lacking it learns
  nothing more. (3) Transaction: order `FOR UPDATE`, re-run every guard on the locked row (the
  pre-read is advisory), live invoices `orderBy('id')->lockForUpdate()`, if uninvoiced > 0
  re-authorize `create` when a race made issuing necessary and call `IssueInvoiceForOrder` inside
  this transaction, then `ApplyInvoiceCollection` over every live uncollected invoice with the
  passed method and type and the actor (it ends with `SyncOrderCollection`, which derives
  `Paid`). (4) Return the caller's order refreshed. All refusals stay validation errors on
  `payment_status` with messages in `orders.payment.*`, so the callers need no change.
- **FD-3 Starting situations.**

  | Situation | Result |
  | --- | --- |
  | No invoice, `PendingPayment` | issue one itemised invoice for the uninvoiced amount (concept line when the order has refunds), collect it, `Paid` |
  | Partly invoiced | issue one concept-line invoice for the uninvoiced amount, collect every live uncollected invoice, `Paid` |
  | Fully invoiced, nothing or part collected | issue nothing, collect every live uncollected invoice, `Paid` |
  | Legacy paid order without invoice (debt 0.00) | not offered; refused `already_paid` |
  | `PartiallyRefunded` / `Refunded` with debt | refused (FD-5) |
  | Cancelled, out of date, fully collected | refused |

  Invoices collected earlier keep their own collection data; only uncollected ones get the
  passed method and type.
- **FD-4 Atomic.** Issue and collect are one transaction; any failure (issuer data incomplete,
  number collision, column ceiling, a failure inside the sync) rolls back everything, consumes no
  number and leaves the order unpaid with a message. The row lock replaces the old
  compare-and-set; a double click or two admins serialise and the loser gets `already_paid`.
- **FD-5 Refund states refused (QA G-2).** A `PartiallyRefunded` or `Refunded` order is never
  marked paid by the shortcut, even with debt (edited up and regenerated); the message tells the
  manager to collect its invoices one by one (accepted default, Q-0106a-2 (a): the shortcut's
  promise is "the order ends Paid", which a refunded order cannot).
- **FD-6 Never sends mail.** No notification, mail, job or delivery row; the manager sends from
  the invoices screens.
- **FD-7 No `order_payments` writes.** The legacy table stays and is read for old orders only.
- **FD-8 Visibility predicate.** `Order::canMarkAsPaid()` = `isAwaitingPayment()` and debt > 0
  and not out of date (columns only, no joins); both callers' `canMarkPaid` switch to it. The
  reflection test pinning `markAsPaid()` as the list's only mutating method is untouched (0109a
  changes it).
- **FD-9 Lost button for narrow roles (QA G-3).** A role that held only `orders.edit` loses the
  button until granted `invoices.collect`; the seeder re-run grants Administrator everything it
  needs; custom roles are not changed automatically and the release note says so (Q-0106a-3 (a)).
- **FD-10 Payment line (QA G-6).** The order detail's "recorded by ... on ..." line reads
  `latestCollectedInvoice()` (collector, collection date), falling back to the legacy
  `order_payments` row, so it never goes blank once the shortcut stops writing that table. This
  one-line view change ships here, not in 0109b.
- **FD-11 Real income.** `GetSalesSeries` income per bucket = sum of `collected_amount` over
  non-cancelled orders in the selected order statuses (one aggregate, no join). Only Real income
  and what derives from it (total income, collected percentage, income hint) change; Sales, the
  orders series, bucketing by `orders.created_at`, the statuses filter and the cancelled exclusion
  (Q-0106a-1 (a)) are unchanged. For every legacy-shaped order the new value equals the old
  `total - refunded_amount`, so past reports do not move. Income stays attributed to the
  creation day (documented limitation); after 0107, refund correctives lower `collected_amount`
  and therefore income.
- **FD-12 Copy.** `dashboard.php` `income_hint` reads "Income is counted once invoices are
  collected" (and its Spanish equivalent); `income_definition` stays true.

## Class contracts
- `App\Actions\Orders\MarkOrderAsPaid::__invoke(Order $order, PaymentMethod $method,
  OrderPaymentType $type): Order` (reworked).
- `App\Policies\OrderPolicy::markPaid(User, Order): bool` = `orders.edit` and `invoices.collect`.
- `App\Models\Order::canMarkAsPaid(): bool`.
- `App\Actions\Dashboard\GetSalesSeries` — income aggregate.
- `Database\Factories\OrderFactory::paidViaInvoice()`.

## Gherkin
```gherkin
Feature: Mark as paid through invoices

  Scenario: Marking an uninvoiced order as paid
    Given a store manager with permission to edit orders, collect invoices and create invoices,
      and an order of 1000.00 EUR with no invoice
    When she marks the order as paid
    Then one invoice for 1000.00 EUR is issued and collected
    And the order shows "Paid"
    And no e-mail is sent

  Scenario: Marking a partly collected order as paid
    Given a store manager with permission to edit orders and collect invoices, and an order of
      1000.00 EUR with a collected 600.00 EUR invoice and an uncollected 400.00 EUR invoice
    When she marks the order as paid
    Then only the 400.00 EUR invoice is collected
    And the 600.00 EUR invoice keeps its original collection data
    And the order shows "Paid"

  Scenario: Issuing needs the create permission
    Given a store manager with permission to edit orders and collect invoices but not to create
      invoices, and an order with no invoice
    When she marks it as paid
    Then it is refused and no invoice is created
    And the attempt is logged

  Scenario: An out-of-date order cannot be marked as paid
    Given a store manager with the needed permissions, and an invoiced order edited afterwards and marked out of date
    When she marks it as paid
    Then it is refused until its invoices are regenerated

  Scenario: A refunded order with debt is not marked as paid
    Given a store manager with the needed permissions, and a "Partially refunded" order that carries debt
    When she marks it as paid
    Then it is refused with a message to collect its invoices one by one

  Scenario: The payment line shows the collection
    Given a store manager with the needed permissions, who has just marked an order as paid
    When she opens the order detail
    Then the payment line shows who collected it and when

  Scenario: Real income counts what was collected
    Given a store manager viewing the dashboard, and an order of 1000.00 EUR with 600.00 EUR collected
    When she reads the Real income figure for that day
    Then it counts 600.00 EUR for that order
    And Sales counts 1000.00 EUR
```

## Files to create/modify
- `app/Actions/Orders/MarkOrderAsPaid.php` — rework (FD-1..FD-7).
- `app/Policies/OrderPolicy.php` — `markPaid()`.
- `app/Models/Order.php` — `canMarkAsPaid()`.
- `app/Livewire/Orders/Index.php`, `app/Livewire/Orders/Show.php` — `canMarkPaid` uses
  `canMarkAsPaid()`; `markAsPaid()` methods unchanged.
- `resources/views/livewire/orders/show.blade.php` — the payment line (FD-10).
- `app/Actions/Dashboard/GetSalesSeries.php` — income aggregate and docblock.
- `lang/{en,es}/orders.php` — `payment.out_of_date`, `payment.refunded_blocked`,
  `payment.nothing_outstanding`; `lang/{en,es}/dashboard.php` — `income_hint`.
- `database/factories/OrderFactory.php` — `paidViaInvoice()`.
- New tests: `tests/Feature/Orders/MarkOrderAsPaidInvoiceShortcutTest.php`,
  `MarkOrderAsPaidShortcutConcurrencyTest.php`, `OrderCanMarkAsPaidTest.php`,
  `MarkOrderAsPaidGateTest.php`; `tests/Feature/Dashboard/GetSalesSeriesRealIncomeTest.php`. The
  existing `MarkOrderAsPaidAuthorizationTest.php`, `MarkOrderAsPaidRefusalLoggingTest.php`,
  `MarkOrderAsPaidConcurrencyTest.php` and `MarkOrderAsPaidRefundInteractionTest.php` already
  exist and are **amended**, never recreated (see below).

## Tests to perform
One dataset of starting situations is shared by the shortcut, predicate, gate and dashboard
files: (1) no invoice, pending; (2) partly invoiced; (3) fully invoiced, nothing collected;
(4) fully invoiced, partly collected; (5) fully collected; (6) legacy paid without invoice;
(7) cancelled; (8) partially refunded with debt; (9) out of date; (10) pending with refunds
(existing anomaly case). Fakes only to assert silence.

Happy path
- [ ] T-1 Situation 1: one itemised invoice for 1000.00 issued by the actor and collected
  (actor, passed method and type, not carried over); `collected_amount 1000.00`; `Paid`; order
  status unchanged; no `order_payments` row; nothing sent.
- [ ] T-2 Situation 2: one concept-line invoice for the remainder; all live invoices collected;
  `Paid`.
- [ ] T-3 Situation 3: no invoice issued (count and sequence unchanged); all collected; `Paid`.
- [ ] T-4 Situation 4: only the uncollected invoice collected; the earlier one keeps its data.
- [ ] T-5 Situation 10: one concept-line invoice for the billable amount; refunds untouched;
  `Paid`.
- [ ] T-6 No code under `app/` creates `order_payments` rows; only `SyncOrderCollection` (and
  `RecordRefund` for refund states) writes `payment_status` (architecture test).
- [ ] T-7 The returned instance is the caller's order refreshed.
- [ ] T-8 Gate: edit + collect + create succeeds in situation 1; edit + collect succeeds in 3 and
  4; Super Admin succeeds.
- [ ] T-9 `canMarkAsPaid()` true for 1-4, false for 5-9 and for `PartiallyPaid` with debt 0.00;
  both callers' `canMarkPaid` equal it.
- [ ] T-9b The order detail payment line shows the latest collection after a shortcut and the
  legacy record for an old paid order.

Edge
- [ ] T-10 Forced failures (issuer incomplete, number collision, exception between issue and
  collect, exception in the sync, ceiling) in situations 1 and 2: nothing persists, counter
  unchanged, validation error on `payment_status`; in situation 3 a failure on the second collect
  leaves the first uncollected. Highest risk.
- [ ] T-11 Two clicks (second connection holds `orders FOR UPDATE`): 1205 while held, then
  `already_paid`; one set of invoices and numbers.
- [ ] T-12 Shortcut racing collect, undo, refund or a line edit: serialised; invariant holds; a
  line edit landing first makes the shortcut refuse `out_of_date`.
- [ ] T-13 State changed between pre-read and lock (status, cancellation, debt 0.00): refused, no
  write.
- [ ] T-14 Without `invoices.create` in situations 1 and 2: refused before the transaction, logged,
  nothing written; the same actor succeeds in 3 and 4; a race that makes issuing necessary is
  refused inside and rolls back.
- [ ] T-15 `collected_at` is the server clock; cent boundaries (999.99, 0.01) end `Paid` exactly.
- [ ] T-16 Issue across a year turn follows 0101a numbering; no extra invoice.
- [ ] T-17 Dashboard dataset in one day (pending uncollected 0; pending with a collected 600 of
  1000; partially paid 600; paid via shortcut 1000; legacy paid 1000; legacy partially refunded
  900; legacy refunded 600; cancelled with 100 collected excluded): income equals the sum of
  `collected_amount` over non-cancelled orders; Sales and orders unchanged.
- [ ] T-18 Seeded random set of 50 legacy-shaped orders: new income equals the old expression.
- [ ] T-19 Order created day 1, collected day 3: income in day 1's bucket; timezone and
  granularity tests still pass.
- [ ] T-20 Status filter: excluded statuses contribute nothing; a partially paid order
  contributes exactly what was collected.
- [ ] T-21 Refund after collection: income equals the stored `collected_amount` (lowered by 0107's
  correctives once they exist; pinned now).
- [ ] T-22 `SalesOverview` percent and hint derive from the new total; `income_hint` en/es parity;
  the series is still one aggregate query.

Negative
- [ ] T-23 Refusal dataset (5, 6, 7, 8, 9): validation error on `payment_status` with the matching
  message; no write, no invoice, no number; Super Admin bound by state refusals.
- [ ] T-24 Without `orders.edit` or without `invoices.collect` (edit only, collect only, view only,
  none): refused before any state read; one warning with `ability = markPaid`, `target_type =
  order`; unauthenticated refused and logged.
- [ ] T-25 Edit + collect without create in situations 1 and 2: refused (`ability = create`,
  `target_type = invoice`); a custom role with only `orders.edit` loses the action.
- [ ] T-26 Livewire callers: refusal messages toast via `errors()->first()`; visibility follows
  `canMarkAsPaid()`; a tampered id is refused server-side; the list's reflection test still passes.
- [ ] T-27 Signature unchanged (reflection); no client value for amount, method or type.
- [ ] T-28 Nothing sent on success or refusal; no delivery row.
- [ ] T-29 Pending order with an existing `order_payments` row: marked paid without touching that
  row (the old unique-violation rollback no longer applies).
- [ ] T-30 Legacy paid order: still refused `already_paid`, no invoice.
- [ ] T-31 Re-seed leaves Administrator with `invoices.collect` and `invoices.create`; a hand-made
  `orders.edit`-only role shows no button.

### Existing tests to change (owner approval required in Phase 3)
Project rule: no test is deleted or weakened without the owner's approval. U-6 intentionally
removes the `order_payments` write, so the assertions below must be rewritten; each file is
edited in place, none is deleted, and the implementer must obtain the owner's explicit approval
for these changes before applying them in Phase 3.
- `tests/Feature/Orders/MarkOrderAsPaidTest.php` (about 39 `order_payments` references): the
  "neither orders nor order_payments change" refusals keep the refusal assertion and drop the
  payment one; "exactly one order_payments row", "type/method stored verbatim", "returned
  instance exposes the new payment", "second attempt leaves the single payment row" move to the
  invoice collection columns; `paid_at` summer/winter becomes `collected_at`; `recorded_by`
  becomes `collected_by`; the refunds-on-a-pending-order anomaly now yields the issued concept
  invoice (T-5); the pending-order-with-a-payment-row unique-violation rollback test becomes T-29;
  "no write against orders or order_payments" on refusal keeps its intent; the architecture test
  "only MarkOrderAsPaid writes `Paid` or creates payment rows" becomes T-6.
- `tests/Feature/Orders/MarkOrderAsPaidAuthorizationTest.php` (`orders.edit` alone is now
  refused), `MarkOrderAsPaidRefusalLoggingTest.php` (new refusal abilities `markPaid` / `create`),
  `MarkOrderAsPaidConcurrencyTest.php` (row lock instead of compare-and-set; winner assertions
  move to the invoice), `MarkOrderAsPaidRefundInteractionTest.php` (refund states refused, FD-5).
- `tests/Feature/Orders/IndexMarkAsPaidTest.php` (about 24 references) and `ShowMarkAsPaidTest.php`
  (about 20): payment-row assertions become collection assertions; `canMarkPaid` follows
  `canMarkAsPaid()`.
- `tests/Browser/Orders/MarkAsPaidTest.php` (about 9 references, frontend-qa): the payment line
  now shows the collection (FD-10).
- `tests/Feature/Orders/OrderModelTest.php` (two `payment()` references) and
  `OrderPaymentModelTest.php` / `OrderPaymentsTableTest.php` (legacy pins; verify, no change
  expected).
- `tests/Feature/Dashboard/{GetSalesSeriesTest, SalesOverviewFiltersTest,
  SalesOverviewRenderingTest, SalesOverviewLocaleTest, DashboardLangParityTest}.php`,
  `tests/Browser/Dashboard/SalesChart*Test.php`, `tests/Support/Dashboard/{SalesOverviewUi,
  SalesChartBrowser}.php`: only where paid orders are arranged without `collected_amount` or the
  `income_hint` copy is asserted.

## Expected outcome
"Mark as paid" settles any payable order through invoices in one step and leaves the order
"Paid" with correct invoices and collections; the dashboard's Real income reflects collected
money, including partial collections, without moving past figures.

## Acceptance criteria
- [ ] The shortcut follows FD-2/FD-3 for every starting situation, atomically (FD-4).
- [ ] Refund states, cancelled, out-of-date, fully collected and legacy orders are refused
  (FD-3, FD-5).
- [ ] Gate is `orders.edit` + `invoices.collect`, plus `invoices.create` before issuing; refusals
  logged.
- [ ] No mail and no `order_payments` write (FD-6, FD-7).
- [ ] Both buttons follow `canMarkAsPaid()`; the payment line shows the latest collection (FD-10).
- [ ] Real income follows FD-11 and equals the old value for legacy-shaped orders.
- [ ] The listed existing tests are changed only with the owner's recorded approval.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): dashboard definitions table (Real income), the orders
  API doc (`MarkOrderAsPaid` contract), authorization (`markPaid` needs two permissions), the
  `order_payments` legacy note, release note for custom roles
- [ ] Acceptance criteria met
- [ ] Owner approval for the existing-test changes recorded in this file
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0106](0106-invoice-collection-order-debt-partially-paid-backend.md) (same release),
  [0101b](done/0101b-issue-standard-invoice-backend.md). Downstream:
  [0109a](0109a-orders-list-invoice-send-ui.md) changes the list's reflection test and [0109b](0109b-order-detail-invoices-panel-ui.md) builds the invoices panel; it does not redo the payment line (FD-10).
  [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md)'s correctives lower
  `collected_amount` and so Real income after a refund.
- Security: the action now creates and collects fiscal documents behind an existing button; the
  gate widens and is logged; only the order id reaches it from the client.
- Risk: custom roles silently lose the button (FD-9); release note.
- Risk: Real income stays attributed to the order's creation day, which a manager may misread
  as cash flow; the hint copy says income is counted once collected.
- Open technical: `IssueInvoiceForOrder` must run inside this transaction as a savepoint without
  autocommit statements (true after 0101a's one-row-per-series design); confirm when 0101a/0101b
  land.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3; the existing-test approval above is required during Phase 3):
  - Q-0106a-1 Real income for cancelled orders: (a) excluded, as today (recommended); (b) include
    the shipping share kept after a full-refund auto-cancel.
  - Q-0106a-2 A refunded order that carries debt: (a) refused, collect invoices one by one
    (recommended); (b) allowed, ending in its refund state with debt 0.00.
  - Q-0106a-3 Roles holding only `orders.edit`: (a) lose the button until granted
    `invoices.collect`; Administrator is re-seeded; release note (recommended); (b) a one-off
    migration grants `invoices.collect` to every role holding `orders.edit`.
  - Q-0106-3 Gate: (a) `orders.edit` + `invoices.collect`, plus `invoices.create` when issuing
    (recommended, accepted default: no privilege bypass); (b) `orders.edit` only.

## Technical tasks
- [ ] Red tests for the situations dataset and the new income measure.
- [ ] Rework `MarkOrderAsPaid` (checks, atomic issue and collect).
- [ ] `OrderPolicy::markPaid`, `Order::canMarkAsPaid()`, caller predicates, payment line.
- [ ] `GetSalesSeries` income, lang copy and parity; `OrderFactory::paidViaInvoice()`.
- [ ] Obtain the owner's approval, then amend the listed existing tests.
- [ ] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, backend-qa. Phase 2 (INVEST) pending.
