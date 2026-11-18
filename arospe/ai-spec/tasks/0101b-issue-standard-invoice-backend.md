# [0101b] Issue standard invoice — the invoice core (backend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](done/0100-invoice-settings-backend.md) (settings, `InvoiceType`, `SaveInvoiceSettings`),
> [0100a](done/0100a-customer-tax-id-backend.md) (`customers.tax_id`) and
> [0101a](0101a-invoice-numbering-sequences-backend.md) (`AllocateInvoiceNumber`). Dependents:
> [0102](0102-invoice-pdf-rendering-and-templates-backend.md),
> [0104](0104-send-invoice-by-email-backend.md),
> [0105](0105-automatic-invoice-on-order-creation-backend.md),
> [0106](0106-invoice-collection-order-debt-partially-paid-backend.md),
> [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md),
> [0108](0108-regenerate-invoices-after-order-edit-backend.md),
> [0110](0110-billing-section-ui.md).

## Description
Create the invoice tables and issue a standard invoice for an order: for the whole uninvoiced
amount or a part of it, never exceeding what is left to invoice, numbered gap-free, with a frozen
snapshot of issuer, recipient, look, lines and tax. Invoices can never be edited or deleted
through the application. This story also adds the order's "invoice out of date" column and its
guard (written by 0108), the `invoices.view` / `invoices.create` permissions, and the settings
check that refuses a numbering format whose next number already exists. No PDF, mail, collection,
corrective or screen.

Request item served (owner, 2026-11-14, translated):
> 5. ... The manager can also create invoices for an order: a 1000 EUR order may have five
> 200 EUR invoices. The manager can keep issuing invoices until the order's debt is 0.

Binding owner decisions:
- U-7 (owner decision, 2026-11-15): "Partial invoice content: one line 'Payment on account of
  order N' with base, tax and shipping split in proportion. An invoice for the whole order copies
  the order lines."
- U-5 (owner decision, 2026-11-14): the invoice carries the issuer's legal name, tax ID and fiscal
  address and the customer's optional tax ID.
- U-2 (owner decision, 2026-11-14): debt = order total minus collected invoices (collection is
  0106). The issuing cap is the **uninvoiced** amount, not the debt: capping by debt would let a
  second full invoice be issued while the first is uncollected, invoicing the order twice; once
  nothing is left to invoice, any remaining debt is settled by collecting the invoices already
  issued, so "issue until the debt is 0" still holds.
- Legal e-invoicing rules are out of scope (accepted default, 2026-11-15, owner may overturn):
  Spanish invoicing-software rules (VeriFactu / TicketBAI: record hash chain, QR code, submission
  to the tax agency) are not implemented by this epic and the docs say so.

## Type
backend | includes database-expert: yes

## Verified facts that shaped the story
- `orders`: `order_number` varchar(20) unique, `customer_id`, `status`, `payment_status`,
  `tax_rate` decimal(6,3) nullable, `subtotal`, `tax_amount` (= subtotal x rate / 100 half-up,
  shipping untaxed), `shipping_amount`, `total`, `refunded_amount` (merchandise only), billing
  address snapshot columns. `order_items.product_name` varchar(255), `product_sku` varchar(128).
- Helpers exist in `app/Actions/Orders/`: `CalculateTaxAmount`, `ToNumericString`,
  `AssertWithinColumnCeiling`. Project lock order (story 0088a FD-7): `orders` -> `products` ->
  `product_variants` -> stock -> `order_items`. Deadlock 1213 is not retried.
- Customers are soft-deleted; a refusal is logged through `LogRefusedPrivilegedAttempt`.
- 0101a: one `invoice_sequences` row per series; `AllocateInvoiceNumber::lock()` takes settings
  `FOR SHARE` then the sequence rows `FOR UPDATE` and returns the locked settings;
  `__invoke(InvoiceType, CarbonInterface $issuedAt)` returns number, series, `sequence_year`
  (calendar year of the allocation) and `sequence_number`; `nextCounter()` is read-only.
- The seeded catalog has 45 permissions after 0100; Administrator is synced with every catalog
  permission except `roles.manage-administrators`.

