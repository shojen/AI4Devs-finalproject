# Documentation Index

Technical documentation for this Laravel 13 + Livewire 4 application, kept in sync with the real code by the [`docs-maintainer`](../.claude/skills/docs-maintainer/SKILL.md) skill. If something here contradicts the code, the code is right — flag it or fix the doc.

**How to read it (token-efficient).** This index is the entry point: open only the document whose *Read when* matches your task. Long documents are a **hub plus parts** — the hub lists each part with a *Read when* line (or, for `contracts.md`, `workflow.md` and `conventions/base-standards.md`, the binding core of its rules); open the matching part only, at an exact heading when you can. Never open a whole doc "just in case" — see [contracts/token-and-doc-rules.md](contracts/token-and-doc-rules.md#token-efficient-reading-and-dispatch-rule). The long per-entry summaries this index used to carry are kept unchanged in [index-details-and-history.md](index-details-and-history.md).

## Agent rules

| Doc | Read when |
| --- | --- |
| [Contracts](contracts.md) (hub, 5 parts) | always (binding core is in the hub): uncertainty handling, commit practice, destructive DB commands, full-suite gate, parallel agents, doc growth, CI protocol, commit granularity, PR closure. |
| [Workflow](workflow.md) (hub, 4 parts) | you carry a task through the Three Amigos → TDD → security → review → docs → closure phases, or move/create a task file. |
| [Workflow token efficiency](workflow-token-efficiency.md) | you want the measured reasons behind the reading/dispatch rules. |
| [`three-amigos-debate` skill](../.claude/skills/three-amigos-debate/SKILL.md) | you decompose a PRD epic or debate one story (Phase 1 only). |

## Product

| Doc | Read when |
| --- | --- |
| [PRD](PRD/PRD.md) (hub, 7 parts: foundations, Epics 1–5, roadmap) | you need product requirements, Gherkin scenarios or scope for a story — open only its epic's part. |
| [Design reference](arospe-handoff/README.md) | you implement a screen from the exported HTML prototype (`arospe-handoff/project/`). |

## Architecture

| Doc | Read when |
| --- | --- |
| [Overview](architecture/overview.md) | you need the request lifecycle, runtime dependencies or a "where things live" map. |
| [Authentication](architecture/authentication.md) (hub, 3 parts) | you touch Fortify features, registration, account status, sign-in block, pending email change, 2FA or passkeys. |
| [Authorization](architecture/authorization.md) (hub, 13 parts) | you touch roles, permissions, policies, the Super Admin bypass, step-up, refusal logging, or add a gated module. |
| [Shipping](architecture/shipping.md) | you touch shipping-rate resolution (ancestry-walk precedence, no-fallback rule). |

## Database

| Doc | Read when |
| --- | --- |
| [Schema index](database/schema.md) | you need the full ER diagram or to find which schema file owns a table. |
| [Users & Auth](database/schema-users-auth.md) | `users`, `passkeys`, permission tables, infrastructure tables. |
| [Products & Taxes](database/schema-products.md) (hub, 5 parts) | `sales_regions`, `media`, categories, `products`, gallery/region pivots, attributes, variants. |
| [Shipping](database/schema-shipping.md) (hub, 3 parts) | `geography_entries`, zones, carriers, rates. |
| [Payment Methods, Customers & Notifications](database/schema-other.md) (hub, 3 parts) | `payment_methods`, `customers`, `notifications`. |
| [Orders](database/schema-orders.md) (hub, 3 parts) | `orders`, `order_items`, `refunds`, snapshots and derived totals. |
| [Blog](database/schema-blog.md) | `blog_categories`, `blog_tags` (stored `normalized_name` uniqueness, folded-length bound), `blog_posts` (soft delete, derived slug, status-governed `published_at`) and the `blog_post_tag` pivot (cascade contract). |
| [Migrations](database/migrations.md) (hub, 3 parts) | you write a migration (naming, UUID keys, FK indexes, delete behaviour). |

## API / routes

| Doc | Read when |
| --- | --- |
| [Routes index](api/routes.md) | you need the full route table, Fortify/passkey routes, or the layout-mounted components. |
| [Users & Roles](api/users-and-roles.md) | `users.index`, `roles.index`. |
| [Sales Regions](api/sales-regions.md) | `sales-regions.index`. |
| [Products & Media](api/products.md) (hub, 4 parts) | product categories, products list/editor, attribute types, the routeless Media Gallery and WYSIWYG editor. |
| [Shipping](api/shipping.md) | `shipping.zones.index`, `shipping.index`. |
| [Payment Methods](api/payment-methods.md) | `payment-methods.index`. |
| [Customers](api/customers.md) | `customers.index`, `customers.show`. |
| [Orders](api/orders.md) | `orders.index`, `orders.show`, `<x-money>`, `<x-confirm-dialog>`. |
| [Blog](api/blog.md) | `blog-tags.index`, `blog-categories.index`, `blog-posts.index` / `.create` / `.edit`. |

## Conventions

| Doc | Read when |
| --- | --- |
| [Base standards](conventions/base-standards.md) (hub, 3 parts) | always (binding core is in the hub): stack, models, UUID keys, Livewire/Flux conventions, quality gates. |
| [Directory structure](conventions/directory-structure.md) (hub, 7 parts) | you place a new class, action, config file, route file, view or test. |
| [Code style](conventions/code-style.md) | you need types/braces/PHPDoc rules or the constructor-vs-method injection exception. |
| [Naming](conventions/naming.md) (hub, 4 parts) | you name a class, Livewire view, route, permission, lang key or boolean. |
| [Validation trait naming](conventions/naming-validation-traits.md) | you add or extend a `<Noun>ValidationRules` trait. |

## Testing

| Doc | Read when |
| --- | --- |
| [Testing index](testing/README.md) | you write, review or run tests — start here; it links the QA, backend, frontend and CI pages. |
| [Backend](testing/backend/README.md), [philosophy](testing/philosophy.md), [QA guides](testing/qa/risk-based-testing.md) | you design or review Pest 4 backend tests. |
| [Frontend / browser](testing/frontend/README.md) | you write browser tests; [Browser test setup](testing/frontend/playwright-setup.md) (hub, 3 parts) is the tooling/waiting-rules reference. |
| [CI commands](testing/ci/commands.md), [pipeline](testing/ci/pipeline-integration.md) | you run the suite (parallel, coverage) or edit CI. |
| [Worktree databases](testing/worktree-databases.md) | you open a `git worktree` (own `.env.testing` and testing DB). |

## Security

| Doc | Read when |
| --- | --- |
| [Security knowledge base](security/README.md) | you gate access, touch auth, roles, seeders, secrets, uploads or sanitization — the index lists 16 pages, one row each with a *Read when*. |

## Decisions and errors

| Doc | Read when |
| --- | --- |
| [Decision records](decisions/README.md), [ADR 0001 — UUID keys](decisions/0001-uuid-primary-keys.md) | you need past architectural context or add an ADR. |
| [Errors log](errors-log.md) (hub + entries by date) | before repeating a past mistake: the hub's topic index points at the exact entry file. |
| [Errors log archive](errors-log-archive.md) | the topic index points at an entry dated before 2026-08-27. |
| [Revision history](history/) | you need the old `_Previously:` revision notes of a doc (moved out of the doc itself; one file per doc, named after its path). |

_Last updated: 2026-09-24 — Docs optimization pass: index rewritten as one *Read when* row per document (long docs are now hub + parts); the previous long-form entries and history moved unchanged to [index-details-and-history.md](index-details-and-history.md)._
