# [0083] Dashboard home overview — page redesign and sales chart (frontend)

> **Amended 2026-09-30 (owner request): the sales chart became a "Sales overview"** — shared filters with an order-status filter,
> a KPI strip (Sales · Real income · Orders) and two charts (D-9). Written by the facilitator without a second debate round;
> Phase 2 should look at D-9 and the ⚑ flags first.
>
> **Status: Phase 1 complete (Three Amigos debate held 2026-09-30).** Ready for Phase 2 (INVEST check, not run yet).
> Backend companion: [0082](0082-dashboard-home-overview-backend.md), which this story is blocked on — its return shapes,
> caller contract (D-1) and caps (D-6) are the contract this story consumes.
> Items marked **⚑ owner to confirm** are facilitator decisions the project owner has not explicitly ratified.

## Description

Replace the placeholder `resources/views/dashboard.blade.php` with the real home page, following
[`docs/PRD/images/01-inicio.png`](../../docs/PRD/images/01-inicio.png) (hero banner: greeting + counters) but **substituting the
four shortcut cards** with live widgets:

1. **Hero** — time-of-day greeting with the user's first name and the counters (active users, products, images), each shown
   only if the actor may see that module.
2. **Blog widget** — the 3 latest published/scheduled posts: title, description of at most 80 characters (posts have no main
   image), a status badge and, for scheduled posts, the date and time they go live. Links to the post editor; footer link to the blog list.
3. **Low-stock widget** — the 3 products closest to running out, with stock and an out-of-stock / low-stock badge. A variable
   product appears as its **parent**, with its lowest variant stock and an "N variants low" hint, linking to the product editor.
4. **Latest orders widget** — the 5 most recent orders (number, customer, total, status), each linking to the order detail.
5. **Sales overview (Chart.js)** (amended 2026-09-30, owner request) — one card with **shared filters** (**Day / Month / Year**,
   presets, a **custom date range** and an **order-status filter**, the old "Include cancelled orders" checkbox now being the
   *Cancelled* chip, off by default), a **KPI strip** (Sales · Real income · Orders for the period) and **two charts**:
   **"Sales vs real income"** (line, two series) and **"Orders by status"** (stacked bars, one segment per status). Everything
   updates without a page reload. See D-9.

## Type

`frontend | includes database-expert: no` — plus **one approved dependency** (`chart.js`, owner decision 2026-09-30) and a small
extraction of two shared badge components.

## Verified facts that shaped the story

- **Flux free has no date picker** (`flux:date-picker` is Pro; `livewire/flux-pro` is not installed): use native
  `<flux:input type="date">`, whose value is always `Y-m-d`.
- **No shared status-badge components exist**: order/blog/product badges are inline `flux:badge` + `match` blocks
  (`orders.blade.php:78-103`, `blog-posts.blade.php:96-105`, `products.blade.php:68-74`). The earlier draft's claim was wrong.
- **No card wrapper component exists** in `resources/views/components/`.
- **Livewire 4.3.3**; `#[Lazy]` is supported but **unused so far** in the repo; `#[Url]` is used in `BlogPosts/Index.php`.
- **Alpine is bundled with Livewire** (no `alpinejs` import); components register inside `document.addEventListener('alpine:init', …)`
  like `wysiwygEditor` in `resources/js/app.js`. `app.js` is already the single Vite entry (`vite.config.js:11-16`).
- **Dark mode is class-based** (`@custom-variant dark`, `app.css:9`, toggled by `@fluxAppearance`); there are no chart tokens.
- **App timezone is `Europe/Madrid`** (`config/app.php:68`); users have no timezone of their own. (A comment in
  `BlogPosts/Editor.php:132` says UTC — the code wins; unrelated to this story.)
- `<x-money>` prints the decimal **string** unchanged (no cast, no `number_format`); the orders list formats dates as `d/m/Y H:i`.
- `RegistrationTest.php:26` asserts `route('dashboard')`; `tests/Feature/DashboardTest.php` asserts a guest redirect and a 200 for a
  bare user — both must stay green.

## Decisions

### D-1 — A thin `Overview` page plus child components

`App\Livewire\Dashboard\Overview` (full page, replaces `Route::view('dashboard', 'dashboard')` at `routes/web.php:8`, **same
route name and middleware, still ungated**) renders the layout heading/subheading (`topbar.dashboard.*`), the hero with counters
(eager — three cheap counts, avoids layout jump) and four children, each wrapped in `@can`:
`Dashboard\BlogWidget`, `Dashboard\LowStockWidget`, `Dashboard\LatestOrdersWidget`, `Dashboard\SalesOverview` (filters + KPI strip + both charts; renamed from `SalesChart`).

- **Why children:** a filter change is a request to `SalesOverview` only; it must not re-run the four other queries or re-morph the widgets.
- **`#[Lazy]` on `SalesOverview` only** (skeleton placeholder with `flux:skeleton`); the three list widgets are eager (3–5 rows).
  It is the repo's first `#[Lazy]`, so it gets its own test.
