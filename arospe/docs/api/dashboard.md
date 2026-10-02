# Dashboard Routes and Read-Side Actions

Part of [Routes](routes.md) — see [routes.md](routes.md#app-owned-routes) for the full route table. This file covers the `dashboard` route, the home overview page that consumes the read-side actions (story 0083) and the actions behind it (story 0082).

## Table of Contents

- [The route](#the-route)
- [The page: Overview and its widgets](#the-page-overview-and-its-widgets)
- [Read-side actions](#read-side-actions)
- [Shared rules](#shared-rules)
- [Sales, Real income and Orders — definitions](#sales-real-income-and-orders--definitions)
- [Known limitations and follow-ups](#known-limitations-and-follow-ups)

## The route

`GET /dashboard` (`dashboard`, `auth` + `verified`) is `Route::livewire('dashboard', Overview::class)` in [`routes/web.php`](../../routes/web.php) (since story 0083; it was `Route::view` before) and is **deliberately ungated**: every verified user lands there, whatever their permissions. Visibility is decided **per widget** — a user without `orders.view` simply gets no orders widget, not a 403 for the page. The routed component can be asserted with `$route->getAction('livewire_component')`, see [feature tests](../testing/backend/feature-integration-tests.md#asserting-a-routelivewire-route).

## The page: Overview and its widgets

[`App\Livewire\Dashboard\Overview`](../../app/Livewire/Dashboard/Overview.php) is a class-based component with **no public property**: the greeting, the counters and the widget flags are `#[Computed]` values recomputed per render, so nothing is client-writable or serialised into the snapshot. Its view is [`overview.blade.php`](../../resources/views/livewire/dashboard/overview.blade.php).

| Part | Source | Shown when |
| --- | --- | --- |
| Hero greeting + counters | `Overview` + `GetDashboardCounters` | greeting always; each counter only when its value is not `null` (a `0` renders) |
| `BlogWidget` | [`app/Livewire/Dashboard/`](../../app/Livewire/Dashboard/) | `blog.view` (`viewAny` on `BlogPost`) |
| `LowStockWidget` | same | `products.view` |
| `LatestOrdersWidget` | same | `orders.view` |
| Reserved full-width slot | an empty comment between the hero and the grid | story 0086 mounts the sales overview card here |
| Empty state | `dashboard.no_widgets` | no widget and no counter |

- **Three layers per widget** ([pattern](../architecture/authorization/policies-variants-and-routeless.md#an-ungated-page-with-per-module-widgets--three-layers)): `Overview` mounts a widget only when `allowsSafely('viewAny', …)` holds; each widget re-checks the same ability inside its `#[Computed]` method (a replayed snapshot cannot render data the actor lost); the action authorizes again. Widgets are read-only: no public property, no mutating method, and `LatestOrdersWidget` carries no "mark as paid" action. No `wire:poll` or other hooks are needed.
- **Editor links are cosmetic.** `BlogWidget` and `LowStockWidget` link a row to its editor only when `Gate::allows('update', new BlogPost|new Product)` (evaluated once per widget); otherwise the title is plain text. This stands for every row only while those policies ignore the target — see [UI hints](../architecture/authorization/grant-meta-rules-and-ui-hints.md#a-ui-only-gate-for-a-whole-widget--valid-only-while-the-policy-ignores-the-target). Order rows link to the detail (`orders.view` only).
- **Missing permission rows deny.** The page and `GetDashboardCounters` use [`App\Concerns\ChecksAbilitiesSafely`](../../app/Concerns/ChecksAbilitiesSafely.php), so a permission row absent from the database means "not permitted" rather than a 500 ([rule](../architecture/authorization/policies-variants-and-routeless.md#checksabilitiessafely--a-missing-permission-row-means-deny)).
- **Shell and badges:** the card shell is `<x-dashboard.widget>`; statuses render through `<x-order-status-badge>` and `<x-blog-status-badge>`, shared with the orders and blog lists. Copy lives in the `hero`, `counters`, `blog`, `stock`, `orders`, `untitled`, `deleted_customer` and `no_widgets` groups of `lang/{en,es}/dashboard.php`.

## Read-side actions

All live in [`app/Actions/Dashboard/`](../../app/Actions/Dashboard/), take no model, return scalars/enums/`CarbonImmutable` (never Eloquent models, so no relation or snapshot leaks into a Livewire component's public state), and write nothing. The exact row shapes are in each class's `@return` PHPDoc — not restated here.

| Action | Ability (`viewAny` on) | Refusal | Rows | Domain-table queries | Ordering / tie-break |
| --- | --- | --- | --- | --- | --- |
| `GetDashboardCounters` | `User` / `Product` / `Media`, each checked on its own | never throws, never logs: a lacking counter, or one whose permission row is missing, is `null` and its table is not queried | 3 counters (active users, all products, all media rows) | one `COUNT` per permitted counter | — |
| `GetLatestBlogPosts` | `BlogPost` (`blog.view`) | logged + throws, `targetType: blog_post` | 3 published/scheduled posts | 1 | `created_at` desc, `id` desc |
| `GetLowStockProducts` | `Product` (`products.view`) | logged + throws, `targetType: product` | 3 active physical products, lowest effective stock first | 2 (ranking, then one grouped variant count) | effective stock asc, `products.id` asc (never the name) |
| `GetLatestOrders` | `Order` (`orders.view`) | logged + throws, `targetType: order` | 5 orders, every status | 2 (orders, customers eager-loaded `withTrashed()`) | `created_at` desc, `id` desc |
| `GetSalesSeries` | `Order` (`orders.view`) | logged + throws, `targetType: order` | per bucket: Sales and Real income | 1 aggregate | bucket order, zero-filled |
| `GetOrdersSeries` | `Order` (`orders.view`) | logged + throws, `targetType: order` | per bucket: order count and a zero-filled `byStatus` | 1 aggregate | bucket order, zero-filled |

The two series actions take `(SalesGranularity $granularity, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null)`; `null` statuses means [`OrderStatus::defaultDashboardSet()`](../../app/Enums/OrderStatus.php), and the two charts always share identical bucket keys because both delegate to `ResolveSalesBuckets` (see [directory-structure](../conventions/directory-structure/actions.md#appactions--domain-actions-per-area)).

## Shared rules

- **Caller contract: check the ability before calling a refusal-logging action.** The five throwing actions log a refusal through `LogRefusedPrivilegedAttempt` ([authorization](../architecture/authorization/step-up-and-refusal-logging.md#the-dashboard-read-side-actions--one-no-throw-exception-five-refusal-logging-actions)). A caller that invokes them unconditionally writes one warning per unprivileged page load, so a consumer must ask `Gate::allows(...)` first and render the widget only when permitted.
- **Order of operations in the series actions:** authorize, then validate (`ResolveSalesBuckets`), then query — a refusal wins over a validation error.
- **Validation errors are `ValidationException`s** carrying `range` and/or `statuses` keys, with messages from `lang/{en,es}/dashboard.php` (`dashboard.errors.range_invalid`, `range_too_long`, `statuses_required`). The bucket cap is per granularity (`SalesGranularity::maxBuckets()`) and is checked in PHP before any query. Range years must be 1000..9998; the status list is normalized (see [raw SQL and query-input bounds](../security/raw-sql-and-query-input-bounds.md)).
- **Time:** the range is read in `config('app.timezone')`; `created_at` already holds that wall time, so nothing is converted in SQL and the predicate (`>= start AND < endExclusive`) is half-open.
- **Translatable-content seam (D-9):** `GetLatestBlogPosts` and `GetLowStockProducts` read title/body/name only through one private `resolve*()` method each and never name, filter or order on those columns, so the pending translatable-content retrofits swap those methods and nothing else. The row shape types them nullable for that reason.
- **The blog `description` is plain text derived from HTML** (block-level tags `p`, `div`, `h1-h6`, `li`, `ul`, `ol`, `br`, `blockquote` and table parts become one space, then strip tags, then decode entities; inline tags add nothing, so `<b>wor</b>ld` is `world`). A consumer must render it escaped — see [Blade / Livewire output encoding](../security/blade-livewire-output-encoding.md#plain-text-derived-from-html-is-still-output-escaped-by-the-caller).

## Sales, Real income and Orders — definitions

| Measure | Definition |
| --- | --- |
| **Sales** | `SUM(total)` of orders whose status is in the selected set; gross (tax and shipping included), refunds **not** netted, unpaid orders counted. |
| **Real income** | `SUM(total - refunded_amount)` of those orders that are also `paid` or `partially_refunded` **and** not cancelled; a cancelled order never counts even when `cancelled` is selected. |
| **Orders** | The number of orders per bucket and per status; an order counts once, never once per line. |

All three are bucketed by `orders.created_at`, even though `order_payments.paid_at` (story 0084) now records when a payment was marked; switching the bucketing to it is a separate follow-up.

## Known limitations and follow-ups

- **Real income reads 0 in a real store today:** `payment_status` only becomes `paid` through [`MarkOrderAsPaid`](orders.md#markorderaspaid--the-action-contract-story-0084-no-route) (story 0084), whose UI is story 0085, so income stays 0 until an administrator marks an order as paid.
- `refunded_amount` is merchandise-only, so a partially refunded order would overstate income by the refund's tax/shipping share — unobservable while tax and shipping are `0.00`.
- **`orders.created_at` and `blog_posts.created_at` are unindexed** (second resolution). Fine at back-office scale; add `index(created_at)` on `orders` (a `database-expert` story) if the table passes roughly 10^5 rows. No schema change shipped with this story — see [Orders schema](../database/schema-orders.md).
- **Shipped consumers:** story 0083 consumes `GetDashboardCounters`, `GetLatestBlogPosts`, `GetLowStockProducts` and `GetLatestOrders`; the two series actions are story 0086's.
- **Cost:** about ten queries per dashboard load, no caching.
- **Badge `match` without default:** `<x-blog-status-badge>` has no `default` arm, so a new `BlogPostStatus` case would throw `UnhandledMatchError` and 500 the dashboard and the blog list until the map is updated (the order badge falls back to zinc).

_Last updated: 2026-10-02 — Story 0084: the income and bucketing notes now reference `MarkOrderAsPaid` and `order_payments.paid_at`. Still current from story 0083 (Dashboard home overview UI): the route is `Route::livewire`, per-widget gating, the editor-link rule and the reserved 0086 slot._
