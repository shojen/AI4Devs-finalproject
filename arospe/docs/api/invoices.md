# Invoices — issuing actions (no routes)

> **Read this when:** you call, change or add to the invoicing actions in `app/Actions/Invoices/` (issuing a standard invoice, the invoice balance, the out-of-date guard, the numbering-format check). Tables and columns are in [database/schema-other/invoices.md](../database/schema-other/invoices.md); numbering in [invoice-sequences.md](../database/schema-other/invoice-sequences.md).

Story 0101b (Epic 6 — Invoicing) ships **no route, no Livewire component and no screen**. The contract surface is the set of actions below; screens (0109a, 0110) and system follow-ons (0104, 0105, 0106a) build on them. `php artisan route:list` shows nothing invoice-related, and an architecture test asserts that no route writes or deletes invoices.

## Out of scope, stated plainly

- **Legal e-invoicing is not implemented.** The Spanish invoicing-software rules (VeriFactu, TicketBAI: record hash chain, QR code, submission to the tax agency) are out of scope for this epic (accepted default, the owner may overturn it).
- **Immutability holds through the application only.** A database administrator can still edit rows; there are no triggers (see [the schema part](../database/schema-other/invoices.md#frozen-columns-versus-lifecycle-columns)).
- **0101b to 0107 ship in one release.** Until 0107 issues refund correctives, a refund after invoicing can leave more invoiced than billable (issuing is then refused), and until 0106 a legacy paid order can get an ordinary uncollected invoice.

## Action contracts

| Action | Signature | Role |
| --- | --- | --- |
| `IssueInvoice` | `__invoke(Order $order, ?string $amount = null): Invoice` | **The only entry point a screen may call.** Authorizes (below), validates the amount, calls the core. |
| `IssueInvoiceForOrder` | `__invoke(Order $order, ?string $amount, ?User $actor): Invoice` | **Ungated** core: opens the transaction, locks the order, calls `CreateStandardInvoice`, reports a number collision. |
| `CreateStandardInvoice` | `__invoke(Order $lockedOrder, ?string $amount, ?User $actor, ?array $collected = null): Invoice` | Transaction-bound writer; `$collected` is the collection record of an invoice born collected (0106, 0108). |
| `ComputeOrderInvoiceBalance` | `__invoke(Order $order, bool $locking = false): array{billable, netInvoiced, uninvoiced, liveCount, components, invoicedComponents}` | Pure arithmetic over strings; `$locking` makes the invoices read a locking read. |
| `SplitInvoiceAmount` | `__invoke(string $amount, string $billable, string $uninvoiced, array $components, array $invoicedComponents): array` | Pure: splits an amount into base, tax and shipping parts. |
| `BuildInvoiceLines` | `itemised(iterable $items, string $storedTax, ?string $taxRate): array`, `adjustment(string $description, string $base, string $tax, ?string $taxRate): array` | Pure line builders. |
| `AssertInvoicesAreCurrent` | `__invoke(Order $lockedOrder, string $errorKey = 'invoice'): void` | Out-of-date guard (FD-15). |
| `AssertNumberingFormatIsFree` | `__invoke(InvoiceSetting $proposed, CarbonInterface $at): void` | Called by `SaveInvoiceSettings` after its row lock, before `save()`. |
| `ResolveInvoiceLocale` | `__invoke(): string` | Language the stored concept line is written in. |

`App\Concerns\InvoiceValidationRules` supplies `invoiceAmountRules()` and `invoiceAmountMessages()` (used by `IssueInvoice`; the screens reuse them).

### `IssueInvoice` authorization (FD-13, FD-17)

Issuing requires **both** `invoices.create` (`InvoicePolicy::create`) **and** `orders.view` (`OrderPolicy::viewAny`): the caller must be able to see the order (owner decision F-3, no catalog change). Every refusal path (neither permission, `invoices.create` without `orders.view`, `orders.view` without `invoices.create`, an unauthenticated caller) throws the same `AuthorizationException` and writes **one** refusal log (ability `create`, `target_type` `invoice`), **before any state read**, so the caller learns nothing about the order.

```php
// app/Actions/Invoices/IssueInvoice.php
if (Gate::forUser($caller)->denies('viewAny', Order::class)) {
    $this->logRefusedPrivilegedAttempt->log($caller, 'create', 'invoice');

    throw new AuthorizationException('This action is unauthorized.');
}

$this->logRefusedPrivilegedAttempt->authorize('create', Invoice::class, targetType: 'invoice');
```

### Who may call `IssueInvoiceForOrder`

Only already-authorized actions or system follow-ons: `IssueInvoice`, `SendOrderInvoices` (0104), `MarkOrderAsPaid` (0106a) and `IssueAutomaticInvoice` (0105). `tests/Unit/ArchitectureTest.php` (T-25b) lists these four and fails on any other reference (a route, a Livewire component, another action).

### `CreateStandardInvoice` guards

It throws `LogicException` (nothing written) when it runs outside a transaction, for an order that was not read from the database (a freshly created instance), or with a non-positive or over-ceiling total. It does not trust the caller's order instance: it re-reads the order `FOR UPDATE` itself.

## Amount format (FD-11)

A dot-decimal string with at most two decimals (`200`, `200.5`, `200.50`), normalised to scale 2. Refused: comma decimals, exponents, surrounding spaces, arrays, `INF`, an empty string, values below `0.01` and values above the column ceiling (`99999999.99`). Screens convert localised input before calling. An omitted amount (`null`) means the whole uninvoiced amount.

## Error-key matrix (FD-19)

| Key | Raised for | Message key |
| --- | --- | --- |
| `amount` | not in the dot-decimal format, below 0.01, above the uninvoiced amount (the message states what remains), above the column ceiling | `invoices.errors.amount_format`, `amount_minimum`, `amount_exceeds_remaining` |
| `issuer` | legal name, tax ID or address line 1 empty (city and country may be empty; no settings row counts as empty) | `invoices.errors.issuer_incomplete` |
| `number` | the printed number collides (`invoices_number_unique`), reported after the rollback | `invoices.errors.number_collision` |
| `invoice` | cancelled order, out-of-date order, nothing left to invoice, customer row missing | `cancelled`, `out_of_date`, `nothing_left`, `customer_missing` |

`AssertInvoicesAreCurrent` takes the key the caller's screen renders (default `invoice`; 0107's refund passes `items`). `AssertNumberingFormatIsFree` throws on `<series>_prefix` (for example `standard_prefix`) with `invoice-settings.numbering.next_number_exists`. Messages live in `lang/{en,es}/invoices.php`.

## Snapshot, itemised versus concept line, split

- **Snapshot (FD-5).** Issuer and look come from the settings read under the share lock in the issuing transaction; the recipient name and tax ID from the customer (`withTrashed`, so a soft-deleted customer does not break issuing); the recipient address from the **order's** billing snapshot.
- **Itemised (FD-6, FD-7).** An invoice is itemised exactly when its amount equals the billable amount, nothing is net invoiced yet and the order has no refund: lines copy the order lines (`kind = item`), shipping goes in the header, and the order's **stored** `subtotal`, `tax_amount` and `shipping_amount` win (per-line tax by largest remainder, stable on ties, nothing recomputed).
- **Concept line.** Every other invoice has one `adjustment` line (`order_item_id` null, quantity 1) "Payment on account of order N", written in the language `ResolveInvoiceLocale` returns and **stored** (see [localization](../conventions/localization.md#invoice-text-is-written-in-the-store-language-story-0101b)).
- **Split (FD-8).** Shipping part and tax part are `round_half_up(component x amount / billable)`, base is the rest (the larger part is reduced by 0.01 if the base would go negative); the last invoice (amount equals the uninvoiced) takes each component minus what was already invoiced, so the invoices sum exactly to the order's base, tax and shipping. All arithmetic is bcmath strings, never floats.
- **Uninvoiced never negative (FD-9).** `max(0.00, billable - net invoiced)`; `Order::billableAmount()` is `total - refunded_amount`, `0.00` when cancelled.

## Order model additions

`Order::invoices()` (`HasMany`), `Order::billableAmount(): string`, `Order::isInvoiceOutOfDate(): bool` and the `invoice_out_of_date_at` datetime cast. Every existing order reads "not out of date"; only 0108 writes the column.

## Locking

The issuing lock order and its rationale are in [orders.md](orders.md#lock-order-convention-project-wide).

_Last updated: 2026-11-19 — Story 0101b: page created (no routes; action contracts, error-key matrix, out-of-scope statements)._
