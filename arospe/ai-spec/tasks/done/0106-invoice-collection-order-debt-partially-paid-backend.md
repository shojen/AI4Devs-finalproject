# [0106] Invoice collection, order debt and the "Partially paid" status (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0101b](0101b-issue-standard-invoice-backend.md) (invoices with their collection columns,
> `IssueInvoiceForOrder`, `CreateStandardInvoice`, `AssertInvoicesAreCurrent`). Ships in the same
> release as [0106a](../0106a-mark-as-paid-invoice-shortcut-and-real-income-backend.md) (FD-13).
> Dependents: [0106a](../0106a-mark-as-paid-invoice-shortcut-and-real-income-backend.md),
> [0107](../0107-corrective-invoice-on-refund-and-cancellation-backend.md),
> [0108](../0108-regenerate-invoices-after-order-edit-backend.md),
> [0110](../0110-billing-section-ui.md), [0111](../0111-customer-report-read-side-backend.md).

## Description
Mark an invoice as collected (or undo a mistaken collection), keep each order's collected amount
and debt, and derive the order's payment status from its invoices, adding the new status
"Partially paid". Invoices issued for orders paid before invoicing existed are born collected.
This story also creates the shared hook every totals change of an order goes through.

Request items served (owner, 2026-11-14, translated):
> 5. ... The manager can keep issuing invoices until the order's debt is 0.
> 6. ... a report of that customer's orders: total spent, average spend, how much they owe ...

Binding owner decisions:
- U-2 (owner decision, 2026-11-14): "every invoice can be marked as collected (paid). Debt =
  order total minus collected invoices, on the order and in the customer report." Made precise
  (accepted default, Q-0106-4 (a)): debt = (total - refunded) - net collected, never below 0.00,
  because the literal reading would show a fully refunded, fully collected order as owing money.
- U-8 (owner decision, 2026-11-15): "A partly collected order gets a NEW payment status
  'Partially paid' ... Story 0106 therefore reviews the refund rules, the order-status matrix,
  filters, badges and the dashboard's real-income measure." Here: the status, matrix, badges and
  labels; the dashboard measure is 0106a; the refund rules are 0107.
- U-10 (owner decision, 2026-11-15): collection is a whole-invoice fact (no partial amount).
- U-11 and U-12 (owner decisions, 2026-11-15: a "Partially paid" order can be refunded up to its
  collected amount; a "Partially refunded" order with nothing collected can be cancelled) are
  **implemented by 0107**, not here (FD-11).

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- `PaymentStatus`: `PendingPayment`, `Paid`, `Refunded`, `PartiallyRefunded`; `allowsOrderStatus()`
  gates only a transition's target (`TransitionOrderStatus`, `Orders\Show::isBackwardTransition`).
  Today: PendingPayment -> Pending, Cancelled; Paid -> all; Refunded -> Cancelled;
  PartiallyRefunded -> Pending, Processing, Shipped, Delivered.
- `RecordRefund` derives `Refunded` when every line is fully refunded, else `PartiallyRefunded`.
- `order_payments` holds one row per order (legacy payment record, unique `order_id`);
  `Order::payment()` reads it. `PaymentMethod` has no active or soft-delete state.
- `RecalculateOrderTotals` is shared by the three line-item actions.
- The payment-status label is printed in the orders list, the order detail and the dashboard
  latest-orders widget, through `lang/{en,es}/orders.php` `payment_statuses.*`; the badge views
  use a `match` with a zinc fallback.
- 0101b's `invoices` already has `collected_at`, `collected_by`, `collection_payment_method_id`,
  `collection_type`, `collection_carried_over`. The catalog has 48 permissions after 0104.

## Shared definitions
- **Billable** = `total - refunded_amount`; for a `Cancelled` order, what was collected stays
  billed.
- **Net collected** = signed sum of the totals of the order's collected standard invoices plus the
  totals of correctives whose corrected invoice is collected (a corrective is collected exactly
  when the invoice it corrects is).
