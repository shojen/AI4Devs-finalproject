# [0111] Customer report read side (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 3 — Customers and orders (ad-hoc; requested
> by the owner on 2026-11-14, not yet in docs/PRD), delivered with Epic 6 — Invoicing. Blocked on
> [0106](done/0106-invoice-collection-order-debt-partially-paid-backend.md) (`orders.collected_amount`,
> debt semantics, `PartiallyPaid`). Dependent: [0112](0112-customer-report-screen-ui.md) (the
> screen, route, presets and chart).

## Description
Two read actions that power a per-customer report for a date range: a summary (orders counted,
total spent, average spend, collected, debt in the range, debt owed now, and the list of orders)
and a chart series per time bucket (spent, collected, debt). Nothing is written; no route or
screen here.

Request item served (owner, 2026-11-14, translated):
> 6. In the customers section, a button opens a report of that customer's orders: total spent,
> average spend (total / orders), how much they owe, and a chart of those figures. It can be
> filtered by time; the default is one month.

Binding owner decision:
- U-2 (owner decision, 2026-11-14): "Debt = order total minus collected invoices, on the order
  and in the customer report." Made precise in 0106 (accepted default Q-0106-4 (a)): an order's
  debt = `max(0, (total - refunded) - collected)`.
- Accepted defaults (owner, 2026-11-15; overturnable, recorded below): total spent = billed net of
  refunds (Q-0111-1 (a)); "owes now" ignores the range (Q-0111-2 (a)); "one month" = the last 30
  days ending today (Q-0111-3 (a)); the list shows at most the 200 newest orders (Q-OWNER-9 (a));
  the screen route is gated by `customers.view` with `orders.view` checked in the screen and the
  actions (Q-OWNER-6 (a)).

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- Dashboard read actions (`app/Actions/Dashboard/`) take no model, return scalars, enums and
  `CarbonImmutable`, write nothing, authorize through `LogRefusedPrivilegedAttempt` first, then
  validate, then query.
- `ResolveSalesBuckets(SalesGranularity, CarbonInterface $from, CarbonInterface $to, ?array
  $statuses)` normalises and validates a range (years 1000..9998, `SalesGranularity::maxBuckets()`
  cap) and returns zero-filled bucket keys; both bounds are required. A reachability test pins
  its callers to the two dashboard series actions. `SalesGranularity` has `Day`, `Month`, `Year`.
- Range predicate convention: `created_at >= start AND created_at < endExclusive` on app wall time
  (`config('app.timezone')`), no conversion in SQL. Validation messages live in
  `dashboard.errors.*`.
- `orders.customer_id` is indexed (foreign key); orders have no soft delete; customers are
  soft-deleted. `CustomerPolicy` has `VIEW_PERMISSION = 'customers.view'`;
  `OrderPolicy::VIEW_PERMISSION = 'orders.view'`.
- After 0106: `orders.collected_amount` (net collected, legacy paid orders backfilled to
  `total - refunded_amount`) is the same column the dashboard Real income now sums (0106a).

## Measures
Per **counted order** = an order of this customer, not `Cancelled`, created in the range:
- **Billable** = `total - refunded_amount`.
- **Total spent** = sum of billable (Q-0111-1 (a)).
- **Average spend** = total spent / number of counted orders, rounded half-up to two decimals in
  bcmath; `null` when no order is counted (shown as a dash), never a division by zero.
- **Total collected** = sum of `collected_amount`.
- **Order debt** = `max(0, billable - collected_amount)`; **debt in range** = sum over counted
  orders; **owed now** = the same sum over all the customer's non-cancelled orders, regardless of
  the range (Q-0111-2 (a)).
- **Chart** per bucket of `orders.created_at`: spent, collected and debt of the orders created in
  that bucket, zero-filled; the bucket sums equal the summary's totals for the same range.

## Decisions
- **FD-1 Two actions.** `GetCustomerReportSummary` and `GetCustomerReportSeries`, mirroring the
  dashboard's split so the chart can load lazily.
