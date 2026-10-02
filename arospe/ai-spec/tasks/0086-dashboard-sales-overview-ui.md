# [0086] Dashboard home — sales overview card with filters and Chart.js charts (frontend)

> **Status: Phase 1 draft (split out of 0083 on 2026-10-01).** Created by the owner-approved split of [0083](done/0083-dashboard-home-overview-ui.md)
> after its Phase 2 FAIL (size); the sales-card content, decisions and the Phase 2 review items that concern it are carried here, rewritten
> against the **merged** backend [0082](done/0082-dashboard-home-overview-backend.md). The Three Amigos debate was held for the original 0083
> (frontend-expert + frontend-qa, 2026-09-30) and the sales card was amended by the owner on 2026-09-30; this story has **not** had its own
> Phase 2. **⚑** marks facilitator defaults the owner has not ratified. **Blocked on [0083](done/0083-dashboard-home-overview-ui.md)** (it adds the
> card to the `Overview` page that 0083 creates and extends the lang file 0083 extends).

## Description

Add to the dashboard home a **"Sales overview" card** (a child Livewire component, `App\Livewire\Dashboard\SalesOverview`) that shows the three
measures defined by the backend (0082 D-6) for a chosen period, filtered by a shared order-status filter:

1. **Filter bar (shared by everything below):** granularity **Day / Month / Year**, **presets**, a **custom date range** and **status chips**
   (*Pending, Processing, Shipped, Delivered, Cancelled*; *Cancelled* off by default — this is the owner's old "Include cancelled orders" checkbox).
2. **KPI strip — three tiles:** **Sales** (total sold, refunds not netted), **Real income** (money collected, net of refunds), **Orders** (count), each with a
   one-line definition in a tooltip.
3. **Chart A — "Sales vs real income":** a Chart.js **line** chart with two series.
4. **Chart B — "Orders by status":** a Chart.js **stacked bar** chart, one segment per selected status.

Everything updates without a page reload. It is shown only to an actor who holds `orders.view` (the series actions are gated by it).

## Type

`frontend | includes database-expert: no` — plus **one approved dependency**, `chart.js` (owner decision 2026-09-30).

## Verified facts that shaped the story

(against the merged tree, 2026-10-01)

- **Backend contract (0082):** `GetSalesSeries` → `array{granularity, from, to, statuses: list<OrderStatus>, totalSales: string, totalIncome: string, points: list<array{bucket, label: CarbonImmutable, sales: string, income: string}>}`
  and `GetOrdersSeries` → `array{…, totalOrders: int, points: list<array{bucket, label, total: int, byStatus: array<string,int>}>}`, called as
  `(SalesGranularity, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null)`. `to` is the **last inclusive day (start of that day) in Madrid**; `label` is the bucket's first
  day; `bucket` is the machine key (`Y-m-d` / `Y-m` / `Y`); `byStatus` is zero-filled for **every selected status** and, because the resolver keeps the caller's order, follows
  the order the component passes. Caps: **Day 366 · Month 120 · Year 50 buckets**. `null` statuses = `OrderStatus::defaultDashboardSet()` (every case except `Cancelled`); a normalized
  empty set is refused. Years outside **1000..9998** are refused with the **existing** `range_invalid` message ("start must not be after end"), which is misleading for that case.
  Validation errors are `ValidationException` with keys `range` and/or `statuses`, **already translated** (`__('dashboard.errors.*')`, `lang/{en,es}/dashboard.php`).
- **Caller contract (0082 D-1):** the series actions authorize through the logging wrapper and throw `AuthorizationException`; a caller that is not authorized must not call them on the **render path**.
- **Lang files exist** (0082) with an `errors` group pinned to exactly three keys by `tests/Feature/Dashboard/DashboardLangParityTest.php`; 0083 adds new top-level groups; this story adds a
  **`sales` group** (new keys outside `errors`, so the pin stays green).
- **Livewire 4.3.3**; `#[Lazy]` is unused in the repo, `#[Url]` is used in `BlogPosts/Index.php`; Alpine ships with Livewire and components register inside `alpine:init`
  (`resources/js/app.js`); `app.js` is the only JS the admin layout loads (`partials/head.blade.php:14`; `vite.config.js` has 5 inputs, but no new entry is needed).
- **Flux free has no date picker** (`flux:date-picker` is Pro); `flux:radio.group variant="segmented"` is used in `appearance.blade.php:5`; `flux:skeleton`, `flux:input`, `flux:checkbox`, `flux:tooltip` exist.
- **Dark mode is class-based** (`@custom-variant dark`, `app.css:9`; `.dark` block `app.css:32`; toggled by `@fluxAppearance`); there are no chart tokens.
- `<x-money>` prints `€ {{ $amount }}` with no formatting; `chart.js` is not in `package.json`.
- **A Livewire PHP `dispatch()` fires a browser event on the dispatching component's root element, which bubbles up**; a listener on a *descendant* of that root never receives it
  (hence D-4's `$wire.$on` listener).
- CI runs the Browser suite as part of `php artisan test`.

## Decisions

### D-1 — Component structure and gating

`SalesOverview` is a child of `Overview` (added by this story to 0083's page, rendered only when `Gate::allows('viewAny', Order::class)`), `#[Lazy]` with a `flux:skeleton` placeholder
(the repo's first `#[Lazy]`; the first red test is a **spike** proving a lazy child reads the page's query string — see R-1 for the eager fallback).

- **Render path** (`render()`/computeds): the series are computed **only when `Gate::allows('viewAny', Order::class)`**, otherwise the card renders nothing and nothing is logged.
- **Update path** (a filter changes): the component calls the two series actions **unconditionally**, so an unauthorized or revoked actor posting a filter update is **refused and logged by the
  action** (`AuthorizationException` → 403) — never silently ignored. A permission revoked after the page loaded is therefore honoured on the next filter change.
- Computeds are parameterless `#[Computed]` methods resolving actions with `app(Action::class)`; **no model, Collection, enum or `CarbonImmutable` is a public property**.

### D-2 — Filter state, parsing and validation (one consolidated block)

Public (client-writable, scalars only): `#[Url(as:'g')] string $granularity = 'day'`, `#[Url(as:'from')] string $from = ''`, `#[Url(as:'to')] string $to = ''`,
`#[Url(as:'s')] array $statuses = <default set values>`.

- **`from`/`to` model:** `''` means **"the granularity's default range"**, resolved at read time (so the default is omitted from the URL, which Livewire does only when a value equals the
  property's initial value). **Default ranges** (application timezone, all inclusive of today): Day = the last 30 days (30 buckets); Month = the 1st of the month 11 months ago through today (12 monthly
  buckets ending in the current month); Year = 1 January four years ago through today (5 yearly buckets ending in the current year). **Changing the granularity resets `from`/`to` to `''`** ⚑.
- **Presets** (`wire:click="applyPreset('…')"`, computed server-side, so testable) set an **explicit** `from`/`to` **and the granularity**: *Last 7 days* and *Last 30 days* (inclusive of today) and
  *This month* → **Day**; *This year* → **Month** ⚑. The active preset is highlighted by comparing the resolved range and granularity.
- **Rules** (a single `rules()`/`updating` block, all keys translated under `sales.*`): `granularity` → `Rule::enum(SalesGranularity::class)` (converted with `tryFrom`, **never `from`**);
  `statuses` → `array|max:5` with `statuses.*` → `Rule::enum(OrderStatus::class)`; `from`/`to` → strict `Y-m-d`, parsed with `CarbonImmutable::createFromFormat('!Y-m-d', $v, config('app.timezone'))` inside a try/catch
  that turns Carbon's `InvalidFormatException` into a validation error; **years outside 1000..9998 are refused by the component first with its own clear message** (`sales.range_year_window`), because the
  action would reuse "start must not be after end"; `to` before `from` is **left to the action's `range_invalid`** (no duplicate `after_or_equal` rule); a cleared date input falls back to `''` (the default range).
- **Before calling an action** the component maps the status strings to `OrderStatus` with `tryFrom` (unknown values dropped) and **sorts them in `OrderStatus::cases()` order**, so the legend, the stack and
  `byStatus` order never depend on click order. An empty selection made in the UI is refused with **the backend's already-translated `statuses_required` message**, taken from the action's exception
  (`ValidationException::errors()['statuses'][0]`); `range` messages likewise come straight from `errors()['range'][0]` — **no duplicate `sales.range_error` key**.
- **Bad URL state** (`?g=garbage`, `from=not-a-date`, `to=2026-13-45`, empty or array values, only one bound, year 99999, `s[]=garbage`, more than five `s[]` entries) is validated in `mount()` and **falls back to the
  defaults** — HTTP 200, no exception, no 500.
- **Keeping the previous data on an invalid change:** the new value is validated in the property's `updating*` hook **before it is assigned**; on failure the hook throws the `ValidationException`, so the property
  **keeps its previous (last valid) value**, the error shows, and no event is dispatched — the KPI tiles and sr-only tables (Blade, recomputed from those properties) therefore keep rendering the previous data too.
  *Spike:* the first test proves this mechanism; fallback is a validated public `applied*` copy of the four values that the computeds read.
- Rapid repeated changes must end in the state a fresh load of that URL would produce.

### D-3 — Layout and UX (⚑, owner-confirmed in outline 2026-09-30)

Why **two charts**, not one with a toggle: money and counts have different units and scales (orders in tens beside sales in thousands of euros would flatten one series), and a toggle hides the comparison the owner asked for.
Top to bottom inside one widget card "Sales overview":

1. **Filter bar:** a segmented Day/Month/Year (`flux:radio.group variant="segmented"`), preset buttons, two native `<flux:input type="date" wire:model.live.blur>` in a `flux:field` with `flux:error name="range"`,
   `max` = today on `to`, and the status chips (multi-select toggles in the colors of the order-status badges, *Cancelled* off by default, with a "Reset" affordance). One filter, one URL, one request.
2. **KPI strip:** Sales and Real income through `<x-money>`; Orders as an integer. The Real-income tile shows the hint **"Collected: N% of sales"** where **N = `round(income / sales × 100)` as an integer (half up)**, shown only
   when sales > 0; when income is 0 while sales > 0 it shows instead the neutral hint **"Income is counted once orders are paid"** (nothing in the application marks an order paid until [0084](done/0084-order-mark-as-paid-backend.md)/[0085](done/0085-order-mark-as-paid-ui.md)).
3. **Chart A — "Sales vs real income":** line, two series distinguished by color **and** line style/marker (Sales = muted, dashed, circle markers; Real income = accent, solid, square markers); Y axis in EUR, tooltip shows both values and the difference.
4. **Chart B — "Orders by status":** stacked bar, one dataset per **selected** status in `OrderStatus::cases()` order, integer Y axis from 0, tooltip with the breakdown and the bucket total. The legend is shown; clicking a legend entry hides
   that series **locally** and does **not** change the server filter (the chips do) — the help text says so.
5. **Responsive:** the charts sit side by side from `xl`, stacked below; KPI tiles wrap to one column on phones; the chips scroll horizontally.
6. **Copy:** the measures are named **"Sales"**, **"Real income"** and **"Orders"** (never "Revenue" or "cash received"); the chart/table title for chart A is **"Sales vs real income"**.

**Empty and edge states:** an all-zero period → both charts show an empty message (the canvas wrapper is hidden with `x-show`, the chart instance survives); income-only-zero → chart A still draws both lines (income at 0) with the hint above;
a selected status with no orders still appears in the legend with zeros.

### D-4 — Chart.js integration (owner-approved dependency; rules **per chart**)

Add `chart.js` to `package.json`/`package-lock.json`; **record the bundle-size delta and the `npm audit` state in the PR**.

- **Two Alpine components**, `moneyChart` and `ordersChart`, registered in `resources/js/sales-chart.js` inside `document.addEventListener('alpine:init', …)` and imported from `app.js` with **one line** (no new Vite entry).
  Chart.js is loaded with a **dynamic `import('chart.js')`** inside `init()` (code-split; guard `destroy()` running before the import resolves). Register only: `LineController, LineElement, PointElement, BarController, BarElement,
  CategoryScale, LinearScale, Tooltip, Legend` (no `chart.js/auto`); the stacked bars use `stacked: true` on both scales.
- **Markup per chart:** a root `<div x-data="moneyChart(@js($this->moneyPayload))">` (resp. `ordersChart`), **`wire:ignore` only on the canvas wrapper**; the empty-state message and the `wire:loading` overlay (`wire:target="granularity,from,to,statuses,applyPreset"`) are
  **siblings** of it, toggled with `x-show`/`hidden` — never a Blade `@if` around the canvas (that would destroy the instance). **Initial data** goes through `x-data` (a dispatch during a lazy mount request is lost).
- **Updates — event delivery:** the component dispatches **one** event `sales-overview-updated` from PHP (`$this->dispatch('sales-overview-updated', money: …, orders: …)`); because it fires on `SalesOverview`'s root and each chart root is a **descendant**,
  each Alpine component subscribes in `init()` with **`$wire.$on('sales-overview-updated', …)`** (the component-scoped Livewire listener, verified against the Livewire 4.3 docs during the spike) — **not** an `x-on` on the chart root.
  **Payload (scalars only):** `{ locale, granularity, money: { labels: string[], sales: number[], income: number[] }, orders: { labels: string[], datasets: { status: string, label: string, data: number[] }[] } }` — the numbers are
  floats **for plotting only** (the KPI tiles and the tables stay decimal strings through `<x-money>`/Blade).
- **Instance lifecycle:** each chart is created **once** in `init()`, kept **outside Alpine's reactivity** (a closure variable or `Alpine.raw()`; a Proxy-wrapped Chart loops), updated in place (`chart.data.labels = …; chart.update()`; the orders chart
  replaces its **datasets** inside `update()` when statuses change; `'none'` animation under `prefers-reduced-motion`), and **destroyed in `destroy()` together with the theme observer** so `wire:navigate` away and back raises no "Canvas is already in use".
- **Testability contract (names are fixed so tests can be written):** each canvas has `data-test="money-chart-canvas"` / `data-test="orders-chart-canvas"`; once the chart exists the component sets **`data-chart-ready="true"`** and exposes the instance as
  **`canvas.chartInstance`**; every `new Chart(...)` increments **`window.__salesChartCreations`** (so a test can prove no re-creation); animation is disabled when `document.documentElement.dataset.testing` is set (the layout sets it under the `testing` environment).
- **Theme:** new CSS tokens in `resources/css/app.css`, light values on `:root` and dark overrides in the existing `.dark` block: `--chart-text`, `--chart-grid`, `--chart-sales`, `--chart-income` and one per status
  (`--chart-status-pending|processing|shipped|delivered|cancelled`, **the same hues as the order-status badge's color map**, read from the badge component 0083 extracts). The component reads them at init and again from a **`MutationObserver` on `<html class>`** (what `@fluxAppearance`
  toggles), followed by `chart.update()` on the **same instance**; `Chart.defaults.font.family` comes from `--font-sans`.
- **Formatting:** category scale (no date adapter); **x labels arrive pre-formatted from the server per locale** with Carbon `isoFormat` on each point's `label` — **Day `D MMM`** (en "1 May", es "1 may"), **Month `MMM YYYY`**, **Year `YYYY`** —
  and the sr-only tables use the same labels; Y ticks and tooltips use `new Intl.NumberFormat(payload.locale, {style: 'currency', currency: 'EUR'})` with the **locale from the payload, never `navigator.language`**.
- **Text alternative:** **two** Blade-rendered `<table class="sr-only">` (caption, `th scope="col"`, one row per bucket; chart B has a column per selected status), **outside** `wire:ignore`, fed by the same computeds, so Livewire updates them natively; the canvases are
  `aria-hidden`; an `aria-live="polite"` line summarizes the totals. These tables are the assertion surface of the Livewire tests.
- **Security:** all labels are plain text rendered through Blade `{{ }}` or `@js()` payloads; never `x-html`, `{!! !!}` or label interpolation into JS strings.

### D-5 — i18n (new `sales` group; no change to `errors`)

Extend `lang/en/dashboard.php` and `lang/es/dashboard.php` with a **`sales` group** (identical key sets and placeholders in both): `title`, `kpi.sales|income|orders` and their one-line `definition` strings, `kpi.collected` (`:percent`), `kpi.income_hint`, `granularity.day|month|year`,
`presets.last_7_days|last_30_days|this_month|this_year`, `from`, `to`, `statuses` (chip labels come from `OrderStatus::label()`), `reset`, `chart_a_title`, `chart_b_title`, `legend_help`, `empty`, `range_year_window` (`:min`, `:max`),
`date_invalid`, `statuses_max`, table captions/column headers, `loading`. **No `sales.range_error`**: range and status refusals are the backend's already-translated messages. The pinned `errors` group and its parity test are **not touched**.

## Open questions closed

| Q | Resolution |
| --- | --- |
| Chart library | Chart.js, dynamic import, tree-shaken (owner) |
| One chart or two | two, plus a KPI strip (owner, 2026-09-30) |
| Default range | Day · last 30 days; Month · 12 months; Year · 5 years |
| Event delivery | `$wire.$on` in each Alpine component (D-4) |
| Date picker | native date inputs (Flux free has none) |

Still **⚑ owner to confirm:** the two-chart layout and definitions; the granularity-resets-range rule and the preset→granularity mapping; the exact default ranges; the KPI percent hint (integer, half up); the label formats; keeping an eager fallback if the `#[Lazy]`+`#[Url]` spike fails.

## Gherkin

"Order manager" is a glossary role (may view orders); the scenarios that need `orders.edit` or other rights say so. Chart assertions go through the **sr-only data tables** (a Livewire test cannot see the canvas); the Browser suite covers the canvas.

```gherkin
Scenario: The sales overview starts on the last 30 days per day without cancelled orders
  Given Olga, an order manager
  When Olga opens the dashboard
  Then both charts cover the last 30 days per day
  And every status chip except "Cancelled" is selected

Scenario: The sales overview is shown only to someone who may see orders
  Given Uma, a user manager who may not see orders
  When Uma opens the dashboard
  Then the dashboard shows no sales overview

Scenario: The KPI strip summarizes the period
  Given Olga, an order manager, and in the period a paid order of 100, a paid order of 50 partly refunded by 20 and an unpaid order of 30
  When Olga opens the dashboard
  Then the Sales tile reads 180
  And the Real income tile reads 130
  And the Orders tile reads 3

Scenario: The Real income tile shows how much of the sales was collected
  Given Olga, an order manager, and in the period a paid order of 100 and an unpaid order of 100
  When Olga opens the dashboard
  Then the Real income tile says 50% of sales was collected

Scenario: The Real income tile explains itself when nothing has been paid
  Given Olga, an order manager, and only unpaid orders in the period
  When Olga opens the dashboard
  Then the Real income tile reads 0
  And a hint explains that income is counted once orders are paid

Scenario: Sales and real income are two series of one chart
  Given Olga, an order manager, and a paid order of 100 and an unpaid order of 60 on the same day
  When Olga opens the dashboard
  Then the "Sales vs real income" chart shows 160 of sales and 100 of real income for that day

Scenario: The orders chart breaks each day down by status
  Given Olga, an order manager, and on 1 May 2 pending orders and 1 shipped order
  When Olga opens the dashboard for the period containing 1 May
  Then the "Orders by status" chart shows 3 orders for 1 May: 2 pending and 1 shipped

Scenario: Cancelled orders are counted when the Cancelled chip is selected
  Given Olga, an order manager on the dashboard, with a delivered order of 100 and a cancelled order of 40 on the same day
  When Olga selects the "Cancelled" chip
  Then the sales for that day read 140
  And the orders for that day read 2

Scenario Outline: The status chips narrow every figure
  Given Olga, an order manager on the dashboard, with a paid delivered order of 100, a paid shipped order of 50 and an unpaid pending order of 20 on the same day
  When Olga keeps only the <chips> chips selected
  Then sales read <sales>, real income reads <income> and orders read <orders>
  Examples:
    | chips              | sales | income | orders |
    | Delivered          | 100   | 100    | 1      |
    | Delivered, Shipped | 150   | 150    | 2      |
    | Pending            | 20    | 0      | 1      |

Scenario: At least one status must stay selected
  Given Olga, an order manager on the dashboard with only the "Delivered" chip selected
  When Olga deselects the "Delivered" chip
  Then Olga is told to pick at least one status
  And both charts and the KPI tiles keep their previous data

Scenario: Statuses appear in a fixed order whatever order they were selected in
  Given Olga, an order manager on the dashboard
  When Olga selects the chips "Shipped", then "Pending", then "Delivered"
  Then the orders chart stacks the statuses as Pending, Shipped, Delivered

Scenario Outline: Changing the granularity shows that granularity's default range
  Given Olga, an order manager on the dashboard, today being 15 June 2026
  When Olga selects "<granularity>"
  Then the charts show <points> from <first> to <last>
  Examples:
    | granularity | points     | first          | last          |
    | Day         | 30 days    | 17 May 2026    | 15 June 2026  |
    | Month       | 12 months  | July 2025      | June 2026     |
    | Year        | 5 years    | 2022           | 2026          |

Scenario Outline: A preset sets the range and the granularity
  Given Olga, an order manager on the dashboard, today being 15 June 2026
  When Olga picks the preset "<preset>"
  Then the charts show <granularity> from <first> to <last>
  Examples:
    | preset       | granularity | first         | last         |
    | Last 7 days  | days        | 9 June 2026   | 15 June 2026 |
    | Last 30 days | days        | 17 May 2026   | 15 June 2026 |
    | This month   | days        | 1 June 2026   | 15 June 2026 |
    | This year    | months      | January 2026  | June 2026    |

Scenario: A custom date range filters the charts
  Given Olga, an order manager on the dashboard
  When Olga picks 1 May to 15 May
  Then the sales overview shows only those 15 days, including sales at 23:59 on 15 May

Scenario: Clearing a date goes back to the default range
  Given Olga, an order manager on the dashboard who picked 1 May to 15 May
  When Olga clears the start date
  Then the sales overview shows the default range for the chosen granularity

Scenario Outline: A range that cannot be shown is explained, not crashed
  Given Olga, an order manager on the dashboard
  When Olga picks <range>
  Then a message explains the problem
  And both charts and the KPI tiles keep their previous data
  Examples:
    | range                                      |
    | a start date after the end date            |
    | more than 366 days shown per day           |
    | more than 120 months shown per month       |
    | more than 50 years shown per year          |
    | a start date before the year 1000          |
    | an end date after the year 9998            |

Scenario Outline: A shared link with a broken filter still opens the dashboard
  Given Olga, an order manager
  When Olga opens a dashboard link whose <filter> is not valid
  Then the dashboard opens with the default sales overview
  Examples:
    | filter                       |
    | period                       |
    | start date                   |
    | end date                     |
    | status list                  |
    | status list with too many entries |

Scenario: A filtered dashboard link restores the same sales overview
  Given Olga, an order manager who filtered the overview to Delivered and Shipped for May per day
  When Olga opens the same dashboard link again
  Then the charts and the KPI tiles show that filter

Scenario: The two charts are redrawn together, in place
  Given Olga, an order manager on the dashboard
  When Olga changes the granularity
  Then both charts are redrawn from the same periods without leaving the page

Scenario: The legend hides a series without changing the filter
  Given Olga, an order manager on the dashboard
  When Olga hides the "Pending" entry in the orders chart legend
  Then the pending segments disappear from the orders chart
  And the KPI tiles and the sales chart do not change

Scenario: The chart data is available as text
  Given Olga, an order manager on the dashboard
  When Olga opens the dashboard
  Then a text alternative lists the same figures as each chart

Scenario: The charts follow the light and dark themes
  Given Olga, an order manager on the dashboard
  When Olga switches to the dark theme
  Then both charts are redrawn with dark colors and keep their data

Scenario: Someone who lost access to orders cannot keep using the filters
  Given Olga, an order manager with the dashboard open, whose access to orders is then withdrawn
  When Olga changes the sales filter
  Then Olga is refused
  And the refusal is recorded

Scenario: A staff member without access cannot obtain sales figures by tampering with the page
  Given Uma, a user manager who may not see orders
  When Uma tries to request sales figures through the dashboard anyway
  Then Uma is refused
  And the refusal is recorded

Scenario: The sales overview speaks the administrator's language
  Given Laura, an administrator whose admin UI language is Spanish
  When Laura opens the dashboard
  Then every label, tooltip, hint, month name and amount in the sales overview is shown in Spanish
```

## Files to create/modify

Create:
- `app/Livewire/Dashboard/SalesOverview.php`, `resources/views/livewire/dashboard/sales-overview.blade.php`
- `resources/js/sales-chart.js`
- tests below (including a static `tests/Support/Dashboard/SalesOverviewUi.php` if a shared fixture is needed)

Modify:
- `resources/views/livewire/dashboard/overview.blade.php` — mount `<livewire:dashboard.sales-overview />` (gated by `@can`), the file 0083 creates
- `resources/js/app.js` (one import line), `resources/css/app.css` (chart tokens), `package.json`, `package-lock.json` (`chart.js`)
- `lang/en/dashboard.php`, `lang/es/dashboard.php` (the `sales` group only)
- `resources/views/layouts/…` only if the `data-testing` flag needs a layout hook (D-4)
- Docs (Phase 6): `docs/conventions/base-standards/livewire-and-flux-conventions.md` (lines 25-51 call the WYSIWYG the "first" `wire:ignore` region: the chart canvas is the second; add the first `#[Lazy]`/`#[Url]`-on-a-lazy-child pattern),
  `docs/api/dashboard.md`, the stack/dependency list (Chart.js), `docs/testing/frontend/*` (the exposed-instance technique), `docs/security/blade-livewire-output-encoding.md` (chart payloads and sr-only tables are plain text), the Gherkin glossary ("status chip", "KPI strip").

## Tests to perform

Feature (`Livewire::withQueryParams([...])->test(SalesOverview::class)` **directly** — with `#[Lazy]`, a `GET /dashboard?...` only renders the placeholder, so it proves nothing about `mount()`; `Livewire::withoutLazyLoading()` for `Overview`-level tests); non-Super-Admin actors;
`seed(RolePermissionSeeder)`; probes on the **sr-only tables** and `data-test` hooks:

- `SalesOverviewRenderingTest` — default state (Day, 30 days, default chips), KPI tiles with the exact `<x-money>` strings (`€ 180.00`), the collected-% hint (integer, half up, hidden when sales = 0), the income hint, both sr-only tables for paid / unpaid / partially refunded /
  cancelled orders (Sales gross, Real income net, Orders by status), chart labels per granularity in en/es (`1 May`/`1 may`, `May 2026`/`mayo 2026`, `2026`), empty state, stack order by `OrderStatus::cases()` regardless of selection order.
- `SalesOverviewFiltersTest` — granularity switch resets the range to that granularity's default (12 monthly / 5 yearly buckets ending today), presets set range **and** granularity (travelTo 2026-06-15), custom-range boundaries (15 May 23:59:59 in, 16 May 00:00:00 out), clearing a date, chips (default set, *Cancelled* on/off, single status, all,
  **empty selection refused with the backend's translated `statuses` message and the previous data kept**), cap boundaries (366/367, 120/121, 50/51) showing the translated `range_too_long` with its `:max`, years outside 1000..9998 refused with `sales.range_year_window` **before any action call**, `from > to` showing `range_invalid`,
  `assertDispatched('sales-overview-updated')` with the scalar payload after a valid change (labels identical in both charts, a dataset per selected status) and `assertNotDispatched` after an invalid one, **the property keeps its previous value after a refused change** (the `updating*` spike), rapid consecutive changes equal a fresh load, a refunded order reduces Real income.
- `SalesOverviewUrlTest` — `#[Url]` round trip, defaults absent from the URL, restoring from `g`/`from`/`to`/`s`, and the broken-param dataset (`g=garbage`, `from=not-a-date`, `to=2026-13-45`, empty/array values, one bound only, year 99999, `s[]=garbage`, six `s[]` entries): **200, defaults, no exception**; plus the **lazy handshake** (the lazy child reads the query string).
- `SalesOverviewPermissionTest` — an actor without `orders.view` gets no card, **no orders-table query and no refusal log on render**; a filter update from a revoked or never-authorized actor is **refused (403) and logged** by the series action (`ability = viewAny`, `target_type = order`) — never silently ignored; no public property holds a model/Collection/enum/Carbon; the card's snapshot/HTML contain no sales sentinel for a non-orders actor.
- `SalesOverviewLocaleTest` + a parity assertion for the `sales` group (the existing `DashboardLangParityTest` compares flattened key sets and per-key placeholders; extend, never loosen, and leave its `errors` pin untouched).

Browser (`tests/Browser/Dashboard/`; `data-test` selectors, `assertNoJavaScriptErrors()`, never `networkidle`, `retry(3, …, 250)` on multi-step flows with a stated reason, run in CI as part of `php artisan test`, `pkill -9 -f "playwright run-server"` locally afterwards):

- `SalesChartTest` — (1) **exactly two Chart instances exist and both survive** granularity changes, chip toggles, a custom range and an unrelated re-render (`window.__salesChartCreations === 2`, the same `canvas.chartInstance` objects); (2) chip toggles add/remove an orders **dataset** without re-creation; legend toggling hides a series locally and leaves the KPI tiles and chart A untouched;
  (3) no JS errors through load, filter change, validation error, theme toggle and `wire:navigate` away and back; (4) `chart.data.labels`/`datasets` equal the seeded series, **also on first load from a non-default URL range with no interaction** (guards the `wire:ignore` first-render race and the lazy handshake); (5) the sr-only tables equal the series before and after a filter change, are not `display:none`, canvases are `aria-hidden`; (6) rapid Day/Month/Year/Day clicks end on the last click's state with the same instances; (7) **the update event reaches both charts** (the `$wire.$on` mechanism — the test that proves the listener fires).
- `SalesChartThemeTest` — the dark-mode toggle changes the chart colors without a new instance (no browser precedent exists — new ground); 375 px viewport has no horizontal scroll.
- The tooltip is its own test (flake isolation); seeded dates are relative to `now()` and expected labels are computed in PHP (never assert "today" in JS).

## Expected outcome

An order manager sees, at a glance and without leaving the home page, how much was sold, how much was actually collected and how many orders were placed, per day, month or year, narrowed by order status, in two clear charts and three KPI tiles that stay correct and accessible.

## Acceptance criteria

- The card renders only for an actor with `orders.view`; an unauthorized render runs no orders query and logs nothing; an unauthorized **update** is refused and logged by the action.
- The filter bar, the KPI strip and the two charts match the Gherkin; the default state is Day · last 30 days · every status except *Cancelled*.
- Every hydrated filter value is bounded and validated before an action runs (granularity enum, ≤ 5 `OrderStatus` values sorted canonically, strict `Y-m-d`, years 1000..9998 with a clear message); garbage URL state never causes a 500; a refused change keeps the previous data on charts, tiles and tables.
- Exactly two Chart.js instances per page view, each created once, updated in place, destroyed on navigation; Chart.js is loaded lazily and tree-shaken; light and dark both work; the series never rely on color alone.
- Two sr-only tables carry the same figures as the charts; no `x-html`/`{!! !!}` anywhere; payloads are scalars; the money in tiles and tables is never cast to float.
- Every new string exists in `en` and `es` with identical key sets and placeholders, the `errors` group and its parity pin are untouched; the layout works at phone width.

## Definition of Done

- [ ] Phase 2 INVEST validation (`code-reviewer`)
- [ ] The two spikes (lazy child + `#[Url]`; `updating*` keeps the old value; `$wire.$on` delivery) recorded green in the first red tests, or their fallbacks adopted and noted
- [ ] Tests written first (red) then green; **full suite** green (unscoped, run as directory chunks, including `tests/Browser`)
- [ ] Pint (unscoped), Larastan, `npm run build` clean; **bundle-size delta and `npm audit` state recorded**; `package-lock.json` committed with the dependency
- [ ] Appsec review (per-component authorization on both paths, hydrated-state bounds, XSS sinks, refusal-log behaviour)
- [ ] Docs synced (list in "Files")

## Risks and follow-ups

- **R-1 Four firsts for the repo:** `#[Lazy]`, Chart.js, a theme `MutationObserver`, `#[Url]` on a lazy child. Each has a named test; **fallback if the lazy+`#[Url]` spike fails:** render `SalesOverview` eagerly.
- **R-2 Alpine Proxy + Chart.js** — D-4 keeps the instance raw. **R-3 Out-of-order responses** on rapid clicks — browser test (6).
- **R-4 Dark-mode detection has no precedent** — observer plus `SalesChartThemeTest`. **R-5 Browser flake budget** — four documented `Timeout 5000ms` CI flakes; `retry(3, …, 250)`; the toast/tooltip assertions are isolated.
- **R-6 Real income reads 0 in a real store** until 0084/0085 ship (nothing sets `paid`), which the hint explains.
- **R-7 Backend contract drift** — if 0082's shapes change, the payload and the Gherkin change with it. **R-8** the action's reuse of `range_invalid` for the year window is worked around in the component; a dedicated backend message would be a separate follow-up.
- **R-9 Unindexed `orders.created_at`** (recorded by 0082): every filter change runs two range scans; debounce/throttle the date inputs (`.live.blur`) and revisit if measured slow.

## Dependencies

- **Blocked on [0083](done/0083-dashboard-home-overview-ui.md)** (`depends_on: ["0083"]`): it adds the card to the `Overview` page 0083 creates, uses the status-badge colors 0083 extracts, and extends the same `lang/{en,es}/dashboard.php`.
- Backend [0082](done/0082-dashboard-home-overview-backend.md) is merged. No conflict with 0084/0085 (it never touches `orders.blade.php`).