- **Two permission layers per child:** the parent `@can` only avoids rendering; **each child re-checks with `Gate::allows`
  inside its own computed/actions before calling a 0082 action** (backend D-1 caller contract — otherwise every unprivileged
  load logs a "Privileged action refused" warning). This includes the filter actions: a hidden chart must not be reachable by
  posting to the component, and a permission revoked mid-session must be honoured on the next request.
- `resources/views/dashboard.blade.php` is **deleted**; Overview's own view carries `<x-layouts::app>` with the heading slots
  like `orders.blade.php:19-20`. `#[Title('Dashboard')]` follows `Orders\Index.php:32`.
- Optional `resources/views/components/dashboard/widget.blade.php` — plain bordered card shell (title, "view all" link, empty slot),
  `rounded-xl border border-neutral-200 dark:border-neutral-700` as the placeholder already used.

### D-2 — Livewire state: scalars public, everything else computed

Public properties are client-visible and writable: only **scalars** (`string`/`bool`/`int`) are public. Result arrays live in
`#[Computed]` (never `persist: true`), and **no model, Collection, enum or `CarbonImmutable` is a public property**.
`SalesOverview` public state:

- `#[Url(as: 'g')] public string $granularity = 'day'`, `#[Url(as: 'from')] public string $from`, `#[Url(as: 'to')] public string $to`,
  `#[Url(as: 's')] public array $statuses` — **a list of `OrderStatus` value strings** (scalars), default = every status except
  `cancelled` (`OrderStatus::defaultDashboardSet()` values). Defaults are omitted from the URL. Unknown status strings in the URL are
  dropped; if none survive, the default set is used. An **empty selection made in the UI** is refused with a translated message
  (`statuses` error key), the previous data stays.
- `$granularity` is validated with `Rule::enum(SalesGranularity::class)` and converted with `SalesGranularity::tryFrom()` /
  `CarbonImmutable::createFromFormat('!Y-m-d', …)` inside a private method — **never `::from()`** (a garbage URL must not 500).
- `rules()`: `from` `required|date_format:Y-m-d`, `to` `required|date_format:Y-m-d|after_or_equal:from`.
- **Bad URL state** (`?g=garbage`, `from=not-a-date`, `to=2026-13-45`, empty or array values, only one bound, year 99999,
  `cancelled=maybe`) is validated in `mount()` and falls back to the defaults: HTTP 200, no exception.