- **FD-2 One definition of the measures.** `CustomerReportQuery` (ungated collaborator, used only
  by the two actions) holds the base query (customer filter, not cancelled, half-open range) and
  the billable and debt SQL fragments as constants; no input is ever interpolated.
- **FD-3 Order of operations.** Authorize `viewReport` (logged, `target_type = customer`), then
  re-read the customer with the soft-delete-aware scope (a soft-deleted or missing customer is a
  `ModelNotFoundException`, 404 over HTTP, never an empty report), then validate the range, then
  query. An unauthorized actor gets 403 even for a deleted customer, so existence is not revealed.
- **FD-4 Permission.** `CustomerPolicy::viewReport(User, Customer)` = `customers.view` and
  `orders.view`; no new permission. Super Admin passes through its gate bypass.
- **FD-5 Return values (QA G-1).** "Scalars only" means no Eloquent model or relation: money as
  two-decimal strings, counts as integers, and immutable value objects (`CarbonImmutable` dates,
  backed enums `OrderStatus`, `PaymentStatus`, `SalesGranularity`), the convention of the
  dashboard read actions.
- **FD-6 Totals identity (QA G-3).** Total spent minus debt equals total collected whenever no
  order is collected above its billable amount, which the refund cap and the edit refusal keep
  true. Collected is not clamped, so it always equals the dashboard Real income for the same
  orders and an anomaly is visible rather than hidden.
- **FD-7 Range (QA G-4, G-6).** Both bounds or none. None: the last 30 days ending today in the
  app timezone (today - 29 .. today), whatever the granularity, the dashboard's `last_30_days`
  preset restated once in the action (Q-0111-3 (a), Q-0111-4 (a)). Exactly one bound: a
  validation error on the range. Otherwise `ResolveSalesBuckets` validates (inverted range, year
  bounds, bucket cap) before any query, with the statuses argument = every `OrderStatus` except
  `Cancelled`, derived from `OrderStatus::cases()` so a future case is counted.
- **FD-11 Error keys and resolved dates (screen contract).** The one-bound refusal is keyed `range`
  with the new `customers.report.errors.range_both_bounds` (`lang/{en,es}/customers.php`, covered by
  the language-parity test); the other range messages stay `dashboard.errors.range_invalid` and
  `dashboard.errors.range_too_long` (`:max`). The returned `from` is the start of the first day and
  `to` the start of the last included day (inclusive), as `GetSalesSeries` returns them, so the
  screen prints them without recomputing; `orderCount` is the number of every counted order, never
  the list length; `points[].label` is the bucket start.
- **FD-8 List.** Counted orders newest first (`created_at desc, id desc`), at most 200 rows, with
  `ordersTruncated` true when more exist; `orderCount`, totals, average, debt and the chart always
  cover every counted order.
- **FD-9 Query budget, no new index.** Summary = customer re-read + one conditional aggregate
  (range sums and owed now together) + one list query; series = customer re-read + one
  `GROUP BY` aggregate, zero-filled in PHP. The list selects columns only (no relations). No index
  beyond `orders.customer_id`; revisit with measured data.
- **FD-10 Orders, not invoices.** The report reads order columns only, so it works for legacy and
  uninvoiced orders and agrees with the order-level debt.

## Class contracts
- `App\Actions\Customers\GetCustomerReportSummary::__invoke(Customer $customer, ?CarbonInterface
  $from = null, ?CarbonInterface $to = null): array{from: CarbonImmutable, to: CarbonImmutable,
  orderCount: int, totalSpent: string, averageSpend: ?string, totalCollected: string, debtInRange:
  string, owedNow: string, orders: list<array{id: string, orderNumber: string, createdAt:
  CarbonImmutable, status: OrderStatus, paymentStatus: PaymentStatus, total: string, billable:
  string, debt: string}>, ordersTruncated: bool}`.
- `App\Actions\Customers\GetCustomerReportSeries::__invoke(Customer $customer, SalesGranularity
  $granularity, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array{granularity:
  SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, totalSpent: string,
  totalCollected: string, totalDebt: string, points: list<array{bucket: string, label:
  CarbonImmutable, spent: string, collected: string, debt: string}>}`.