- **Debt** = `max(0.00, billable - net collected)`.
- **Legacy order** = no invoice and a stored status `Paid`, `Refunded` or `PartiallyRefunded`; its
  collected amount equals its billable amount, so its debt is 0.00.

## Column added
`orders.collected_amount decimal(10,2) not null default 0`, after `refunded_amount`, no index,
written only by `SyncOrderCollection` (and once by this migration). The same migration runs two
steps, in this order (owner decision B-1 (a), 2026-11-21):
1. **Carry over collection.** Every live (not voided), uncollected standard invoice of an order
   whose stored `payment_status` is `paid`, `refunded` or `partially_refunded` is marked
   collected: `collection_carried_over = true`, `collected_at` = `order_payments.paid_at` or the
   migration time, method = the payment row's or the order's, type = the payment row's or null,
   `collected_by` null (no actor). Voided invoices and correctives are untouched.
2. **Backfill `collected_amount`.** Orders with invoices: net collected (Shared definitions), so
   the invariant "collected_amount = net collected for orders with invoices" holds afterwards.
   Orders without invoices: `GREATEST(total - refunded_amount, 0)` when paid-like (cancelled
   included), else 0.00. Status values come from `PaymentStatus`.

The migration never writes `payment_status` (Q-0106-10). `down()` drops the column and does not
revert step 1 (data-lossy, stated in the docblock). `payment_status` needs no schema change
(`partially_paid` fits `varchar(20)`).

## Derived payment status (truth table)
`ResolveOrderPaymentStatus`, first matching row wins; inputs read under the order lock.

| # | Condition | Result |
| --- | --- | --- |
| 0 | order is `Cancelled`, or legacy-shaped (no invoice) | stored status unchanged (never re-derived) |
| 1 | `refunded_amount > 0` and every line fully refunded | `Refunded` |
| 2 | `refunded_amount > 0` otherwise | `PartiallyRefunded` |
| 3 | billable > 0 and debt = 0.00 | `Paid` |
| 4 | net collected > 0 | `PartiallyPaid` |
| 5 | otherwise | `PendingPayment` |

Reads: billable 0.00 with no refund -> row 5; a refunded order that still carries debt shows its
refund state with the debt figure beside it (Q-0106-8 (a)); a legacy order that gains its first
invoice is derived from then on (with refunds it lands on row 1 or 2, never `PendingPayment`).
The "every line fully refunded" test is one shared helper used by both `RecordRefund` and this
table, so the two cannot drift.

## Decisions
- **FD-1 Collected amount is recomputed, never incremented.** `SyncOrderCollection(Order
  $lockedOrder)` (transaction-bound, idempotent) sets `collected_amount` — orders with invoices:
  net collected; without invoices: `Cancelled` keeps the stored value, legacy paid-like = billable,
  otherwise 0.00 — and then `payment_status` from the truth table, writing both on the locked
  order. It never touches the order `status`. The migration's backfill is the same rule applied
  once, after the collection carry-over (step 1), so it agrees with every later sync.
- **FD-2 Corrective collection by join.** Correctives carry no collection columns; their state is
  derived from the corrected invoice.
