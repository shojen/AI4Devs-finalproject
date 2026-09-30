# [0083] Dashboard home overview — page redesign and sales chart (frontend)

> **Status: Phase 1 draft.** Three Amigos debate and INVEST validation have not run yet. Backend half:
> [0082](0082-dashboard-home-overview-backend.md), which this story is blocked on.

## Description

Replace the placeholder `resources/views/dashboard.blade.php` with the real home page, following the design
reference [`docs/PRD/images/01-inicio.png`](../../docs/PRD/images/01-inicio.png) (hero banner with greeting and
three headline counters) but **substituting its four shortcut cards** (Gestión de usuarios, Impuestos & Envíos,
Productos, Blog) with live widgets:

1. **Hero banner** — time-of-day greeting with the user's first name, and the counters: active users, products,
   images (each shown only if the actor may see that module).
2. **Blog widget** — the 3 latest published/scheduled posts: title, a description truncated to 80 characters
   (posts have no main image yet), a status badge (*Published* / *Scheduled*) and, for scheduled posts, the date
   and time they will go live. Links to the post editor; footer link to the blog list.
3. **Low-stock widget** — the 3 products closest to running out, with stock count and an out-of-stock /
   low-stock badge; links to the product editor. A variable product appears as its **parent**, showing its lowest
   variant stock and a "N variants low" hint, and links to the editor where its variants are managed.
4. **Latest orders widget** — the 5 most recent orders (number, customer, total, status); links to the order
   detail.
5. **Sales chart** — dynamic revenue chart with filters **Day / Month / Year**, a **custom date range** picker
   and an **"Include cancelled orders"** checkbox (unchecked by default); it re-renders without a page reload.

## Type

`frontend | includes database-expert: no`

## Decisions proposed (to be ratified in the debate)

- **D-1 — Livewire component** `App\Livewire\Dashboard\Overview` (class-based, per the repo convention) replaces
  `Route::view('dashboard', 'dashboard')` in `routes/web.php`, keeping the route name `dashboard`. It calls the
  0082 actions; widgets the actor is not authorized for are not rendered.
- **D-2 — Chart.js (decided by the project owner, 2026-09-30):** adds `chart.js` to `package.json` /
  `package-lock.json` — **the dependency change is explicitly approved** (CLAUDE.md requires approval; this is
  it). Integration with Livewire: an Alpine component (`resources/js/`) owns a `<canvas>` inside a `wire:ignore`
  wrapper; the Livewire component holds the series and, after each filter change, dispatches a browser event
  (`$this->dispatch('sales-series-updated', ...)`) that the Alpine component uses to call `chart.update()` — the
  chart instance is created once and destroyed in Alpine's `destroy()`, never recreated per morph. Import only the
  needed Chart.js modules (tree-shaken bar/line controller, scales, tooltip, legend) so the bundle stays small;
  colors are read from the CSS design tokens and refreshed on light/dark change. The canvas is not accessible by
  itself, so D-7's visually hidden data table remains the text alternative.
- **D-3 — Filters:** a segmented control for granularity plus presets (last 7/30 days, this month, this year) and a
  from/to date range; state lives in Livewire `#[Url]` properties so a filtered view is shareable/refresh-safe;
  invalid ranges (from > to, oversized) show a validation message, never a 500.
- **D-4 — Reuse existing components** (`flux:*` cards/badges/date inputs, the shared status badge components used
  by the order and blog lists) rather than writing new ones; check `resources/views/components/` first.
- **D-5 — All copy via translation keys** in new `lang/en/dashboard.php` and `lang/es/dashboard.php`; the topbar
  strings stay as they are. Money and dates are locale-formatted.
- **D-6 — Empty and loading states:** each widget has an empty state (no posts, no low stock, no orders, no
  sales in range) and a `wire:loading` skeleton for the chart while filters change.
- **D-7 — Accessibility:** the chart has a text alternative (a visually hidden table of the same series) and
  colors from the existing design tokens, working in light and dark mode.

## Open questions

