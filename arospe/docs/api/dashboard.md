# Dashboard Routes and Read-Side Actions

Part of [Routes](routes.md) — see [routes.md](routes.md#app-owned-routes) for the full route table. This file covers the `dashboard` route and the read-side actions behind its home overview (story 0082, backend only; the screen that consumes them is story 0083's).

## Table of Contents

- [The route](#the-route)
- [Read-side actions](#read-side-actions)
- [Shared rules](#shared-rules)
- [Sales, Real income and Orders — definitions](#sales-real-income-and-orders--definitions)
- [Known limitations and follow-ups](#known-limitations-and-follow-ups)

## The route

`GET /dashboard` (`dashboard`, `auth` + `verified`) is still `Route::view('dashboard', 'dashboard')` in [`routes/web.php`](../../routes/web.php) and is **deliberately ungated**: every verified user lands there, whatever their permissions. Story 0082 changed neither the route nor the view. Visibility is decided **per widget, by the action that feeds it** — a user without `orders.view` simply gets no orders widget, not a 403 for the page.

## Read-side actions

All live in [`app/Actions/Dashboard/`](../../app/Actions/Dashboard/), take no model, return scalars/enums/`CarbonImmutable` (never Eloquent models, so no relation or snapshot leaks into a Livewire component's public state), and write nothing. The exact row shapes are in each class's `@return` PHPDoc — not restated here.

| Action | Ability (`viewAny` on) | Refusal | Rows | Domain-table queries | Ordering / tie-break |
| --- | --- | --- | --- | --- | --- |
| `GetDashboardCounters` | `User` / `Product` / `Media`, each checked on its own | never throws, never logs: a lacking counter is `null` and its table is not queried | 3 counters (active users, all products, all media rows) | one `COUNT` per permitted counter | — |
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
- **The blog `description` is plain text derived from HTML** (strip tags, then decode entities). A consumer must render it escaped — see [Blade / Livewire output encoding](../security/blade-livewire-output-encoding.md#plain-text-derived-from-html-is-still-output-escaped-by-the-caller).

## Sales, Real income and Orders — definitions

| Measure | Definition |
| --- | --- |
| **Sales** | `SUM(total)` of orders whose status is in the selected set; gross (tax and shipping included), refunds **not** netted, unpaid orders counted. |
| **Real income** | `SUM(total - refunded_amount)` of those orders that are also `paid` or `partially_refunded` **and** not cancelled; a cancelled order never counts even when `cancelled` is selected. |
| **Orders** | The number of orders per bucket and per status; an order counts once, never once per line. |

All three are bucketed by `orders.created_at`, because there is no `paid_at` column.

## Known limitations and follow-ups

- **Real income reads 0 in a real store today:** no code path sets `payment_status = paid` yet (only `OrderFactory::paid()` does). Follow-up stories 0084/0085 (mark an order paid, `paid_at`).
- `refunded_amount` is merchandise-only, so a partially refunded order would overstate income by the refund's tax/shipping share — unobservable while tax and shipping are `0.00`.
- **`orders.created_at` and `blog_posts.created_at` are unindexed** (second resolution). Fine at back-office scale; add `index(created_at)` on `orders` (a `database-expert` story) if the table passes roughly 10^5 rows. No schema change shipped with this story — see [Orders schema](../database/schema-orders.md).
- Consumer: story 0083 (the dashboard home overview UI) is the first caller of any of these actions.

_Last updated: 2026-10-01 — Story 0082 (Dashboard home overview — backend). New file: the dashboard route stays ungated; the read-side action contract table, shared rules, measure definitions and follow-ups._