- **Keeping the previous chart on an invalid range:** validation and the action's `ValidationException` (key `range`) are caught,
  the message is added to the `range` error bag (**translated**, with the cap as `:max`, never the backend's English text), and
  **no `sales-overview-updated` event is dispatched**, so neither chart is blanked. `resetValidation()` runs at the start of each
  successful update.
- **Presets** (`wire:click="applyPreset('…')"`, computed server-side in the app timezone, so testable): *Last 7 days*,
  *Last 30 days* (inclusive of today, 30 points), *This month*, *This year*; the active preset is highlighted by comparing from/to.
  **Changing granularity applies that granularity's default range** (Day: last 30 days · Month: last 12 months · Year: last 5 years)
  so "Month" never shows one lonely point — **⚑ owner to confirm**. Default state: Day, last 30 days.
- Rapid repeated changes must end in the state a fresh load of that URL would produce.

### D-3 — Chart.js integration (owner-approved dependency)

Add `chart.js` to `package.json`/`package-lock.json`; **record the bundle-size delta in the PR** (`npm run build`).

- **Markup (superseded for the two-chart layout by D-9; the rules below apply to each chart):** a root `<div x-data="moneyChart(@js($this->moneyPayload))" x-on:sales-overview-updated="update($event.detail.money)">`
  and its sibling for the orders chart (component-scoped listener, **no `.window`**, matching `WysiwygEditor.php:205`). **`wire:ignore` only on the canvas wrapper**;
  the empty state and the loading overlay are **siblings** of it and toggle with `x-show`/`hidden` — never a Blade `@if` around
  the canvas (that would destroy the chart instance).
- **Initial data:** passed through `x-data`, not through an event (a dispatch during a lazy mount request is lost because Alpine
  is not yet listening). Update events carry a **scalar payload**: `labels` (server-formatted per locale), `revenue` (floats — display
  only, for plotting; the money total shown in Blade stays a string via `<x-money>`), `ordersCount`, `granularity`, `locale`.
- **`resources/js/sales-chart.js`** registers `Alpine.data('moneyChart', …)` and `Alpine.data('ordersChart', …)` inside `alpine:init` and is imported from `app.js` with a
  single line (no new Vite entry). **Load Chart.js with a dynamic `import('chart.js')` inside `init()`** so it is code-split and
  loaded on the dashboard only; guard `destroy()` being called before the import resolves. Register only: `BarController`,
  `BarElement`, `CategoryScale`, `LinearScale`, `Tooltip` (no `chart.js/auto`, no legend for a single series).
- **Alpine reactivity trap:** the Chart instance must **not** live on the reactive `Alpine.data` object (Proxy → infinite loops);
  keep it in a closure variable / `Alpine.raw()`.
- **Instance lifecycle:** created **once** in `init()`, updated with `chart.data.labels = …; chart.data.datasets[0].data = …;
  chart.update()` (`'none'` when `prefers-reduced-motion`), destroyed in `destroy()` together with the theme observer, so
  `wire:navigate` away and back raises no "Canvas is already in use".
- **Testability requirement:** with a tree-shaken import there is no `window.Chart`; the component **exposes its instance on the
  canvas element** and a `data-chart-ready` attribute plus an instance-creation counter, and disables animation under test.
- **Theme:** add `--chart-line/--chart-grid/--chart-text` tokens to `resources/css/app.css` with a `.dark` override (the existing
  `.dark` block at `app.css:32`); read them at init and refresh them from a `MutationObserver` on `<html class>`
  (what `@fluxAppearance` toggles), followed by `chart.update()` — **same instance**. Set `Chart.defaults.font.family` from `--font-sans`.
- **Formatting:** category scale (no date adapter dependency); x labels arrive pre-formatted from the server; y ticks and tooltips
  use `Intl.NumberFormat(payload.locale, {style: 'currency', currency: 'EUR'})` — the **locale comes from the server payload**,
  never `navigator.language`. Chart-axis formatting is display-only and does not conflict with the `<x-money>` no-cast rule for tables.
- **Text alternative:** a Blade-rendered `<table class="sr-only">` (caption, `th scope="col"`, one row per bucket) **outside**
  `wire:ignore`, fed by the same computed series, so Livewire updates it natively; the canvas is `aria-hidden` and the table is the
  alternative (an `aria-live="polite"` line summarizes the total). This table is also the assertion surface for Livewire tests.
- **Empty state:** when every point is zero, show the empty message and hide the canvas wrapper (chart instance survives).
- **Loading:** `wire:loading` overlay on a sibling of the wrapper, `wire:target="granularity,from,to,includeCancelled,applyPreset"`.
- **Security:** Blade `{{ }}` for every title/description/name (all plain text); never `x-html`, never `{!! !!}`, never interpolate
  labels into JS strings.

### D-4 — Filters UI

Segmented Day/Month/Year using the `flux:radio.group variant="segmented"` pattern of `appearance.blade.php:5`; two native
`<flux:input type="date" wire:model.live.blur>` inside a `flux:field` with `flux:error name="range"`; preset buttons; a
status chips (D-9) in place of the old single checkbox. Measures are named **"Sales"** (total sold, refunds not netted), **"Real
income"** (money collected, net of refunds) and **"Orders"**, each with a one-line definition in a tooltip; never "Revenue" or
"cash received". Date inputs: `max` = today on `to` (cosmetic).

### D-5 — Shared badge components (small extraction)

Extract `resources/views/components/order-status-badge.blade.php` and `blog-status-badge.blade.php` (colors from the existing
`match` blocks, labels from `OrderStatus`/`BlogPostStatus::label()`) and **reuse them in the existing orders, blog and dashboard views**;
this touches `orders.blade.php` and `blog-posts.blade.php` (low risk, covered by their existing tests). Stock badge: red when
`isOutOfStock`, amber otherwise. *(Fallback if the owner prefers zero blast radius: duplicate the `match` blocks and log tech debt.)*

### D-6 — Formats, copy and tolerance

- **Greeting** by app-timezone hour: 05:00–11:59 morning, 12:00–19:59 afternoon, otherwise evening; first name =
  `Str::of($name)->before(' ')` (whole name if it has no space). Server-side only (client-side is untestable).
- **Dates:** the scheduled date shows as `d/m/Y H:i` in the app timezone, like the orders list; published posts show **no date**.
- **Nulls:** a null product name shows the SKU; a null post title shows a translated "Untitled" placeholder and stays clickable;
  a null/removed customer shows a translated "deleted customer" text; no exception, no empty link text.
- **Money:** table figures and the chart total use `<x-money>`; **order rows do not link the customer**, only the order number
  (`orders.show`). Blog rows link to `blog-posts.edit`, low-stock rows to `products.edit` (parent, never a variant URL).
- **Lists:** stacked `<ul>` rows, not `flux:table`, so phones do not scroll horizontally.

### D-7 — Layout by visible content

Grid, not fixed columns: chart full width; blog/stock/orders in `lg:grid-cols-2`, a lone odd last card spans both columns.
Hero counters: render each stat only when its value `!== null` (**never `@if($count)`** — `0` must render `0`); with **no** visible counter
the stats container is omitted and the greeting takes the full width; with 1–2 the flex row still works. If **no counter and no widget** is visible
(a user with only e.g. `roles.manage`), show a friendly "nothing to show yet" message. Visuals per `docs/arospe-handoff/project/css/index.css:3-13`
(gradient `#4f46e5→#6d5ef0`, radius 18px, mono counters via Tailwind `font-mono`); the hero gradient stays the same in dark mode.

### D-8 — i18n

New `lang/en/dashboard.php` and `lang/es/dashboard.php`, snake_case leaves, `trans_choice` for plurals, **identical key sets**
(parity test). Groups: `hero` (`greeting_morning|afternoon|evening` with `:name`, `tagline`), `counters`, `blog`, `stock`
(`out_of_stock`, `low_stock`, `units`, `variants_low` choice), `orders`, `sales` (title, granularity, presets, from/to,
`include_cancelled`, `total`, `empty`, `range_error` with `:max`, table caption/columns, loading), `untitled`, `deleted_customer`,
`no_widgets`. Reuse `OrderStatus`/`PaymentStatus`/`BlogPostStatus::label()`; `topbar.dashboard.*` stays. The admin locale is per request
(`SetUiLocale`), so JS formatting takes it from the server payload.

### D-9 — Sales overview: shared filters, KPI strip and two charts (amended 2026-09-30, ⚑ owner to confirm)

**Why two charts rather than one with a toggle:** money and counts have different units and scales; putting orders (tens) on the
same axis as sales (thousands of euros) flattens one of them, and a metric toggle hides the comparison the owner asked for (sales
vs what was actually collected). Two charts of different shapes are also easier to read at a glance.

**Layout (top to bottom inside one widget card "Sales overview"):**

1. **Filter bar (shared, applies to everything below):** granularity segmented control · presets · from/to dates · **status chips**
   (*Pending, Processing, Shipped, Delivered, Cancelled* — multi-select toggle chips using the same colors as the status badges;
   *Cancelled* off by default; an "All" / "Reset" affordance). One filter, one URL, one Livewire request.
2. **KPI strip — three tiles** for the selected period: **Sales** (total), **Real income**, **Orders** (count). Sales and income use
   `<x-money>`. Income tile hint: "Collected: N% of sales" when sales > 0. If income is 0 while sales > 0 the tile shows a neutral
   hint "Income is counted once orders are paid" (payment capture does not exist yet — see backend risks).
3. **Chart A — "Sales vs real income"** (Chart.js **line**, two series with distinct colors *and* distinct line styles/markers so
   it does not rely on color alone): *Sales* = muted/dashed, *Real income* = accent/solid; the gap between them is the money not
   yet collected or refunded. Y axis in EUR via `Intl.NumberFormat`; tooltip shows both values and the difference.
4. **Chart B — "Orders by status"** (Chart.js **stacked bar**, one dataset per *selected* status, colors from the status tokens,
   integer y axis starting at 0; tooltip shows the status breakdown and the bucket total). The legend is shown; toggling a legend
   entry only hides that series **locally** (client-side) and does **not** change the server filter — the chips do. The tooltip
   and the help text say so.
5. **Responsive:** charts sit side by side from `xl`, stacked below it; KPI tiles wrap to one column on phones; chips scroll
   horizontally instead of wrapping into a wall.

**Mechanics:** `SalesOverview` calls `GetSalesSeries` and `GetOrdersSeries` (0082) with the same arguments (both gated
`orders.view`, checked with `Gate::allows` first) and dispatches **one** event `sales-overview-updated` with a scalar payload
`{money: {labels, sales, income, locale, granularity}, orders: {labels, datasets: [{status, label, data}], locale}}`.
Two Alpine components, `moneyChart` and `ordersChart`, each create their Chart.js instance **once**, update **in place** and
keep it outside Alpine's reactivity (D-3 rules apply to **each**), so the page holds **exactly two** Chart instances. Adding or
removing a status changes the orders chart's **datasets** inside `update()` (no re-creation). Each chart has its own `wire:ignore`
canvas wrapper, empty state and sr-only data table (two tables, server-rendered, same buckets). Chart.js is still imported
dynamically once; the extra controllers needed are `LineController`, `LineElement`, `PointElement` (chart A) on top of the bar set.
A single invalid-range / empty-status response dispatches nothing, so **both** charts keep their previous data.

**Empty and edge states:** all-zero period → both charts show their empty message; income-only-zero → chart A still draws the
sales line and the income line at 0 with the hint above; a status selected with no orders still appears in the orders legend with
zeros.

## Open questions closed by the debate

| Q | Resolution |
| --- | --- |
| Charting library | Chart.js, dynamic import, tree-shaken (owner) |
| Default range | Day · last 30 days (inclusive of today) |
| Date picker | native date inputs (Flux free has none) |
| Blog image / cancelled toggle / variable products | owner decisions, see backend 0082 |

Still **⚑ owner to confirm**: D-9's two-chart layout and definitions (Sales gross, Real income = paid net of refunds, one shared
status filter whose *Cancelled* chip replaces the old checkbox); granularity change resets the range; extracting the two badge components; "Untitled" /
"deleted customer" placeholders; `d/m/Y H:i` date format.

