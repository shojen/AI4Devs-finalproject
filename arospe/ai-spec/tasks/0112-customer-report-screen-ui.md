# [0112] Customer report screen with chart (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 3 — Customers and orders (ad-hoc; requested
> by the owner on 2026-11-14, not yet in docs/PRD), delivered with Epic 6 — Invoicing. Blocked on
> [0111](0111-customer-report-read-side-backend.md) (both read actions, `viewReport`), and through
> it on [0106](done/0106-invoice-collection-order-debt-partially-paid-backend.md) (collected amount,
> debt, the "Partially paid" status). No dependents.

## Description
A report page for one customer, opened from a button in the customers list row and in the
customer detail: tiles for orders, total spent (with what was collected), average spend and what
the customer owes now (with the debt of the period), a chart of spent, collected and debt per
period, an accessible data table, and the list of the period's orders. The period defaults to the
last 30 days and can be changed with presets or two dates; it survives a reload.

Request item served (owner, 2026-11-14, translated):
> 6. In the customers section, a button opens a report of that customer's orders: total spent,
> average spend (total / orders), how much they owe, and a chart of those figures. It can be
> filtered by time; the default is one month.

Binding decisions:
- U-2 (owner decision, 2026-11-14): debt = order total minus collected invoices, on the order and in
  the customer report (precise form in 0106/0111: per order `max(0, billable - collected)`).
- Accepted defaults (owner, 2026-11-15; overturnable, recorded below): "one month" = the last 30
  days ending today (Q-0111-3 (a)); "owes now" ignores the period (Q-0111-2 (a)); total spent = net
  of refunds (Q-0111-1 (a)); at most 200 orders listed (Q-OWNER-9 (a)); route gated by
  `customers.view`, `orders.view` checked in the screen (Q-OWNER-6 (a)); grouped bars plus a debt
  line (Q-0112-1 (a)).

## Type
frontend | includes database-expert: no

## Verified facts that shaped the story
- `routes/customers.php` declares `customers` and `customers/{customer}` (`customers.show`) with
  `can:customers.view` (Livewire 4 persists `can:` middleware on `/livewire/update`, not Spatie's
  `permission:`); `customers/{customer}` must follow the literal `customers` route.
  `Customers\Show::mount(Customer $customer)` stores the id and re-reads the customer per render.
- The dashboard `SalesOverview` card (story 0086) is the pattern: a `#[Lazy]` child with `#[Url]`
  state, constant `x-data`, data in a hidden `x-ref="config"` element, one Chart.js instance per
  canvas inside `wire:ignore`, `data-chart-ready`, a creations counter on `window`, sr-only tables
  outside `wire:ignore`, money through `<x-money>`, floats only in the canvas payload, refusals
  restoring the previous value through a protected `$refusedUpdates` map. Chart.js `^4.5.1` is
  installed.
- `SalesGranularity`: `Day`, `Month`, `Year`.

## Backend contracts used (from 0111)
- `GetCustomerReportSummary::__invoke(Customer $customer, ?CarbonInterface $from = null,
  ?CarbonInterface $to = null)` returns `from` (start of the first day), `to` (start of the last
  included day), `orderCount` (every counted order, never the list length), `totalSpent`,
  `averageSpend` (`?string`), `totalCollected`, `debtInRange`, `owedNow`, `orders` (max 200 rows:
  `id`, `orderNumber`, `createdAt`, `status`, `paymentStatus`, `total`, `billable`, `debt`),
  `ordersTruncated`. Money is two-decimal strings.
- `GetCustomerReportSeries::__invoke(Customer $customer, SalesGranularity $granularity,
  ?CarbonInterface $from = null, ?CarbonInterface $to = null)` returns `points` (`bucket`, `label` =
  bucket start, `spent`, `collected`, `debt`), zero-filled, plus totals.
- Both authorize `viewReport` (`customers.view` and `orders.view`) first and log a refusal; a
  soft-deleted customer is a not-found error. Range refusals are validation errors on `range`:
  `dashboard.errors.range_invalid`, `dashboard.errors.range_too_long` (`:max`) and
  `customers.report.errors.range_both_bounds` (exactly one bound). With no range the default is the
  last 30 days, whatever the granularity.
- `CustomerPolicy::viewReport(User, Customer)`.

## Components

