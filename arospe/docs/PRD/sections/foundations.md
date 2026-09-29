# Arospe PRD — Assumptions, design reference and cross-cutting

> Part of [Arospe PRD](../PRD.md). **Read this part when:** you need the confirmed product decisions, the dashboard shell/design reference, or global search and notifications. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Assumptions & confirmed decisions

These decisions were confirmed with the product owner before this PRD was written. They explain
why several epics **deliberately extend or diverge from the Claude-Design prototype** — the
prototype is the visual/UX reference, not the final data model.

1. **Backoffice only.** No public storefront, cart, or checkout is built here. The admin panel
   manages the data; a future, separate storefront consumes it.
2. **Auth is done and out of scope.** Fortify already provides registration/login/2FA/passkeys.
   This PRD starts at the authenticated dashboard.
3. **Permissions come first and are dynamic.** Epic 1 wires the already-installed
   `spatie/laravel-permission` to the `User` model and adds full **create/edit/delete of custom
   roles** with granular per-module permissions — beyond the prototype's flat role dropdown.
   Every other epic depends on permissions existing, so Epic 1 is built first.
4. **Sales Regions and Shipping zones are two independent catalogs.** Do not merge them. The
   fiscal regions (the ISO country list, plus — for Spain — Península / Baleares / Canarias /
   Ceuta / Melilla) used for tax are a separate concept from the shipping zones used for
   carrier rates.
5. **A tax rule *is* a Sales Region entry.** There is no separate parallel "tax rule" list.
   Each Sales Region catalog entry (a country — and for Spain, its special fiscal territories:
   Península, Baleares, Canarias, Ceuta, Melilla) carries its own **rate, description, and
   code**. Managing sales regions *is* managing tax rates. This lives as its own section inside
   the Taxes area (not a separate top-level sidebar item, not free text inside a modal). This
   is the biggest deliberate divergence from the prototype's flat per-country list.
6. **The region catalog is fixed/seeded, not admin-creatable from scratch.** Seed it with the
   standard ISO country list and, for Spain, its special fiscal territories (because Canarias,
   Baleares, Ceuta and Melilla have different tax treatment than mainland Spain). Admins
   configure rate/description/status on existing seeded entries and flag exactly one as the
   **default**; they do not invent new countries. **Every catalog entry is a single country, or
   one of Spain's five fiscal territories — there are no supranational or catch-all "grouping"
   entries** (confirmed with the product owner on 2026-08-18 during Epic 2's Three Amigos
   debate: no seeded data records which countries belong to such a grouping, so a grouping could
   only ever be matched by hand, which defeats its purpose).
7. **Exactly one default tax rule.** Used as the fallback rate whenever a product's assigned
   region has no matching entry.
8. **Products have their own category taxonomy (full CRUD)**, separate from the blog's
   taxonomy — the prototype's flat dropdown becomes a managed list.