## Gherkin

"Catalog manager" and "order manager" join the glossary when this story is ratified. Chart assertions go through the sr-only
data table (canvas content is not testable in Livewire tests); browser tests cover the canvas.

```gherkin
Scenario: The hero shows the greeting and the three counters
  Given Laura, an administrator, with 6 active users, 6 products and 12 images
  When Laura opens the dashboard
  Then the hero greets Laura by time of day
  And it shows the counters 6, 6 and 12

Scenario Outline: The greeting follows the time of day
  Given Laura, an administrator
  When Laura opens the dashboard at <time>
  Then the hero greets her with "<greeting>"
  Examples:
    | time  | greeting  |
    | 09:00 | morning   |
    | 14:00 | afternoon |
    | 22:00 | evening   |

Scenario Outline: The dashboard shows only what the actor may see
  Given <actor>, <role>, and a store with users, products, images, posts and orders
  When <actor> opens the dashboard
  Then the dashboard shows <visible>
  And it shows none of <hidden>
  Examples:
    | actor | role                      | visible                                   | hidden                                       |
    | Nora  | a receptionist            | the greeting only                         | counters, blog, stock, orders and sales      |
    | Uma   | a user manager            | the users counter                         | products, images, blog, stock, orders, sales |
    | Olga  | an order manager          | latest orders and the sales chart         | every counter, blog and stock                |
    | Carla | a catalog manager         | products and images counters, low stock   | users counter, blog, orders and sales        |
    | Sara  | a super administrator     | every counter and widget                  | nothing                                      |

Scenario: A receptionist with no module access sees a friendly message
  Given Nora, a receptionist who may see no module
  When Nora opens the dashboard
  Then Nora is told there is nothing to show yet

Scenario: The hero degrades gracefully when the actor sees no counters
  Given Olga, an order manager who may not see users, products or images
  When Olga opens the dashboard
  Then the hero greets Olga without any counter

Scenario: Hidden widgets are never read nor logged as refusals
  Given Uma, a user manager
  When Uma opens the dashboard
  Then no blog, stock, order or sales information is read
  And no refused-action warning is recorded

Scenario: A hidden widget cannot be reached by tampering with the page
  Given Uma, a user manager who cannot see orders
  When Uma's browser asks the dashboard for a sales range
  Then no sales information is returned to Uma

Scenario: A permission revoked while the page is open is honoured
  Given Olga, an order manager with the dashboard open, whose order access is then withdrawn
  When Olga changes the chart filter
  Then no sales information is returned to Olga

Scenario Outline: A post's state decides whether a date is shown
  Given Bea, a blog editor, with a <state> post
  When Bea opens the dashboard
  Then the post carries a "<badge>" badge and <date_rule>
  Examples:
    | state     | badge     | date_rule                            |
    | published | Published | no date                              |
    | scheduled | Scheduled | its go-live date and time, 12/10/2026 10:00 |

Scenario: The blog widget shows a title and a short description, never an image
  Given Bea, a blog editor, and a published post with a long body
  When Bea opens the dashboard
  Then the post shows its title and a description of at most 80 characters
  And no image is shown

Scenario: A hostile title is shown as text
  Given Bea, a blog editor, and a post whose title contains markup
  When Bea opens the dashboard
  Then the title is shown as plain text

Scenario: The low-stock widget warns about the emptiest products
  Given Carla, a catalog manager, and physical products with stock 0, 2, 7 and 50
  When Carla opens the dashboard
  Then the widget lists the products with stock 0, 2 and 7
  And the product with stock 0 is marked out of stock

Scenario Outline: A variable product shows as its parent with a variants-low hint
  Given Carla, a catalog manager, and a variable product with <low> variants at or below the cut-off
  When Carla opens the dashboard
  Then the parent product is listed once with the hint "<hint>"
  And the entry links to that product's editor
  Examples:
    | low | hint             |
    | 1   | 1 variant low    |
    | 2   | 2 variants low   |

Scenario: Entries with missing names still appear
  Given Carla, a catalog manager, and a product without a name
  When Carla opens the dashboard
  Then the product is listed by its SKU

Scenario: A post without a title still appears
  Given Bea, a blog editor, and a post without a title
  When Bea opens the dashboard
  Then the post is listed as untitled and can still be opened

Scenario: The latest orders widget lists five orders
  Given Olga, an order manager, and 7 orders
  When Olga opens the dashboard
  Then 5 orders are listed, newest first, each linking to its detail page

Scenario: An order from a deleted customer still appears
  Given Olga, an order manager, and an order whose customer has been deleted
  When Olga opens the dashboard
  Then the order is listed and its customer is shown as deleted

Scenario: A store with nothing to show
  Given Laura, an administrator, and a store with no posts, orders or low-stock products
  When Laura opens the dashboard
  Then each widget shows its empty state and the counters read 0

Scenario: The sales overview starts on the last 30 days per day without cancelled orders
  Given Olga, an order manager
  When Olga opens the dashboard
  Then both charts cover the last 30 days per day
  And every status chip except "Cancelled" is selected

Scenario: Cancelled orders are counted when the Cancelled chip is selected
  Given Olga, an order manager on the dashboard, with a delivered order of 100 and a cancelled order of 40 on the same day
  When Olga selects the "Cancelled" chip
  Then the sales for that day read 140
  And the orders for that day read 2

Scenario: The KPI strip summarizes the period
  Given Olga, an order manager, and in the period a paid order of 100, a paid order of 50 partly refunded by 20 and a pending order of 30
  When Olga opens the dashboard
  Then the Sales tile reads 180
  And the Real income tile reads 130
  And the Orders tile reads 3

Scenario: Sales and real income are two lines of one chart
  Given Olga, an order manager, and a paid order of 100 and a pending order of 60 on the same day
  When Olga opens the dashboard
  Then the "Sales vs real income" chart shows 160 of sales and 100 of real income for that day

Scenario: Real income explains itself when nothing has been paid
  Given Olga, an order manager, and only pending orders in the period
  When Olga opens the dashboard
  Then the Real income tile reads 0
  And a hint explains that income is counted once orders are paid

Scenario: The orders chart breaks each day down by status
  Given Olga, an order manager, and on 1 May 2 pending orders and 1 shipped order
  When Olga opens the dashboard for the period containing 1 May
  Then the "Orders by status" chart shows 3 orders for 1 May: 2 pending and 1 shipped

Scenario Outline: The status chips narrow every figure
  Given Olga, an order manager on the dashboard, with a delivered order of 100, a shipped order of 50 and a pending order of 20
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
  And both charts keep their previous data

Scenario: The legend hides a series without changing the filter
  Given Olga, an order manager on the dashboard
  When Olga hides the "Pending" entry in the orders chart legend
  Then the pending segments disappear from the orders chart
  And the KPI tiles and the money chart do not change

Scenario: The two charts are redrawn together in place
  Given Olga, an order manager on the dashboard
  When Olga changes the granularity
  Then both charts are redrawn in place from the same periods and the page shows no error

Scenario: A shared link restores the status filter
  Given Olga, an order manager who filtered the overview to Delivered and Shipped for May per day
  When Olga opens the same dashboard link again
  Then both charts and the KPI tiles show that filter

Scenario Outline: The chart regroups the sales by period
  Given Olga, an order manager on the dashboard showing sales per day
  When Olga selects "<granularity>"
  Then the chart shows one point per <granularity> without a page reload
  Examples:
    | granularity |
    | Day         |
    | Month       |
    | Year        |

Scenario Outline: A preset sets the range
  Given Olga, an order manager on the dashboard
  When Olga picks the preset "<preset>"
  Then the chart covers <range>
  Examples:
    | preset       | range                          |
    | Last 7 days  | the 7 days ending today        |
    | Last 30 days | the 30 days ending today       |
    | This month   | the current month so far       |
    | This year    | the current year so far        |

Scenario: A custom date range filters the chart
  Given Olga, an order manager on the dashboard
  When Olga picks 1 May to 15 May
  Then the sales table shows only those 15 days, including sales at 23:59 on 15 May

Scenario Outline: An invalid range is explained, not crashed
  Given Olga, an order manager on the dashboard
  When Olga picks <range>
  Then a message explains the problem
  And the chart keeps its previous data
  Examples:
    | range                                |
    | a start date after the end date      |
    | more than 366 days shown per day     |
    | more than 120 months shown per month |
    | more than 50 years shown per year    |

Scenario Outline: A shared link with a broken filter still opens the dashboard
  Given Olga, an order manager
  When Olga opens a dashboard link whose <filter> is not valid
  Then the dashboard opens with the default chart
  Examples:
    | filter      |
    | period      |
    | start date  |
    | end date    |

Scenario: A filtered dashboard link restores the same chart
  Given Olga, an order manager who filtered the chart to May per day
  When Olga opens the same dashboard link again
  Then the chart shows May per day

Scenario: The chart is redrawn in place while the page updates around it
  Given Olga, an order manager on the dashboard
  When Olga changes the chart filters
  Then the chart is redrawn in place and the page shows no error

Scenario: The chart data is available as text
  Given Olga, an order manager on the dashboard
  When Olga opens the dashboard
  Then a text alternative lists the same figures as the chart

Scenario: The chart follows the light and dark themes
  Given Olga, an order manager on the dashboard
  When Olga switches to the dark theme
  Then the chart is redrawn with dark colors and keeps its data

Scenario: Unpaid orders are part of the sales but not of the real income
  Given Olga, an order manager, and a pending order of 60 awaiting a bank transfer
  When Olga opens the dashboard
  Then that day's sales include the 60
  And that day's real income does not

Scenario: The dashboard speaks the administrator's language
  Given Laura, an administrator whose admin UI language is Spanish
  When Laura opens the dashboard
  Then every label, badge and date is shown in Spanish
```