## Shared definitions (epic arithmetic used here)
- **Billable** = `total - refunded_amount` (0.00 when the order is `Cancelled`).
- **Net invoiced** = signed sum of the totals of all the order's invoices (correctives are
  negative, from 0107).
- **Uninvoiced** = `max(0.00, billable - net invoiced)` (FD-9).
- A standard invoice is **live** while `voided_at` is null.

## Tables and columns created (final definition)

`invoices` (columns in order; "frozen" = never updated after insert):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `order_id` | FK `orders`, restrict | |
| `order_number` | varchar(30) not null | frozen snapshot of `orders.order_number` |
| `type` | varchar(20) not null | `InvoiceType` |
| `reason` | varchar(20) null | correctives only (0107/0108), `InvoiceCorrectionReason` |
| `series` | varchar(20) not null | |
| `sequence_year` | smallint unsigned not null | calendar year of the allocation, informational |
| `sequence_number` | int unsigned not null | counter value used |
| `number` | varchar(50) not null, **UNIQUE** | rendered, frozen; the only unique key on numbering |
| `issued_at` | timestamp not null | no default |
| `corrects_invoice_id` | FK `invoices`, restrict, null | correctives only |
| `voided_at` | timestamp null | lifecycle (set by a corrective bringing remaining to 0.00) |
| `template` | varchar(30) not null | look snapshot |
| `primary_color`, `accent_color` | varchar(7) null | look snapshot |
| `logo_media_id` | FK `media`, restrict, null | look snapshot |
| `issuer_legal_name` | varchar(255) not null | issuer snapshot |
| `issuer_tax_id` | varchar(40) not null | |
| `issuer_address_line1`, `issuer_address_line2` | varchar(255) null | |
| `issuer_city` (100), `issuer_postal_code` (20), `issuer_province` (100), `issuer_country` (2) | null | |
| `recipient_name` | varchar(150) not null | recipient snapshot |
| `recipient_tax_id` | varchar(40) null | |
| `recipient_address_line1`/`line2` (255), `_city` (100), `_postal_code` (20), `_province` (100), `_country` (2) | null | from the order's billing snapshot |
| `tax_rate` | decimal(6,3) null | |
| `subtotal`, `shipping_amount`, `tax_amount`, `total` | decimal(10,2) not null, signed | `subtotal` = sum of lines |
| `issued_by` | FK `users`, restrict, null | null = system |
| `collected_at` | timestamp null | lifecycle, written from 0106 |
| `collected_by` | FK `users`, restrict, null | lifecycle |
| `collection_payment_method_id` | FK `payment_methods`, restrict, null | lifecycle |
| `collection_type` | varchar(20) null | lifecycle |
| `collection_carried_over` | boolean not null default false | lifecycle |
| `created_at`, `updated_at` | timestamp | |

Indexes: PK; `invoices_number_unique` UNIQUE(`number`); `invoices_issued_at_index`; the
FK-created indexes. No composite unique on (`series`, `sequence_year`, `sequence_number`) and
none on type, series or recipient. The table collation is `utf8mb4_unicode_ci`, so `F-1` and
`f-1` collide, which is intended.

`invoice_lines` (insert-only): `id` uuid PK; `invoice_id` FK invoices restrict; `order_item_id`
FK order_items **nullOnDelete**, null (null on the concept line; the database SET NULL is the
only write a line ever receives); `position` smallint unsigned; `kind` varchar(20)
(`item`|`adjustment`); `description` varchar(255); `sku` varchar(128) null; `quantity` int signed;
`unit_price` decimal(10,2) positive; `tax_rate` decimal(6,3) null; `line_subtotal`, `tax_amount`,
`line_total` decimal(10,2) signed; timestamps. FK-created indexes only.

`orders.invoice_out_of_date_at` timestamp null, after `refunded_amount`.

Migrations, in order: `create_invoices_table`, `create_invoice_lines_table`,
`add_invoice_out_of_date_at_to_orders_table`; `down()` reverses them (lines, then invoices; drop
the column). No backfill.