9. **Product variants are configurable.** Variant *attribute types* (e.g. Size, Color,
   Material) and their values are admin-defined — not a hardcoded Size/Color pair. Each variant
   is a combination of attribute values with its own SKU, price, and stock, and may optionally
   have its own featured image (inheriting the parent's if unset). This extends the prototype's
   flat SKU/price/stock.
10. **Single currency: EUR.** No multi-currency.
11. **Local file storage, multi-format images.** Uploaded media lives on the local server disk
    (`storage/app/public`). No cloud storage (S3, etc.) this phase. Every uploaded image keeps
    its original format (`.png` / `.jpg` / `.jpeg`) and the system additionally generates and
    stores `.webp` and `.avif` variants of the same image.
12. **Shipping matches the prototype almost as-is for carriers and rate rules:** carriers with
    enable/disable toggle, and per-carrier rate rules by shipping zone + weight range + price +
    delivery estimate. **No real carrier API** — no live tracking, no label generation; manual
    configuration only. **The shipping zone catalog itself diverges from this** — see
    [2.4 Shipping](epic-2-products-taxes-shipping.md#24-shipping) for the confirmed admin-editable zone catalog and its seeded
    three-level geography backing (countries / comunidades autónomas / municipios).
13. **Blog gets categories (full CRUD) and tags (full CRUD + create-on-the-fly from the post
    editor).** A post has one category and multiple tags. Both taxonomies are distinct from the
    product category taxonomy. This extends the prototype, which had a fixed category dropdown
    and no tags.
14. **Two independent i18n layers.** (a) An **admin UI language switcher** (Spanish/English
    only) via standard Laravel localization (`lang/`, greenfield — no `lang/` exists yet). (b)
    **Store Languages**: an admin-managed set of content-authoring languages that surface as
    **tabs** for translatable fields. Translatable content covers product title/description,
    post title/body, slug/SEO fields, and category/tag names; the store default is Spanish on
    install and can later be changed to any active store language, independent of the UI language.
15. **Global search and the notifications bell are functional**, not decorative. Search spans
    users, products, and blog posts. Notifications fire for four confirmed events: low/zero
    stock, new customer, new order, and blog post published/going live.
16. **Customers and Orders are backoffice-managed entities**, separate from the admin
    Users/Roles system. Customers cannot log into the dashboard (no customer portal this phase).
    Orders/customers originate from an out-of-scope external/future channel or manual admin
    entry; the panel only manages that data once it exists.
17. **No audit / change-history log** this phase (possible future enhancement only).
18. **Redis cache is a technical requirement, not yet implemented.** `.env` currently uses
    `CACHE_STORE=database` and no Redis/Predis package is installed. Moving cache to Redis is
    part of this initiative's technical scope; it is flagged here as an assumption so it is not
    forgotten, but it carries no user-facing acceptance criteria in the functional epics below.
19. **UUID (v7) primary keys on seven entities.** These use a **UUID as their sole primary key**,
    generated by Laravel 13's native `HasUuids` trait — which defaults to **time-ordered UUIDv7**
    (not random v4), applied at **both** the migration/schema level and the Eloquent model level
    (`use HasUuids;` on each model): `users` (Epic 1), Products, Product Variants, Product
    Categories (Epic 2), and Blog Categories, Blog Tags, Blog Posts (Epic 4). The UUID **replaces**
    the primary key entirely — no dual-column pattern (no internal `bigint` autoincrement kept
    alongside a public UUID), matching this project's simple/explicit migration style. **Not
    ULID**: `HasUuids`' UUIDv7 is already time-ordered, solving the same MySQL index-locality
    concern ULID would address while staying a literal UUID. Six of the seven are greenfield
    (created with a UUID PK from day one); **`users` is the exception** — it already exists as a
    `bigint` autoincrement table, so this is a breaking alteration-with-backfill migration (see the
    Epic 1 note below), not yet implemented. This is a documentation-only decision; the actual
    migrations/models are written during each epic's TDD implementation.

---

## Design reference & the dashboard shell

> **Style guide only — not code to port.** Everything under
> [`docs/arospe-handoff/`](../../arospe-handoff) (the static HTML/CSS/JS Claude-Design bundle)
> exists **only as a visual/UX reference** — a style guide for layout, spacing, colors, and
> interaction patterns. **None of that HTML/CSS/JS is ported as-is.** The real implementation
> must be built entirely in **Livewire, Blade, and Laravel**, following this project's existing
> conventions ([`docs/conventions/`](../../conventions)) — never by adapting the prototype's
> markup or vanilla JS directly.

The visual and interaction reference is the static Claude-Design prototype at
[`docs/arospe-handoff/project/`](../../arospe-handoff/project) (`index.html`, `usuarios.html`,
`productos.html`, `blog.html`, `impuestos.html`, `envios.html`). Reuse its patterns: the
persistent left sidebar, the topbar with title/subtitle + search + notifications, the
list-then-editor pattern, status **badges**, **modals** for quick create/edit, the shared
**media gallery**, and the **WYSIWYG toolbar** (Bold, Italic, Underline, H2, bullet list,
numbered list, link, Insert image). The prototype UI is Spanish-labeled, which matches the
dashboard's Spanish locale option.

Every image uploaded through the shared media gallery is stored in **multiple formats**: the
original `.png` / `.jpg` / `.jpeg` is kept, and `.webp` and `.avif` variants are generated
alongside it (see [2.3 Shared Media Gallery](epic-2-products-taxes-shipping.md#23-shared-media-gallery)).

![Home / dashboard landing](../images/01-inicio.png)
*Home: a hero greeting, three stat counters (users / products / media images), and quick-access
cards to each module. The left sidebar groups navigation into **Inicio**, **Usuarios**,
**TIENDA** (Impuestos, Envíos) and **CONTENIDO** (Productos, Blog), with the signed-in user
pinned at the bottom.*

**The sidebar shown above is only a starting visual example, not the final navigation.** The
real dashboard's sidebar must add sections/links for everything the prototype does not cover:
**Roles & Permissions** (Epic 1), **Payment Methods** (Epic 2), **Customers** and **Orders**
(Epic 3), and **Store Languages settings** (Internationalization, Epic 5). Do not assume the
final nav is limited to the prototype's four groups.

Treat the prototype as the real styling reference but **not** the final scope. Wherever a
requirement below goes beyond it, this document says **"extends the prototype"** so nobody
mistakes the mockup for the deliverable.

---

## Cross-cutting: global search & notifications

The topbar (visible on every screen in the prototype) carries a global search field
("Buscar en el panel…") and a notifications bell. Both are **in scope and functional**.

```gherkin
Feature: Global panel search

  Scenario: Global search returns matches across modules
    Given a signed-in administrator with access to all modules
    When they search the panel for "runner"
    Then they see results grouped by users, products, and blog posts that match "runner"

  Scenario: Opening a search result navigates to its record
    Given a signed-in administrator viewing global search results for "runner"
    When they select one of the results
    Then they are taken to that record's edit view

  Scenario: Global search hides results the administrator may not view
    Given a blog editor without permission to view products
    When they search the panel for a term that matches a product
    Then no product results are shown
    And only results from modules they may view are returned

  Scenario: Global search shows an empty state when nothing matches
    Given a signed-in administrator
    When they search the panel for a term that matches nothing
    Then they see an empty-state message instead of a results list
```

The bell generates notifications for exactly these **confirmed** events (this is the final
list, not a proposal):

- **Low or zero stock** on a product or a variant.
- **New customer created** (a store end-customer record — see
  [Epic 3](epic-3-customers-orders.md#epic-3--customers--orders) — not a new dashboard admin user).
- **New order received** (see [Epic 3](epic-3-customers-orders.md#epic-3--customers--orders)).
- **Blog post published**, or a **scheduled post going live**.

```gherkin
Feature: Notifications bell

  Scenario: The bell shows an unread indicator
    Given a signed-in administrator with at least one unread notification
    When they view the topbar
    Then the notifications bell shows an unread indicator

  Scenario: Reading notifications clears the unread indicator
    Given a signed-in administrator whose notifications bell shows an unread indicator
    When they open and read their notifications
    Then the unread indicator is cleared

  Scenario Outline: A confirmed event generates a notification
    Given a signed-in administrator
    When <event> occurs
    Then a corresponding notification is surfaced on their bell

    Examples:
      | event                                                  |
      | a product or variant reaches low or zero stock         |
      | a new customer is created                              |
      | a new order is received                                |
      | a blog post is published or a scheduled post goes live |

  Scenario: An unrelated change does not generate a notification
    Given a shipping administrator editing a shipping rate's delivery estimate
    When they save that change
    Then no notification is generated, because it is not one of the confirmed events
```

**Acceptance criteria**

- [ ] Global search queries at least users, products, and blog posts and groups results by type.
- [ ] Search results are filtered by the current user's module permissions.
- [ ] An explicit empty state is shown when nothing matches.
- [ ] The bell displays an unread indicator and clears it once notifications are read.
- [ ] Notifications are generated for exactly the four confirmed events (low/zero stock, new
      customer, new order, blog post published or going live) and not for other events.
- [ ] Both controls are present on every authenticated screen, matching the prototype topbar.

---