## Files to create/modify

Create:
- `app/Livewire/Dashboard/Overview.php`, `BlogWidget.php`, `LowStockWidget.php`, `LatestOrdersWidget.php`, `SalesOverview.php`
- `resources/views/livewire/dashboard/overview.blade.php`, `blog-widget.blade.php`, `low-stock-widget.blade.php`,
  `latest-orders-widget.blade.php`, `sales-overview.blade.php`
- `resources/js/sales-chart.js`
- `resources/views/components/order-status-badge.blade.php`, `blog-status-badge.blade.php` (D-5),
  optional `resources/views/components/dashboard/widget.blade.php`
- `lang/en/dashboard.php`, `lang/es/dashboard.php`
- tests below, incl. `tests/Support/Dashboard/DashboardUi.php` (static helper class: `actor(array $permissions)`, `superAdmin()`,
  `snapshotOf(Testable)` — static, because global helper functions redeclare-fatal, per the `OrdersUi` docblock)

Modify:
- `routes/web.php` (route → `Overview`, name and middleware unchanged)
- `resources/views/dashboard.blade.php` — **delete**
- `resources/js/app.js` (one import line), `resources/css/app.css` (chart tokens)
- `package.json`, `package-lock.json` (`chart.js`)
- `resources/views/livewire/orders.blade.php` and `resources/views/livewire/blog-posts.blade.php` (use the extracted badge components)
- `tests/Feature/DashboardTest.php` (extend: a user with no permissions sees the greeting and the "nothing to show" message)
- Docs (Phase 6): `docs/api/routes.md`, the PRD design-reference note that the shortcut cards were replaced, glossary.

