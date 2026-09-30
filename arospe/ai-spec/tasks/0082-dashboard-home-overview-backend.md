# [0082] Dashboard home overview — read-side actions (backend)

> **Status: Phase 1 draft.** Written from the project owner's request; the Three Amigos debate (Phase 1)
> and INVEST validation (Phase 2) have **not** run yet. Open questions are listed under
> [Open questions](#open-questions) with a recommended answer each — resolve them in the debate.

## Description

Backend half of the dashboard home page redesign (frontend half: [0083](0083-dashboard-home-overview-ui.md)).
The dashboard at `resources/views/dashboard.blade.php` is still the starter-kit placeholder (five
`x-placeholder-pattern` boxes). The design reference is
[`docs/PRD/images/01-inicio.png`](../../docs/PRD/images/01-inicio.png): a hero banner with three headline
counters, then four shortcut cards. The owner wants the four shortcut cards **replaced** by live widgets, plus a
sales chart. This story adds the read-only queries those widgets need, each as a single-purpose action under
`app/Actions/Dashboard/`, so the Livewire component of 0083 stays a thin caller.

Data required:

1. **Headline counters** — number of *active* users (`users.status = active`), number of products, number of
   images (`media` rows of image kind).
2. **Latest blog posts** — the 3 most recent posts whose status is `published` or `scheduled` (drafts excluded),
   each with title, main image, status and, when `scheduled`, the date it will publish.
3. **Low-stock products** — the 3 products with the lowest stock, to warn before they run out.
4. **Latest orders** — the 5 most recently placed orders.
5. **Sales series** — revenue over time, bucketed by **day, month or year**, or over an **arbitrary date range**.

## Type

`backend | includes database-expert: no` (no migration is planned — see Q-1 for the one case where that could change).

## Decisions proposed (to be ratified in the debate)

- **D-1 — One action per widget**, all read-only, in `app/Actions/Dashboard/`: `GetDashboardCounters`,
  `GetLatestBlogPosts`, `GetLowStockProducts`, `GetLatestOrders`, `GetSalesSeries`. Each authorizes with `Gate`
  against the ability of the module it reads (`users.view`, `products.view`, `blog.view`, `orders.view`) and
  returns a typed DTO/array shape documented in PHPDoc. The dashboard route itself stays ungated
  (`config/modules.php` `dashboard` has `permissions => []`), so **each widget is independently permission-gated**:
  an actor without `orders.view` gets no orders widget and the query is never run.
- **D-2 — Blog widget:** `status IN (published, scheduled)`, soft-deleted excluded (default scope), ordered by
  `created_at desc`, limit 3, eager-load nothing unbounded. For `scheduled` posts the publish date is
  `published_at` (whose meaning is fixed by `status` per the blog schema, D-6 there). Titles are read through the
  translation layer in the viewer's current locale with the default-language fallback.
- **D-3 — Low stock:** `products.stock` ascending, limit 3, only `status = active` and `type = physical`
  (virtual products have no stock to run out). Ties broken by `name`/`id` for determinism. A product with
  variants: see Q-3.
- **D-4 — Latest orders:** `orders.created_at desc`, limit 5, eager-load `customer` only; show
  `order_number`, customer name, `total`, `status`, `payment_status`.
- **D-5 — Sales series:** input is a `granularity` enum (`day|month|year`) and an inclusive `from`/`to` range.
  Presets (today-relative "last 30 days", "this month", "this year") are resolved by the caller into a range;
  the action only knows range + granularity. Revenue = `SUM(total - refunded_amount)` over orders whose status
  is not `cancelled` (Q-4). Buckets with no orders are **zero-filled** so the chart has no gaps. Bucketing is done
  in the application timezone. Guardrails: `from <= to`, and a maximum number of buckets (e.g. 366 day buckets)
  so a "day" granularity over ten years is refused with a `ValidationException` instead of returning 3 650 points.
- **D-6 — Counters** are three cheap `COUNT(*)` queries; no caching in this story (revisit if measured slow).

## Open questions

- **Q-1 — Blog "main image" does not exist in the data model.** `blog_posts` has no `featured_media_id` (verified
  in `create_blog_posts_table`); products have `featured_media_id`, posts do not. Options: **(a, recommended)**
  derive it as the first `<img>` in the post body, `null` → a neutral placeholder, no schema change; **(b)** add a
  `featured_media_id` column + editor field in a separate, earlier story (touches the blog editor and the
  translatable-content work of 0078/0079). Recommendation (a) keeps this story small; (b) is the correct long-term
  model and should be filed as its own story if the owner wants a chosen cover image.
- **Q-2 — "Images" counter:** count all `media` rows, or only those attached/used? Recommend all image rows of the
  media library (it is what the gallery shows).
- **Q-3 — Low stock and variants:** a product with variants keeps stock on each `product_variants` row. Recommend
  ranking by the product's own `stock` for simple products and by the **lowest variant stock** for products that
  have variants, listing the product once. Confirm in the debate.
- **Q-4 — Revenue definition:** net of refunds and excluding `cancelled`? Should `pending`/unpaid orders count?
  Recommend: exclude `cancelled`, subtract `refunded_amount`, count by `orders.created_at`.
- **Q-5 — Low-stock threshold:** the request says "the 3 lowest stock". Recommend exactly that (no configurable
  threshold); a product at `stock <= 0` is shown as out of stock, consistent with `Product::isOutOfStock()`.

## Gherkin (draft)

```gherkin
Scenario: Counters show active users only
  Given an administrator and 4 active users, 1 inactive user and 1 suspended user
  When the administrator's dashboard counters are computed
  Then the users counter is 4

Scenario: Latest posts exclude drafts and include scheduled ones with their date
  Given a blog editor and posts: 1 draft, 2 published, 2 scheduled
  When the latest blog posts are requested
  Then at most 3 posts are returned, newest first, none of them a draft
  And each scheduled post carries the date it will be published

Scenario: Low-stock list is the 3 lowest active physical products
  Given a catalog manager and physical products with stock 50, 2, 0, 7 and a virtual product with stock 0
  When the low-stock products are requested
  Then the products with stock 0, 2 and 7 are returned in that order
  And the virtual product is not returned

Scenario: Latest orders are the 5 most recent
  Given an order manager and 7 orders
  When the latest orders are requested
  Then exactly the 5 newest orders are returned, newest first

Scenario: Sales series is zero-filled per bucket
  Given an order manager and orders on 1 May and 3 May but none on 2 May
  When the sales series is requested per day from 1 May to 3 May
  Then three buckets are returned and the 2 May bucket is 0

Scenario Outline: Sales series supports every granularity
  Given an order manager and orders spread over several days, months and years
  When the sales series is requested per <granularity>
  Then the totals are grouped per <granularity>

Scenario: An oversized range is refused
  Given an order manager
  When the sales series is requested per day over ten years
  Then the request is refused with a validation error

Scenario: A user without the module ability gets nothing
  Given a user without orders.view
  When the latest orders are requested
  Then the action refuses with an authorization error and runs no order query
```

## Files to create/modify

- `app/Actions/Dashboard/GetDashboardCounters.php`, `GetLatestBlogPosts.php`, `GetLowStockProducts.php`,
  `GetLatestOrders.php`, `GetSalesSeries.php`
- `app/Enums/SalesGranularity.php` (`Day|Month|Year`)
- `tests/Feature/Dashboard/*ActionTest.php` (one file per action)
- Docs: `docs/api/routes.md` (dashboard contract), `docs/architecture/overview.md` if a new `Actions/Dashboard`
  folder needs listing.

## Tests to perform

Pest feature tests per action against MySQL with factories: correctness of each Gherkin scenario, authorization
refusal per action, soft-deleted posts/products excluded, ordering ties, empty database (all zeros, empty lists,
zero-filled series), N+1 guard (query-count assertion on the orders and posts actions).

## Expected outcome

Five tested, permission-gated, read-only actions that give the dashboard everything it displays.

## Acceptance criteria

- Each action returns exactly the shape and ordering above and refuses actors lacking its ability.
- No query runs for a widget the actor cannot see.
- The sales series is zero-filled, timezone-consistent and bounded.
- No schema change and no new dependency.

## Definition of Done

- [ ] Phase 1 debate + Phase 2 INVEST recorded in this file
- [ ] Tests written first (red) then green; full suite green
- [ ] Pint and Larastan clean
- [ ] Appsec review (authorization per widget, no data leak across permissions)
- [ ] Docs synced (routes/contracts; ER diagram untouched — no new table)

## Dependencies

- None pending. Consumed by [0083](0083-dashboard-home-overview-ui.md) (frontend, blocked on this).
- Related: if Q-1 resolves to option (b), a new blog featured-image story must land first.