### `App\Livewire\Customers\Report` (routed, `#[Title]` "Customer report")
- Route: `Route::livewire('customers/{customer}/report', CustomersReport::class)
  ->middleware(['can:customers.view'])->withTrashed()->name('customers.report')`, declared after
  `customers/{customer}`.
- `mount(Customer $customer)`: (1) `LogRefusedPrivilegedAttempt->authorize('viewReport',
  $customer)` (logged 403 for a user without `orders.view`); (2) only then, a soft-deleted customer
  is a 404 (FD-1); (3) `#[Locked] public string $customerId`; (4) sanitise the URL state (bad values
  reset to defaults, never throw).
- Public state: `#[Url(as: 'from', except: '')] string $from`, `#[Url(as: 'to', except: '')]
  string $to`, `#[Url(as: 'g', except: 'day')] string $granularity`; non-URL drafts `string
  $draftFrom`, `string $draftTo` bound to the two date inputs.
- Methods: `applyPreset(string $preset)` (`last_7_days`, `last_30_days` clears both bounds,
  `this_month`, `this_year` sets Month; unknown ignored); `applyRange()` (both drafts set -> copy to
  `from`/`to` and validate through the actions; one set -> error on `range` with
  `customers.report.errors.range_both_bounds`, nothing changes; none -> back to the default);
  `updating()` / `updated()` record and restore a refused URL property (0086 mechanism); a
  non-scalar write to a URL property is 422.
- Computed `summary` (memoised): the summary action with both bounds or none; `activePreset`.
- Every request re-reads the customer; a customer deleted mid-session makes the next request 404.

### `App\Livewire\Customers\ReportChart` (routeless `#[Lazy]` child)
- Mounted as `<livewire:customers.report-chart :customer-id="$customerId" :from="$from"
  :to="$to" :granularity="$granularity" lazy :key="'chart-'.$granularity.$from.$to" />`; a change of
  range or granularity re-keys (remounts) it, so its props are `#[Locked]` and never
  client-writable.
- `mount()` stores props and, if `Gate::allows('viewReport', ...)` fails (forged snapshot), renders
  an empty card with no query and no refusal log; `render()` calls the series action and builds a
  flat payload (`locale`, `granularity`, `labels[]` pre-formatted per locale, `spent[]`,
  `collected[]`, `debt[]` as floats for plotting only) into the hidden config element (escaped JSON).
- `placeholder()`: markup only (skeleton, `role="status"`, sr text "Loading chart").

### Front-end script and helpers
- `resources/js/customer-report-chart.js` (Alpine `customerReportChart`, constant `x-data`):
  `init()` reads the config once, builds one chart after the lazy Chart.js import, sets
  `canvas.dataset.chartReady = 'true'` and `canvas.chartInstance`, increments
  `window.__customerReportChartCreations`; animation off under `data-testing` or reduced motion; a
  `MutationObserver` on the `html` class re-reads colour tokens for dark mode without a new chart;
  `destroy()` disconnects, destroys the chart and removes the ready flag.
- `resources/js/chart-support.js` (shared loader and small helpers); `sales-chart.js` is not
  touched.
- `app/Concerns/FormatsSalesBucketLabels.php`: the dashboard's bucket-label formatting extracted so
  both screens print identical labels; `SalesOverview` uses it with unchanged behaviour.
- `resources/css/app.css`: tokens `--chart-spent`, `--chart-collected`, `--chart-debt` (light:
  indigo-600, teal-600, amber-600; dark: indigo-400, teal-400, amber-400).

## View and `data-test` hooks
| Hook | Element |
| --- | --- |
| `report-customer-{id}` | report icon button in a customers-list row (only with both permissions) |
| `customer-report-link` | "Customer report" button in the customer detail header (same rule) |
| `report-subtitle` | the resolved period printed from `from` / `to` |
| `report-granularity` | Day / Month / Year segmented control |
| `report-preset-{key}` | preset buttons (`aria-pressed` on the active one) |
| `report-range-from`, `report-range-to`, `report-range-apply` | custom range inputs and Apply |
| `report-range-error` | the range error message (`flux:error name="range"`) |
| `report-kpi-orders`, `report-kpi-spent`, `report-kpi-collected`, `report-kpi-average`, `report-kpi-debt`, `report-kpi-debt-range` | tiles and sub-lines |
| `report-chart-canvas` | the canvas (`aria-hidden`; carries `data-chart-ready="true"` when built) |
| `report-chart-empty` | "No orders in this period" box |
| `report-chart-data` | the `<details>` "Show chart data" with its table |
| `report-orders-table`, `report-orders-row-{id}` | the orders table and its rows |
| `report-orders-truncated` | the "showing the 200 newest" note |
| `report-no-orders` | "This customer has no orders yet" state |

