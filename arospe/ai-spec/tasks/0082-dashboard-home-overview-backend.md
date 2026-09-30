# [0082] Dashboard home overview — read-side actions (backend)

> **Status: Phase 1 complete (Three Amigos debate held 2026-09-30).** Ready for Phase 2 (INVEST check by
> `code-reviewer`, not run yet). Frontend companion: [0083](0083-dashboard-home-overview-ui.md), blocked on this story.
> Items marked **⚑ owner to confirm** are facilitator decisions the project owner has not explicitly ratified.
> **Amended 2026-09-30 (owner request):** Sales and Real income became two measures, an orders-by-status series was added, and
> the include-cancelled boolean became one entry of a shared status filter (D-6, D-8). The amendment was written by the facilitator
> without a second debate round; the new definitions carry ⚑ flags and Phase 2 should look at them first.

## Description

Backend half of the dashboard home redesign. `resources/views/dashboard.blade.php` is still the starter-kit
placeholder. The design reference is [`docs/PRD/images/01-inicio.png`](../../docs/PRD/images/01-inicio.png): a hero banner
with three counters and four shortcut cards. The owner wants the shortcut cards **replaced** by live widgets plus a sales
chart. This story adds the read-only queries those widgets need, one single-purpose action each in
`app/Actions/Dashboard/`, so the Livewire components of 0083 stay thin callers.

Data required:

1. **Counters** — active users (`users.status = active`), products (all rows, drafts included), images (every `media` row).
2. **Latest blog posts** — the 3 newest `published` or `scheduled` posts (drafts excluded): title, a description of at most
   80 characters, status and, when scheduled, the date it will publish.
3. **Low-stock products** — the 3 products with the lowest effective stock; a variable product counts by its lowest variant
   and is listed once, as the parent.
4. **Latest orders** — the 5 most recent orders.
5. **Sales overview series** (amended 2026-09-30, owner request) — over a day/month/year range, three measures that the
   UI shows as a KPI strip and **two charts**: **Sales** (total sold, gross) and **Real income** (money actually collected,
   net of refunds) in one money chart, and the **number of orders** (broken down by status) in a second chart. All three
   obey one shared **order-status filter**.

## Type

`backend | includes database-expert: no` — no migration, no model, no seeder, no route, no permission-catalog change, no new
dependency. The one schema observation (no index on `orders.created_at`) is recorded as a follow-up trigger, not scope.

## Verified facts that shaped the story

- **`media` has no kind/type column** — every row is a gallery image, so "images" is `Media::count()`.
- **`Product` and `ProductVariant` have no `SoftDeletes`**; `BlogPost`, `User` and `Customer` do; `Order` has none
  (cancelling is a status). Orders **may reference a soft-deleted customer**.
- **`orders.refunded_amount` is merchandise-only** (quantity × unit price); `total` includes tax and shipping.
  A **full refund auto-cancels** the order (`AutoCancelFullyRefundedOrder`).
- **`config/app.php` timezone is `Europe/Madrid`** and Laravel stores `created_at` as Madrid wall-clock time — no
  `CONVERT_TZ` anywhere.
- **`orders.created_at` and `blog_posts.created_at` are second-resolution and unindexed**; factory rows share timestamps.
- **`Customer` has a single `name`**; `PaymentStatus` = `pending_payment|paid|refunded|partially_refunded`.
- Policies map to the abilities: `UserPolicy`/`ProductPolicy`/`BlogPostPolicy`/`OrderPolicy::viewAny` →
  `users.view`/`products.view`/`blog.view`/`orders.view`; `MediaPolicy::VIEW_PERMISSION` = `media.view`.
- **`Product::isOutOfStock()` reads the parent's own stock** and must not be reused for variable products.

## Decisions

### D-1 — Six actions, authorized per module through `LogRefusedPrivilegedAttempt`

`GetDashboardCounters`, `GetLatestBlogPosts`, `GetLowStockProducts`, `GetLatestOrders`, `GetSalesSeries`, `GetOrdersSeries`
(the last two gated by `orders.view`; they share the non-action helper `ResolveSalesBuckets`), all `__invoke`,
read-only, in `app/Actions/Dashboard/`. The four list/series actions authorize with the project wrapper
`LogRefusedPrivilegedAttempt::authorize('viewAny', <Model>::class, targetType: ...)` as their **first statement** (never a
bare `Gate::authorize()`, per story 0015b), so a refusal is logged and throws `AuthorizationException` **before validation
and before any query**. Super Admin passes via `Gate::before`. The dashboard **route stays ungated**; each widget is
independently gated.

