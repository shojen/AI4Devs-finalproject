# Arospe — Product Requirements Document (PRD)

Arospe is an **admin dashboard / backoffice** for an ecommerce operation. It is the internal
control panel where a team manages the data a store runs on: users and their permissions, a
blog, a product catalog, tax rules tied to sales regions, and shipping carriers and rates.

**Scope boundary — backoffice only.** This PRD covers the admin panel exclusively. There is
**no public storefront, cart, or checkout UI** in scope here — only the administration of the
data a future storefront would consume. Where a scenario mentions "the checkout" or "a
customer", it is describing *why* a piece of admin data exists, not a screen this project
builds.

**Tech stack (current + planned).** Laravel 13, Livewire 4, MySQL, Tailwind CSS v4, Flux UI.
Authentication (registration, login, 2FA, passkeys) is already implemented via Laravel Fortify
and is **out of scope** for this PRD — this document only concerns what happens *after* login.
`spatie/laravel-permission` is installed and migrated but not yet wired to the domain; Epic 1
wires it up. Redis-backed cache is a **stated technical requirement, not yet implemented**
(see [Assumptions](sections/foundations.md#assumptions--confirmed-decisions)).

This PRD sits at product/requirements level (Gherkin scenarios + acceptance criteria per epic)
and feeds the project's Three Amigos process for individual tasks. It is **not** a technical or
schema design — data models, migrations, and component contracts are decided per task inside
that process. For current-state technical grounding, see the documentation index at
[`docs/README.md`](../README.md).

---

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Assumptions, design reference and cross-cutting](sections/foundations.md) | you need the confirmed product decisions, the dashboard shell/design reference, or global search and notifications. | Assumptions & confirmed decisions; Design reference & the dashboard shell; Cross-cutting: global search & notifications |
| [Epic 1 — Users, Roles & Permissions](sections/epic-1-users-roles-permissions.md) | the task is a Users, Roles or Permissions story. | Epic 1 |
| [Epic 2 — Products, Taxes & Sales Regions, Shipping](sections/epic-2-products-taxes-shipping.md) | the task is a Products, categories, attributes, variants, Sales Regions/taxes, media, shipping or payment-methods story. | Epic 2 |
| [Epic 3 — Customers & Orders](sections/epic-3-customers-orders.md) | the task is a Customers or Orders story. | Epic 3 |
| [Epic 4 — Blog](sections/epic-4-blog.md) | the task is a Blog categories, tags or posts story. | Epic 4 |
| [Epic 5 — Internationalization](sections/epic-5-internationalization.md) | the task is a locale, language switcher or translatable-content story. | Epic 5 |
| [Roadmap, out of scope, open questions](sections/roadmap-scope-open-questions.md) | you need the roadmap/priority reasoning, the out-of-scope list, or the open questions. | Roadmap & priority reasoning; Out of scope; Open questions for the user |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