Testability contracts that ship with the story: `data-chart-ready`, `canvas.chartInstance`,
`window.__customerReportChartCreations`.

Layout: filter bar (`flex flex-wrap items-end gap-4`), tiles `grid gap-3 sm:grid-cols-2
xl:grid-cols-4`, chart full width `relative h-72`, the orders table scrolls inside its own
`overflow-x-auto` wrapper only; card classes and dark variants mirror the dashboard card. Heading
"Report: {customer name}" with a back link to `customers.show`.

## Lang keys (`lang/en/customers.php` and `lang/es/customers.php`, group `report`, key for key)
`title`, `heading` (`:name`), `back`, `button` ("Customer report"), `subtitle` (`:from`, `:to`),
`granularity.day|month|year`, `presets.last_7_days|last_30_days|this_month|this_year`,
`range.from`, `range.to`, `range.apply`, `kpi.orders`, `kpi.spent`, `kpi.collected` (`:amount`),
`kpi.average`, `kpi.average_none`, `kpi.owes_now`, `kpi.owed` (word shown with a non-zero debt),
`kpi.debt_in_range` (`:amount`), `kpi.definitions.*` (one per tile; owes-now says it ignores the
period), `chart.title`, `chart.loading`, `chart.empty`, `chart.data_toggle`, `chart.table_caption`,
`chart.series.spent|collected|debt`, `table.order|date|status|payment|total|billable|debt`,
`table.empty`, `table.truncated` (`:count`), `no_orders`, `summary_sentence` (aria-live text), and
`errors.range_both_bounds` (added by 0111, reused here). Range errors otherwise reuse
`dashboard.errors.*`. Existing groups gain nothing else.

## Decisions
- **FD-1 Authorization before existence (QA G-1).** The route binds the customer including
  soft-deleted rows (`withTrashed()`), `mount()` authorizes `viewReport` first (logged 403), and
  only then answers 404 for a deleted customer. A user without `orders.view` therefore never learns
  about a deletion from the report address, matching 0111's order of operations. Confirm in the
  Phase 3 spike that `Route::livewire(...)->withTrashed()` applies to the bound parameter; if not,
  bind by id and resolve with `withTrashed()` in `mount()`.
- **FD-2 One-bound message (QA G-2).** A custom range with only one date is refused under the
  range controls with `customers.report.errors.range_both_bounds`, the key 0111 raises; the screen
  checks the drafts first, so the action is never called with a single bound, and the URL path is
  sanitised to defaults if it carries only one bound.
- **FD-3 Draft dates plus Apply (QA G-5).** Unlike the dashboard's live single-bound inputs, the
  two dates are applied together, because live typing of one bound would be refused and reverted.
- **FD-4 Button in both places, hidden without permission.** The list row and the detail header
  show the button only when `Gate::allows('viewReport', $customer)`; otherwise it is absent (not
  disabled), so no report surface is disclosed. No sidebar entry.
- **FD-5 Lazy chart child re-keyed per range.** Keeps tiles and table instant, keeps child props
  locked, reuses the proven destroy path.
- **FD-6 Chart form (Q-0112-1 (a)).** One EUR axis: grouped bars "Spent" and "Collected" per
  bucket, a dashed line with square markers for "Debt"; legend with point styles, so meaning never
  depends on colour alone; y from zero, ticks in EUR without decimals, tooltip lists the three
  values with two decimals; labels per granularity (`D MMM`, `MMM YYYY`, `YYYY`).
- **FD-7 Tiles.** "Owes now" is the debt tile (amber, bold and the word "owed" when above 0.00,
  compared with `bccomp`), with "Debt in this period" as its sub-line; "Total spent" shows the
  collected amount as its sub-line; the average shows a dash with a hint when no order is counted.
  No arithmetic in PHP or Blade: every figure comes from the summary.
- **FD-8 Accessible alternative.** Canvas `aria-hidden`; a visible collapsed `<details>` "Show
  chart data" outside `wire:ignore` holds a real table (caption, `scope` headers, one row per
  bucket, money through `<x-money>`); a sr-only `aria-live` sentence summarises the period, orders,
  spent, average and owes now.