## Decisions
- **FD-1 Complete table now.** `invoices` carries the lifecycle, collection and look columns
  from the start, so 0106/0107/0108 write them without a migration on it.
- **FD-2 Uniqueness (database decision).** Only `UNIQUE(number)`: the printed number is what must
  be unique, and a composite (series, year, counter) key could refuse a valid number after an
  OFF -> ON -> OFF reset history. A collision rolls the whole issue back, counter included; the
  action catches the 1062 on `invoices_number_unique` **after** the rollback and reports a
  validation error on `number` asking the manager to change the prefix. Any other unique
  violation is a bug and propagates.
- **FD-3 Immutability through the application (QA G-3).** The `Invoice` model refuses saving a
  dirty frozen column (only `voided_at`, the five collection columns and `updated_at` may change)
  and refuses every delete; `InvoiceLine` refuses every update and delete. An architecture test
  fails if code under `app/` writes either table through a query builder (`query()->update`,
  `->delete`, `DB::table('invoices'...)`, `upsert`). A direct database edit is outside the
  application and outside this epic (no triggers; legal e-invoicing out of scope).
- **FD-4 Order cannot be deleted once invoiced.** Enforced by `restrictOnDelete()` on
  `invoices.order_id`, tested; no delete path changes.
- **FD-5 Snapshot.** Issuer from the settings read under the share lock in the issuing
  transaction; recipient name and tax ID from the customer (`withTrashed`, a soft-deleted
  customer does not break issuing), recipient address from the **order's** billing snapshot;
  look (template, colours, logo id) from the settings; `order_number` from the order.
- **FD-6 Itemised vs concept line (U-7, QA G-1).** An invoice is itemised exactly when its amount
  equals the billable amount, nothing is net invoiced yet (earlier invoices all voided count as
  nothing) and the order has no refund. Then its lines copy the order lines (name, SKU,
  quantity, unit price, line total) with `kind = item`, and shipping goes in the header. Every
  other invoice has one line, `kind = adjustment`, `order_item_id` null, quantity 1, description
  "Payment on account of order N" (translated at issue in the store language and stored).
- **FD-7 Stored order values win (QA G-2).** An itemised invoice copies the order's stored
  `subtotal`, `tax_amount` and `shipping_amount`; per-line taxes are allocated by largest
  remainder (stable on ties) so they sum exactly to the stored tax. Nothing is recomputed, so an
  order whose stored tax differs from a fresh recomputation still invoices exactly its stored
  amounts.
- **FD-8 Concept split.** Components of the billable amount: base = `subtotal - refunded_amount`,
  tax = `tax_amount`, shipping = `shipping_amount` (the merchandise-only refund limitation is
  inherited). For an amount A: shipping part and tax part = `round_half_up(component x A /
  billable)`, base = A minus both; if rounding would make the base negative, the larger part is
  reduced by 0.01. When A equals the uninvoiced amount (the last invoice), each component is the
  component minus what was already invoiced of it, so the order's invoices sum exactly to its
  base, tax and shipping. All arithmetic in bcmath strings, never floats.
- **FD-9 Uninvoiced never negative (QA G-5).** Uninvoiced = `max(0.00, billable - net invoiced)`.
  Until 0107 issues refund correctives, a refund after invoicing can push billable below net
  invoiced; issuing is then refused ("nothing left to invoice"). 0107 restores the invariant.
- **FD-10 Refusals.** Issuing is refused, each as a validation error, when: issuer legal name,
  tax ID or address line 1 is empty (`issuer`, message `invoices.errors.issuer_incomplete`, a
  dedicated key so a screen can link to the settings without parsing text); the order is
  `Cancelled` (`invoice`); the order
  is out of date (`invoice`); uninvoiced is 0.00 (`invoice`); the amount is outside
  `0.01..uninvoiced` (`amount`, the message states the remaining amount); the customer row is
  missing (`invoice`); the number collides (`number`). Authorization is checked before any of
  them, so an unprivileged caller learns nothing about the order.
