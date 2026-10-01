# [0083] Dashboard home — hero, counters and widgets (frontend)

> **Status: Phase 1 draft, rewritten 2026-10-01 after the Phase 2 FAIL.** The first Phase 2 review (INVEST + docs/code consistency, run
> against the merged backend [0082](done/0082-dashboard-home-overview-backend.md)) failed this story on **size** and on a set of
> contradictions with the shipped code. **Owner decisions of 2026-10-01:** (1) the story is **split** — this story keeps the hero,
> the counters, the three list widgets, the badge extraction and the route swap; the whole sales card (filters, KPI strip, two
> Chart.js charts) moves to the new [0086](0086-dashboard-sales-overview-ui.md), which depends on this one; (2) a widget row links to
> an editor **only when the actor may edit**, otherwise it is plain text. Phase 1 content below is rewritten for the narrower scope; it
> needs a Phase 2 re-validation. Items marked **⚑ owner to confirm** are facilitator defaults the owner has not ratified.
> Backend: [0082](done/0082-dashboard-home-overview-backend.md) (merged, PR #48) — its shapes and caller contract are what this story consumes.

## Description

Replace the placeholder `resources/views/dashboard.blade.php` (five `x-placeholder-pattern` boxes) with the real home page, following
[`docs/PRD/images/01-inicio.png`](../../docs/PRD/images/01-inicio.png): a **hero** with a greeting and three counters, and, instead of
the mockup's four shortcut cards, **three live widgets**:

1. **Hero** — time-of-day greeting with the user's first name, and the counters (active users, products, images), each shown only if
   the actor may see that module.
2. **Blog widget** — the 3 latest published/scheduled posts: title, a description of at most 80 characters (posts have no main image),
   a status badge and, for scheduled posts, the date and time they go live; footer link to the blog list.
3. **Low-stock widget** — the 3 products closest to running out, with stock and an out-of-stock (red) / low-stock (amber) badge. A
   variable product appears as its **parent**, with its lowest variant stock and an "N variants low" hint; footer link to the product list.
4. **Latest orders widget** — the 5 most recent orders (number, customer, total, status), each linking to the order detail; footer
   link to the orders list. **Read-only**: it carries no "Mark as paid" action (owner decision 2026-10-01, see [0085](0085-order-mark-as-paid-ui.md)).

The **sales overview card** (Day/Month/Year filters, order-status filter, KPI strip, "Sales vs real income" and "Orders by status"
charts) is **not in this story**: it is [0086](0086-dashboard-sales-overview-ui.md). Until 0086 lands the dashboard simply has no
sales card; no placeholder remains.

## Type

`frontend | includes database-expert: no` — no new dependency, no `#[Lazy]`, no JavaScript module. One small extraction of two shared
badge components (D-5).

## Verified facts that shaped the story

(re-verified 2026-10-01 against the merged tree)

- **Backend shapes** (0082): `GetDashboardCounters` → `array{users: ?int, products: ?int, images: ?int}` (never throws; `null` = no
  ability, and no query runs for it); `GetLatestBlogPosts` → `list<array{id, title, description, status: BlogPostStatus, publishAt: ?CarbonImmutable}>`;
  `GetLowStockProducts` → `list<array{id, name, sku, effectiveStock, isOutOfStock, hasVariants, lowVariantCount}>`; `GetLatestOrders` →
  `list<array{id, orderNumber, customerName, total: string, status: OrderStatus, paymentStatus: PaymentStatus, createdAt}>`. The four list
  actions authorize through the logging wrapper and throw; **a caller must check the ability first** (`Gate::allows`) or every
  unprivileged load logs a "Privileged action refused" warning.
- **Names and titles are NOT NULL today** (`products.name`, `blog_posts.title`); they become nullable only after 0076/0078. The shapes are
  `?string` defensively, so the null case is reachable only through a faked action (seam test), not through the database.
- **A soft-deleted customer is loaded `withTrashed()`** and keeps its real name (`customer_id` is `restrictOnDelete`); `customerName` is never
  null in practice, and the orders list already shows the trashed customer's name (`orders.blade.php:71-73`).
- **Editors authorize on `mount()`**: the routes `blog-posts.edit` and `products.edit` only require `blog.view`/`products.view`
  (`routes/blog-posts.php:23-24`, `routes/products.php:26-28`), but `BlogPosts/Editor.php:393-400` and `Products/Editor.php:146-153`
  then authorize `update`. A view-only actor following an editor link gets a 403 and a refusal warning. Order rows (`orders.show`,
  `orders.view` only) are safe.
- **Routing/layout:** `routes/web.php:8` is `Route::view('dashboard', 'dashboard')` under `auth`+`verified`, ungated; pages mount with
  `Route::livewire(...)` (`routes/orders.php:14`, `docs/conventions/base-standards/livewire-and-flux-conventions.md`); the full-page layout
  comes from `config/livewire.php:47` (`component_layout`); a page view is a plain root `<div>` containing `<x-slot:heading>`/`<x-slot:subheading>`
  (`orders.blade.php:19-20`), not `<x-layouts::app>`. `RegistrationTest.php:26` asserts `route('dashboard')`; `tests/Feature/DashboardTest.php`
  asserts a guest redirect and a 200 for a bare user — both must stay green. `config/modules.php:130-138` gives the dashboard `permissions => []`.
- **Badges are inline, no shared component exists:** order status `orders.blade.php:78-89`, order payment `:93-103`, blog `blog-posts.blade.php:96-105`,
  product `products.blade.php:68-74`, each a `flux:badge` + `match`. `BlogPostsIndexRenderingTest.php:109-138` asserts the blog badge's
  `data-test="status-badge-blog-post-{id}"` hook, its color class and its Spanish label; `Orders/IndexRenderingTest.php:37-55` asserts label text
  only (no color, no markup), so the orders extraction is **unprotected today**.
- **`PaymentStatus` has no `label()`**; `OrderStatus::label()` and `BlogPostStatus::label()` exist. The orders list resolves payment text with
  `__('orders.payment_statuses.*')` (`Orders/Index.php:81`).
- **`lang/en/dashboard.php` and `lang/es/dashboard.php` already exist** (created by 0082) with only an `errors` group of exactly three keys,
  pinned by `tests/Feature/Dashboard/DashboardLangParityTest.php` (`errors` must equal those three keys). This story **extends** the files with
  **new top-level groups**, which keeps that test green.
- `<x-money>` prints `€ {{ $amount }}` with the decimal string unchanged (no `number_format`); the orders list formats dates as `d/m/Y H:i`.
  The app timezone is `Europe/Madrid` (`config/app.php:68`); there is no per-user timezone.
- No card wrapper component exists in `resources/views/components/`; **`flux:card` exists in Flux free**.
- CI **does** run the Browser suite (`docs/testing/ci/pipeline-integration.md`: `php artisan test` has included it since 0006b and CI installs
  Chromium; PR #48's 14-minute `tests` job ran it).

## Decisions

### D-1 — A thin `Overview` page plus three read-only widgets

`App\Livewire\Dashboard\Overview` (class-based, `#[Title('Dashboard')]` like `Orders\Index`, mounted with **`Route::livewire('dashboard', Overview::class)->name('dashboard')`**
inside the existing `auth`+`verified` group — **same name, same middleware, still ungated**) renders, in a root `<div>`, the heading slots
(`topbar.dashboard.*`), the hero with the counters (computed once, eager: three cheap counts, no layout jump) and the three widgets as
children — `Dashboard\BlogWidget`, `Dashboard\LowStockWidget`, `Dashboard\LatestOrdersWidget` — each rendered **only when the actor holds
its module's view ability** (`@can`). All are **eager** (3–5 rows each; no `#[Lazy]` in this story).

- **Why children:** each widget is an isolated, read-only component, so a later change (e.g. 0086 adding a card with its own requests) never
  re-runs their queries; and each owns its own gate.
- **Gating, two layers:** the parent `@can` decides whether to mount a child; **each child also checks `Gate::allows(...)` inside the computed that
  calls its action** and returns an empty result without calling it when the ability is missing — the widgets expose **no write method and no
  client-writable state**, so there is nothing to forge, and a re-render after a permission change simply shows nothing and logs nothing. Abilities:
  blog `viewAny` on `BlogPost` (`blog.view`), low stock `viewAny` on `Product` (`products.view`), orders `viewAny` on `Order` (`orders.view`), counters
  per `users.view`/`products.view`/`media.view` (inside `GetDashboardCounters`).
- **Computed shape:** results live in `#[Computed]` methods (no parameters) that resolve the action through `app(Action::class)`;
  no model, Collection, enum or `CarbonImmutable` is ever a public property. The widgets have **no public properties at all**.
- `resources/views/dashboard.blade.php` is **deleted** (its heading/subheading slots move into `overview.blade.php`).
- **Widget shell:** one small component `resources/views/components/dashboard/widget.blade.php` (a bordered card with a title, a "view all" footer
  link and an empty-state slot), built on a plain `rounded-xl border border-neutral-200 dark:border-neutral-700` container — `flux:card` is an
  acceptable alternative the implementer may choose after checking its dark-mode output.

### D-2 — Row links respect the actor's rights (owner decision 2026-10-01)

- **Blog and low-stock rows link to the editor only when the actor may update** — checked with the **same ability the editor's `mount()`
  authorizes** (`BlogPosts/Editor.php:393-400`, `Products/Editor.php:146-153`; the implementer reads those two calls and mirrors them through
  `Gate::allows`); otherwise the title/name renders as **plain text with no link**. A view-only actor therefore never reaches a 403 and never
  writes a refusal warning. The widget's footer link to the module list is always shown (it needs only the view ability the widget already requires).
- **Order rows always link to `orders.show`** (it needs `orders.view` only). The customer name is plain text (no customer link).
- A variable product links to its **parent's** editor (`products.edit`), never to a variant URL.

### D-3 — Content rules

- **Greeting** by **application-timezone** hour: 05:00–11:59 morning, 12:00–19:59 afternoon, otherwise evening; the first name is
  `Str::of($name)->before(' ')` (the whole name when it has no space). Server-side only (a client-side greeting is untestable).
- **Counters:** each stat renders only when its value `!== null` (**never `@if($count)`** — `0` must render `0`); with no visible counter the stats
  container is omitted and the greeting takes the full width; one or two stats keep the flex row.
- **Blog:** title, description (plain text from the action, always output with `{{ }}`), the status badge, and for a **scheduled** post its go-live
  `publishAt` as **`d/m/Y H:i`** in the application timezone (like the orders list); a **published** post shows **no date**.
- **Low stock:** `effectiveStock`, a **red "Out of stock"** badge when `isOutOfStock`, otherwise an **amber "Low stock"** badge; when `hasVariants`
  and `lowVariantCount > 0`, the hint `trans_choice('dashboard.stock.variants_low', $lowVariantCount)` ("1 variant low" / "2 variants low").
- **Orders:** number, customer name, total through `<x-money>` (no float cast), the order-status badge, and the payment state through the
  existing `orders.payment_statuses.*` keys.
- **Defensive nulls (seam):** a null `title` renders the translated `dashboard.untitled` and stays a valid row; a null `name` renders the SKU; a null
  `customerName` renders `dashboard.deleted_customer`. These are **defensive only** — unreachable through the database today (NOT NULL columns,
  trashed customers keep their name) — and are exercised by tests that **fake the action**, not by Gherkin scenarios.
- **Lists** are stacked `<ul>` rows, not `flux:table`, so phones never scroll horizontally.

### D-4 — Layout by visible content

A CSS grid, not fixed columns: blog / low stock / latest orders in `lg:grid-cols-2`, a lone odd last card spanning both columns (the card
shell uses an arbitrary variant, e.g. `[&>:last-child:nth-child(odd)]:lg:col-span-2` on the grid). If **no counter and no widget** is visible (an actor
whose abilities are, say, only `roles.manage`), the page shows the translated `dashboard.no_widgets` message instead of an empty body. Visuals follow
`docs/arospe-handoff/project/css/index.css:3-13` (gradient `#4f46e5 → #6d5ef0`, radius 18px, mono counters via Tailwind `font-mono`); the hero
gradient is the same in dark mode (white text on indigo works in both). Widgets use the existing dark-mode classes of the placeholder. Phone width: the
hero stacks and every grid collapses to one column.

### D-5 — Shared status-badge components (small extraction, ⚑)

Extract **`resources/views/components/order-status-badge.blade.php`** and **`blog-status-badge.blade.php`** from the inline `match` blocks and reuse
them in `orders.blade.php` (status cell, lines 78-89), `blog-posts.blade.php` (96-105) **and** the dashboard widgets:

- `<x-order-status-badge :status="…"/>` accepts the status **string value** (the orders list passes strings) and renders the same `flux:badge`
  (color map copied unchanged, label from `OrderStatus::from($status)->label()`); `<x-blog-status-badge :status="…"/>` accepts the **`BlogPostStatus`
  enum** (the blog list passes the enum). **Both forward `$attributes`** (so the blog `data-test="status-badge-blog-post-{id}"` hook survives).
- **The orders payment-badge cell (`orders.blade.php:93-103`) and the product badge stay inline** — out of this story (0085 D-6 asked 0083 to say so).
- **Characterization first:** before moving any markup, a **new test pins the orders list status badge** (color class + label for each of the five
  statuses, en and es), because today's orders tests assert only label text; the existing blog test (`BlogPostsIndexRenderingTest.php:109-138`) is
  the safety net for the blog badge. Then the extraction must leave both green.
- *Fallback if the owner prefers zero blast radius:* keep the `match` blocks inline in the widgets (duplicating them) and log tech debt.

### D-6 — i18n

**Extend** `lang/en/dashboard.php` and `lang/es/dashboard.php` with new **top-level groups only** (the `errors` group and its three-key parity test are
untouched): `hero` (`greeting_morning|afternoon|evening` with `:name`, `tagline`), `counters` (`users`, `products`, `images`), `blog` (`title`, `empty`,
`view_all`, `scheduled_for`), `stock` (`title`, `empty`, `view_all`, `out_of_stock`, `low_stock`, `units` choice, `variants_low` choice), `orders`
(`title`, `empty`, `view_all`), `untitled`, `deleted_customer`, `no_widgets`. snake_case leaves, `trans_choice` for plurals, **identical key sets
and placeholders in en and es** (the existing parity test already compares the two flattened key sets). Status words come from
`OrderStatus::label()`, `BlogPostStatus::label()` and `orders.payment_statuses.*` — **not** from `PaymentStatus::label()`, which does not exist.
`topbar.dashboard.*` is unchanged. The admin locale is per request (`SetUiLocale`).

## Open questions closed

| Q | Resolution |
| --- | --- |
| Size | split: this story = hero + widgets; sales card = 0086 (owner, 2026-10-01) |
| Editor links for view-only actors | link only when the actor may edit, else plain text (owner, 2026-10-01) |
| Mark as paid in the orders widget | none (owner, 2026-10-01) |
| Payment-badge cell | stays inline |

Still **⚑ owner to confirm:** extracting the two badge components (vs duplicating); the `d/m/Y H:i` scheduled-date format; the "Untitled" /
"deleted customer" defensive placeholders; greeting boundaries (05/12/20).

## Gherkin

Roles are named per the glossary in `docs/testing/frontend/gherkin-guidelines.md` ("catalog manager", "order manager", "user manager", "blog editor",
"administrator", "super administrator"; the dashboard vocabulary was added by 0082). "Receptionist" is not a glossary role: the no-access actor is
**"a staff member with no module access"** (Phase 6 adds it). Spanish appears only in the locale scenario.

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
    | 04:59 | evening   |
    | 05:00 | morning   |
    | 11:59 | morning   |
    | 12:00 | afternoon |
    | 19:59 | afternoon |
    | 20:00 | evening   |

Scenario Outline: The dashboard shows only what the actor may see
  Given <actor>, <role>, and a store with users, products, images, posts and orders
  When <actor> opens the dashboard
  Then the dashboard shows <visible>
  And it shows none of <hidden>
  Examples:
    | actor | role                              | visible                                   | hidden                                  |
    | Nora  | a staff member with no module access | the greeting only                      | counters, blog, stock and orders        |
    | Uma   | a user manager                    | the users counter                         | products, images, blog, stock, orders   |
    | Olga  | an order manager                  | the latest orders widget                  | every counter, blog and stock           |
    | Carla | a catalog manager                 | products and images counters, low stock   | users counter, blog and orders          |
    | Bea   | a blog editor                     | the blog widget                           | every counter, stock and orders         |
    | Sara  | a super administrator             | every counter and widget                  | nothing                                 |

Scenario: An actor with no module access is told there is nothing to show
  Given Nora, a staff member with no module access
  When Nora opens the dashboard
  Then Nora is told there is nothing to show yet

Scenario: The hero degrades gracefully when the actor sees no counters
  Given Olga, an order manager who may not see users, products or images
  When Olga opens the dashboard
  Then the hero greets Olga without any counter

Scenario: Widgets the actor may not see are never read nor reported as refusals
  Given Uma, a user manager
  When Uma opens the dashboard
  Then no blog, stock or order information is read
  And no refusal is recorded

Scenario Outline: A post's state decides whether a date is shown
  Given Bea, a blog editor, with a <state> post
  When Bea opens the dashboard
  Then the post carries a "<badge>" badge and <date_rule>
  Examples:
    | state     | badge     | date_rule                                       |
    | published | Published | no date                                         |
    | scheduled | Scheduled | its go-live date and time, 12/10/2026 10:00     |

Scenario: The blog widget shows a title and a short description, never an image
  Given Bea, a blog editor, and a published post with a long body
  When Bea opens the dashboard
  Then the post shows its title and a description of at most 80 characters
  And no image is shown

Scenario: A hostile title is shown as text
  Given Bea, a blog editor, and a post whose title contains markup
  When Bea opens the dashboard
  Then the title is shown as plain text

Scenario: A blog editor who may edit posts can open a post from the dashboard
  Given Bea, a blog editor who may edit posts, and a published post
  When Bea opens the dashboard
  Then the post's title links to its editor

Scenario: A user who may only view the blog sees the post without a link to its editor
  Given Vera, a user who may view the blog but not edit posts, and a published post
  When Vera opens the dashboard
  Then the post's title is shown as plain text without a link
  And no refusal is recorded

Scenario: The low-stock widget warns about the emptiest products
  Given Carla, a catalog manager, and physical products with stock 0, 2, 7 and 50
  When Carla opens the dashboard
  Then the widget lists the products with stock 0, 2 and 7
  And the product with stock 0 carries an "Out of stock" badge
  And the products with stock 2 and 7 carry a "Low stock" badge

Scenario Outline: A variable product shows as its parent with a variants-low hint
  Given Carla, a catalog manager, and a variable product with <low> low-stock variants
  When Carla opens the dashboard
  Then the parent product is listed once with the hint "<hint>"
  Examples:
    | low | hint           |
    | 1   | 1 variant low  |
    | 2   | 2 variants low |

Scenario: A catalog manager who may edit products can open the parent product
  Given Carla, a catalog manager who may edit products, and a variable product with a low-stock variant
  When Carla opens the dashboard
  Then the parent product's name links to its editor

Scenario: A user who may only view products sees the product without a link to its editor
  Given Vera, a user who may view products but not edit them, and a product that is running out
  When Vera opens the dashboard
  Then the product's name is shown as plain text without a link
  And no refusal is recorded

Scenario: The latest orders widget lists five orders
  Given Olga, an order manager, and 7 orders
  When Olga opens the dashboard
  Then 5 orders are listed, newest first, each linking to its detail page

Scenario: An order from a deleted customer still shows the customer's name
  Given Olga, an order manager, and an order whose customer has since been deleted
  When Olga opens the dashboard
  Then the order is listed with that customer's name

Scenario: The latest-orders widget is read-only
  Given Olga, an order manager who may also edit orders, and an order whose payment state is Pending payment
  When Olga opens the dashboard
  Then the latest-orders widget lists the order with a link to its page
  And the widget offers no "Mark as paid" button

Scenario Outline: Each widget links to its module's list
  Given <actor>, <role>, and some <items>
  When <actor> opens the dashboard
  Then the <widget> widget offers a link to the <destination> list
  Examples:
    | actor | role              | items    | widget       | destination |
    | Bea   | a blog editor     | posts    | blog         | blog        |
    | Carla | a catalog manager | products | low-stock    | products    |
    | Olga  | an order manager  | orders   | latest-orders | orders     |

Scenario: A store with nothing to show
  Given Laura, an administrator, and a store with no posts, orders or low-stock products
  When Laura opens the dashboard
  Then each widget shows its empty state and the counters read 0

Scenario: The dashboard speaks the administrator's language
  Given Laura, an administrator whose admin UI language is Spanish
  When Laura opens the dashboard
  Then every label, badge and date is shown in Spanish
```

(Missing names/titles, the sales card, filters, charts and their permission/tamper scenarios are **not** Gherkin here: the first are seam tests with a
faked action, the rest belong to [0086](0086-dashboard-sales-overview-ui.md).)

## Files to create/modify

Create:
- `app/Livewire/Dashboard/Overview.php`, `BlogWidget.php`, `LowStockWidget.php`, `LatestOrdersWidget.php`
- `resources/views/livewire/dashboard/overview.blade.php`, `blog-widget.blade.php`, `low-stock-widget.blade.php`, `latest-orders-widget.blade.php`
- `resources/views/components/dashboard/widget.blade.php`, `resources/views/components/order-status-badge.blade.php`, `resources/views/components/blog-status-badge.blade.php`
- tests below, including the static helper `tests/Support/Dashboard/DashboardUi.php` (`actor(array $permissions)`, `superAdmin()`, `snapshotOf(Testable)` — a static
  class, never global functions)

Modify:
- `routes/web.php` (`Route::view(...)` → `Route::livewire('dashboard', Overview::class)->name('dashboard')`, same middleware)
- `resources/views/dashboard.blade.php` — **delete**
- `resources/views/livewire/orders.blade.php` (status cell only, via the component) and `resources/views/livewire/blog-posts.blade.php` (status cell only)
- `lang/en/dashboard.php`, `lang/es/dashboard.php` (new top-level groups, D-6)
- `tests/Feature/DashboardTest.php` (extend: a user with no permissions gets 200, the greeting and the "nothing to show" message)
- `tests/Feature/Dashboard/DashboardLangParityTest.php` — **only its stale header comment** ("files do not exist yet"); the pinned `errors` assertions stay
- Docs (Phase 6): `docs/api/routes.md` (line 34 still shows `view('dashboard')`), `docs/api/dashboard.md` (the route section and "Consumer: 0083"),
  `docs/architecture/overview.md`, `docs/conventions/directory-structure/livewire-models-policies.md` (a `Dashboard/` area of sibling components),
  `docs/conventions/directory-structure/config-database-resources-tests.md` (new `components/` entries, `tests/Support/Dashboard`), the PRD design-reference note
  that the shortcut cards were replaced, and the Gherkin glossary ("staff member with no module access"; "hero", "widget").

## Tests to perform

Livewire feature tests, MySQL; actors are **non-Super-Admin** users holding exactly the listed abilities (a Super Admin makes every "hidden" assertion a false
negative through `Gate::before`; it is used only in its own positive case); `seed(RolePermissionSeeder)` and `forgetCachedPermissions()` in `beforeEach`; probes by
`data-test` hook on rendered HTML; actions that must be unreachable are asserted with `DB::listen` (per domain table, `tests/Support/Dashboard/DomainQueryLog.php`)
and `Log::spy()`.

- `OverviewRenderingTest` — hero greeting and counters (first name only; `0` rendered; partially-null counters; zero counters), greeting boundaries with
  `setTestNow` (04:59/05:00/11:59/12:00/19:59/20:00, application timezone), each widget's exact text, badges, `d/m/Y H:i` scheduled date (and none for published),
  amber/red stock badges, the variants-low hint singular and plural, `<x-money>` strings (exact `€ 100.00`), stacked rows, the footer links, empty states, and escaping of
  hostile titles/descriptions/customer names (`<img onerror>`, `"><script>`, `{{`, `@`). **Cross-story guard:** the latest-orders widget renders **no element whose `data-test`
  starts with `mark-as-paid`** for an actor holding `orders.edit` with a pending-payment order present (that prefix is a frozen contract with 0085).
- `OverviewPermissionTest` — profile dataset (none, only `users.view`, only `orders.view`, only `products.view`, only `media.view`, only `blog.view`, three counters,
  all, Super Admin, Administrator role) asserting the rendered hooks; **no domain-table query for a hidden module and no "Privileged action refused" warning** for any
  partial actor; the editor-link rule (link only with the editor's ability, plain text otherwise, both for blog and products; order rows always link), with no refusal
  logged either way; the widgets expose no public property and no mutating method (reflection).
- `OverviewSeamTest` — with the actions **faked** (container bound to a stub): null `title` → "Untitled" and a valid row; null `name` → the SKU; null `customerName` →
  the deleted-customer text; no empty link text, no exception. (Real data cannot produce these today.)
- `OverviewSnapshotSecurityTest` — for restricted actors, decode `wire:snapshot` and assert **sentinel strings** of hidden modules (post title, product SKU, order
  number, customer name/email, totals) appear neither in the snapshot nor in the HTML; reflection: no public property anywhere holds a model/Collection/enum/Carbon.
- `OverviewLocaleTest` — actor with `ui_locale = 'es'` (not `app()->setLocale`; `SetUiLocale` re-applies it every round trip): every label, badge, hint plural/singular
  and date; no raw `dashboard.` key in the render; extend the key-set parity coverage to the new groups (the existing `DashboardLangParityTest` already compares the flattened sets).
- `StatusBadgeComponentsTest` plus the **characterization test** of D-5 (written and green **before** the extraction): orders-list status badge color and label for the
  five statuses in en/es; blog badge unchanged (existing `BlogPostsIndexRenderingTest`); both components forward `$attributes`.
- `tests/Feature/DashboardTest.php` stays green (guest redirect, bare user 200) and `RegistrationTest.php`'s `route('dashboard')`.

Browser (`tests/Browser/Dashboard/DashboardJourneyTest.php`; `data-test` selectors, `assertNoJavaScriptErrors()`, never `networkidle`, one journey per test; run in CI as part of
`php artisan test`): an editor-capable actor clicks the blog row → the post editor path, the low-stock parent row → `/products/{id}/edit`, an order row → `/orders/{id}`
(`assertPathIs`); smoke `visit('/dashboard')` in light and dark at 375 px with no horizontal scroll and no JS errors.

## Expected outcome

The dashboard home shows the real state of the store at a glance — hero, counters, latest posts, products about to run out and latest orders — matching the mockup's look,
respecting each actor's rights with no leakage, no dead links and no log noise.

## Acceptance criteria

- No placeholder pattern remains, the four mockup shortcut cards are gone, `dashboard.blade.php` is deleted, the route is `Route::livewire`, keeps its name `dashboard` and stays ungated.
- Each widget matches its Gherkin and is mounted only for an actor who may see its module; **a hidden widget runs no domain-table query and writes no refusal log**.
- Blog and low-stock rows link to the editor only for an actor who may edit (plain text otherwise); order rows link to the order; no widget row ever produces a 403.
- The latest-orders widget has no "Mark as paid" action.
- Hero counters render `0` and omit nulls; with no counter and no widget the page says there is nothing to show.
- No widget exposes a public property or a mutating method; no module data reaches the snapshot or HTML of an actor who cannot see it.
- The two badge components exist, forward `$attributes`, and the orders/blog lists render byte-for-byte the same badges as before (characterization test).
- Every new string exists in `en` and `es` with identical key sets and placeholders; the existing `errors` parity test is unchanged and green; layout works at phone width.

## Definition of Done

- [x] Phase 1 content rewritten for the narrowed scope (2026-10-01)
- [ ] Phase 2 INVEST re-validation (`code-reviewer`)
- [ ] Characterization test green before the badge extraction; tests written first (red) then green; **full suite** green (unscoped, run as directory chunks including `tests/Browser`)
- [ ] Pint (unscoped), Larastan, `npm run build` clean
- [ ] Appsec review (per-widget authorization, snapshot leakage, XSS sinks, no refusal-log noise, no dead links)
- [ ] Docs synced (list in "Files")

## Risks and follow-ups

- **R-1 Shared file with 0085** (`orders.blade.php`): this story edits only the status cell (lines 78-89); 0085 the actions cell and a dialog — disjoint hunks, hook-only tests; whichever lands second rebases.
- **R-2 Editor-ability mirroring:** the widgets must use the exact check the editors' `mount()` makes; a drift reintroduces dead links. A feature test per module pins it.
- **R-3 Seam:** after 0076/0078 the shapes' `name`/`title` can be null; the defensive placeholders and their seam tests already cover it.
- **R-4 Sales card:** 0086 adds a card to `overview.blade.php` and extends the same `lang/*/dashboard.php`; this story leaves a clean slot in the grid and no sales-related key.

## Dependencies

- Backend [0082](done/0082-dashboard-home-overview-backend.md) is **merged**; no pending dependency (`depends_on: []`).
- **Consumed by [0086](0086-dashboard-sales-overview-ui.md)** (blocked on this story).
- `conflict_risk_with`: **[0085](0085-order-mark-as-paid-ui.md)** — both edit `resources/views/livewire/orders.blade.php`, in disjoint regions (above).

## Review record

Phase 2 (first review, 2026-10-01): **FAIL** — Small (split required; waivable by the owner, who chose to split), a wrong lang plan (the dashboard lang files already existed;
a parity test pinned `errors` to three keys), an inaccurate layout/route description, untestable missing-name/deleted-customer scenarios, dead editor links for view-only
actors, unprotected orders-badge markup, and stale text. This rewrite addresses the 0083-scope items; the sales-card items moved to 0086.