- **FD-9 Empty states.** Empty period: tiles 0 / 0.00, average dash, chart box "No orders in this
  period" (the canvas wrapper hidden by `x-show`, never an `@if` around the canvas), table empty
  state, "owes now" still shown with its explanation. Customer without any order: "This customer
  has no orders yet" with a link back.
- **FD-10 Orders table.** Columns order (link to `orders.show`), date, status and payment badges
  (the orders colours, including the orange `partially_paid` arm as a fourth copy of the arm),
  total, net of refunds, debt (amber above 0.00); newest first; the truncation note when more than
  200. Not paginated.
- **FD-11 Loading.** Chart skeleton on (re)mount; `wire:loading` text, `opacity-60` and `aria-busy`
  on the tile grid and table while `applyPreset`, `applyRange` or `granularity` run.
- **FD-12 Partial buckets accepted (QA G-3).** Month or Year granularity on the default 30-day range
  shows partial buckets; the subtitle states the dates and the Month/Year presets send explicit
  ranges.

## Gherkin
```gherkin
Feature: Customer report

  Scenario: A store manager opens a customer's report from the customer list
    Given a store manager with permission to view customers and orders, and a customer with orders
    When she opens the report from the customer's row
    Then she sees the customer's total spent, average spend and what the customer owes

  Scenario: A store manager opens the report from the customer detail
    Given a store manager with permission to view customers and orders
    When she opens the report from the customer detail
    Then the report for that customer is shown

  Scenario: A user who cannot view orders has no report button
    Given a sales assistant with permission to view customers but not orders
    When she opens a customer detail
    Then it does not offer the customer report

  Scenario: A user who cannot view orders cannot open the report address
    Given a sales assistant with permission to view customers but not orders
    When she opens a customer's report address
    Then access is denied and the refusal is logged

  Scenario: A user who cannot view orders learns nothing about a deleted customer
    Given a sales assistant with permission to view customers but not orders, and a deleted customer
    When she opens that customer's report address
    Then access is denied rather than not found

  Scenario: The report covers the last 30 days by default
    Given a store manager with permission to view customers and orders, and orders 10 and 40 days old
    When she opens the report without choosing a period
    Then the report covers the last 30 days and counts only the 10-day-old order

  Scenario Outline: A store manager picks a preset period
    Given a store manager with permission to view customers and orders, and orders spread over the year
    When she chooses the period "<preset>"
    Then the figures and the chart cover <covers>

    Examples:
      | preset      | covers                         |
      | Last 7 days | the last seven days            |
      | This month  | 1 to 15 November 2026          |
      | This year   | 1 January to today, by month   |

  Scenario: A store manager chooses a custom period
    Given a store manager with permission to view customers and orders
    When she enters 1 October 2026 and 31 October 2026 and applies them
    Then the report covers October and the address carries the dates

  Scenario: A period with only one date is not applied
    Given a store manager viewing a customer's report
    When she enters only a start date and applies
    Then she is told both dates are needed
    And the report still shows the previous period

  Scenario: The chosen period survives a reload
    Given a store manager who chose 1 to 31 October grouped by month
    When she reloads the page
    Then the same period and grouping are shown

  Scenario: A period with no orders says so
    Given a store manager viewing a customer whose orders are all from last year, one still unpaid
    When she chooses this month
    Then the tiles show zero and the chart area says there are no orders in the period
    And the amount the customer owes is still shown, explained as counting every order

  Scenario: A customer with no orders at all
    Given a store manager with permission to view customers and orders, and a customer who never ordered
    When she opens the report
    Then it says the customer has no orders yet

  Scenario: The chart has a text alternative
    Given a store manager viewing a customer's report with orders
    When she opens "Show chart data"
    Then a table lists each period with spent, collected and debt

  Scenario: The chart follows the dark theme
    Given a store manager viewing a customer's report with orders
    When she switches to the dark theme
    Then the chart colours change and stay readable

  Scenario: A deleted customer has no report
    Given a store manager with permission to view customers and orders, and a deleted customer
    When she opens that customer's report address
    Then the page is not found
```