## Tests to perform

Per-concern files (the draft's single `OverviewTest.php` is dropped so a red test names its subject):

Feature (`Livewire::test`, MySQL; actors built with `OrdersUi::actor()`-style helpers — **never** a Super Admin for a "hidden" assertion, `Gate::before` makes it a false negative; `seed(RolePermissionSeeder)` and `forgetCachedPermissions()` in `beforeEach`):

- `OverviewRenderingTest` — every widget's exact text/badges/dates/links, escaping (`<img onerror>`, `"><script>`, `{{`/`@` in titles, names, customer names — in the widgets **and** the sr-only table), null tolerance, trashed customer, empty states, first-name greeting, greeting boundaries with `setTestNow` (04:59/05:00/11:59/12:00/19:59/20:00).
- `OverviewPermissionTest` — profile dataset (none, only `users.view`, only `orders.view`, only `products.view`, only `media.view`, only `blog.view`, three counters, all, Super Admin, Administrator role) asserting rendered `data-test` hooks; **`DB::listen`: zero queries on tables of hidden modules; `Log::spy()`: no "Privileged action refused" warning**; direct-call bypass of filter actions; permission revoked between load and action; lazy chart handshake.
- `OverviewFiltersTest` — default state; granularity switch (Outline) resets to that granularity's default range; presets with `travelTo(2026-09-30)`; custom range boundaries (15 May 23:59:59 counted, 16 May 00:00:00 not); `from > to`; cap boundaries (366 ok / 367 refused, 120/121, 50/51) with the **translated** message and previous series retained; status chips (default set, *Cancelled* on/off, single status, all, **empty selection refused** with the translated message and both charts keeping their data, unknown statuses in the URL dropped); KPI tiles (Sales, Real income, Orders) for paid / pending / partially refunded / cancelled orders, the income hint when income is 0 and sales > 0, the "collected %" hint; `assertDispatched('sales-overview-updated')` with the scalar `{money, orders}` payload after a valid change (status datasets present for every selected status, money and orders labels identical) and `assertNotDispatched` after an invalid one; `#[Url]` round trip and defaults absent from the URL; garbage params via `Livewire::withQueryParams` **and** `$this->get('/dashboard?g=garbage')`; rapid consecutive changes equal a fresh load; a refunded order reduces its bucket (locks the definition on the UI side); Madrid 23:30 order lands on its own day.
- `OverviewSnapshotSecurityTest` — for restricted actors, decode the `wire:snapshot` and assert **sentinel strings** of hidden modules (post title, product SKU, order number, customer name/email, totals) appear neither in the snapshot nor in the HTML, including the chart wrapper's `x-data`/`data-*`; reflection: no public property is a model/Collection/enum/Carbon; tampering with `set('orders', …)`/`set('counters', …)` is refused or renders nothing.
- `OverviewLocaleTest` + `DashboardLangParityTest` — actor with `ui_locale = 'es'` (not `app()->setLocale`, `SetUiLocale` re-applies it every round trip): every label, badge, hint plural/singular, date; `array_keys` parity of `lang/en|es/dashboard.php`; no raw `dashboard.` key in the render (precedent `OrdersLangParityTest`).

Browser (`tests/Browser/Dashboard/`; `data-test` selectors, `assertNoJavaScriptErrors()` in every test, **never** `networkidle`, `retry(3, …, 250)` on multi-step tests with a stated reason, output to a file, `pkill -9 -f "playwright run-server"` afterwards; **not** multiplied by permission profile):

- `SalesChartTest` (covers **both** charts) — (1) **exactly two Chart instances exist and both survive** filter changes, status-chip toggles, a custom range and an unrelated re-render (same instance ids, `Chart.instances` length 2, via the exposed instances); toggling a chip adds/removes an orders **dataset** without re-creating the chart; legend toggling hides a series client-side only and leaves the KPI tiles and the money chart untouched; (2) no JS errors through load, filter change, validation error, theme toggle and `wire:navigate` away and back; (3) canvas non-empty (animation disabled under test, or polled); (4) `chart.data.labels`/`datasets[0].data` equal the seeded series, also on first load from a non-default URL range (no interaction needed — guards the `wire:ignore` first-render race); (5) the sr-only table equals the series before and after a filter change, is not `display:none`, canvas is `aria-hidden`; (6) rapid Day/Month/Year/Day clicks end on the last click's state (compare with the `wire:snapshot`), same instance, no error.
- `SalesChartThemeTest` — dark-mode toggle changes the chart colors without a new instance (no existing precedent — new ground, least certain); 375 px viewport has no horizontal scroll.
- `DashboardJourneyTest` — blog row → post editor path, low-stock parent row → `/products/{id}/edit`, order row → `/orders/{id}` (`assertPathIs`); smoke `visit(['/dashboard', '/dashboard?g=garbage'])`.
- Tooltip (Chart.js config assertion, or `chart.tooltip.setActiveElements`, kept as its **own test** so its flake is isolated); dates are seeded relative to `now()` and expected labels computed in PHP (never assert "today" in JS).

Pest browser tests need `npm run build` in the worktree. Browser tests are not run in CI by default per the agent doc while the DoD asks for the full suite:
**confirm real CI behaviour before promising the chart regression net**, which is why the event payload and the sr-only table also have Feature-level proxies.

## Expected outcome

The dashboard home shows the real store state at a glance, matches the mockup's look, respects each actor's permissions with no
leakage and no log noise, and the Chart.js sales chart updates live from its filters without ever being recreated.

## Acceptance criteria

- No placeholder pattern remains; the four shortcut cards are gone; `dashboard.blade.php` is deleted; the route keeps its name and stays ungated.
- Each widget matches its Gherkin and renders only for an actor allowed to see it; **hidden widgets run no query and write no refusal log**, including through direct calls to the filter actions.
- Only scalar filter properties are public; no other-module data appears in the snapshot or the HTML.
- The sales overview offers one shared filter bar (Day/Month/Year, presets, custom range, order-status chips with *Cancelled* off by
  default), a KPI strip (Sales, Real income, Orders) and **two charts**: "Sales vs real income" (line) and "Orders by status"
  (stacked bar); invalid or oversized ranges and an empty status selection show a translated message and keep the previous data on
  both charts; broken URL params never produce a 500.
- Sales, Real income and Orders are named and defined exactly as in backend D-6, with their definitions in tooltips; Real income
  shows the "counted once paid" hint when it is 0 while sales are not.
- Exactly two Chart.js instances per page view, each created once, updated in place, destroyed on navigation; Chart.js is loaded
  lazily and tree-shaken; light/dark both work; the two charts never rely on color alone (line styles/markers, legend).
- **Two** sr-only text-alternative tables carry the same figures as the charts; no `x-html`/`{!! !!}` on any title, name or label.
- Every string exists in `en` and `es` with identical key sets; layout works at phone width.

## Definition of Done

- [x] Phase 1 debate recorded in this file
- [ ] Phase 2 INVEST validation (`code-reviewer`)
- [ ] Tests written first, **full suite** green (unscoped, including `tests/Browser`)
- [ ] Pint (unscoped), Larastan, `npm run build` clean; bundle-size delta recorded; `npm audit` state checked
- [ ] Appsec review (per-widget authorization, snapshot/public-state leakage, XSS sinks, refusal-log noise)
- [ ] Docs synced (routes contract, PRD design-reference note, glossary)

## Risks and follow-ups

- **R-1 Chart instance untestable without an exposed handle** — mitigated by D-3's testability requirement.
- **R-2 Alpine Proxy + Chart.js** — D-3 keeps the instance raw.
- **R-3 Out-of-order responses** on rapid clicks — browser test (6) verifies last-click-wins; Livewire supersedes requests per component.
- **R-4 Dark-mode detection has no precedent** — observer on `<html class>` plus its own test.
- **R-5 Browser-test flake budget** — four documented `Timeout 5000ms` CI flakes exist; `retry(3, …, 250)`, no `networkidle`.
- **R-6 First `#[Lazy]` and first Chart.js in the repo** — each gets a dedicated test and a PR note.
- **R-7 Backend contract drift** — if 0082's shapes change, this story's Gherkin and payload change with it.

## Dependencies

- **Blocked on [0082](0082-dashboard-home-overview-backend.md)**.
- `conflict_risk_with`: none pending. It shares `orders`/`blog-posts` list views only through the badge extraction (D-5);
  no other pending story touches them today (0079 edits the blog **editor** view, not the list).

## Debate record

Facilitator: Claude (product-owner role). Participants: frontend-expert, frontend-qa (dispatched as `general-purpose` agents
reading their `.claude/agents/*.md` definition, since the project agent types are not registered in this session). Both read-only.
Owner decisions honoured unchanged: Chart.js; title + 80-character description with no image; include-cancelled checkbox default
off; variable products shown as the parent. Corrections made to the earlier draft: no shared badge components existed; Flux free
has no date picker; one fat component would re-run every query per filter change; the Alpine/Chart.js reactivity trap and the lost
dispatch on lazy mount were missing; the hero with partially-null counters was unspecified; single `OverviewTest.php` split.