**`GetDashboardCounters` is the deliberate exception**: it spans three modules, so it never throws. Each counter is guarded
by its own `Gate::allows` (`users.view`, `products.view`, `media.view`) and is `null` when the actor lacks it; **no count query
runs for a null counter and nothing is logged** (a hidden counter is not an attempt). Return
`array{users: ?int, products: ?int, images: ?int}`.

**Caller contract (binding on 0083):** a caller must check the ability itself (`Gate::allows`/`@can`) and call an action only
when it is already permitted, otherwise every unprivileged dashboard load would write a "Privileged action refused" warning.

### D-2 — Return values are arrays of scalars, never Eloquent models

PHPDoc array shapes (Larastan level 7), enums kept as enums, dates as `CarbonImmutable`, **money as decimal strings, never
floats**. Returning no models stops the Livewire component lazy-loading relations or leaking columns (e.g. address snapshots)
into public state. `name`/`title` are **nullable** in every shape (see D-9).

- Posts: `list<array{id: string, title: ?string, description: string, status: BlogPostStatus, publishAt: ?CarbonImmutable}>`
- Low stock: `list<array{id: string, name: ?string, sku: string, effectiveStock: int, isOutOfStock: bool, hasVariants: bool, lowVariantCount: int}>`
- Orders: `list<array{id: string, orderNumber: string, customerName: ?string, total: string, status: OrderStatus, paymentStatus: PaymentStatus, createdAt: CarbonImmutable}>`
- Money series (`GetSalesSeries`): `array{granularity: SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, statuses: list<OrderStatus>, totalSales: string, totalIncome: string, points: list<array{bucket: string, label: CarbonImmutable, sales: string, income: string}>}`
- Orders series (`GetOrdersSeries`): `array{granularity: SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, statuses: list<OrderStatus>, totalOrders: int, points: list<array{bucket: string, label: CarbonImmutable, total: int, byStatus: array<string, int>}>}` — `byStatus` has a key for **every** selected status (zero-filled), keyed by the `OrderStatus` value.
  — `bucket` is the machine key (`2026-05-01` / `2026-05` / `2026`); the UI localizes `label`.

### D-3 — Latest blog posts

`status IN (published, scheduled)`, soft-deleted excluded by the default scope, `ORDER BY created_at DESC, id DESC`, limit 3,
one query, no relations. Ordering by `created_at` (not `published_at`) keeps the meaning "most recently created"; a scheduled
post created long ago can rank low — accepted **⚑ owner to confirm**. `publishAt` is `published_at` **only when the status is
`scheduled`** (status governs the column's meaning), else `null`; a scheduled post whose date already passed (scheduler not yet
run) is still listed with its past date.

**Description** (R-1 of the owner, refined): derive from `body`, in this order — take the first 2 000 characters
(`mb_substr`, so a `mediumText` body is not processed whole) → `strip_tags` → `html_entity_decode` → collapse whitespace
(`/\s+/u`) → trim → if longer than 80, `Str::limit($text, 79, '')` followed by `…`, so the **total never exceeds 80
characters including the ellipsis**. Decoding happens **after** stripping so an encoded `&lt;b&gt;` stays literal text. A
`null`, empty or tags-only body yields `''`. Multibyte-safe. The output is plain text and is only ever rendered escaped.

### D-4 — Low-stock products

One grouped query, no per-product query:

```php
$variantMin = ProductVariant::query()->selectRaw('product_id, MIN(stock) AS min_variant_stock')->groupBy('product_id');
Product::query()->leftJoinSub($variantMin, 'v', 'v.product_id', '=', 'products.id')
    ->where('products.status', ProductStatus::Active)->where('products.type', ProductType::Physical)
    ->select('products.*')
    ->selectRaw('COALESCE(v.min_variant_stock, products.stock) AS effective_stock')
    ->selectRaw('v.min_variant_stock IS NOT NULL AS has_variants')
    ->orderBy('effective_stock')->orderBy('products.id')->limit(3);
```