## Files to create/modify
- `app/Livewire/Customers/Report.php`, `resources/views/livewire/customers/report.blade.php`.
- `app/Livewire/Customers/ReportChart.php`, `resources/views/livewire/customers/report-chart.blade.php`.
- `routes/customers.php` — `customers.report`.
- `app/Livewire/Customers/Index.php`, `resources/views/livewire/customers.blade.php` — row
  `canViewReport` and the button; `app/Livewire/Customers/Show.php`,
  `resources/views/livewire/customers/show.blade.php` — computed `canViewReport` and the header link.
- `resources/js/customer-report-chart.js`, `resources/js/chart-support.js`, imported from
  `resources/js/app.js`; `resources/css/app.css` tokens.
- `app/Concerns/FormatsSalesBucketLabels.php`; `app/Livewire/Dashboard/SalesOverview.php` uses it.
- `lang/{en,es}/customers.php` — `report` group.
- Tests below; `tests/Support/Customers/CustomerReportUi.php` (hook map).

## Tests to perform
Time frozen at 2026-11-15 in the app timezone (Europe/Madrid). Browser assertions read
`canvas.chartInstance` data, never pixels; waits are polled conditions (no `networkidle`, no bare
sleeps); chart flows wrapped in the dashboard's `retry(3, ..., 250)` pattern.

Component level (`tests/Feature/Customers/Report/`)
- [ ] T-1 Tiles show exactly the strings of `GetCustomerReportSummary` (orders, spent, collected,
  average, owes now, debt in range) for a fixture with paid, pending and partially paid orders and
  one unpaid order outside the period (so owes now differs from debt in range); one fixed case
  against hand-computed literals.
- [ ] T-2 No float in markup (no `\d+\.\d{3,}` or exponent in tiles and table); three orders of
  33.33 show `99.99`.
- [ ] T-3 No range: subtitle is today - 29 .. today; day-30 and day-31 orders included/excluded;
  the midnight Madrid-vs-UTC boundary.
- [ ] T-4 Presets dataset: `from`/`to`/`g` set as defined, `aria-pressed` on the active one,
  unknown preset ignored, last 30 days clears both bounds.
- [ ] T-5 Both drafts applied: URL properties set, figures change; one draft: error on `range` with
  `customers.report.errors.range_both_bounds`, `from`/`to` and the summary unchanged.
- [ ] T-6 Inverted range, over the bucket cap, year out of window (by Apply or URL write):
  translated error, previous data kept, chart child not re-keyed.
- [ ] T-7 `withQueryParams` bad values (`from=garbage`, only `from`, `g=week`, `to=2026-02-31`):
  reset to defaults, no exception; a valid pair applied.
- [ ] T-8 Spy on the actions: never called with a single bound from any UI or URL path.
- [ ] T-9 Button in the list row and the detail header present (with `href` to `customers.report`)
  for both permissions and Super Admin; absent for `customers.view` only.
- [ ] T-10 `customers.view` only: GET and mount refused 403, one refusal warning, no figure in the
  response.
- [ ] T-11 Deleted or unknown customer: 404 for an authorized user; 403 (not 404) for a
  `customers.view`-only user (FD-1); deleted mid-session then a preset: 404.
- [ ] T-12 Empty period: tiles 0 / 0.00, average dash with hint, table empty, chart empty box, owes
  now shown with its explanation.
- [ ] T-13 Customer with no orders: "no orders yet" with a link back, no error.
- [ ] T-14 205 orders: 200 rows, truncation note with 205, tiles over all 205.
- [ ] T-15 Chart payload: one label per zero-filled bucket; arrays equal the series action; sums
  equal the summary's totals; a `<script>` customer name never appears unescaped in the config.
- [ ] T-16 Canvas `aria-hidden`; `<details>` table with caption, `scope` headers, one row per
  bucket through `<x-money>`; the aria-live sentence present.
- [ ] T-17 Changing preset or granularity changes the child key; parent and child agree for the
  same range.
- [ ] T-18 Writes to `customerId`, array writes to `from`, client writes to child props: locked or
  422; a forged child snapshot by a `customers.view`-only actor renders an empty card, no query, no
  refusal log.
- [ ] T-19 Query budget with 200 rows: summary 3 domain queries, series 2, no per-row query.
- [ ] T-20 en/es parity of the `report` group; range errors use the dashboard keys and the
  one-bound key.
- [ ] T-21 Order rows link to `orders.show`; the `partially_paid` badge arm renders; per-row debt
  matches the order.
- [ ] T-22 `SalesOverview` labels unchanged after extracting `FormatsSalesBucketLabels` (its
  existing tests pass untouched).

