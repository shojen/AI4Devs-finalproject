# Database Schema — Payment Methods, Customers, Notifications, Invoice Settings, Invoice Sequences & Invoices

Part of [Database Schema](schema.md) — see [schema.md](schema.md#er-diagram) for the full ER diagram and [schema.md#notes](schema.md#notes) for the UUID/ADR-0001 status notes. This file covers `payment_methods` (the store-settings bank-transfer catalog), `customers` (Epic 3's first domain table), `notifications` (Laravel's own `database`-channel storage), `invoice_settings` (the store-wide invoicing singleton, story 0100), `invoice_sequences` (the two gap-free invoice counters, story 0101a), and `invoices` / `invoice_lines` (issued invoices, story 0101b).

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [payment_methods](schema-other/payment-methods.md) | the task touches the bank-transfer payment method or IBAN validation. | `payment_methods` |
| [customers](schema-other/customers.md) | the task touches the `customers` table, its address columns or its soft delete. | `customers` |
| [notifications](schema-other/notifications.md) | the task touches database notifications and the `notifiable` UUID morph. | `notifications` |
| [invoice_settings](schema-other/invoice-settings.md) | the task touches the invoice settings singleton: issuer data, logo, numbering formats, `SaveInvoiceSettings`. | `invoice_settings` |
| [invoice_sequences](schema-other/invoice-sequences.md) | the task touches the invoice counters: `AllocateInvoiceNumber`, the yearly reset, the starting counter, the issuing lock order. | `invoice_sequences` |
| [invoices](schema-other/invoices.md) | the task touches issued invoices: `invoices`, `invoice_lines`, `orders.invoice_out_of_date_at`, frozen versus lifecycle columns, foreign-key delete rules. | `invoices`, `invoice_lines`, `orders.invoice_out_of_date_at` |

_Last updated: 2026-11-19 — Story 0101b: added the [invoices](schema-other/invoices.md) part._