- **FD-11 Amount format (QA G-9).** A dot-decimal string with at most two decimals (`200`,
  `200.5`, `200.50`), normalised to scale 2; comma decimals, exponents, surrounding spaces,
  arrays, `INF` and values above the column ceiling (99999999.99) are refused; screens convert
  localised input before calling. Omitted amount = the uninvoiced amount.
- **FD-12 Lock order and timing.** One transaction: `orders` row `FOR UPDATE`, read the order's
  invoices, `AllocateInvoiceNumber::lock([Standard])` (settings `FOR SHARE`, sequence
  `FOR UPDATE`), take `issued_at = now()` after the lock, allocate, insert invoice and lines. The
  cap is safe because every invoice writer locks the order first. There is no issue-date
  parameter (no back-dating).
- **FD-13 Gated action, ungated core.** `IssueInvoice` (authorize `create` with logging, validate
  the amount, call the core) is the only entry point a screen may call.
  `IssueInvoiceForOrder` holds the transaction and is ungated; only already-authorized actions or
  system follow-ons may call it: `IssueInvoice`, `SendOrderInvoices` (0104), `MarkOrderAsPaid`
  (0106a), `IssueAutomaticInvoice` (0105). An architecture test lists these callers and fails on
  any other reference (routes, Livewire components). `CreateStandardInvoice` is the
  transaction-bound writer (refuses to run outside a transaction, for another order's locked row,
  or with a non-positive / over-ceiling total) and accepts an optional collection record used by
  0106 and 0108.
- **FD-14 Legacy paid orders until 0106 (QA G-4).** Here an invoice for a paid order without
  invoices is an ordinary uncollected invoice; 0106 adds the whole-amount-and-born-collected
  rule. Release note: 0101b to 0107 ship in one release, so no store ever runs with that gap.
- **FD-15 Out-of-date guard.** `Order::isInvoiceOutOfDate()` and `AssertInvoicesAreCurrent` live
  here; issuing calls the guard. 0104, 0106, 0106a and 0107 add their calls; only 0108 sets and
  clears the column. Every existing order reads "not out of date".
- **FD-16 Numbering format check (BR-0100-9).** `AssertNumberingFormatIsFree` runs inside
  `SaveInvoiceSettings` after its row lock: for each series it composes the next number under the
  proposed format (0101a's `nextCounter()`) and refuses the save when that number already exists
  in `invoices.number` (case-insensitive by collation), with the error on that series' prefix.
- **FD-17 Permissions.** `invoices.view` and `invoices.create` are appended to the seeder's
  `INVOICE_PERMISSIONS` (catalog 45 -> 47); Administrator and Super Admin hold them. Refusal log
  `target_type` is `invoice`.
- **FD-19 Error keys (screen contract).** `IssueInvoice` raises `amount` (below 0.01, above the
  uninvoiced amount, above the column ceiling, the legacy whole-amount rule of 0106), `issuer`
  (incomplete issuer data), `number` (collision, FD-2) and `invoice` (cancelled, out of date,
  nothing left to invoice, customer problems). `AssertInvoicesAreCurrent` takes the key the
  caller's screen renders (default `invoice`; 0107's refund passes `items`).
  `AssertNumberingFormatIsFree` throws on `<series>_prefix` with
  `invoice-settings.numbering.next_number_exists`. Screens build their read sides on these tables
  through their own read actions (`DescribeOrderInvoicing` in
  [0109a](0109a-orders-list-invoice-send-ui.md); `ListInvoices` and `FindInvoiceableOrders` in
  [0110](0110-billing-section-ui.md)).
- **FD-18 Logo restrict.** `invoices.logo_media_id` is `restrictOnDelete`, so a gallery image used
  on an issued invoice cannot be deleted (the frozen look; owner question Q-0102-5 (a)). No media
  delete action exists today; a future one must turn this into a friendly refusal.

## Class contracts
- Enums: `App\Enums\InvoiceLineKind` (`Item='item'`, `Adjustment='adjustment'`),
  `App\Enums\InvoiceCorrectionReason` (`Refund='refund'`, `OrderEdited='order_edited'`,
  `OrderCancelled='order_cancelled'`).
