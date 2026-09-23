# Database Schema — Orders

Part of [Database Schema](schema.md) — see [schema.md](schema.md#er-diagram) for the full ER diagram and [schema.md#notes](schema.md#notes) for the UUID/ADR-0001 status notes. This file covers `orders` and `order_items` (story 0045) plus `refunds` (story 0051, PRD [§3.2 Orders](../PRD/sections/epic-3-customers-orders.md#32-orders)). `orders`/`order_items` are the first pair in this codebase whose *only* reason to ship together is a single cross-table invariant: an order is a customer plus one or more priced line items, and every price is frozen at the moment of ordering. Split into its own file rather than appended to [schema-other.md](schema-other.md) because the invariant needs enough prose (the price snapshot, the address snapshot, the three-way delete-behaviour split, a security fix on the write path) to be worth a dedicated page, per [contracts.md](../contracts/token-and-doc-rules.md#doc-growth-management-rule)'s doc growth management rule — and because Epic 3's remaining Orders stories (0046–0055) will all extend this same domain rather than `payment_methods`/`customers`/`notifications`.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [orders](schema-orders/orders.md) | the task touches the `orders` table: status columns, totals, tax snapshot, address snapshot columns, flags. | `orders` |
| [order_items and refunds](schema-orders/items-and-refunds.md) | the task touches order line items or the refund event log. | `order_items`; `refunds` |
| [Snapshots, totals, delete behaviour and authorization](schema-orders/snapshots-totals-and-rules.md) | you need the price-at-time-of-order snapshot, re-derived totals, address snapshot, `order_number` generation, delete behaviour, or the orders authorization summary. | The price snapshot; Totals are derived and re-derived, not write-once; The address snapshot; `order_number` generation; Delete behaviour; Nothing is resolved that this story does not own; Authorization |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
