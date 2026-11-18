# Database Schema — Payment Methods, Customers, Notifications, Invoice Settings & Invoice Sequences — invoice_settings

> Part of [Database Schema — Payment Methods, Customers, Notifications, Invoice Settings & Invoice Sequences](../schema-other.md). **Read this part when:** the task touches the invoice settings singleton: issuer data, logo, numbering formats, `SaveInvoiceSettings`. The other parts are listed in the [hub](../schema-other.md#table-of-contents).

### `invoice_settings`

Source: `database/migrations/2026_11_16_222441_create_invoice_settings_table.php` (story 0100, Epic 6 — Invoicing). One store-wide row holding the issuer's fiscal data, the invoice logo and colours, the PDF template and the numbering format of both series (standard and corrective). **No row is inserted by the migration or any seeder**: an unsaved store reads the documented defaults through `InvoiceSetting::current()`.

Model: [`App\Models\InvoiceSetting`](../../../app/Models/InvoiceSetting.php) — a fixed-key singleton like [`locale_settings`](../schema-localization.md#locale_settings): `$incrementing = false`, `$keyType = 'int'`, `SINGLETON_ID = 1`, `#[Fillable([])]` (written only through `forceFill()` of validated keys inside `SaveInvoiceSettings`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `TINYINT UNSIGNED` PK | always `1` |
| `issuer_legal_name`, `issuer_address_line1`, `issuer_address_line2` | `VARCHAR(255)` nullable | free text, stored raw and trimmed; escaped at render time, never stripped |
| `issuer_tax_id` | `VARCHAR(40)` nullable | normalised by `TaxIdValidationRules` (whitespace removed, upper-cased, max 32 of `A-Z0-9 - . /`); column wider than the rule on purpose |
| `issuer_city`, `issuer_province` | `VARCHAR(100)` nullable | |
| `issuer_postal_code` | `VARCHAR(20)` nullable | |
| `issuer_country` | `VARCHAR(2)` nullable | upper-cased ISO alpha-2 shape (`/^[A-Z]{2}$/D`) |
| `logo_media_id` | `CHAR(36)` nullable, FK → `media.id` `nullOnDelete()` | `InvoiceSetting::logo()` (`BelongsTo`); deleting the media row clears the logo |
| `primary_color`, `accent_color` | `VARCHAR(7)` nullable | `#RRGGBB`, upper-cased (`/^#[0-9A-F]{6}$/D`); they reach PDF CSS, so the anchored regex is the injection barrier |
| `template` | `VARCHAR(30)`, default `classic` | cast to `App\Enums\InvoiceTemplate` (`classic`, `modern`) |
| `standard_prefix` / `corrective_prefix` | `VARCHAR(10)`, defaults `F` / `R` | `/^[A-Za-z0-9\-\/]{0,10}$/D`; **never trimmed** |
| `standard_year_format` / `corrective_year_format` | `VARCHAR(4)`, default `YYYY` | cast to `App\Enums\InvoiceNumberYear` (`none`, `YYYY`, `YY`) |
| `standard_separator` / `corrective_separator` | `VARCHAR(3)`, default `-` | `/^[\-\/.]{0,3}$/D`; **never trimmed** |
| `standard_padding` / `corrective_padding` | `TINYINT UNSIGNED`, default `5` | `between:1,10` |
| `reset_numbering_yearly` | boolean, default `true` | |
| `auto_issue_on_order` | boolean, default `false` | refused as `true` while legal name, tax ID or address line 1 is blank |
| `created_at` / `updated_at` | timestamp, nullable | |

#### Reading: `current()` and `defaults()`

`InvoiceSetting::current()` returns the saved row or, when none exists, `InvoiceSetting::defaults()` — a **public static** method beyond the class contract that returns an unsaved instance `forceFill`ed from the `DEFAULT_*` constants (these equal the column defaults; a parity test pins it). Neither ever writes. `hasCompleteIssuerData()` is true when legal name, tax ID and address line 1 are all `filled()`.

#### Writing: `SaveInvoiceSettings`

[`App\Actions\Invoices\SaveInvoiceSettings`](../../../app/Actions/Invoices/SaveInvoiceSettings.php) is the only writer. Order: logged authorization (`update` on the class, via `LogRefusedPrivilegedAttempt`) → normalisation → field validation → cross-field checks (reset-yearly needs a year in both formats; the two prefixes must differ case-insensitively; auto-issue needs complete issuer data) → an autocommit `insertOrIgnore` of row 1 *outside* the transaction → a transaction that locks the row `FOR UPDATE` and `forceFill()->save()`s. A save replaces the whole record: every settings key must be present, unknown keys are ignored. The info log carries the actor id and changed key names, never fiscal values.

#### Composing a number: `ComposeInvoiceNumber`

[`App\Actions\Invoices\ComposeInvoiceNumber`](../../../app/Actions/Invoices/ComposeInvoiceNumber.php) is pure: `prefix + year token + separator + zero-padded counter` for an `InvoiceType` series (`standard`, `corrective`) and an issue moment passed in by the caller. A counter wider than the padding is never truncated.

#### Permissions

`invoice-settings.view` and `invoice-settings.manage` live in `RolePermissionSeeder::INVOICE_PERMISSIONS` and gate `InvoiceSettingPolicy::viewAny()` / `update()`; see [the catalog](../../architecture/authorization/overview-catalog-seeding.md#permission-catalog).

_Last updated: 2026-11-17 — Story 0100: part created._