- `App\Models\Invoice`: `HasUuids`, `#[Fillable([])]`, casts (`type`, `reason` enums, money
  `decimal:2`, `tax_rate` `decimal:3`, timestamps); relations `order()`, `lines()`, `corrects()`,
  `correctives()`, `issuedBy()`; scopes `standard()`, `corrective()`, `live()`;
  `remainingAmount(): string`; saving/deleting guards (FD-3).
- `App\Models\InvoiceLine`: insert-only guard.
- `App\Models\Order`: `invoices()`, `billableAmount(): string`, `isInvoiceOutOfDate(): bool`,
  `invoice_out_of_date_at` cast and `@property`.
- `App\Policies\InvoicePolicy`: `VIEW_PERMISSION = 'invoices.view'`, `CREATE_PERMISSION =
  'invoices.create'`; `viewAny(User)`, `view(User, Invoice)`, `create(User)`.
- `App\Concerns\InvoiceValidationRules::invoiceAmountRules(): array`.
- `App\Actions\Invoices\IssueInvoice::__invoke(Order $order, ?string $amount = null): Invoice`.
- `App\Actions\Invoices\IssueInvoiceForOrder::__invoke(Order $order, ?string $amount, ?User
  $actor): Invoice` (ungated, FD-13).
- `App\Actions\Invoices\CreateStandardInvoice::__invoke(Order $lockedOrder, string $amount,
  ?User $actor, ?array $collected = null): Invoice`.
- `App\Actions\Invoices\ComputeOrderInvoiceBalance::__invoke(Order $order): array{billable:
  string, netInvoiced: string, uninvoiced: string, liveCount: int, components: array<string,
  string>, invoicedComponents: array<string, string>}`.
- `App\Actions\Invoices\SplitInvoiceAmount` and `BuildInvoiceLines` (pure).
- `App\Actions\Invoices\AssertInvoicesAreCurrent::__invoke(Order $lockedOrder, string $errorKey =
  'invoice'): void`.
- `App\Actions\Invoices\AssertNumberingFormatIsFree::__invoke(InvoiceSetting $proposed,
  CarbonInterface $at): void`.

## Gherkin
```gherkin
Feature: Issue a standard invoice

  Scenario: A store manager invoices a whole unrefunded order
    Given a store manager with permission to create invoices, and an unrefunded order of 120.00 EUR with nothing invoiced
    When she issues the invoice without an amount
    Then a numbered invoice of 120.00 EUR is created
    And it lists the order's items, tax and shipping exactly as the order stores them

  Scenario: A store manager issues a partial invoice
    Given a store manager with permission to create invoices, and an order of 1000.00 EUR with nothing invoiced
    When she issues an invoice of 200.00 EUR
    Then a numbered invoice of 200.00 EUR is created with the single line "Payment on account of order" and that order's number
    And 800.00 EUR remains to invoice

  Scenario: Five partial invoices add up exactly to the order
    Given a store manager with permission to create invoices, and an order of 1000.00 EUR already invoiced four times for 200.00 EUR
    When she issues the fifth invoice for 200.00 EUR
    Then the five invoices' bases, taxes and shipping add up exactly to the order's
    And nothing remains to invoice

  Scenario: Issuing more than what is left is refused
    Given a store manager with permission to create invoices, and an order of 1000.00 EUR with 900.00 EUR already invoiced
    When she issues an invoice of 200.00 EUR
    Then it is refused with a message stating that 100.00 EUR remains
    And no invoice and no number are created

  Scenario: Issuing needs complete issuer data
    Given a store manager with permission to create invoices, in a store whose issuer tax ID is empty
    When she issues an invoice for an order
    Then it is refused because the issuer data is incomplete

  Scenario: An issued invoice keeps its snapshot
    Given a store manager with permission to manage invoice settings, and an invoice issued under the old issuer address
    When she changes the issuer address in the invoice settings
    Then the issued invoice still shows the old address

  Scenario: An issued invoice cannot be changed or deleted
    Given a super administrator, and an issued invoice
    When she attempts to change its total through the application
    Then the change is refused
    And the invoice is unchanged

  Scenario: An invoiced order cannot be deleted
    Given a store manager with permission to delete orders, and an order with an invoice
    When she attempts to delete the order
    Then the deletion is refused

  Scenario: A numbering format that would repeat a number is refused
    Given a store manager with permission to manage invoice settings, and invoice F2026-00001 already issued
    When she saves a format whose next number would be F2026-00001
    Then the save is refused with an error on the standard prefix
    And the settings stay as they were

  Scenario: A user without permission cannot issue
    Given a sales assistant who may view but not create invoices
    When she tries to issue an invoice
    Then it is refused
    And the attempt is logged
```

