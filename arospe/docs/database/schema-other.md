# Database Schema — Payment Methods, Customers & Notifications

Part of [Database Schema](schema.md) — see [schema.md](schema.md#er-diagram) for the full ER diagram and [schema.md#notes](schema.md#notes) for the UUID/ADR-0001 status notes. This file covers `payment_methods` (the store-settings bank-transfer catalog), `customers` (Epic 3's first domain table), and `notifications` (Laravel's own `database`-channel storage).

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [payment_methods](schema-other/payment-methods.md) | the task touches the bank-transfer payment method or IBAN validation. | `payment_methods` |
| [customers](schema-other/customers.md) | the task touches the `customers` table, its address columns or its soft delete. | `customers` |
| [notifications](schema-other/notifications.md) | the task touches database notifications and the `notifiable` UUID morph. | `notifications` |

_Last updated: 2026-09-29 — Story 0070 (Translatable content mechanism — backend, piloted on Product Categories). [customers](schema-other/customers.md) had two stale `product_categories.name` precedent citations repointed to `product_category_translations.name`, since story 0070 moved that column — no content beyond the citations changed._
