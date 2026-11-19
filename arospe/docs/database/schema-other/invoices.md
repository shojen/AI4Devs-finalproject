# Database Schema — Payment Methods, Customers, Notifications, Invoice Settings & Invoice Sequences — invoices and invoice_lines

> Part of [Database Schema — Payment Methods, Customers, Notifications, Invoice Settings & Invoice Sequences](../schema-other.md). **Read this part when:** the task touches the issued invoices: `invoices`, `invoice_lines`, `orders.invoice_out_of_date_at`, the frozen-versus-lifecycle column split, the foreign-key delete rules. The issuing actions are in [api/invoices.md](../../api/invoices.md). The other parts are listed in the [hub](../schema-other.md#table-of-contents).

### `invoices`

Sources: `database/migrations/2026_11_18_120001_create_invoices_table.php`, `..._120002_create_invoice_lines_table.php`, `..._120003_add_invoice_out_of_date_at_to_orders_table.php` (story 0101b, Epic 6 — Invoicing). Model [`App\Models\Invoice`](../../../app/Models/Invoice.php): `HasUuids`, `#[Fillable([])]` (every write is `forceFill()` from one writer, `CreateStandardInvoice`), enum casts `type` (`InvoiceType`) and `reason` (`InvoiceCorrectionReason`), money casts `decimal:2`, `tax_rate` `decimal:3`. Factories: `InvoiceFactory`, `InvoiceLineFactory`.

The table is created **complete** (FD-1): the lifecycle, collection and look columns that later stories (0106, 0107, 0108) write exist from the start, so none of them needs another migration on `invoices`.

| Column | Type | Kind | Notes |
| --- | --- | --- | --- |
| `id` | `CHAR(36)` PK | | UUIDv7 |
| `order_id` | FK `orders`, **restrict** | frozen | an invoiced order cannot be deleted |
| `order_number` | `VARCHAR(30)` | frozen | snapshot of `orders.order_number` |
| `type` | `VARCHAR(20)` | frozen | `InvoiceType` (`standard`, `corrective`) |
| `reason` | `VARCHAR(20)` null | frozen | correctives only (0107/0108), `InvoiceCorrectionReason` |
| `series` | `VARCHAR(20)` | frozen | |
| `sequence_year` | `SMALLINT UNSIGNED` | frozen | calendar year of the allocation, informational, part of no key |
| `sequence_number` | `INT UNSIGNED` | frozen | counter value used |
| `number` | `VARCHAR(50)`, **UNIQUE** | frozen | the rendered number; the only unique key on numbering |
| `issued_at` | `TIMESTAMP`, indexed | frozen | no default; the single `$at` of the issuing transaction |
| `corrects_invoice_id` | FK `invoices` (self), **restrict**, null | frozen | correctives only |
| `voided_at` | `TIMESTAMP` null | **lifecycle** | set when a corrective brings the remaining amount to 0.00 |
| `template` | `VARCHAR(30)` | frozen | look snapshot |
| `primary_color`, `accent_color` | `VARCHAR(7)` null | frozen | look snapshot |
| `logo_media_id` | FK `media`, **restrict**, null | frozen | look snapshot, see below |
| `issuer_legal_name` (255), `issuer_tax_id` (40) | `VARCHAR` not null | frozen | issuer snapshot |
| `issuer_address_line1`/`line2` (255), `issuer_city` (100), `issuer_postal_code` (20), `issuer_province` (100), `issuer_country` (2) | `VARCHAR` null | frozen | issuer snapshot |
| `recipient_name` (150) | `VARCHAR` not null | frozen | recipient snapshot (customer, `withTrashed`) |
| `recipient_tax_id` (40) | `VARCHAR` null | frozen | |
| `recipient_address_line1`/`line2` (255), `recipient_city` (100), `recipient_postal_code` (20), `recipient_province` (100), `recipient_country` (2) | `VARCHAR` null | frozen | the **order's** billing snapshot |
| `tax_rate` | `DECIMAL(6,3)` null | frozen | |
| `subtotal`, `shipping_amount`, `tax_amount`, `total` | `DECIMAL(10,2)` not null, signed | frozen | `subtotal` = sum of the lines; correctives are negative |
| `issued_by` | FK `users`, **restrict**, null | frozen | null = issued by the system |
| `collected_at` | `TIMESTAMP` null | **lifecycle** | written from 0106 |
| `collected_by` | FK `users`, **restrict**, null | **lifecycle** | |
| `collection_payment_method_id` | FK `payment_methods`, **restrict**, null | **lifecycle** | |
| `collection_type` | `VARCHAR(20)` null | **lifecycle** | |
| `collection_carried_over` | `BOOLEAN`, default `false` | **lifecycle** | |
| `created_at` / `updated_at` | `TIMESTAMP` null | | |

**Indexes:** PK, `invoices_number_unique` (`UNIQUE(number)`), `invoices_issued_at_index`, and the indexes MySQL creates for the foreign keys. There is **no** composite unique on (`series`, `sequence_year`, `sequence_number`) and none on type, series or recipient: the printed number is what must be unique, and a composite key could refuse a valid number after an OFF, ON, OFF reset history (FD-2).

**Case-insensitive number.** The table collation is `utf8mb4_unicode_ci`, so `F-1` and `f-1` collide on `UNIQUE(number)`. That is intended; `AssertNumberingFormatIsFree` relies on it.

**Collision handling.** A 1062 on `invoices_number_unique` rolls the whole issue back (the counter included); `IssueInvoiceForOrder` catches it **after** the rollback and raises a validation error on `number`. A unique violation on any other index is a bug and propagates.

#### Frozen columns versus lifecycle columns

Only `Invoice::MUTABLE_COLUMNS` may change on a saved invoice: `voided_at`, the five collection columns and `updated_at`. The model's `updating` hook throws `LogicException` for any other dirty column and `deleting` always throws (FD-3).

```php
// app/Models/Invoice.php
static::updating(function (Invoice $invoice): void {
    $frozen = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_COLUMNS);

    if ($frozen !== []) {
        throw new LogicException('Invoices are immutable; refused to change: '.implode(', ', $frozen).'.');
    }
});
```

> Boundary, stated plainly: immutability holds **through the application only**. A query-builder bulk update bypasses model events, so an architecture test (`tests/Unit/ArchitectureTest.php`) fails when code under `app/` writes either table through a query builder (`query()->update`, `->delete`, `DB::table('invoices'...)`, `upsert`). A database administrator can still edit rows; there are no triggers.

### `invoice_lines`

Model [`App\Models\InvoiceLine`](../../../app/Models/InvoiceLine.php), **insert-only**: every update and delete throws. Not mass-assignable.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `CHAR(36)` PK | UUIDv7 |
| `invoice_id` | FK `invoices`, **restrict** | |
| `order_item_id` | FK `order_items`, **nullOnDelete**, null | null on the concept line |
| `position` | `SMALLINT UNSIGNED` | |
| `kind` | `VARCHAR(20)` | `InvoiceLineKind`: `item` or `adjustment` |
| `description` | `VARCHAR(255)` | product name, or the stored concept sentence |
| `sku` | `VARCHAR(128)` null | |
| `quantity` | `INT`, signed | |
| `unit_price` | `DECIMAL(10,2)` | positive |
| `tax_rate` | `DECIMAL(6,3)` null | |
| `line_subtotal`, `tax_amount`, `line_total` | `DECIMAL(10,2)`, signed | |
| `created_at` / `updated_at` | `TIMESTAMP` null | |

Only the foreign-key indexes exist. **The database `SET NULL` on `order_item_id` is the only write an invoice line ever receives**: removing an invoiced order line succeeds, and the invoice line keeps its description, SKU and amounts with `order_item_id` null.

### `orders.invoice_out_of_date_at`

`TIMESTAMP` null, after `refunded_amount`. Null means the order's invoices are current; every existing order reads null (no backfill). Story 0101b only reads it (`Order::isInvoiceOutOfDate()`, `AssertInvoicesAreCurrent`); only story 0108 sets and clears it.

### `invoice_deliveries`

Source: `database/migrations/2026_11_19_144639_create_invoice_deliveries_table.php` (story 0104). Model [`App\Models\InvoiceDelivery`](../../../app/Models/InvoiceDelivery.php) (`HasUuids`, `status` cast to `App\Enums\InvoiceDeliveryStatus`), factory `InvoiceDeliveryFactory` with states `queued()`, `sent()`, `failed()`, `copy()`. One row per invoice per e-mail send attempt; `Invoice::deliveries()`, `latestDelivery()` and `hasBeenSent()` (true iff a `sent` row exists) read it.

| Column | Type | Null / default | Notes |
| --- | --- | --- | --- |
| `id` | uuid PK | | |
| `invoice_id` | FK `invoices`, restrict | not null | |
| `recipient_email` | varchar(255) | not null | the customer's address at send time, frozen |
| `is_copy` | boolean | not null, default false | false only for the original |
| `status` | varchar(20) | not null, default `queued` | `queued`, `sent`, `failed` |
| `sent_by` | FK `users`, restrict | null | null = the system (automatic send, story 0105) |
| `queued_at` | timestamp | not null | |
| `sent_at`, `failed_at` | timestamp | null | |
| `failure_reason` | text | null | sanitised, at most 500 characters |
| `created_at`, `updated_at` | timestamp | | |

Only the foreign-key indexes exist. Rows are never deleted and a `sent` row never changes status again. `down()` is `dropIfExists`, which discards the whole delivery history. How the original-versus-copy decision and the status transitions work: [api/invoices.md](../../api/invoices.md#sending-invoices-by-e-mail-story-0104).

### Foreign-key delete rules (deliberate)

- **Restrict everywhere on `invoices`** (`order_id`, `corrects_invoice_id`, `logo_media_id`, `issued_by`, `collected_by`, `collection_payment_method_id`) and on `invoice_lines.invoice_id`. An invoiced order cannot be deleted (FD-4); the application has no order-delete path anyway, so this is a data-integrity guarantee tested at foreign-key level.
- **`invoices.logo_media_id` is `restrictOnDelete`, while `invoice_settings.logo_media_id` is `nullOnDelete`, on purpose** (FD-18): the settings may lose their logo, but an issued invoice's frozen look may not. A gallery image used on an issued invoice cannot be deleted.
- **Accepted risk I-1.** The restrict foreign keys on `issued_by` (a user) and `logo_media_id` (a media row) mean any future user-delete or media-delete flow must turn the database refusal into a clean message instead of a 500. No media delete action exists today.

### Migrations

In order: `create_invoices_table`, `create_invoice_lines_table`, `add_invoice_out_of_date_at_to_orders_table`, and (story 0104) `create_invoice_deliveries_table`. `down()` reverses them: `invoice_lines` is dropped before `invoices` (the child before the parent), then the `orders` column is dropped. Rolling back discards every issued invoice and every out-of-date marker; there is no backfill in either direction.

_Last updated: 2026-11-19 — Story 0104: added the `invoice_deliveries` table (the part now also covers it); story 0101b tables unchanged._