## Files to create/modify
- Migrations: `create_invoices_table`, `create_invoice_lines_table`,
  `add_invoice_out_of_date_at_to_orders_table`.
- `app/Models/{Invoice, InvoiceLine}.php`; `app/Models/Order.php` (relations, predicates, cast).
- `database/factories/{InvoiceFactory, InvoiceLineFactory}.php` (states `standard()`,
  `corrective(Invoice $of)`, `forOrder(Order)`); `OrderFactory` helper state if tests need it.
- `app/Enums/{InvoiceLineKind, InvoiceCorrectionReason}.php`.
- `app/Policies/InvoicePolicy.php`; `app/Concerns/InvoiceValidationRules.php`.
- `app/Actions/Invoices/{IssueInvoice, IssueInvoiceForOrder, CreateStandardInvoice,
  ComputeOrderInvoiceBalance, SplitInvoiceAmount, BuildInvoiceLines, AssertInvoicesAreCurrent,
  AssertNumberingFormatIsFree}.php`.
- `app/Actions/Invoices/SaveInvoiceSettings.php` (0100) — call `AssertNumberingFormatIsFree`
  after the row lock.
- `database/seeders/RolePermissionSeeder.php` — two names; `lang/{en,es}/roles.php` —
  `modules.invoices` leaf; catalog-count tests 45 -> 47.
- `lang/{en,es}/invoices.php` (new) — `errors.*` (including `issuer_incomplete`) and the
  concept-line description; `lang/{en,es}/invoice-settings.php` — `numbering.next_number_exists`.
- Tests below. No route, config or `modules.php` change.

## Tests to perform
Real actions and database; only the clock and a second connection are arranged. Amounts are
strings in every assertion.

Happy path
- [ ] T-1 Whole uninvoiced amount: one standard invoice, number from 0101a, `issued_at` now,
  `issued_by` the actor, lifecycle columns null, `reason`/`corrects_invoice_id` null.
- [ ] T-2 Itemised (FD-6): lines copied; `subtotal`, `tax_amount`, `shipping_amount`, `total`
  equal the order's stored values; line sum = subtotal.
- [ ] T-3 Five 200.00 invoices on 1000.00: five numbers, concept lines, a sixth refused; sums per
  component equal the order's.
- [ ] T-4 Split dataset (thirds; 0.01 + rest; 999.99 then 0.01; 1.00 repeatedly on 7.00): each
  invoice's base + tax + shipping = its total; the last absorbs rounding. Seeded randomised
  property test in `tests/Unit/Actions/Invoices/SplitInvoiceAmountTest.php`.
- [ ] T-5 Cent-edge itemised dataset (rates 10 / 21 / 7.5, 0.005 residues): per-line tax sums to
  the stored order tax (largest remainder, stable ties).
- [ ] T-5b Order whose stored `tax_amount` differs from a fresh recomputation: the invoice uses
  the stored value (FD-7).
- [ ] T-6 Null `tax_rate`: invoice and lines tax 0.00, rate null.
- [ ] T-7 Partially refunded order invoiced for its whole billable amount: one concept line.
- [ ] T-8 Earlier invoices all voided (net invoiced 0.00): invoiced whole again, itemised.
- [ ] T-9 Policy: `view` holder passes `viewAny`/`view`; `create` holder passes `create`; Super
  Admin all; re-seed gives catalog 47 and Administrator both names.