- **Effective stock** = the lowest variant stock when the product has variants (the parent's own `stock` is then ignored),
  else `products.stock`. Stock is signed: negative stock ranks first and is out of stock.
- Only **active, physical parents** are eligible; variants of draft/virtual parents never surface. Tie-break is **`id`** —
  never `name` (0076 moves it).
- `isOutOfStock` = `effectiveStock <= 0`, computed here, **not** via `Product::isOutOfStock()`.
- **`lowVariantCount` — ⚑ owner to confirm.** The cut-off is the `effectiveStock` of the last returned row; a product's
  `lowVariantCount` is the number of its variants with `stock <= cut-off` (0 for a simple product), obtained in **one extra
  grouped query** for all returned parents. Variants 40/1/2 among products with effective stocks 1, 7, 9 give cut-off 9 →
  count 3; the Gherkin below fixes concrete numbers. Total: **2 queries** regardless of catalog size.

### D-5 — Latest orders

`ORDER BY created_at DESC, id DESC`, limit 5, all statuses (cancelled included). Eager-load the customer as
**`with(['customer' => fn ($q) => $q->withTrashed()->select('id', 'name')])`** — a soft-deleted customer must not produce a
null and crash the widget. Select only the needed order columns. **2 queries** total. `total` stays the decimal string.

### D-6 — Sales overview: money series and orders series (amended 2026-09-30)

Two actions sharing one internal collaborator, **`ResolveSalesBuckets`** (range normalization, cap validation, bucket list and
zero-fill), so both charts always show identical buckets:

- `GetSalesSeries::__invoke(SalesGranularity $granularity, CarbonInterface $from, CarbonInterface $to, array $statuses = OrderStatus::defaultDashboardSet()): array`
- `GetOrdersSeries::__invoke(…same arguments…): array`

**Shared status filter (replaces the old `includeCancelled` boolean — ⚑ owner to confirm):** `$statuses` is a non-empty list of
`OrderStatus`; the default is **every status except `cancelled`**, so "Include cancelled orders" (the owner's earlier checkbox,
unchecked by default) is simply the `cancelled` entry of this list. An empty list is refused with a `ValidationException` on
`statuses`. The filter applies to **Sales and Orders**; **Real income ignores the cancelled status by definition** (see below).

**Definitions (single source of truth, shown in the UI):**

| Measure | Formula | Notes |
| --- | --- | --- |
| **Sales** ("ventas totales") | `SUM(total)` of orders whose `status ∈ $statuses` | gross: tax and shipping included, **refunds not netted**; counts unpaid orders (bank transfers) |
| **Real income** ("ingresos reales") | `SUM(total − refunded_amount)` of orders with `payment_status ∈ {paid, partially_refunded}` **and** `status ≠ cancelled` **and** `status ∈ $statuses` | money actually collected, net of refunds; fully refunded orders (`payment_status = refunded`) and unpaid orders are excluded |
| **Orders** | `COUNT(*)` of orders whose `status ∈ $statuses`, plus a per-status breakdown | one row per order, never per line |

All three are bucketed by **`orders.created_at`** (there is no `paid_at`/`placed_at` column; adding one is a schema change this
story bans — follow-up below). Because income is attributed to the order's creation day, an order created on 30 April and paid on
2 May counts as April income. **Known limitations, accepted and to be documented in the tooltip/docblock:** (a) `refunded_amount` is
merchandise-only, so a *partially* refunded order overstates income by the tax/shipping share of the refund; (b) **no code path in
the application sets `payment_status = paid` yet** — only `OrderFactory::paid()` does, and the refund action derives
`partially_refunded`/`refunded` from it — so in a real store Real income reads 0 until a payment-capture story exists; the
dashboard must show a neutral empty state for it rather than an error.

`GetSalesSeries` implementation notes (the rest of this decision applies to both actions):

- **Order of operations:** authorize → validate → query.
- **Range normalization:** `from` → `setTimezone('Europe/Madrid')->startOfDay()`, `to` → end of day, inclusive; predicate is
  half-open and sargable: `created_at >= from-start` and `created_at < day-after-to-start` (never `whereDate`).
- **Bucket caps, on bucket count, not span:** `SalesGranularity` carries them — **Day 366, Month 120, Year 50**. `from > to` or
  too many buckets → `ValidationException::withMessages(['range' => ...])` (single key `range`, which 0083 renders and
  translates). Cap is computed in PHP before touching the database.
- **Timezone:** `created_at` is already Madrid wall time, so bucket in SQL **without `CONVERT_TZ`**. DST days
  (2026-03-29, 2026-10-25) group correctly for that reason.
- **Bucketing SQL:** `DATE_FORMAT(created_at, '<fmt>') AS bucket` with the format string **interpolated from the enum** (a
  closed constant, never input) and `GROUP BY bucket` — bound parameters would differ between SELECT and GROUP BY and fail
  under `ONLY_FULL_GROUP_BY`.
- **Money series query:** one aggregate query per action, using conditional aggregation —
  `SUM(total) AS sales` and `SUM(CASE WHEN payment_status IN ('paid','partially_refunded') AND status <> 'cancelled' THEN total - refunded_amount ELSE 0 END) AS income`
  (enum values interpolated from the enums, never bound input), `WHERE status IN (<statuses>)`, grouped by `bucket`. Decimal
  strings throughout, **no clamp** (a negative bucket exposes bad data). Refunds are attributed to the **order's** creation day.
- **Orders series query:** one query, `GROUP BY bucket, status` with `COUNT(*)`, pivoted in PHP into `byStatus`.
- **Zero-fill in PHP** with `CarbonPeriod` over the normalized range, merged over the SQL result: money default `'0.00'`,
  counts default `0`, every selected status present in `byStatus`; ascending, no duplicates, length = bucket count.
- **Naming in the UI (copy lives in 0083):** "Sales" = orders placed (gross), "Real income" = money collected net of refunds,
  "Orders" = count. Never label Sales as "Revenue" or "cash received".

### D-7 — Counters

`Media::query()->count()`, `Product::query()->count()`, `User::query()->where('status', Active)->count()` (soft-deleted
excluded). **At most three `COUNT` queries; zero for an actor with none of the three abilities.** No caching (revisit only if
measured slow).

### D-8 — Cancelled orders and the status filter (owner decisions, amended 2026-09-30)

The owner's "include cancelled orders" checkbox (default off) stays a UI control, now one entry of the shared status filter of
D-6 (`cancelled ∈ $statuses`). The owner's 2026-09-30 request adds filtering the order count **by status** and separating
**total sales** from **real income**; both are covered by D-6. `OrderStatus::defaultDashboardSet()` (every case except
`Cancelled`) is the single place that default lives.

### D-9 — Translatable-content seam (risk: pending 0076 / 0078)

**Risk found in the debate.** Pending stories [0076](0076-translatable-content-retrofit-products-backend.md) and
[0078](0078-translatable-content-retrofit-blog-posts-backend.md) (both `ready`, unclaimed) **delete `products.name` and
`blog_posts.title/body`** and move them into translation tables. Neither knows about this story. Decision (**⚑ owner to
confirm**): **no hard dependency** on them (they are large and would stall the dashboard) — instead **one read seam**:

- name/title/body are read **only** through one private method per action (`resolveTitle`, `resolveBody`, `resolveName`),
  returning the plain attribute today; the queries `select('products.*')`/`blog_posts.*` and never name a moving column and
  never `orderBy`/`where`/`pluck` on it.
- Whichever of {0076, 0078, 0082} **lands last performs the conversion**: swap the seam to `translated('title')` +
  `withTranslationsFor(...)` in the viewer's UI locale with default-language fallback. Each of 0076/0078 gets a
  "consumers to migrate: `App\Actions\Dashboard\*`" line in its DoD; 0082 gets an acceptance criterion and one seam test per action.
- `tasks-status.json`/`tasks-map.md`: `conflict_risk_with` between 0082 and each of 0076/0078 (done in this pass).

## Open questions closed by the debate

| Q | Resolution |
| --- | --- |
| Q-2 images counter | all `media` rows (there is no kind column; "used" would need joins over three tables) |
| Q-4 pending/unpaid in sales | superseded by the 2026-09-30 amendment: **Sales** (gross, unpaid included) and **Real income** (paid, net of refunds) are now two measures; date basis `created_at` for both |
| Q-5 low-stock threshold | none; exactly the 3 lowest effective stocks |

Still **⚑ owner to confirm** (defaults applied): post ordering by `created_at`; `lowVariantCount` cut-off semantics;
products counter includes drafts; the D-9 no-hard-dependency approach.

## Gherkin

Actors are named business roles. "Catalog manager" and "order manager" are added to the glossary
(`docs/testing/frontend/gherkin-guidelines.md`) when this story is ratified. Backend tests translate "opens the dashboard" to
invoking the action.

```gherkin
Scenario: The dashboard counts only active users
  Given Laura, an administrator, and 3 other active users, 1 inactive user and 1 suspended user
  When Laura opens the dashboard
  Then the users counter shows 4

Scenario: A deleted user is not counted
  Given Laura, an administrator, and 2 other active users of whom 1 has been deleted
  When Laura opens the dashboard
  Then the users counter shows 2

Scenario: Counters the actor may not see are never read
  Given Uma, a user manager who may not view products or images
  When Uma opens the dashboard
  Then Uma sees the users counter only
  And no product or image information is read

Scenario: An empty shop shows zeros
  Given Laura, an administrator, and a shop with no products, images or orders
  When Laura opens the dashboard
  Then the products and images counters show 0 and every list is empty
  And the sales chart shows every day at zero

Scenario: The latest posts exclude drafts
  Given Bea, a blog editor, and posts: 1 draft, 2 published and 2 scheduled
  When Bea opens the dashboard
  Then the 3 newest of the published and scheduled posts are shown, newest first
  And no draft is shown

Scenario: A scheduled post shows the date it will be published
  Given Bea, a blog editor, and a post scheduled for 15 June 2026
  When Bea opens the dashboard
  Then the post is shown as scheduled for 15 June 2026

Scenario: A deleted post is not shown
  Given Bea, a blog editor, and the 3 newest posts of which one has been deleted
  When Bea opens the dashboard
  Then the 3 newest posts that were not deleted are shown

Scenario Outline: A post's description is at most 80 characters of plain text
  Given Bea, a blog editor, and a published post whose body has <body_length> characters of text
  When Bea opens the dashboard
  Then the description has <shown_length> characters
  Examples:
    | body_length | shown_length |
    | 50          | 50           |
    | 80          | 80           |
    | 300         | 80           |

Scenario: A post's description is plain text
  Given Bea, a blog editor, and a published post whose body is formatted text with a bold word and a special character
  When Bea opens the dashboard
  Then the description shows the same words without formatting

Scenario: A post without content has an empty description
  Given Bea, a blog editor, and a published post with no body
  When Bea opens the dashboard
  Then the post is shown with an empty description

Scenario: The low-stock list is the 3 lowest active physical products
  Given Carla, a catalog manager, and physical products with stock 50, 2, 0 and 7, and a virtual product with stock 0
  When Carla opens the dashboard
  Then the products with stock 0, 2 and 7 are listed in that order
  And the virtual product is not listed

Scenario Outline: Products that cannot run out are never listed
  Given Carla, a catalog manager, and an out-of-stock product that is <kind>
  When Carla opens the dashboard
  Then the product is not listed as low stock
  Examples:
    | kind              |
    | still a draft     |
    | a virtual product |

Scenario: A product owed to customers is listed first
  Given Carla, a catalog manager, and products with stock 3, -2 and 0
  When Carla opens the dashboard
  Then the product with stock -2 is listed first

Scenario: Products with equal stock are always listed in the same order
  Given Carla, a catalog manager, and 4 products that each have 1 unit in stock
  When Carla opens the dashboard
  Then 3 products are listed
  And opening the dashboard again lists the same 3 in the same order

Scenario: A variable product is listed once, as the parent
  Given Carla, a catalog manager, a variable product whose variants have 40, 1 and 2 units, and a simple product with 9 units
  When Carla opens the dashboard
  Then the variable product is listed once, as the parent, with 1 unit left
  And none of its variants is listed on its own

Scenario: A variable product's own stock is ignored
  Given Carla, a catalog manager, and a variable product with 999 units of its own whose variants have 40 and 50 units
  When Carla opens the dashboard
  Then the product is ranked by 40 units

Scenario: A variable product reports how many variants are low
  Given Carla, a catalog manager, and products whose lowest stocks are 1 (a variable product with variants of 1 and 2 units), 5 and 6
  When Carla opens the dashboard
  Then the variable product reports 2 variants at or below the 6-unit cut-off

Scenario: The latest orders are the 5 most recent
  Given Olga, an order manager, and 7 orders
  When Olga opens the dashboard
  Then exactly the 5 newest orders are listed, newest first

Scenario: Orders placed in the same second are listed in a stable order
  Given Olga, an order manager, and 6 orders placed in the same second
  When Olga opens the dashboard
  Then 5 orders are listed
  And opening the dashboard again lists the same 5 in the same order

Scenario: An order from a deleted customer is still listed
  Given Olga, an order manager, and an order whose customer has been deleted
  When Olga opens the dashboard
  Then the order is listed with that customer's name

Scenario Outline: Cancelled orders count in sales only when included
  Given Olga, an order manager, and one delivered order of 100 and one cancelled order of 40 on the same day
  When Olga views daily sales <choice>
  Then that day's sales are <sales>
  Examples:
    | choice                             | sales |
    | without including cancelled orders | 100   |
    | including cancelled orders         | 140   |

Scenario: Unpaid orders count in sales but not in real income
  Given Olga, an order manager, and a pending order of 60 awaiting a bank transfer
  When Olga views the daily sales overview
  Then that day's sales are 60
  And that day's real income is 0

Scenario: A paid order counts in both sales and real income
  Given Olga, an order manager, and a paid order of 100
  When Olga views the daily sales overview
  Then that day's sales are 100
  And that day's real income is 100

Scenario Outline: A refund reduces real income but not sales
  Given Olga, an order manager, and a paid order of <total> of which <refunded> was refunded
  When Olga views the daily sales overview for the day of the order
  Then that day's sales are <total>
  And that day's real income is <income>
  Examples:
    | total | refunded | income |
    | 100   | 30       | 70     |

Scenario: A fully refunded order yields no real income
  Given Olga, an order manager, and a paid order of 100 that was fully refunded and therefore cancelled
  When Olga views the daily sales overview including cancelled orders
  Then that day's sales are 100
  And that day's real income is 0

Scenario: Cancelled orders never count as real income
  Given Olga, an order manager, and a paid order of 80 that was cancelled
  When Olga views the daily sales overview including cancelled orders
  Then that day's real income is 0

Scenario: The orders chart counts orders per day
  Given Olga, an order manager, and 3 orders on 1 May and 1 order on 3 May
  When Olga views the daily orders from 1 May to 3 May
  Then 1 May shows 3 orders, 2 May shows 0 and 3 May shows 1

Scenario: The orders chart breaks each period down by status
  Given Olga, an order manager, and on 1 May 2 pending orders and 1 shipped order
  When Olga views the daily orders for 1 May
  Then 1 May shows 3 orders: 2 pending and 1 shipped
  And every other status shows 0

Scenario Outline: The status filter narrows sales, income and orders alike
  Given Olga, an order manager, and on 1 May a paid delivered order of 100, a paid shipped order of 50 and a pending order of 20
  When Olga views the daily overview for 1 May filtered to <statuses>
  Then sales are <sales>, real income is <income> and orders are <orders>
  Examples:
    | statuses           | sales | income | orders |
    | delivered          | 100   | 100    | 1      |
    | delivered, shipped | 150   | 150    | 2      |
    | pending            | 20    | 0      | 1      |
    | all except cancelled | 170 | 150    | 3      |

Scenario: The default status filter leaves cancelled orders out
  Given Olga, an order manager, and a delivered order of 100 and a cancelled order of 40 on the same day
  When Olga views the daily overview without choosing statuses
  Then sales are 100 and orders are 1

Scenario: An empty status filter is refused
  Given Olga, an order manager
  When Olga views the daily overview with no status selected
  Then Olga is told to pick at least one status

Scenario: The orders chart and the money chart always share the same periods
  Given Olga, an order manager, and orders on 1 May and 3 May
  When Olga views the daily overview from 1 May to 3 May
  Then both the money series and the orders series show the same three days

Scenario: Days without orders show zero
  Given Olga, an order manager, and orders on 1 May and 3 May but none on 2 May
  When Olga views daily sales from 1 May to 3 May
  Then three days are shown and 2 May shows no sales

Scenario Outline: Sales are grouped by the chosen period
  Given Olga, an order manager, and orders of 10 on 30 April 2026, 20 on 1 May 2026 and 40 on 1 May 2027
  When Olga views sales <period> from 30 April 2026 to 1 May 2027
  Then the sales shown are <shown>
  Examples:
    | period    | shown                                                                  |
    | per day   | 10 on 30 April, 20 on 1 May, 40 on 1 May 2027, zero on every other day |
    | per month | 10 in April 2026, 20 in May 2026, 40 in May 2027, zero in other months |
    | per year  | 30 in 2026, 40 in 2027                                                 |

Scenario: An order just after midnight belongs to the new day
  Given Olga, an order manager, and an order placed at 00:30 on 1 May in the shop's local time
  When Olga views daily sales from 30 April to 1 May
  Then that order counts towards 1 May

Scenario: A range that ends on a day includes that whole day
  Given Olga, an order manager, and an order placed at 23:59 on 1 May
  When Olga views daily sales from 1 May to 1 May
  Then that order counts towards 1 May

Scenario: A leap day is its own day
  Given Olga, an order manager, and an order placed on 29 February 2028
  When Olga views daily sales for February 2028
  Then 29 days are shown and 29 February shows that order

Scenario: The sales of a single day are one period
  Given Olga, an order manager
  When Olga views sales per month for a single day
  Then one period is shown

Scenario: A range that ends before it starts is refused
  Given Olga, an order manager
  When Olga views daily sales from 5 May to 1 May
  Then Olga is told the range is not valid

Scenario Outline: A range with too many periods is refused
  Given Olga, an order manager
  When Olga views sales <period> over <span>
  Then Olga is told the range is too long
  And no sales are shown
  Examples:
    | period    | span            |
    | per day   | 367 days        |
    | per month | 121 months      |
    | per year  | 51 years        |

Scenario Outline: A user without access to a module reads none of its data
  Given a user who may not view <module>
  When the user opens the dashboard
  Then the user is refused the <widget> widget
  And no <module> information is read
  Examples:
    | module   | widget        |
    | orders   | latest orders |
    | orders   | sales chart   |
    | products | low stock     |
    | blog     | latest posts  |

Scenario: A super administrator sees every widget
  Given Sara, a super administrator
  When Sara opens the dashboard
  Then every widget is shown
```

## Files to create/modify

Create:
- `app/Actions/Dashboard/GetDashboardCounters.php`, `GetLatestBlogPosts.php`, `GetLowStockProducts.php`, `GetLatestOrders.php`,
  `GetSalesSeries.php` (Sales + Real income), `GetOrdersSeries.php` (count by status), `ResolveSalesBuckets.php` (shared range/cap/zero-fill)
- `OrderStatus::defaultDashboardSet()` (every case except `Cancelled`) in `app/Enums/OrderStatus.php`
- `app/Enums/SalesGranularity.php` — string-backed `Day|Month|Year`; carries SQL format, PHP key format, `CarbonPeriod` step and bucket cap (no `switch` in the action)
- `tests/Feature/Dashboard/` (see Tests)

Modify: `database/seeders/DemoDataSeeder.php` (seed a share of orders as **paid** via `OrderFactory::paid()` and a few as
cancelled, so Real income and the status chips show data; update the existing demo-seeder test's expectations, and keep the
seeder's idempotence and its query budget intact) — no other application code. Docs (Phase 6, `docs-keeper`): `docs/api/routes.md`, `docs/architecture/overview.md`
(new `Actions/Dashboard` folder), `docs/architecture/authorization.md` (widgets gated per action, route stays ungated),
`docs/testing/frontend/gherkin-guidelines.md` glossary (two roles). Coordination: `ai-spec/tasks-status.json`,
`ai-spec/tasks-map.md`, plus a "consumers to migrate" line in 0076 and 0078 (Phase 6).

## Tests to perform

Pest 4 feature tests on MySQL, factories, `seed(RolePermissionSeeder)`, explicit `created_at` (never rely on factory
timestamps), `Carbon::setTestNow` in `Europe/Madrid`.

Files under `tests/Feature/Dashboard/`: `GetDashboardCountersTest`, `GetLatestBlogPostsTest`, `GetLowStockProductsTest`,
`GetLatestOrdersTest`, `GetSalesSeriesTest` (split `…GranularityTest`/`…TimezoneTest` past ~400 lines),
`DashboardActionsAuthorizationTest`, `DashboardActionsQueryCountTest`; plus a pure unit test for the description derivation
in `tests/Unit/Actions/Dashboard/` if it is extracted to its own class.

Required cases (matrix agreed with backend-qa):

- **Counters:** active-only users; soft-deleted user; products incl. draft/virtual; media count incl. an unattached row; empty DB;
  partial abilities → the others are `null` **and absent from the query log**; none → all `null`, zero queries; Super Admin; ≤ 3 queries.
- **Posts:** drafts/soft-deleted/stale-`published_at` draft excluded; fewer than 3; ties by `id desc` and identical on repeat;
  scheduled `publishAt` vs published `null`; past-dated scheduled still listed; description: `null`/empty/`<p></p>`/tags-only,
  HTML + entities (`&amp; &lt;b&gt;` stays literal), `<script>` text, multibyte (`ñ`, `日本語`, emoji — valid UTF-8, ≤ 80), 79/80/81/300
  characters, image-only body, 255-char title unchanged; 1 query.
- **Low stock:** ties and ties straddling the cut-off (stable on repeat); zero-variant vs many variants; variant negative stock;
  parent stock 999 ignored / parent 0 with variants 40 and 50 ranks by 40; draft/virtual parent with a low variant excluded;
  parent with all variants deleted falls back to its own stock; 60 variants → still one row; `lowVariantCount` per D-4;
  **query count constant (2)** for 3 vs 30 products.
- **Orders:** 5 of 7; ties; cancelled included; trashed customer; exactly 2 queries with `Model::preventLazyLoading()` on.
- **Series (money + orders, amended 2026-09-30):** status-filter dataset over all five statuses (default set excludes cancelled;
  explicit `cancelled` includes it; empty list refused on `statuses`); **Sales = gross `SUM(total)`**, unaffected by refunds;
  **Real income** dataset over {payment status × order status × refunded amount}: pending → 0, paid → total, partially refunded →
  `total − refunded`, refunded → 0, cancelled → always 0 even with `cancelled` selected; income ignores the fully-refunded
  residual (documented limitation); orders count per status with every selected status present and zero-filled, totals equal the
  sum of `byStatus`; money and orders series share identical bucket keys for the same input; the orders query is 1 statement and the
  money query is 1 statement; anomaly `refunded > total` not clamped; exact decimal sums (0.10 + 0.20 = `0.30`);
  zero-fill; single-day range for every granularity; `from > to`; exactly-cap accepted / cap + 1 refused for each granularity;
  month across a year boundary; leap day 2028-02-29; midnight boundaries in Madrid (`00:00:00`, `23:59:59`), `00:30` Madrid
  lands on the same day, **DST days** each one bucket; orders outside the range excluded; ascending unique keys; 1 aggregate
  query; authorization refusal wins over an invalid range.
- **Authorization matrix (dataset):** five actions × {no ability, only its ability, the other abilities, Super Admin}; refusals
  assert a logged warning and no query on the target table; the counters partial-visibility case asserts **no** warning.
  An arch/read-only guard: only `SELECT`s run.
- **D-9 seam tests:** one per action asserting title/name/description resolve through the seam; **`->todo()` placeholders**
  for the locale cases (Spanish translation, default-language fallback, description from the translated body) until 0076/0078 land.
- Keep `tests/Feature/DashboardTest.php` green (a user with no permissions still gets HTTP 200 at `/dashboard`).

## Expected outcome

Five tested, permission-aware, read-only actions returning scalar shapes that give the dashboard everything it displays,
with a documented seam so the pending translatable-content retrofits do not break them.

## Acceptance criteria

- Each action returns exactly the shape, filtering, ordering and tie-breaks above; the list/series actions refuse an actor
  lacking their ability with a **logged** `AuthorizationException` before any query; the counters action never throws.
- No query runs for a module the actor cannot see; query counts: counters ≤ 3, posts 1, low stock 2, orders 2, series 1.
- The description never exceeds 80 characters including the ellipsis, is multibyte-safe and plain text.
- The money and orders series are zero-filled, Madrid-day-consistent, **bucket-identical to each other**, bounded by the
  per-granularity caps, and raise `ValidationException` on `range` (and `statuses` for an empty filter).
- Sales is gross `SUM(total)`; Real income is `SUM(total − refunded_amount)` over paid/partially-refunded, non-cancelled orders;
  Orders is a count with a per-status breakdown; all three obey the one status filter (income never counts cancelled orders).
- No model is returned; money is a decimal string; `name`/`title` are nullable in every shape.
- name/title/body are read only through the seam; no moving column is selected by name, ordered on or filtered on.
- No schema change and no new dependency.

## Definition of Done

- [x] Phase 1 debate recorded in this file
- [ ] Phase 2 INVEST validation (`code-reviewer`)
- [ ] Tests written first (red) then green; **full suite** green (unscoped)
- [ ] Pint (unscoped) and Larastan clean
- [ ] Appsec review (per-module authorization, refusal logging, no cross-module leakage, no models returned)
- [ ] Docs synced (routes/contracts, overview, authorization, glossary; ER diagram untouched — no new table)
- [ ] `consumers to migrate` line added to 0076 and 0078
- [ ] `DemoDataSeeder` seeds paid and cancelled orders; its test updated and green
- [ ] Follow-up recorded: story "Record order payment" (mark an order paid + `paid_at`), to be created by the project owner's call
- [ ] Follow-up recorded: add `index(created_at)` on `orders` (via a `database-expert` story) if the table grows past ~10⁵ rows

## Risks and follow-ups

- **Retrofit collision (0076/0078)** — mitigated by D-9; whichever lands last converts the seam.
- **No index on `orders.created_at` / `blog_posts.created_at`** — filesort/scan at backoffice scale; not in scope.
- **Refund/tax residual and refund-day attribution** (D-6) — documented, accepted.
- **Real income depends on a payment state that nothing in the app writes yet.** The column `orders.payment_status` and the
  `PaymentStatus` enum (`pending_payment|paid|refunded|partially_refunded`) exist and are read (`Order::isRefundable()`, the orders
  list badge), but a search of `app/` (2026-09-30) finds exactly two writers: `CreateOrder` always sets `PendingPayment`, and
  `RecordRefund` derives `Refunded`/`PartiallyRefunded` from an existing state. **No action, Livewire component or command sets
  `Paid`** (only `OrderFactory::paid()` does), and `DemoDataSeeder` creates its orders without it, so every seeded order is
  `pending_payment`. The PRD's refund scenarios already assume a "Pagado" state, so this is a gap of the order module, not of the
  dashboard. **Owner decision (2026-09-30):** keep income defined by `payment_status` and handle the gap separately —
  (1) this story makes `DemoDataSeeder` create a share of **paid** orders (and a few cancelled ones) so the dashboard is
  demonstrable; (2) a **future story "Record order payment"** (mark an order paid from its detail screen, with a `paid_at` column so
  income can be dated by payment rather than creation) is recorded as a follow-up, not scoped here; (3) until it ships, the UI shows a
  neutral "income is counted once orders are paid" hint when income is 0.
- **Refusal-log noise** if a caller skips its own permission check — binding caller contract in D-1, enforced by 0083's tests.

## Dependencies

- None pending as a hard dependency (`depends_on: []`). `conflict_risk_with`: 0076, 0078 (D-9).
- Consumed by [0083](0083-dashboard-home-overview-ui.md).

## Debate record

Facilitator: Claude (product-owner role). Participants: backend-expert, backend-qa (both dispatched as `general-purpose`
agents reading their `.claude/agents/*.md` definition, because the project agent types are not registered in this session).
No database-expert (no schema change). Both agents worked read-only. Owner decisions honoured unchanged: title + 80-character
description with no image; Chart.js; include-cancelled checkbox default off; variable products shown as the parent by lowest
variant stock.
