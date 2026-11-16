# Database Schema — Payment Methods, Customers, Notifications & Invoice Settings

Part of [Database Schema](schema.md) — see [schema.md](schema.md#er-diagram) for the full ER diagram and [schema.md#notes](schema.md#notes) for the UUID/ADR-0001 status notes. This file covers `payment_methods` (the store-settings bank-transfer catalog), `customers` (Epic 3's first domain table), `notifications` (Laravel's own `database`-channel storage), and `invoice_settings` (the store-wide invoicing singleton, story 0100).

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [payment_methods](schema-other/payment-methods.md) | the task touches the bank-transfer payment method or IBAN validation. | `payment_methods` |
| [customers](schema-other/customers.md) | the task touches the `customers` table, its address columns or its soft delete. | `customers` |
| [notifications](schema-other/notifications.md) | the task touches database notifications and the `notifiable` UUID morph. | `notifications` |
| [invoice_settings](schema-other/invoice-settings.md) | the task touches the invoice settings singleton: issuer data, logo, numbering formats, `SaveInvoiceSettings`. | `invoice_settings` |

_Last updated: 2026-11-17 — Story 0100: added the [invoice_settings](schema-other/invoice-settings.md) part._