- [ ] T-10 Snapshot: issuer = settings, recipient name/tax ID = customer, recipient address =
  order billing snapshot, look = settings, `order_number` = order's.

Edge
- [ ] T-11 Two issuers of 600.00 on 1000.00 (second connection holds `orders FOR UPDATE`, short
  `innodb_lock_wait_timeout`): the waiter fails with 1205; after commit it is refused by the cap;
  exactly one 600.00 invoice exists. Highest risk.
- [ ] T-12 `DB::listen`: `orders FOR UPDATE`, invoice reads, settings `FOR SHARE`, sequence
  `FOR UPDATE`, then inserts.
- [ ] T-13 Failure dataset (invoice insert, line insert, cap, collision): nothing written,
  sequence row unchanged, next issue reuses the number; a failure after commit changes nothing.
- [ ] T-14 Amount dataset: `0.01`, `1000.00`, `200`, `200.5` accepted; `0.00`, `-0.01`,
  `1000.01`, `200.005`, `' 200.00'`, `'2e2'`, `'200,50'`, `'abc'`, `''`, array, `INF`,
  `100000000.00` refused on `amount`, no counter movement.
- [ ] T-15 Billable 0.00 (fully refunded merchandise, zero order): refused, nothing left.
- [ ] T-16 Invoiced, then refunded below net invoiced: uninvoiced 0.00, issuing refused (FD-9).
- [ ] T-17 Issues at 2026-12-31 23:59:59 and 2027-01-01 00:00:00: `issued_at` and the number's
  year follow the issue moment; the signature has no date.
- [ ] T-18 After changing settings, customer (edit, rename, soft-delete), order billing address
  or line prices: every invoice column and line unchanged (dataset of 8); deleting the logo media
  row is refused by the foreign key.
- [ ] T-19 Each frozen column (dataset over all of them) set and saved through the model: guard
  throws; lifecycle columns writable; `delete()` throws; line update and delete throw.
- [ ] T-20 Bulk `Invoice::query()->update()` bypasses model events (boundary documented); the
  architecture test fails if `app/` code writes the invoice tables by query builder.
- [ ] T-21 Soft-deleted customer: issuing works (`withTrashed`); null customer tax ID -> null
  `recipient_tax_id`.
- [ ] T-22 Deleting an invoiced order fails on the foreign key; an order without invoices still
  deletes.
- [ ] T-23 Removing an invoiced order line: succeeds; the invoice line keeps description, SKU,
  amounts and gets `order_item_id` null.
- [ ] T-24 Forced number collision: validation error on `number` after rollback, counter not
  consumed; another unique violation propagates.
- [ ] T-25 `CreateStandardInvoice` outside a transaction, for another order, or with a
  non-positive / over-ceiling total: `LogicException`, nothing written.
- [ ] T-25b Architecture test: only the four FD-13 callers reference `IssueInvoiceForOrder`.

Negative
- [ ] T-26 Without `invoices.create` (none, view only, `invoice-settings.manage` only), valid or
  invalid amount, any order state: `AuthorizationException` before any state read; same refusal
  everywhere; nothing written.
- [ ] T-27 One `Log::warning('Privileged action refused')` (`ability = create`, `target_type =
  invoice`, no amount or issuer data); unauthenticated caller refused and logged.
- [ ] T-28 Issuer data missing (legal name, tax ID, address line 1, no settings row): refused on
  `issuer` with `invoices.errors.issuer_incomplete`; city and country may be empty.
- [ ] T-28b Error-key matrix (FD-19): each refusal raises exactly its key; `AssertInvoicesAreCurrent`
  with a custom key raises on that key.
- [ ] T-29 Cancelled order (manual and auto-cancel, also with billable > 0 after a full refund):
  refused.
- [ ] T-30 Out-of-date order refused; every existing order and factory default is not out of date.
- [ ] T-31 Amount just above, equal to billable after a prior invoice, or double: refused, message
  states the remaining amount.
- [ ] T-32 Missing customer row: refused, no partial rows.
- [ ] T-33 `SaveInvoiceSettings` with a format whose next number exists (standard prefix back
  to an earlier one; corrective clash; year token change): refused on `<series>_prefix` with
  `invoice-settings.numbering.next_number_exists`, row unchanged;
  a free format saves.