- Resolved: charting library is **Chart.js** (D-2), approved by the owner.
- Resolved by the owner (2026-09-30): no blog image, title + 80-character description
  ([0082 R-1](0082-dashboard-home-overview-backend.md#resolved-by-the-project-owner-2026-09-30)); cancelled
  orders are toggled by a checkbox (R-2); variable products show as the parent (R-3).
- **Q-3 — Default chart range:** recommend granularity *Day*, last 30 days.

## Gherkin (draft)

```gherkin
Scenario: The hero shows the greeting and the three counters
  Given an administrator named Laura with 6 active users, 6 products and 12 images
  When the administrator opens the dashboard
  Then the hero greets Laura by time of day
  And it shows the counters 6, 6 and 12

Scenario: The blog widget labels each post's state
  Given a blog editor and one published post and one post scheduled for 12 October 10:00
  When the blog editor opens the dashboard
  Then the blog widget lists both posts with their title and a description of at most 80 characters
  And the published post carries a "Published" badge
  And the scheduled post carries a "Scheduled" badge and the date 12 October 10:00

Scenario: The low-stock widget warns about the emptiest products
  Given a catalog manager and products with stock 0, 2, 7 and 50
  When the catalog manager opens the dashboard
  Then the widget lists the products with stock 0, 2 and 7
  And the product with stock 0 is marked out of stock

Scenario: A variable product with a low variant shows as its parent
  Given a catalog manager and a variable product whose variants have stock 40, 1 and 2
  When the catalog manager opens the dashboard
  Then the low-stock widget lists the parent product once, with lowest stock 1 and "2 variants low"
  And the entry links to that product's editor

Scenario: The cancelled-orders checkbox changes the chart
  Given an order manager and a day with one delivered order of 100 and one cancelled order of 40
  When the order manager opens the dashboard
  Then the chart shows 100 for that day and the "Include cancelled orders" checkbox is unchecked
  When the order manager checks "Include cancelled orders"
  Then the chart shows 140 for that day

Scenario: The latest orders widget lists five orders
  Given an order manager and 7 orders
  When the order manager opens the dashboard
  Then 5 orders are listed, newest first, each linking to its detail page

Scenario: Changing the chart granularity redraws it
  Given an order manager on the dashboard showing sales per day
  When the order manager selects "Month"
  Then the chart shows one point per month without a page reload

Scenario: A custom date range filters the chart
  Given an order manager on the dashboard
  When the order manager picks 1 May to 15 May
  Then the chart shows only sales within that range

Scenario: An invalid range is explained, not crashed
  Given an order manager on the dashboard
  When the order manager picks a start date after the end date
  Then a validation message is shown and the chart keeps its previous data

Scenario: Widgets follow permissions
  Given a user with only the users.view permission
  When the user opens the dashboard
  Then only the users counter is shown
  And no blog, stock, orders or sales widget is rendered

Scenario: Empty store
  Given an administrator and a store with no posts, orders or low-stock products
  When the administrator opens the dashboard
  Then each widget shows its empty state and the counters read 0
```

## Files to create/modify

- `app/Livewire/Dashboard/Overview.php`, `resources/views/livewire/dashboard/overview.blade.php`
- `resources/views/dashboard.blade.php` (removed or reduced to the layout wrapper), `routes/web.php`
- `resources/views/components/dashboard/*.blade.php` only if no existing component fits (D-4)
- `package.json`, `package-lock.json` (add `chart.js`, approved), `resources/js/app.js` (register the component)
- `resources/js/sales-chart.js` — Alpine component wrapping the Chart.js instance (D-2)
- `lang/en/dashboard.php`, `lang/es/dashboard.php`
- `tests/Feature/Dashboard/OverviewTest.php`, `tests/Browser/Dashboard/SalesChartTest.php`

## Tests to perform

Livewire feature tests per Gherkin scenario (rendering, permission gating, filters, validation, empty states),
a browser test for the chart's interactive filtering (the same Chart.js instance is updated, not recreated; no JS
errors; survives a Livewire re-render of the surrounding widgets), a Spanish-locale render test, and a
smoke check in light and dark mode.

## Expected outcome

The dashboard home shows the real store state at a glance, matches the mockup's look, and the sales chart
updates live from its filters.

## Acceptance criteria

- No placeholder pattern remains on the page; the four shortcut cards are gone.
- Each widget matches its Gherkin scenario and is hidden when the actor lacks its ability.
- The chart supports day/month/year, a custom range and the include-cancelled checkbox, with validation and
  empty states.
- All strings are translated in `en` and `es`; layout works at phone width.

## Definition of Done

- [ ] Phase 1 debate + Phase 2 INVEST recorded in this file
- [ ] Tests written first, full suite green (including `tests/Browser`)
- [ ] Pint, Larastan, `npm run build` clean
- [ ] Appsec review (per-widget authorization, no data leak through Livewire public state)
- [ ] Docs synced (routes contract, PRD design-reference note that the shortcut cards were replaced)

## Dependencies

- Blocked on [0082](0082-dashboard-home-overview-backend.md).
- `conflict_risk_with`: none pending (touches `routes/web.php` and the dashboard view only).
