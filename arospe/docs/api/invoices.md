# Invoices — issuing actions, the PDF download and e-mail delivery

> **Read this when:** you call, change or add to the invoicing actions in `app/Actions/Invoices/` (issuing a standard invoice, the invoice balance, the out-of-date guard, the numbering-format check). Tables and columns are in [database/schema-other/invoices.md](../database/schema-other/invoices.md); numbering in [invoice-sequences.md](../database/schema-other/invoice-sequences.md).

Story 0101b (Epic 6 — Invoicing) ships **no Livewire component and no screen**. The contract surface is the set of actions below; screens (0109a, 0110) and system follow-ons (0104, 0105, 0106a) build on them. Since story 0102 the only invoice route is the read-only PDF download (see [the download section](#pdf-rendering-and-download-story-0102)); story 0103 adds the [settings screen](#invoice-settings-screen-story-0103) and its sample-PDF preview route; an architecture test still asserts that no route writes or deletes invoices.

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
| `ResolveInvoiceLocale` | `__invoke(): string` | Language the stored concept line is written in; story 0102 reuses it unchanged for the PDF. |
| `DownloadInvoice` | `__invoke(Invoice $invoice): Symfony\...\Response` | Story 0102. Authorizes `view`, renders and returns the PDF attachment. |
| `RenderInvoicePdf` | `__invoke(Invoice $invoice, bool $isCopy, bool $isSample = false): Spatie\LaravelPdf\PdfBuilder` | Story 0102. Builds the hardened PDF from the frozen snapshot; nothing is stored. Also used by 0104 (attachment) and 0103 (preview, unsaved invoice). |
| `BuildInvoicePdfData` | `__invoke(Invoice $invoice, bool $isCopy, string $locale, bool $isSample = false): array` | Story 0102. Pure view-model builder; reads the invoice, its `lines` and `corrects` only, never `orders`, `customers` or `invoice_settings`. |
| `LoadInvoiceLogo` | `__invoke(?string $mediaId): ?string` | Story 0102. The frozen logo as a data URI, or `null`. |
| `FormatInvoiceMoney` | `__invoke(string $amount, string $locale): string` | Story 0102. Locale formatting of a stored decimal string, never through a float. |

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

## PDF rendering and download (story 0102)

`GET /invoices/{invoice}/pdf` (`invoices.download`, [`routes/invoices.php`](../../routes/invoices.php)) is served by the thin `App\Http\Controllers\InvoiceDownloadController` in front of `DownloadInvoice`. Middleware: `auth`, `verified` and `throttle:invoice-downloads` (30 per minute per user, bounding Chromium cost). It has **no `can:` on purpose**, so the refusal is logged (see [authorization](../architecture/authorization/step-up-and-refusal-logging.md#refusing-a-download-without-a-can-middleware-story-0102)).

```php
// app/Actions/Invoices/DownloadInvoice.php
$this->logRefusedPrivilegedAttempt->authorize('view', $invoice, targetType: 'invoice', targetId: $invoice->id);

$name = 'invoice-'.preg_replace('/[^A-Za-z0-9._-]/u', '-', $invoice->number).'.pdf';
```

| Case | Result |
| --- | --- |
| Guest | Redirect to login (not logged) |
| Signed in without `invoices.view` | 403 and one refusal log |
| Unknown invoice id | 404 |
| Voided invoice, cancelled order, soft-deleted customer | Still downloads |
| Success | `application/pdf` attachment, `Cache-Control: private, no-store` |

- **File name.** `invoice-` + the number with every character outside `[A-Za-z0-9._-]` replaced by `-` (numbers may contain `/`), plus `.pdf`.
- **Snapshot only.** The PDF is rendered on demand from the invoice's own frozen columns, lines and `corrects` row: its `template`, colours and logo included. Two renders of one invoice give identical view data and HTML, not identical bytes (a PDF embeds a creation time).
- **Templates.** `App\Enums\InvoiceTemplate::view()` maps `classic` / `modern` to `resources/views/invoices/pdf/{classic,modern}.blade.php`, which both include the shared partials in `resources/views/invoices/pdf/partials/`; a stored value that is not a case fails loudly.
- **COPY and SAMPLE marks.** `RenderInvoicePdf` takes `$isCopy` (downloads pass `Invoice::hasBeenSent()`, true once a `sent` delivery exists since story 0104) and `$isSample` (the settings preview of 0103). Both marks are translated strings (`invoices.pdf.copy`, `invoices.pdf.sample`).
- **Language.** `ResolveInvoiceLocale`, never the admin's UI locale; see [localization](../conventions/localization.md#the-invoice-pdf-uses-the-same-language-story-0102).
- **Hardening and the driver.** Rules and rationale in [security/headless-pdf-rendering.md](../security/headless-pdf-rendering.md); runtime prerequisites in the [deployment note](../architecture/overview.md#deployment-note).
- **Config.** `config/invoicing.php` `pdf`: `format` (`INVOICE_PDF_FORMAT`, `a4`), `margin_mm` (`INVOICE_PDF_MARGIN_MM`, 15), `timeout_seconds` (`INVOICE_PDF_TIMEOUT_SECONDS`, 30), `logo_max_bytes` (`INVOICE_PDF_LOGO_MAX_BYTES`, 1 MiB); plus the published `config/laravel-pdf.php`.

## Invoice settings screen (story 0103)

The backoffice screen for every invoice setting of story 0100, in the Store settings cluster. No new table or permission: it reads `invoice-settings.view` and writes through `invoice-settings.manage` (`InvoiceSettingPolicy`).

| Route | Name | Middleware |
| --- | --- | --- |
| `GET /invoice-settings` | `invoice-settings.index` | `auth`, `verified`, `can:invoice-settings.view` ([`routes/invoice-settings.php`](../../routes/invoice-settings.php)) |
| `GET /invoice-settings/preview/{template}` | `invoice-settings.preview` | `auth`, `verified`, `throttle:invoice-downloads`, **no `can:`** ([`routes/invoices.php`](../../routes/invoices.php)) |

**Sidebar.** `config/modules.php` `items.invoice_settings` sits in the `store_settings` cluster right after `store_languages` (`group => null`, `permissions` exactly `['invoice-settings.view']`, `current_when => 'invoice-settings.*'`, label `navigation.items.invoice_settings`); topbar subtitle `topbar.invoice_settings.subtitle`.

### `App\Livewire\InvoiceSettings\Index`

- **Mount.** `Gate::authorize('viewAny', InvoiceSetting::class)`, then `fillFromSettings(InvoiceSetting::current())` (null columns become `''`).
- **Write methods authorize themselves** (the disabled fieldset of a read-only viewer is not security). Each calls `authorizeUpdate()`: `LogRefusedPrivilegedAttempt::authorize('update', InvoiceSetting::class, targetType: 'invoice_setting', ...)`, so a refusal is a 403 plus one log.
- **Methods.** `save()` sends the complete key set of `formAttributes()` to `SaveInvoiceSettings` (last write wins, no version check) and refills the form from the stored row so normalised values show; `setLogo(array $media)` (listens to `invoice-logo-selected`); `removeLogo()`; `updatedPrimaryColor()` / `updatedAccentColor()` (validate with the save's normaliser and rule, error on the property only); `openStartModal(string $series)`; `setStart(SetInvoiceSequenceStart)`.
- **Error re-key.** The action throws snake_case keys; `rekeyErrors()` maps them to component properties with `Str::camel`, except `reset_numbering_yearly` to `isNumberingResetYearly` and `auto_issue_on_order` to `isAutoIssueEnabled` (`IRREGULAR_PROPERTIES`). `setStart` re-keys `start_at` to `startAt`.
- **Logo.** `logoMediaId` and `startSeries` are `#[Locked]`. `setLogo()` gate order: `update` on `InvoiceSetting`, then `viewAny` on `Media`, both through the refusal-logging helper (same as `Products\Editor::setFeaturedImage()`). The preview URL is derived from the id read from the database, never from the event payload; an unknown id is a silent no-op. A forged non-array payload fails the `array $media` type and answers 500; accepted, consistent with `setFeaturedImage` (a `mixed $media` change for both is an optional follow-up). Only Save persists the logo.
- **Colours.** The colour inputs use `wire:model.live.blur`, not `.blur` (see [errors-log](../errors-log.md) topic Livewire rendering). A colour error is the `flux:input`'s own auto-rendered error under the input (the screen wraps its sections in a native `<fieldset>`, which does not swallow it, unlike `flux:fieldset`); only the logo needs an explicit `<flux:error name="logoMediaId" />`. The only value written into a `style` attribute is `safePrimaryColor` / `safeAccentColor` (`^#[0-9A-Fa-f]{6}$`, else `null`).
- **Computeds.** `canManage`, `canChooseLogo` (`update` and `viewAny` on `Media`, through `allowsSafely`), `logo`, `templateOptions`, `yearOptions`, `safePrimaryColor`, `safeAccentColor`, `nextNumbers`, `sequenceState`. `nextNumbers` calls `PeekNextInvoiceNumber` with the format being typed and returns `null` for a series whose format is invalid; it writes nothing and never puts errors in the bag.
- **Read-only model.** Without `invoice-settings.manage` the form is a disabled fieldset and every write method refuses. The start-number control opens only while `sequenceState[series].hasIssued` is false; `setStart` still refuses an issued series with `start_already_issued`.

### Read and preview actions

| Class | Signature | Role |
| --- | --- | --- |
| `ReadInvoiceSequenceState` | `__invoke(): array{standard: S, corrective: S}`, `S = array{hasIssued: bool, lastNumber: int, lastYear: int\|null, lastIssuedAt: CarbonImmutable\|null}` | Authorizes `viewAny` on `InvoiceSetting` (logged), one query on `invoice_sequences`. `hasIssued` follows `last_issued_at` (a starting number moves the counter without issuing). A missing row reads `0`/`null`. |
| `PreviewInvoicePdf` | `__invoke(InvoiceTemplate $template): PdfBuilder` | Authorizes `viewAny` on `InvoiceSetting` (logged). Builds an **unsaved** `Invoice` plus two in-memory `InvoiceLine`s from the saved settings; the number is only peeked (`PeekNextInvoiceNumber`), nothing is written or consumed. Sample customer and item strings (`invoices.pdf.sample_customer`, `sample_item`) are translated in the `ResolveInvoiceLocale` language, never the admin's. Renders with `RenderInvoicePdf($invoice, false, isSample: true)`. |

`App\Http\Controllers\InvoiceSettingsPreviewController::__invoke(InvoiceTemplate $template, PreviewInvoicePdf)` streams the PDF inline as `invoice-preview-<template>.pdf` with `Cache-Control: private, no-store`. Implicit enum binding makes an unknown template a 404 (not logged). The refusal for a user without `invoice-settings.view` is the action's logged 403 (see [authorization](../architecture/authorization/step-up-and-refusal-logging.md#refusing-a-download-without-a-can-middleware-story-0102)). The route shares the `invoice-downloads` limiter with real downloads: one 30-per-minute bucket per user, intended.

## Sending invoices by e-mail (story 0104)

No route and no screen: two self-authorizing actions plus a queued notification. Schema: [`invoice_deliveries`](../database/schema-other/invoices.md#invoice_deliveries); permission: [`invoices.send`](../architecture/authorization/overview-catalog-seeding.md).

| Class | Contract |
| --- | --- |
| `SendInvoice` | `__invoke(Invoice $invoice): InvoiceDelivery`. One live standard invoice or one corrective; a voided standard invoice is refused. |
| `SendOrderInvoices` | `__invoke(Order $order): list<InvoiceDelivery>`. One mail with every live standard invoice plus every corrective not yet sent. With no live standard invoice and an uninvoiced amount it is "Issue and send" (also needs `invoices.create`): pre-checks first, the invoice is issued in its own committed transaction, then sent as the original. |
| `RecordInvoiceDeliveries` | `__invoke(Order $lockedOrder, list<Invoice> $invoices, ?User $sentBy): list<InvoiceDelivery>`. Transaction-bound and **ungated** (called by the two actions and, as the system with `$sentBy = null`, by story 0105). |
| `InvoiceSent` | Queued, after-commit notification to an on-demand address with an explicit locale (`ResolveInvoiceLocale`). Its payload is delivery ids only; `toMail()` re-reads the rows and renders each PDF in the worker (nothing is stored), under the sanitised file name of `BuildInvoicePdfFileName`. |
| `MarkInvoiceDeliverySent` | Listener on `NotificationSent`; ignores every other notification; sets the rows `sent`. The notification's `failed()` sets them `failed` through `ClassifyDeliveryFailure`. |

- **Original or copy.** Decided under the invoice row lock: the send is the original when the invoice has no delivery other than `failed` ones, otherwise a copy (its PDF carries the COPY mark). A double click or an automatic send racing a manual one yields exactly one original; a later send after only failures is an original.
- **Recipient.** Only the customer's current e-mail, read inside the lock and frozen on the row; no action accepts a recipient. It is validated with the RFC rule before any row or invoice is written.
- **One operation, one mail.** If any PDF fails to render the whole mail fails and all its rows become `failed`. At most `max_attachments` (10) invoices per mail, checked before any row.
- **Dispatch failure.** Dispatch is after commit; if it throws, the operation's rows are set `failed` ("dispatch failed") and the error reaches the caller, so a mail that never left cannot turn later sends into copies. A row queued while no worker runs stays `queued`.
- **Lock order.** `orders` row first, then the invoices `orderBy('id')->lockForUpdate()`, then the inserts; the issue step of "Issue and send" runs before, in its own transaction.
- **Failure reason.** At most 500 characters, stripped of credentials, file paths and e-mail addresses.

| Key | Raised for | Message key |
| --- | --- | --- |
| `invoice` | soft-deleted customer; unusable address; nothing to send; more than the attachment cap; out-of-date order; voided invoice; cancelled order | `customer_deleted`, `customer_email_unusable`, `nothing_to_send`, `too_many_attachments`, plus the existing `out_of_date`, `cancelled` |
| `delivery` | "Issue and send" committed the invoice but the delivery step failed | `issued_but_not_queued` (the screen must say the invoice exists and is sent manually) |

Permission refusals are `AuthorizationException` only. Refusal log: `target_type` `invoice` (single) or `order` (order-level). Mail strings live in `lang/{en,es}/invoices.php` under `mail` (`subject`, `greeting`, `body`, `copy_notice`).

**Config** (`config/invoicing.php` `delivery`): `tries` (`INVOICE_DELIVERY_TRIES`, 3), `backoff_seconds` (`INVOICE_DELIVERY_BACKOFF_SECONDS`, 60), `timeout_seconds` (`INVOICE_DELIVERY_TIMEOUT_SECONDS`, default 10 x the PDF timeout + 60; it must cover `max_attachments` renders, so override with care), `max_attachments` (10, fixed).

### Operations and known limitations

- **Never `MAIL_MAILER=log` in production.** It is the `.env.example` default; with it a "sent" row records a mail that was only written to the log, and the invoice stops being an original.
- **A queue worker is required** (`QUEUE_CONNECTION=database`), with headless Chrome on the worker host (see the [deployment note](../architecture/overview.md#deployment-note)); without one rows stay `queued`.
- **Schedule `queue:prune-failed`.** The customer's address sits in the `jobs` and `failed_jobs` payloads (delivery ids only, but the notifiable is serialized); prune failed jobs on a schedule, for example `queue:prune-failed --hours=168`.
- **At-least-once delivery.** If `MarkInvoiceDeliverySent` fails after the mail left, a retry can send a duplicate mail; the rows still never end both failed and sent.
- **No rate limit on resend** in this story; the screen stories (0109a, 0109b, 0110) must add one.
- **`invoices.send` does not need `invoices.view`** (intended).
- **Accepted edge cases.** A customer deleted after queueing still receives the mail; a queued original that finally fails leaves a copy made meanwhile marked copy.

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

## Automatic invoicing on order creation (story 0105)

When `auto_issue_on_order` is on, a new order gets one itemised invoice for its whole billable amount, issued and queued to the customer as the original, right after the order commits. Off (the default) changes nothing; switching it never scans existing orders.

| Class | Contract |
| --- | --- |
| `IssueAutomaticInvoice` | `__invoke(string $orderId): void`. Never throws. Called only by `CreateOrder` from a `DB::afterCommit` callback (architecture test). Ungated, never reads the authenticated user: `issued_by` and `sent_by` are null. Skips: flag off, missing or cancelled order, order that already has any invoice (idempotent), billable `0.00`. |
| `NotifyAutomaticInvoiceFailed` | `__invoke(Order $order, string $stage, Throwable $cause): void`. Notifies holders of `invoices.create` or `invoices.send` (Super Admin excluded, no recipient: log only). One notification per stage per hour (`Cache::add`, key `invoices.automatic-failed.<stage>`). |
| `App\Notifications\AutomaticInvoiceFailed` | `database` channel; payload is exactly `order_id`, `order_number`, `stage` (`issue` or `send`), `reason_key`. Never the exception text (it goes to the log via `report()`). Copy in `lang/{en,es}/invoices.php` group `automatic`. |

- **Two failure scopes.** An issue failure leaves the order untouched and consumes no number; a send failure (including a soft-deleted customer) leaves the invoice issued and a later manual send is an original. Neither propagates to `CreateOrder`.
- **Retry.** The issue is retried once, only on deadlock (1213) or lock wait timeout (1205).
- **Stale-totals limitation (FD-9).** If an order's totals change after creation without a line edit (tax or shipping resolved later through the region resolvers), the auto-issued invoice goes stale silently. Until story 0108 marks every totals writer out of date (0106 adds the shared after-totals-changed hook), a future checkout must resolve tax and shipping before `CreateOrder` commits.

## Collecting an invoice (story 0106)

No route and no screen yet (the UI is stories 0109b/0110a): two self-authorizing actions. Schema: [`orders.collected_amount`](../database/schema-orders/orders.md#orders) and the five collection columns on `invoices` ([invoices schema](../database/schema-other/invoices.md)); status derivation: [Orders - derived payment status](orders.md#derived-payment-status-and-the-partiallypaid-matrix-story-0106).

| Class | Contract |
| --- | --- |
| `CollectInvoice` | `__invoke(Invoice $invoice, PaymentMethod $method, OrderPaymentType $type): Invoice`. Logged `authorize('collect')` first, then one transaction: `orders` row `FOR UPDATE`, `AssertInvoicesAreCurrent`, invoice row `FOR UPDATE`, refusals, then `ApplyInvoiceCollection`. Collection is whole-invoice (no amount); the actor is the authenticated user, the moment the server clock, `collection_carried_over = false`. Returns the invoice refreshed with `order` loaded (new debt and payment status). `Log::info`. |
| `UndoInvoiceCollection` | `__invoke(Invoice $invoice): Invoice`. Logged `authorize('uncollect')`, same locks, clears the five columns, then `SyncOrderCollection`. `Log::warning` (a financial reversal). |
| `ApplyInvoiceCollection` | `__invoke(Order $lockedOrder, list<Invoice> $lockedInvoices, PaymentMethod $method, OrderPaymentType $type, User $actor): void`. Ungated collaborator (reused by story 0106a); writes the columns and syncs the order. |
| `ComputeOrderInvoiceBalance` | gains `netCollected`, `debt` and `isLegacy` in its result. |

- **Refusals** are `ValidationException`s keyed `invoice` (keys under `invoices.errors.*`, en/es parity). Collect: `collect_voided`, `collect_corrective` (a corrective is collected together with the invoice it corrects), `already_collected`, `collection_cancelled`, and the out-of-date guard. Undo: `not_collected`, `collect_voided`, `undo_has_corrective`, `undo_has_refund`, `collection_cancelled`, out-of-date. A missing payment method is keyed `payment_method` (`payment_method_missing`). Authorization always comes first.
- **Net collected** = signed sum of collected standard invoices plus correctives of collected invoices; correctives carry no collection columns of their own.
- **Permission:** both abilities share `invoices.collect` (`InvoicePolicy::COLLECT_PERMISSION`); see [authorization catalog](../architecture/authorization/overview-catalog-seeding.md#permission-catalog). Refusals are logged with `target_type = invoice`.
- **Legacy invoicing.** `IssueInvoiceForOrder` on an order with no invoice and a paid-like stored status may only invoice the whole uninvoiced amount (error on `amount`) and the invoice is born collected (`collection_carried_over = true`).
- **Order model additions:** `collected_amount` cast, `debt(): string`, `latestCollectedInvoice()` (feeds the order's payment line); `Invoice::collectedBy()`, `collectionPaymentMethod()`, `isCollected()`, scopes `collected()` / `uncollected()`.

## Order model additions

`Order::invoices()` (`HasMany`), `Order::billableAmount(): string`, `Order::isInvoiceOutOfDate(): bool` and the `invoice_out_of_date_at` datetime cast. Every existing order reads "not out of date"; only 0108 writes the column.

## Locking

The issuing lock order and its rationale are in [orders.md](orders.md#lock-order-convention-project-wide).

_Last updated: 2026-11-21 — Story 0106: collect/undo actions, `ApplyInvoiceCollection`, refusal keys, `invoices.collect` and legacy invoicing; the stories 0101b-0105 contracts are unchanged._