- `App\Actions\Customers\CustomerReportQuery` — `base(string $customerId, CarbonImmutable $start,
  CarbonImmutable $endExclusive): Illuminate\Database\Query\Builder`; constants `BILLABLE_SQL`,
  `DEBT_SQL`.
- `App\Policies\CustomerPolicy::viewReport(User $actor, Customer $customer): bool`.

## Gherkin
```gherkin
Feature: Customer report figures

  Scenario: Total spent and average for the period
    Given a store manager with permission to view customers and orders, and a customer with three
      orders totalling 100.00 EUR in the last 30 days
    When she requests the customer's report
    Then total spent is 100.00 EUR
    And the average spend is 33.33 EUR

  Scenario: No orders in the period
    Given a store manager with permission to view customers and orders, and a customer with no
      orders in the selected period
    When she requests the customer's report
    Then total spent is 0.00 EUR
    And there is no average

  Scenario: Owed now ignores the period
    Given a store manager with permission to view customers and orders, and a customer with an
      unpaid order from three months ago
    When she requests the report for the last 30 days
    Then "owed now" includes that unpaid order
    And the period's debt and order list do not

  Scenario: Debt of a partially paid order
    Given a store manager with permission to view customers and orders, and a customer with an
      order of 1000.00 EUR with 600.00 EUR collected
    When she requests the customer's report
    Then that order shows a debt of 400.00 EUR

  Scenario: Cancelled orders are not counted
    Given a store manager with permission to view customers and orders, and a customer with a
      cancelled order in the period
    When she requests the customer's report
    Then the cancelled order is not counted in any figure

  Scenario: The default period is the last 30 days
    Given a store manager with permission to view customers and orders, on 15 November 2026
    When she requests a customer's report without choosing a period
    Then the report covers 17 October to 15 November 2026

  Scenario: A user without order permission is refused
    Given a store manager who can view customers but not orders
    When she requests a customer's report
    Then it is refused
    And the attempt is logged

  Scenario: A deleted customer has no report
    Given a store manager with permission to view customers and orders, and a deleted customer
    When she requests that customer's report
    Then she gets a not-found result
```

## Files to create/modify
- `app/Actions/Customers/{GetCustomerReportSummary, GetCustomerReportSeries,
  CustomerReportQuery}.php`.
- `app/Policies/CustomerPolicy.php` — `viewReport`.
- Tests under `tests/Feature/Customers/Report/`; `tests/Feature/Policies/CustomerPolicyTest.php`
  extended.
- `tests/Feature/Dashboard/ResolveSalesBucketsTest.php` — amended, see below.
- `lang/{en,es}/customers.php` — `report.errors.range_both_bounds`.
- No migration, route, permission, seeder or config change.

## Tests to perform
Read-only: real database, `OrderFactory` rows (`withCollectedAmount`, refunds, statuses, explicit
`created_at`) plus some orders built through the real actions; `travelTo` with a non-UTC app
timezone (Europe/Madrid) and a DST pair; money as exact strings; `DomainQueryLog` for query
counts. Dataset ORDERS-MIX: pending uncollected 100.00; processing partially paid 1000.00 with
600.00 collected; delivered paid 250.00; legacy paid (backfilled); partially refunded total 100
refunded 40; cancelled 500 with 50 collected; another customer's order; a soft-deleted customer's
order; an order outside the range.

Happy path
- [ ] T-1 ORDERS-MIX: only this customer's non-cancelled orders in the range counted; total spent
  = sum of billable.
- [ ] T-2 Average rounding dataset ((100.00, 3) -> 33.33; (100.01, 2) -> 50.01; (0.02, 3) -> 0.01;
  (2.00, 3) -> 0.67; one order; 0.00 totals with count 2 -> 0.00); zero orders -> null, total
  0.00.
- [ ] T-3 Owed now includes an unpaid order before the range; changing the range never changes it;
  cancelled debt never counted.