- **FD-3 Collect.** `CollectInvoice(Invoice, PaymentMethod, OrderPaymentType)`: logged
  `authorize('collect')` first; transaction: order `FOR UPDATE`, out-of-date guard, invoice row
  `FOR UPDATE`, refusals (FD-5), write the five collection columns (`collected_at` = server clock,
  `collected_by` = actor, method, type, `collection_carried_over = false`) through the ungated
  collaborator `ApplyInvoiceCollection` (reused by 0106a), then `SyncOrderCollection`;
  `Log::info` with actor, invoice and order. No confirmation parameter (the dialog is the
  screen's).
- **FD-4 Undo.** `UndoInvoiceCollection(Invoice)`: logged `authorize('uncollect')` first; same
  locks; clears the five columns; `SyncOrderCollection`; `Log::warning` (a financial reversal)
  with actor, invoice and order (accepted default, Q-0106-2 (a)).
- **FD-5 Refusals (QA G-2), each a validation error on `invoice`.** Collect: voided invoice,
  corrective, already collected, `Cancelled` order, out-of-date order. Undo: not collected,
  voided, has a corrective, order has a refund, `Cancelled` order, out-of-date order.
  Authorization always comes first, so an unprivileged caller learns nothing.
- **FD-6 Payment inputs (QA G-4).** The payment method is resolved by the caller (`findOrFail`)
  and must exist; payment methods have no inactive state today, and when one is introduced an
  inactive method must be refused. The type is validated as an `OrderPaymentType`. No amount is
  accepted: collection is whole-invoice (U-10).
- **FD-7 New status and matrix.** `PaymentStatus::PartiallyPaid = 'partially_paid'`, labelled
  "Partially paid" / "Parcialmente pagado". `allowsOrderStatus()` for it allows `Pending` only
  (accepted default Q-0106-7 (a): an order advances once fully paid); the other four rows are
  unchanged. A derivation never moves the order status: a `Processing` order that becomes
  `PartiallyPaid` stays `Processing` and may only move back to `Pending`.
- **FD-8 Predicates in this story.** `isAwaitingPayment()` becomes `PendingPayment` or
  `PartiallyPaid`, not cancelled. Nothing else (FD-11).
- **FD-9 Labels and badges.** Every screen printing a payment status shows the new label; the
  badge `match` arms in the orders list, order detail and latest-orders widget get an orange arm
  (distinct from pending's amber; accepted default Q-OWNER-8 (a)), keeping the zinc fallback. No
  payment-status filter exists and none is added; no dashboard counter reads payment status
  (counters are users, products, media), so no tile changes (QA G-8).
- **FD-10 `order_payments` frozen.** Kept and read by `Order::payment()` for legacy orders; never
  written after 0106/0106a. `Order::latestCollectedInvoice()` (most recent by `collected_at`)
  feeds the order's payment line for new orders.
- **FD-11 U-11 / U-12 predicates move to 0107 (QA G-1).** `isRefundable()` admitting
  `PartiallyPaid` and `isManuallyCancellable()` lifting the `PartiallyRefunded` block when nothing
  is collected ship in 0107 with the refund cap and the cancellation correctives; shipping them
  here would open uncapped refunds on partly paid orders.
- **FD-12 Legacy invoicing.** In `IssueInvoiceForOrder`, under the order lock: a legacy order may
  only be invoiced for its whole uninvoiced amount (else a validation error on `amount`), and the
  invoice is born collected with `collection_carried_over = true`, `collected_at` =
  `order_payments.paid_at` or now, method = the payment row's or the order's, type = the payment
  row's (null when there is no payment row). Its debt stays 0.00 and its status is unchanged (or a
  refund state when it has refunds). Invoices issued before 0106 for paid-like orders get the same
  carried-over collection from the migration (step 1), so there is one rule for both.
- **FD-13 Release coupling (QA G-3).** Until 0106a, a `PartiallyPaid` order would show "Mark as
  paid" (`isAwaitingPayment`) while `MarkOrderAsPaid` refuses it; `PartiallyPaid` can only arise
  from `CollectInvoice`, which has no screen before 0109b/0110a. 0106 and 0106a ship in the same
  release; a test pins today's refusal so 0106a's change is visible. `MarkOrderAsPaid` is not
  changed in 0106 (owner decision, 2026-11-21): the interim state in which an order marked paid
  after 0106 shows its full debt until 0106a lands (same release) is explicitly accepted.
- **FD-14 Legacy edits (QA G-5).** An order edited before it has any invoice keeps its stored
  status; a legacy paid-like order's collected amount follows its billable amount (debt 0.00), a
  pending one stays 0.00 (accepted).
- **FD-15 Shared totals hook.** `AfterOrderTotalsChanged(Order $lockedOrder)` is created here and
  called by `RecalculateOrderTotals` (so by all three line-item actions); in 0106 it only runs
  `SyncOrderCollection`. 0108 adds the out-of-date mark and wires the tax and shipping resolvers.
- **FD-16 Permission.** `invoices.collect` (catalog 48 -> 49); two abilities, `collect` and
  `uncollect`, on the same permission; Administrator and Super Admin hold it. Refusal log
  `target_type = invoice`, with the ability name.
- **FD-17 Collect return value (screen contract).** `CollectInvoice` returns the invoice refreshed
  with its `order` relation loaded, so a screen can show the order's new debt and payment status;
  its state refusals are `ValidationException`s keyed `invoice`. The payment-method list a collect
  dialog offers comes from `ListCollectionPaymentMethods` (owned by
  [0109b](../0109b-order-detail-invoices-panel-ui.md)).

## Class contracts
- `App\Enums\PaymentStatus::PartiallyPaid`.
- `App\Actions\Invoices\CollectInvoice::__invoke(Invoice $invoice, PaymentMethod $method,
  OrderPaymentType $type): Invoice`.
- `App\Actions\Invoices\UndoInvoiceCollection::__invoke(Invoice $invoice): Invoice`.
- `App\Actions\Invoices\ApplyInvoiceCollection::__invoke(Order $lockedOrder, list<Invoice>
  $lockedInvoices, PaymentMethod $method, OrderPaymentType $type, User $actor): void` (ungated).
- `App\Actions\Invoices\SyncOrderCollection::__invoke(Order $lockedOrder): void`.
- `App\Actions\Invoices\ResolveOrderPaymentStatus::__invoke(...): PaymentStatus` (pure).
- `App\Actions\Invoices\AfterOrderTotalsChanged::__invoke(Order $lockedOrder): void`.
- `App\Actions\Invoices\ComputeOrderInvoiceBalance` gains `netCollected`, `debt`, `isLegacy`.
- `App\Models\Order`: `collected_amount` cast, `debt(): string`, `latestCollectedInvoice()`,
  `isAwaitingPayment()`.
- `App\Models\Invoice`: `collectedBy()`, `collectionPaymentMethod()`, `isCollected()`, scopes
  `collected()` / `uncollected()`.
- `App\Policies\InvoicePolicy`: `COLLECT_PERMISSION = 'invoices.collect'`, `collect`, `uncollect`.

## Gherkin
```gherkin
Feature: Invoice collection and order debt

  Scenario: Collecting one of two invoices makes the order partially paid
    Given a store manager with permission to collect invoices, and an order of 1000.00 EUR with invoices of 600.00 and 400.00 EUR
    When she marks the 600.00 EUR invoice as collected
    Then the order shows "Partially paid" and a debt of 400.00 EUR

  Scenario: Collecting the last invoice makes the order paid
    Given a store manager with permission to collect invoices, and an order whose only uncollected invoice is 400.00 EUR
    When she marks it as collected
    Then the order shows "Paid" and a debt of 0.00 EUR
    And the order's fulfilment status is unchanged

  Scenario: Undoing a mistaken collection
    Given a store manager with permission to collect invoices, and an invoice collected by mistake on an order without refunds
    When she undoes the collection
    Then the invoice is uncollected again
    And the debt returns

  Scenario: A collection cannot be undone after a refund
    Given a store manager with permission to collect invoices, and a collected invoice whose order has a refund
    When she tries to undo the collection
    Then it is refused
    And the collection stays

  Scenario: An invoice of a cancelled order cannot be collected
    Given a store manager with permission to collect invoices, and an uncollected invoice of a cancelled order
    When she tries to mark it as collected
    Then it is refused

  Scenario: A partially paid order cannot advance
    Given a store manager with permission to edit orders, and a "Partially paid" order in "Pending"
    When she moves it to "Processing"
    Then the move is refused

  Scenario: An order paid before invoicing gets a collected invoice
    Given a store manager with permission to create invoices, and an order paid before invoicing existed
    When she issues its invoice for the whole amount
    Then the invoice is already collected
    And the debt stays 0.00 EUR

  Scenario: A paid-before-invoicing order cannot be partially invoiced
    Given a store manager with permission to create invoices, and an order paid before invoicing existed
    When she issues an invoice for part of its amount
    Then it is refused

  Scenario: A user without permission cannot collect
    Given a sales assistant who may view but not collect invoices
    When she tries to mark an invoice as collected
    Then it is refused
    And the attempt is logged
```

## Files to create/modify
- `database/migrations/<ts>_add_collected_amount_to_orders_table.php` (collection carry-over of
  paid-like orders' invoices, then the backfill).
- `app/Enums/PaymentStatus.php` — case and matrix arm.
- `app/Actions/Invoices/{CollectInvoice, UndoInvoiceCollection, ApplyInvoiceCollection,
  SyncOrderCollection, ResolveOrderPaymentStatus, AfterOrderTotalsChanged}.php`.
- `app/Actions/Invoices/{ComputeOrderInvoiceBalance, IssueInvoiceForOrder,
  CreateStandardInvoice}.php` (0101b) — balance fields, legacy rule, sync after insert.
- `app/Actions/Orders/RecalculateOrderTotals.php` — call `AfterOrderTotalsChanged`.
- `app/Actions/Orders/RecordRefund.php` — use the shared "every line fully refunded" helper (no
  behaviour change).
- `app/Models/{Order, Invoice}.php`; `app/Policies/InvoicePolicy.php`.
- `database/seeders/RolePermissionSeeder.php` — `invoices.collect`; `lang/{en,es}/roles.php`
  segment `collect`; catalog-count tests 48 -> 49.
- `lang/{en,es}/orders.php` — `payment_statuses.partially_paid`; `lang/{en,es}/invoices.php` —
  collection refusals.
- Badge arms: `resources/views/livewire/orders.blade.php`, `livewire/orders/show.blade.php`,
  `livewire/dashboard/latest-orders-widget.blade.php` (orange).
- `database/factories/OrderFactory.php` — `partiallyPaid()`, `withCollectedAmount()`, and
  `paid()` also sets `collected_amount = total - refunded_amount`; `InvoiceFactory::collected()`.
- Existing tests extended, never deleted: `tests/Unit/Enums/PaymentStatusTest.php`,
  `Feature/Orders/{TransitionOrderStatusTest, IndexRenderingTest, ShowRenderingTest,
  OrdersLangParityTest}`, `Feature/Dashboard/{OverviewRenderingTest, OverviewLocaleTest}`, seeder
  count tests.

## Tests to perform
Real actions and database; second connection for concurrency. Files under
`tests/Feature/Invoices/Collection/` plus `tests/Feature/Orders/{PartiallyPaidMatrixTest,
PaymentStatusConsumersTest}.php`.

Happy path
- [x] T-1 Truth table as one dataset (cancelled and legacy unchanged; refund rows; paid; partially
  paid; pending; billable 0.00; refunded with debt; collected above billable -> paid), rows built to
  satisfy two conditions so first-match is proven.
- [x] T-2 Collect 600 of 600 + 400: five columns set, `collected_amount 600.00`, `PartiallyPaid`,
  order status untouched, one info log.
- [x] T-3 Collect the 400 too: 1000.00, debt 0.00, `Paid`, order status untouched.
- [x] T-4 Undo: columns cleared, recomputed, status back, one warning log.
- [x] T-5 Debt dataset (collected 0 / 600 / 1000; refunded 100 and collected 900; uninvoiced
  pending; legacy paid): billable - net collected, clamped, two-decimal strings.
- [x] T-6 Collected invoice plus a corrective of it: net collected is the signed sum; a corrective
  of an uncollected invoice contributes 0.
- [x] T-7 Legacy order invoiced whole: born collected, carried over, collection fields from the
  payment row or fallbacks, debt 0.00, status unchanged.
- [x] T-8 Every `PaymentStatus` case has en/es labels; the three views render an orange badge for
  `PartiallyPaid`.
- [x] T-9 Matrix dataset over all 5 x 5 combinations: `PartiallyPaid` allows `Pending` only; the
  other rows equal a frozen copy of today's matrix.
- [x] T-10 Legacy pending order gets its first invoice: derived from then on; a legacy partially
  refunded order with a born-collected whole invoice stays `PartiallyRefunded`.
- [x] T-11 `latestCollectedInvoice()` returns the most recent collection; `Order::payment()` still
  reads legacy rows and is never written.
- [x] T-12b `CollectInvoice` returns the refreshed invoice with `order` loaded (no extra query to
  read its debt and payment status); refusals keyed `invoice`.
- [x] T-12 `invoices.collect` alone allows collect and undo; Super Admin allowed; catalog 49.

Edge
- [x] T-13 Two clicks collecting one invoice (second connection holds `orders FOR UPDATE`): the
  waiter fails with 1205, then is refused as already collected; counted once. Top risk.
- [x] T-14 Collect vs undo, collect vs refund, collect vs line edit: serialised; collected amount
  equals the signed sum afterwards.
- [x] T-15 `SyncOrderCollection` three times: same result, no write after the first; outside a
  transaction `LogicException`; a stale order instance is re-derived from rows.
- [x] T-16 Migration on seeded rows, as one dataset. Without invoices (paid, refunded, partially
  refunded, over-refunded, pending, cancelled legacy paid): `GREATEST(total - refunded, 0)` for
  paid-like, 0.00 otherwise, never negative. With invoices: (a) paid order with an uncollected
  live invoice -> invoice carried over (`collection_carried_over = true`, `collected_at` from
  `order_payments.paid_at`, else migration time; method and type from the payment row, else the
  order's method and null type), `collected_amount` = net collected; (b) partially refunded order
  with an invoice (and a corrective of it) -> same carry-over, `collected_amount` = signed sum;
  (c) a voided invoice and a pending order's invoice stay uncollected. Afterwards
  `collected_amount` = net collected for every order with invoices, and `payment_status` is
  unchanged. Mandatory: `RefreshDatabase` alone runs it on empty tables.
- [x] T-17 Legacy paid order edited before any invoice: collected follows billable, debt 0.00,
  status unchanged; legacy pending edited: stays 0.00 (FD-14).
- [x] T-18 `AfterOrderTotalsChanged` runs exactly once per line-item action inside the caller's
  transaction; the three actions' existing tests still pass.
- [x] T-19 A `Processing` order becoming `PartiallyPaid` stays `Processing`; `Shipped` refused,
  `Pending` allowed; once `Paid`, `Shipped` allowed.
- [x] T-20 Cent boundaries: 999.99 and 0.01 of 1000.00 -> `PartiallyPaid`; 1000.00 -> `Paid`.
- [x] T-21 Standard 600 collected, corrective -100, standard 400 uncollected: net collected 500,
  billable 900, debt 400, `PartiallyPaid`.
- [x] T-22 Orders list, detail and latest-orders widget with a `PartiallyPaid` order: no match
  error, label shown, `isAwaitingPayment()` true.
- [x] T-22b The shared "every line fully refunded" helper gives the same answer as `RecordRefund`
  for a dataset of line states.

Negative
- [x] T-23 Without `invoices.collect` (none, view, send, create): refused before any state read,
  same refusal for any invoice state, nothing written.
- [x] T-24 One refusal warning per call with `ability` `collect` or `uncollect`,
  `target_type = invoice`, no amounts; unauthenticated refused and logged.
- [x] T-25 Collect refusals dataset (FD-5): validation error on `invoice`, nothing written.
- [x] T-26 Undo refusals dataset (FD-5): validation error, columns unchanged.
- [x] T-27 Missing payment method, type `crypto`, `''`, null, array: refused, nothing written.
- [x] T-28 Extra keys (`collected_by`, `collected_at`, `amount`, `collected_amount`) ignored:
  actor and server clock decide; no partial amount exists.
- [x] T-29 Legacy order partial invoice refused on `amount`; whole succeeds; a non-legacy order
  may be partial.
- [x] T-30 `order_payments` never written by collect, undo or sync; architecture test.
- [x] T-31 `TransitionOrderStatus` for every target on a `PartiallyPaid` order: only `Pending`
  accepted.
- [x] T-32 Pin: `MarkOrderAsPaid` still refuses a `PartiallyPaid` order (changed by 0106a).

## Expected outcome
Holders of `invoices.collect` can mark invoices collected (or undo a mistake) and every order
shows a correct collected amount, debt and derived payment status, including "Partially paid";
orders paid before invoicing keep a debt of 0.00 and get collected invoices.

## Acceptance criteria
- [x] `orders.collected_amount` exists; the migration first carries over the collection of
  paid-like orders' live uncollected invoices, then backfills, so collected amount equals net
  collected for every order with invoices.
- [x] Collected amount, debt and payment status follow the definitions and the truth table.
- [x] Collect and undo follow FD-3..FD-6, refusals included, safe under concurrency.
- [x] `PartiallyPaid` exists with the FD-7 matrix, labels and badges; derivation never moves the
  order status.
- [x] Legacy orders are invoiced only whole and born collected (FD-12).
- [x] `AfterOrderTotalsChanged` exists and runs from `RecalculateOrderTotals`.
- [x] `invoices.collect` seeded (catalog 49); refusals logged; en/es parity.
- [x] No change to `isRefundable()` or `isManuallyCancellable()` (0107).

## Definition of Done
- [x] Tests written and green
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor)
- [x] Documentation updated (docs-keeper): ER (`orders.collected_amount`), the orders schema doc
  (`order_payments` frozen as legacy), the orders and invoices API docs (truth table, matrix),
  authorization catalog (49)
- [x] Acceptance criteria met
- [x] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0101b](0101b-issue-standard-invoice-backend.md). Downstream:
  [0106a](../0106a-mark-as-paid-invoice-shortcut-and-real-income-backend.md) (same release; reuses
  `ApplyInvoiceCollection` and `SyncOrderCollection`),
  [0107](../0107-corrective-invoice-on-refund-and-cancellation-backend.md) (U-11 / U-12 predicates,
  refund correctives), [0108](../0108-regenerate-invoices-after-order-edit-backend.md) (adds the mark
  to `AfterOrderTotalsChanged`), [0111](../0111-customer-report-read-side-backend.md) (sums
  `collected_amount`).
- Risk: the backfill is a single `UPDATE` over paid-like orders; its lock time on a large table is
  unmeasured (acceptable at back-office scale).
- Risk: a holder of `invoices.collect` can reverse money facts; undo is logged at warning level.
  A password step-up for undo is a possible hardening (Q-0106-9).
- Concurrency: every writer locks the order first; guards run on re-read rows.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0106-2 Undo a collection: (a) allowed with `invoices.collect` and a confirmation, within
    the FD-5 limits (recommended, accepted default: mistakes happen; collection is not a fiscal
    document); (b) never.
  - Q-0106-4 Debt formula: (a) refunds netted, as defined (recommended, accepted default);
    (b) literal "total minus collected".
  - Q-0106-7 Order statuses a `PartiallyPaid` order may move to: (a) `Pending` only
    (recommended); (b) `Pending`, `Processing`, `Shipped`, `Delivered` (ship against a deposit).
  - Q-0106-8 A refunded order that later carries debt: (a) shows its refund state with the debt
    beside it (recommended); (b) a combined state.
  - Q-0106-9 Password step-up before undoing a collection: (a) no, consistent with "Mark as
    paid" (recommended); (b) yes.
  - Q-0106-10 Stored `payment_status` of orders with invoices after the migration: (a) left as
    stored; the next `SyncOrderCollection` derives it (recommended: the migration only fixes
    amounts; a paid order invoiced for part of its total keeps "Paid" until its next change);
    (b) the migration re-derives it with the truth table.

## Technical tasks
- [x] Truth-table dataset, then `ResolveOrderPaymentStatus` and the shared refund helper.
- [x] Migration with backfill and its seeded-rows test; `Order` cast and methods.
- [x] `PaymentStatus` case, matrix, lang, badge arms, factory states.
- [x] `SyncOrderCollection`, `AfterOrderTotalsChanged`, `RecalculateOrderTotals` wiring.
- [x] `ApplyInvoiceCollection`, `CollectInvoice`, `UndoInvoiceCollection` with authorization,
  logging and concurrency tests.
- [x] Legacy issue rule in the 0101b classes.
- [x] `invoices.collect`, policy abilities, roles segment, count tests; update every test that
  enumerates payment statuses.
- [x] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
- Phase 2 (INVEST) — owner decisions of 2026-11-21 on the REJECTED review (blocker B-1; the
  rejection record was not present in this file when revised): B-1 option (a) adopted (migration
  carries over collection before the backfill; Column added, FD-1, FD-12, T-16, files list and
  acceptance criteria updated); `MarkOrderAsPaid` unchanged in 0106 and the interim state accepted
  (FD-13); non-blocking note fixed (FD-16 now precedes FD-17); Q-0106-10 recorded. Revised by
  product-owner; returns to code-reviewer for INVEST re-validation.
- Phase 2 (INVEST) re-validation — **APPROVED** by code-reviewer on 2026-11-21. B-1 resolved:
  migration step 1 (carry-over) and step 2 (backfill) agree with FD-1 and FD-12, T-16 covers
  (a)/(b)/(c) plus the invariant and the unchanged `payment_status`, and the acceptance criteria
  match. Every column reused exists (`invoices.collected_at`, `collected_by`,
  `collection_payment_method_id`, `collection_type`, `collection_carried_over`, `voided_at`,
  `corrects_invoice_id`; `order_payments.paid_at`, `payment_method_id`, `type`;
  `orders.payment_method_id`, `refunded_amount`). Non-blocking notes for Phase 3: (1) under
  Q-0106-10 (a), a pre-0106 paid order invoiced for only part of its total shows the uninvoiced
  remainder as debt immediately and is derived to `PartiallyPaid` on its next sync; the manager can
  clear it by issuing and collecting the remaining invoice (not legacy, so FD-12 does not apply);
  (2) under FD-13, `MarkOrderAsPaid` does not call `SyncOrderCollection`, so until 0106a an order
  marked paid keeps `collected_amount` 0.00 and a later line edit on an invoiced one would re-derive
  it to `PendingPayment` — accepted interim, same release.
- Phase 3 (TDD) — complete on 2026-11-21. backend-qa wrote the red tests (T-1..T-32); database-expert
  wrote the `orders.collected_amount` migration with the carry-over and backfill; backend-expert
  wrote the code (`ResolveOrderPaymentStatus`, `SyncOrderCollection`, `AfterOrderTotalsChanged`,
  `ApplyInvoiceCollection`, `CollectInvoice`, `UndoInvoiceCollection`, `PaymentStatus::PartiallyPaid`,
  `invoices.collect`). The orchestrator made the three remaining test fixes. Tests green.
- Phase 4 (security) — **APPROVED** by appsec-auditor on 2026-11-21. Low findings 1 and 3 were
  fixed; Low finding 2 is recorded for 0107/0108 in the epic digest
  (`ai-spec/tasks/_digests/epic-6.md`).
- Phase 5 (code review) — **APPROVED** by code-reviewer on 2026-11-21 after one Larastan defect in
  `SyncOrderCollection` was fixed; acceptance criteria, conventions and Definition of Done met.
- Phase 6 (documentation) — complete on 2026-11-21 by docs-keeper (`docs/`, ER diagram, orders schema,
  orders and invoices API docs, authorization catalog 49, epic 6 digest).
- Phase 7 (closure) — 2026-11-21. Full suite in directory chunks: Unit + Orders + Localization
  1623/1623; Invoices + Dashboard + Models + Policies 1881 (1874 passed, 7 skipped); Blog + Products
  1950/1950; remaining Feature directories 2103/2103; Browser (after the back-merge of
  `origin/main`) 344 (341 passed, 3 skipped). Pint unscoped clean; Larastan level 7, 0 errors. Moved
  to `ai-spec/tasks/done/`; link-integrity check run in both directions.
