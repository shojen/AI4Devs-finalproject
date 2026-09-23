# Arospe PRD — Roadmap, out of scope, open questions

> Part of [Arospe PRD](../PRD.md). **Read this part when:** you need the roadmap/priority reasoning, the out-of-scope list, or the open questions. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Roadmap & priority reasoning

The five epics ship in this order. The ordering is dependency-driven, not arbitrary.

| Order | Epic | Why here |
| --- | --- | --- |
| 1 | **Users, Roles & Permissions** | Foundation. Every other module gates its screens and actions on permissions, so `spatie/laravel-permission` must be wired and the roles UI built first. |
| 2 | **Products, Taxes & Sales Regions, Shipping** | The store core — the primary commerce data the whole panel exists to manage. Sales Regions/Taxes underpin product pricing, so this cluster is built as one push. |
| 3 | **Customers & Orders** | Depends on Products/Taxes/Shipping existing first: orders reference product/variant line items, a sales region for tax, and a shipping rate/carrier, so it can only be built once those catalogs exist. |
| 4 | **Blog** | Independent content module. Reuses the WYSIWYG editor and shared media gallery proven in Epic 2, so it benefits from building after Products. |
| 5 | **Internationalization** | Cross-cuts Products, Blog, and their shared taxonomies (translatable fields live in their editors and taxonomy screens), so it can only be layered on once those exist. |

**Technical note (not user-facing):** the move from database cache to **Redis** is part of this
initiative's technical scope and should be scheduled alongside Epic 1/2 hardening, but it has no
functional acceptance criteria in the epics above.

---

## Out of scope

Explicitly excluded from this PRD (and, where noted, flagged as possible future work):

- **Public storefront, cart, and checkout UI.** This is backoffice only. Admin data is managed
  here; a future separate project consumes it. Orders and customers are assumed to originate from
  such an out-of-scope external/future channel (or manual admin entry).
- **Customer-facing authentication / portal.** Customers (Epic 3) are admin-managed records only
  and cannot log into the dashboard; no customer login or self-service portal is built this phase.
- **Real payment gateway integration** — the panel records order payment/refund *state* as a
  manual admin-set status; wiring a live payment processor (charge/capture/refund) is not in scope.
- **Authentication flows** (registration, login, password reset, 2FA, passkeys) — already
  implemented via Fortify; this PRD starts after login.
- **Real carrier API integration** — no live tracking, no label generation, no rate lookups from
  carriers. Shipping is manual configuration only.
- **Multi-currency** — EUR only.
- **Cloud media storage** (S3 or similar) — local disk (`storage/app/public`) only this phase.
- **Audit / change-history log** — not required this phase; possible future enhancement.
- **Public storefront filtering/browsing of blog posts by category or tag** — confirmed out of
  scope. The data relationships exist to support it later, but no public-facing filtering UI is
  built in this phase (admin-side list filtering, by contrast, is in scope — see Epic 4).
- **Admin-creatable countries/regions from scratch** — the Sales Region catalog is seeded and
  fixed; admins only configure existing entries.

---

## Open questions for the user

No open questions remain — all ambiguities identified during PRD authoring were resolved with
the user. New ambiguities that surface while breaking these epics into tasks should be raised at
that point, per the project's [Uncertainty Handling Rule](../../contracts.md).