- [ ] T-34 Next number equal to an existing one ignoring case: refused (collation).
- [ ] T-35 No route writes or deletes invoices (`route:list` and architecture assertion).
- [ ] T-37 Product name with markup stored raw (escaping is 0102's); description and SKU fit the
  source column lengths (255 / 128).

## Expected outcome
A holder of `invoices.create` can issue whole or partial invoices for an order up to what is
left to invoice; each invoice is numbered gap-free, frozen and impossible to edit or delete
through the application; later stories can issue, correct, collect and render on top of these
tables without another migration on `invoices`.

## Acceptance criteria
- [ ] The three migrations create exactly the columns and keys above; only `UNIQUE(number)` on
  numbering.
- [ ] Issuing follows the cap, refusals and amount format (FD-9..FD-11) with authorization first.
- [ ] Itemised vs concept lines and all sums follow FD-6..FD-8 to the cent.
- [ ] Concurrent issuers can never exceed the uninvoiced amount; failures consume no number.
- [ ] Snapshots never change after later edits (FD-5); immutability holds through the
  application (FD-3); invoiced orders cannot be deleted (FD-4).
- [ ] `IssueInvoiceForOrder` is reachable only from the listed callers (FD-13).
- [ ] The settings save refuses a format whose next number already exists (FD-16).
- [ ] Permissions seeded (catalog 47); refusals logged; en/es lang parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): ER diagram (`invoices`, `invoice_lines`,
  `orders.invoice_out_of_date_at`), the invoicing schema doc, authorization catalog (47), the
  invoices API doc, directory structure (actions), the lock-order rule, and the statement that
  legal e-invoicing rules are out of scope
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0100](done/0100-invoice-settings-backend.md), [0100a](done/0100a-customer-tax-id-backend.md),
  [0101a](0101a-invoice-numbering-sequences-backend.md). Downstream: every later invoicing story;
  [0106](0106-invoice-collection-order-debt-partially-paid-backend.md) owns the legacy-order rule
  and `SyncOrderCollection`; [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md)
  adds correctives and `invoice_lines.refund_id`;
  [0108](0108-regenerate-invoices-after-order-edit-backend.md) writes the out-of-date column.
- Release risk (FD-9, FD-14): between this story and 0107, a refund after invoicing leaves more
  invoiced than billable, and a legacy paid order can get an uncollected invoice; ship 0101b to
  0107 in one release.
- Security: the amount comes from client-writable screens (0109a, 0110); only the rules and the
  server-computed cap decide. Snapshot text (product names, issuer, customer) is stored raw and
  escaped at render (0102). Mass assignment impossible (`#[Fillable([])]`).
- Fiscal: immutability holds through the application only; a database administrator can still
  edit rows; stated in the docs together with the e-invoicing out-of-scope note.
- Open questions recorded for the owner (none blocks Phase 3):
  - Q-0101-2 Legal e-invoicing rules (VeriFactu / TicketBAI): (a) out of scope for this epic,
    stated in the docs (recommended, accepted default); (b) in scope now (adds a hash chain here,
    a QR code to 0102 and a submission story).

## Technical tasks
- [ ] Schema tests; three migrations; models, factories, enums.
- [ ] Immutability guards with one test per frozen column; architecture tests (bulk writes,
  allowed callers).
- [ ] `InvoicePolicy`, seeder names, catalog-count updates, roles leaf.
- [ ] `ComputeOrderInvoiceBalance`, `SplitInvoiceAmount`, `BuildInvoiceLines` with cent-edge and
  property tests.
- [ ] `CreateStandardInvoice`, `IssueInvoiceForOrder`, `IssueInvoice` with authorization,
  logging, concurrency and rollback tests.
- [ ] `AssertInvoicesAreCurrent`; `AssertNumberingFormatIsFree` hooked into `SaveInvoiceSettings`.
- [ ] Lang en/es; Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, database-expert, backend-qa. Phase 2 (INVEST) pending.
