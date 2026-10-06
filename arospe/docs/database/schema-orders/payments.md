# Database Schema — Orders — order_payments

> Part of [Database Schema — Orders](../schema-orders.md). **Read this part when:** the task touches the payment recorded against an order (`order_payments`, `App\Enums\OrderPaymentType`, the `MarkOrderAsPaid` write path). The other parts are listed in the [hub](../schema-orders.md#table-of-contents).

### `order_payments`

Source: `database/migrations/2026_10_02_005300_create_order_payments_table.php` (story 0084) — a greenfield UUID `create_*` migration. It replaced the first design's `orders.paid_at` column, which was never merged: **`orders` has no `paid_at`**; the payment moment lives here.

Model: [`App\Models\OrderPayment`](../../../app/Models/OrderPayment.php); reached from an order through `Order::payment()` (`HasOne`, `null` while no payment exists). Columns in physical order:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid (v7) PK | `CHAR(36)`; `HasUuids`. A plain UUIDv7 business entity under [ADR 0001 Amendment 1](../../decisions/0001-uuid-primary-keys.md#amendment-1-2026-08-27--the-scope-is-the-policy-not-the-list-of-seven)'s general policy |
| `order_id` | `CHAR(36)` FK → `orders.id`, **unique**, `restrictOnDelete()` | **one payment per order**, enforced by the database and not only by the action |
| `payment_method_id` | `CHAR(36)` FK → `payment_methods.id`, `restrictOnDelete()` | the method the administrator chose, stored here and never on `orders` (which keeps its own `payment_method_id` as the method the order was placed with) |
| `type` | `VARCHAR(20)`, not null, no default | cast to [`App\Enums\OrderPaymentType`](../../../app/Enums/OrderPaymentType.php) (`transfer`, `card`, `paypal`; `label()` reads `orders.payment.types.*`). A plain varchar, not a database `ENUM`, like `orders.status` |
| `paid_at` | `TIMESTAMP`, not null, no default | the moment the payment was marked (the click, second precision); always supplied explicitly by the action. Unindexed: nothing filters or sorts by it yet |
| `recorded_by` | `CHAR(36)` FK → `users.id`, **nullable**, `restrictOnDelete()` | the administrator who marked the order as paid, written by `MarkOrderAsPaid` from `Auth::id()`. Nullable because factories leave it `null` and a payment may predate the column's meaning; reached through `OrderPayment::recordedBy(): BelongsTo`. Placed after `paid_at` |
| `created_at` / `updated_at` | timestamp, nullable | |

**Actor.** `recorded_by` records who marked the order as paid (the same attribution idea as `refunds.refunded_by`). It is a deliberately small interim: a later story will replace it with a general movements log table. It is nullable, factories leave it `null`, and the action fills it with `Auth::id()`. A successful mark still writes no log line.

**Legacy and semantics.** The migration backfills nothing and rewrites no row: an order that was already `Paid` before the table existed has no payment row (`Order::$payment` is `null`), which is a valid state. A payment row survives refunds (`payment_status` stays the source of truth for the current state), and a row is never updated or deleted by the app — there is no undo.

**`#[Fillable([])]`** — nothing is mass-assignable. The only writer is [`MarkOrderAsPaid`](../../../app/Actions/Orders/MarkOrderAsPaid.php), through `forceCreate()`, inside the same transaction as the `orders` compare-and-set `UPDATE` (contract in [api/orders.md](../../api/orders.md#markorderaspaid--the-action-contract-story-0084-no-route)).

**Delete behaviour.** All three foreign keys (`order_id`, `payment_method_id`, `recorded_by`) are `restrictOnDelete()`, never a cascade (matching `refunds.order_item_id`): a payment is a financial fact, so deleting an order, a payment method or the recording user must not silently destroy it. See [Delete behaviour](snapshots-totals-and-rules.md#delete-behaviour--the-same-three-way-rule-stated-once).

#### Indexes — the unique one, plus two FK-created

The `UNIQUE` index is declared on `order_id` **before** the foreign key, so MySQL reuses it as the FK's supporting index and no redundant `order_payments_order_id_foreign` is created; `payment_method_id` gets the usual FK-created index. No hand-written index and none on `paid_at` (follow-up if a payment-date filter ever ships, see [Dashboard follow-ups](../../api/dashboard.md#known-limitations-and-follow-ups)).

_Last updated: 2026-10-02 — Story 0084 amendment: `recorded_by` (nullable FK to `users`, restrict) and `OrderPayment::recordedBy()`._