Browser level (`tests/Browser/Customers/CustomerReportTest.php`)
- [ ] B-1 From the list button and from the detail link (`wire:navigate`): the report loads with no
  JavaScript error; navigate away and back: one chart creation per visit.
- [ ] B-2 Chart ready: `data-chart-ready` true, `chartInstance` labels equal the expected buckets,
  three datasets with the seeded values (compared as floats of the same decimals), creations = 1.
- [ ] B-3 A preset: after a polled wait the chart data equals the new range, creations +1; the URL
  carries `from`, `to`, `g`; reload restores tiles and chart.
- [ ] B-4 Only a start date and Apply: the error visible, chart and tiles unchanged, creations
  unchanged; both dates: the chart updates.
- [ ] B-5 Empty period: canvas wrapper hidden, empty box visible, owes-now tile visible.
- [ ] B-6 Theme toggle: dataset colours switch to the dark tokens without a new chart instance;
  back to light restores them.
- [ ] B-7 Keyboard: Enter opens "Show chart data"; tile definition buttons reachable by Tab.
- [ ] B-8 Phone viewport: tiles one column, chart full width, table scrolls inside its wrapper, no
  horizontal page scroll.
- [ ] B-9 Tooltip hover shows the three values with two decimals (isolated test).

## Expected outcome
A manager allowed to see customers and orders opens any active customer's report from the list or
the detail, sees exact figures and a readable chart for the last 30 days or any chosen period, and
can share or reload the address with the same period; others never see the button nor the data.

## Acceptance criteria
- [ ] The button appears in both places only with both permissions (FD-4).
- [ ] Authorization precedes existence (FD-1); refusals logged.
- [ ] Default period, presets, custom range with Apply, URL persistence and refusals keeping the
  previous data work as specified (FD-2, FD-3).
- [ ] Tiles, chart, data table and orders table show the backend's figures exactly, money through
  `<x-money>` (FD-6..FD-10).
- [ ] Empty states and loading follow FD-9 and FD-11.
- [ ] The listed hooks and testability contracts exist.
- [ ] en/es lang parity.

## Definition of Done
- [ ] Tests written and green (component and browser)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/routes.md` (`customers.report`), the customers
  API doc (the report screen), the 0086 convention doc (second lazy chart, shared bucket labels)
- [ ] Acceptance criteria met
- [ ] `npm run build` passes; Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream: [0111](0111-customer-report-read-side-backend.md);
  [0106](done/0106-invoice-collection-order-debt-partially-paid-backend.md) for the `partially_paid`
  case. No new dependency (Chart.js installed).
- Tampering: `customerId` and the child props are locked; the three URL properties are validated
  through the actions on every update; spend and debt appear only after both permissions.
- Risk: the lazy child and Alpine lifecycle after `wire:navigate`; covered by the destroy path and
  B-1; spike early in Phase 3.
- Risk: `withTrashed()` on a Livewire route (FD-1) to be confirmed in the spike, with the stated
  fallback.
- Note: chart data are floats for plotting only; exact cents are asserted on tiles and table.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0112-1 Chart form: (a) grouped bars for spent and collected with a debt line (recommended:
    one money axis, meaning not only by colour); (b) one line chart of all three.
  - Q-0112-2 CSV export of the report: (a) not in this story (recommended: not requested); (b) add.
  - Q-0112-3 Shared payment-status badge: (a) the report adds a fourth copy of the badge arm
    (recommended: no change to other screens in this story); (b) extract one badge component used by
    all four screens (a separate refactor with its own test updates).
  - Q-0111-3 "One month": (a) last 30 days ending today (recommended); (b) the calendar month;
    (c) same day last month to today.

## Technical tasks
- [ ] Spike: `withTrashed()` on the Livewire route; lazy child lifecycle after `wire:navigate`.
- [ ] Route, `Report` component (mount gate, URL state, presets, drafts, refusals), view and hooks.
- [ ] `ReportChart` child, payload, placeholder, `<details>` table; JS component and support file;
  colour tokens.
- [ ] `FormatsSalesBucketLabels` extraction (behaviour preserved).
- [ ] Buttons in `Customers\Index` and `Customers\Show`.
- [ ] Lang en/es; component tests; browser tests with the hook map.
- [ ] `npm run build`; Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa. Phase 2 (INVEST) pending.