- [ ] T-4 Per-order debt dataset: partially paid 400.00; paid 0.00; legacy paid 0.00; legacy pending
  = billable; partially refunded with 60 collected 0.00; over-collected -> 0.00.
- [ ] T-5 List rows carry id, number, date, status, payment status, total, billable, debt; newest
  first with a deterministic id tiebreak.
- [ ] T-6 Series for Day, Month and Year: zero-filled, per-bucket sums by `created_at`, labels are
  each bucket's first day; totals equal the summary's for the same range.
- [ ] T-7 With every collected amount at or below billable: spent - debt = collected exactly; the
  over-collected anomaly breaks it visibly (FD-6).
- [ ] T-8 No range at 2026-11-15 12:00: 2026-10-17 .. 2026-11-15, Day, 30 buckets; at 23:30 UTC
  "today" is Madrid's day; with Month or Year granularity the same 30-day range.
- [ ] T-9 200 counted orders: 200 rows, not truncated; 201: the 200 newest, truncated, count 201,
  totals over all 201.
- [ ] T-10 Both permissions or Super Admin: allowed; results contain no model or relation, only
  the FD-5 types.

Edge
- [ ] T-11 Day edges in the app timezone (start included, previous second excluded, `to`
  inclusive of its day, next midnight excluded, both DST nights, a leap day): half-open rule.
- [ ] T-12 Validation dataset (inverted, single day, years 999/9999 and 1000/9998, 366 vs 367 day
  buckets, 10-year Month range): errors from `dashboard.errors.*` before any query; exactly one
  bound -> range error on `range` with `customers.report.errors.range_both_bounds` (FD-7, FD-11).
- [ ] T-12b Returned `from` / `to` are the start of the first day and of the last included day;
  `orderCount` exceeds the list length when truncated.
- [ ] T-13 Exact sums: a 99999999.99 order with small ones, `0.10 + 0.20 = 0.30`, 10,000 orders of
  0.01 = 100.00.
- [ ] T-14 Every non-cancelled status counted; the statuses passed are derived from
  `OrderStatus::cases()` minus `Cancelled`.
- [ ] T-15 Auto-cancelled refunded order excluded; a non-cancelled refunded order contributes its
  remainder with debt 0.00.
- [ ] T-16 Orders driven through the real actions (create, issue, collect, refund, regenerate,
  cancel): owed now equals the sum of order debts at each step.
- [ ] T-17 Month/Year range starting mid-bucket: the partial buckets include only orders inside
  the range.
- [ ] T-18 Two customers with identical orders: each report counts only its own.
- [ ] T-19 Soft-deleted customer: 404; restored: works; a stale loaded model deleted afterwards:
  still 404.
- [ ] T-20 Query budget with 50 and 500 orders: summary 3 domain queries, series 2, no relation
  tables in the log.
- [ ] T-21 Generated SQL: no interpolated input, customer and statuses bound, sargable range.
- [ ] T-22 Total collected equals the dashboard's total Real income for the same orders, range and
  statuses.
- [ ] T-23 Customer with no orders: series all zero, totals 0.00, no exception.

Negative
- [ ] T-24 Permission dataset (customers only, orders only, neither, invoices view only): refused
  unless both; refused before any query and before range validation.
- [ ] T-25 One `Log::warning('Privileged action refused')` with `ability = viewReport`,
  `target_type = customer`, the customer id, no figures; unauthenticated refused and logged; a
  permitted call logs nothing.
- [ ] T-26 Unauthorized actor and a deleted customer: 403, not 404.
- [ ] T-27 Hostile inputs (unknown granularity, malformed dates, wrong types): typed errors or
  validation errors, never a SQL error or an unbounded query.
- [ ] T-28 Architecture: no writes, `CustomerReportQuery` used only by the two actions, the
  customer bound by model, no route or controller referencing the actions in this story.
- [ ] T-29 Far-past/far-future `created_at` at the year bounds: no exception.
- [ ] T-30 Hard-deleted or unknown customer: `ModelNotFoundException`, authorization still first.

### Existing tests to change (owner approval required in Phase 3)
Project rule: no test is deleted or weakened without the owner's approval. None is deleted and no
assertion is removed, but the implementer must obtain the owner's explicit approval before
applying these changes in Phase 3:
- `tests/Feature/Dashboard/ResolveSalesBucketsTest.php` — the reachability pin "only
  `GetSalesSeries` and `GetOrdersSeries` reference `ResolveSalesBuckets`" must also allow
  `GetCustomerReportSummary` and `GetCustomerReportSeries` (both authorize before using it).
- `tests/Feature/Dashboard/DashboardActionsAuthorizationTest.php` and
  `DashboardActionsQueryCountTest.php` — verify they do not enumerate the users of the bucket
  collaborator; amend only if they do.
- `tests/Feature/Policies/CustomerPolicyTest.php` — extended with `viewReport` (no change to the
  permission catalog).

## Expected outcome
For any active customer and period, an authorized manager's screen can obtain correct, exact
figures — total spent, average, collected, debt in the period, debt owed now — plus the order list
and a zero-filled chart series, consistent with the dashboard's Real income.

## Acceptance criteria
- [ ] Counted orders and every measure follow the definitions above.
- [ ] Authorization, deleted-customer handling and refusal logging follow FD-3/FD-4.
- [ ] Results follow FD-5 types and the class-contract shapes.
- [ ] Range handling follows FD-7, including the default and the one-bound refusal.
- [ ] The list is capped per FD-8; totals cover all counted orders.
- [ ] Query budget per FD-9 holds with many orders.
- [ ] The existing-test changes are applied only with the owner's recorded approval.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): customers API doc (the two actions and the measures),
  authorization (`viewReport`), dashboard doc cross-reference to the shared bucket collaborator
- [ ] Acceptance criteria met
- [ ] Owner approval for the existing-test changes recorded in this file
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0106](done/0106-invoice-collection-order-debt-partially-paid-backend.md); its figures
  agree with [0106a](0106a-mark-as-paid-invoice-shortcut-and-real-income-backend.md)'s Real
  income. Downstream: [0112](0112-customer-report-screen-ui.md).
- Correctness depends on every writer maintaining `collected_amount` (0106, 0106a, 0107, 0108);
  T-16 pins it through the real actions.
- An out-of-date order's debt uses its edited totals until it is regenerated (accepted).
- Inherited limitation: billable nets only the merchandise refund, so tax on refunded goods stays
  in "total spent".
- Privacy: spend and debt are shown only to holders of both permissions; refusals are logged.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3; the existing-test approval above is required during Phase 3):
  - Q-0111-1 "Total spent": (a) billed net of refunds, collected or not (recommended: spent minus
    debt = actually paid); (b) collected only; (c) the dashboard's Sales (gross, refunds not netted).
  - Q-0111-2 Debt and the range: (a) "owed now" is all-time, the chart and list show the period
    (recommended: what a customer owes is a present balance); (b) debt limited to the period
    everywhere.
  - Q-0111-3 "One month" default: (a) the last 30 days ending today (recommended: same as the
    dashboard preset); (b) the current calendar month; (c) the same day last month to today.
  - Q-0111-4 Default range for Month or Year granularity: (a) the same last 30 days (recommended:
    the screen passes explicit ranges for its other presets); (b) a default per granularity.
  - Q-OWNER-9 Order list size: (a) the 200 newest with a note (recommended); (b) paginated;
    (c) unbounded.

## Technical tasks
- [ ] `CustomerPolicy::viewReport` with authorization and logging tests.
- [ ] `CustomerReportQuery` (base query, SQL constants).
- [ ] `GetCustomerReportSummary` (default range, validation, aggregate, list cap, rounding).
- [ ] `GetCustomerReportSeries` (zero-filled buckets).
- [ ] Measure, range, isolation, deleted-customer and query-count tests.
- [ ] Obtain the owner's approval, then amend the listed existing tests.
- [ ] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, backend-qa. Phase 2 (INVEST) pending.
