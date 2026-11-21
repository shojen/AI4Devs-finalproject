# [0109b] Order detail: invoices panel with download, send, collect and undo (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Second of three stories split from the former 0109
> (INVEST "Small", approved 2026-11-15). Blocked on
> [0102](done/0102-invoice-pdf-rendering-and-templates-backend.md) (download route),
> [0104](done/0104-send-invoice-by-email-backend.md) (send actions),
> [0106](in-progress/0106-invoice-collection-order-debt-partially-paid-backend.md) (collect, undo, debt) and
> [0109a](0109a-orders-list-invoice-send-ui.md) (`DescribeOrderInvoicing`). Dependents:
> [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md) (`Show::invoicing`),
> [0110](0110-billing-section-ui.md) (invoice badge components) and
> [0110a](0110a-billing-collect-and-undo-ui.md) (collect and undo dialog components,
> `ListCollectionPaymentMethods`, `defaultPaymentType()`).

## Description
The order detail gains an invoices panel for holders of `invoices.view`: the order's invoice
figures (total, billable, net invoiced, uninvoiced, collected, debt) and every invoice and
corrective with number, type, date, total, remaining, collection and last delivery. Per invoice
the manager can download the PDF, send or resend it, mark it collected (choosing payment method
and type) or undo the collection. The panel also offers the order-level send ("Issue and send" /
"Send invoice" / "Resend (copy)") that the list row offers. Every orders viewer sees the order's
debt next to the payment badge.

Request item served (owner, 2026-11-14, translated):
> 2. In the orders list, a button on every row sends the invoice; if it was already sent when the
> order was placed, it is sent as a copy. Inside the order the invoice can also be resent or
> downloaded.

Binding owner decisions:
- U-2 (owner decision, 2026-11-14): every invoice can be marked as collected; debt = order total
  minus collected invoices (made precise as billable minus net collected).
- U-10 (owner decision, 2026-11-15): collection is a whole-invoice fact, no partial amount per
  invoice.
- U-8 (owner decision, 2026-11-15): a partly collected order is "Partially paid".

## Type
frontend (related_task_id: 0109a, 0102, 0104, 0106) | includes database-expert: no

## Ownership across the split
- **0109b (this story):** the panel, figures, debt line, the four invoice badge components
  (reused by 0110), download link, per-invoice send/resend, order-level send in the panel, the
  collect and undo dialog components (reused by 0110a), `Show::invoicing` computed,
  `ListCollectionPaymentMethods` and `PaymentMethodCode::defaultPaymentType()` (first consumer;
  0110a reuses them).
- **[0109a](0109a-orders-list-invoice-send-ui.md):** `DescribeOrderInvoicing`, the list row button.
- **[0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md):** out-of-date callout,
  regenerate, refund/cancel disabled reasons, refund cap.
- **Not here:** the payment line "recorded by ... on ..." (0106a FD-10), the `PartiallyPaid`
  badge arm (0106 FD-9), the Billing list (0110) and collecting from Billing (0110a).

## Verified facts that shaped the story (2026-11-15)
- `App\Livewire\Orders\Show` (view `resources/views/livewire/orders/show.blade.php`) keeps
  `#[Locked] string $orderId`, many memoised computeds and a private `refreshOrderState()` that
  `unset`s them after each write; dialogs use `#[Locked]` pending state cleared by one private
  method on every exit path (story 0094 precedent). Write controls the actor may not use are
  rendered disabled with the "not allowed" tooltip, or hidden by state.
- Header hooks: `header-section`, `order-status-badge`, `order-payment-badge`, `payment-info`;
  sections `customer-section`, `line-items-section`, `lifecycle-section`, `totals-section`.
- `payment_methods` has columns `id`, `code` (`PaymentMethodCode`, single case `BankTransfer`),
  `iban`; **no active flag**. `OrderPaymentType`: `Transfer`, `Card`, `Paypal`, each with
  `label()`.

## Backend contracts used
- `DescribeOrderInvoicing::__invoke(Order)` (0109a): `figures` (`total`, `billable`,
  `netInvoiced`, `uninvoiced`, `collected`, `debt`, `isLegacy`), `send` (`mode`, `reasonKey`,
  `recipientUsable`, `recipientEmail`), and `invoices` rows (`id`, `number`, `type`, `issuedAt`,
  `total`, `remaining`, `state`, `correctsNumber`, `collected`, `collectedAt`, `collectionType`,
  `collectionMethodName`, `canSend`/`sendReasonKey`, `canCollect`/`collectReasonKey`,
  `canUndo`/`undoReasonKey`, `lastDelivery` {`status`, `isCopy`, `recipientEmail`, `at`,
  `failureReason`}), ordered `issued_at desc, id desc`. Reason keys are the actions' own lang
  keys.
- `SendInvoice::__invoke(Invoice $invoice): InvoiceDelivery` and
  `SendOrderInvoices::__invoke(Order $order): list<InvoiceDelivery>` (0104): logged `send`;
  refusals `ValidationException` keyed `invoice`, issued-not-queued keyed `delivery`
  (`invoices.errors.issued_but_not_queued`); a voided standard invoice is "nothing to send"; a
  corrective is sendable.
- `CollectInvoice::__invoke(Invoice $invoice, PaymentMethod $method, OrderPaymentType $type):
  Invoice` (0106): logged `collect` (`invoices.collect`); refusals keyed `invoice` (voided,
  corrective, already collected, cancelled order, out of date); the method is resolved by the
  caller and must exist; returns the invoice refreshed with `order` loaded.
  `UndoInvoiceCollection::__invoke(Invoice $invoice): Invoice`: logged `uncollect`; refusals keyed
  `invoice` (not collected, voided, has a corrective, order has a refund, cancelled, out of date).
- Download route `invoices.download` (`GET invoices/{invoice}/pdf`, 0102): `auth`, `verified`,
  throttle 30/min, logged `view`; COPY mark once sent.
- `Order::debt(): string` (0106).

## Backend classes owned by this story
- `App\Actions\Invoices\ListCollectionPaymentMethods::__invoke(): list<array{id: string, name:
  string, code: string, defaultType: ?OrderPaymentType}>` — logged `authorize('collect',
  Invoice::class)`; lists **every** payment method (no active flag exists; this class is the one
  place an active filter goes when 0106 FD-6's inactive state is introduced); `name` is the
  code's `label()`; `defaultType = PaymentMethodCode::tryFrom($code)?->defaultPaymentType()`.
- `App\Enums\PaymentMethodCode::defaultPaymentType(): OrderPaymentType` — `BankTransfer` ->
  `Transfer`; an exhaustive `match`, so a new case fails static analysis until mapped.

## Component changes: `App\Livewire\Orders\Show`
Computeds (all `unset` in `refreshOrderState()`):
- `invoicing` — `DescribeOrderInvoicing::__invoke($this->order)`, once per render (0109c reads it
  too).
- `canViewInvoices` (`Gate::allows('viewAny', Invoice::class)`), `canSendInvoices`,
  `canCreateInvoices`, `canCollectInvoices` (policy abilities, UI hints only).
- `collectPaymentMethods` — `ListCollectionPaymentMethods`, read only while the collect modal is
  open.

State:
- `#[Locked] public ?string $pendingInvoiceId = null`, `#[Locked] public string $pendingInvoiceAction = ''`
  (`send`|`collect`|`undo`), `public bool $showInvoiceSendConfirm`, `$showCollectModal`,
  `$showUndoDialog`, `$showOrderSendConfirm`; `public ?string $collectError = null` (the
  refusal shown inside the collect dialog);
- client-writable: `public string $collectPaymentMethodId = ''`, `public string $collectType = ''`.
- The names `showCollectModal`, `collectPaymentMethodId`, `collectType`, `collectError`,
  `collectInvoice`, `closeCollectModal`, `showUndoDialog`, `undoCollection` and `cancelUndo` are
  the host contract of the shared dialog components (FD-10); 0110a's `Invoices\Index` uses the
  same names.

Methods (each opener verifies the id is a row of `invoicing.invoices` with the matching `can*`
flag and the actor's ability, else a silent no-op; each writer re-loads the invoice with
`Invoice::query()->whereBelongsTo($this->order)->find($this->pendingInvoiceId)`, calls the
action unconditionally, toasts or puts the `invoice` error in its modal, then clears the pending
state through one private `clearPendingInvoice()` and calls `refreshOrderState()`):
- `confirmSendInvoice(string $invoiceId)` / `sendInvoice(SendInvoice $sendInvoice)`;
- `confirmSendOrderInvoices()` / `sendOrderInvoices(SendOrderInvoices $sendOrderInvoices)` —
  same modes, dialog text and toasts as the list (0109a);
- `openCollectModal(string $invoiceId)` — preselects the first method and its `defaultType`;
  `updatedCollectPaymentMethodId()` — selects the new method's `defaultType` when it has one;
  `collectInvoice(CollectInvoice $collectInvoice)` — validates `collectPaymentMethodId` is one of
  `collectPaymentMethods` ids and `collectType` is an `OrderPaymentType` value (errors on those
  properties), resolves `PaymentMethod::query()->findOrFail()` and `OrderPaymentType::from()`; an
  action refusal (key `invoice`) goes to `$collectError` and the dialog stays open; success toast
  with the new debt and payment status from the returned `order`;
- `confirmUndoCollection(string $invoiceId)` / `undoCollection(UndoInvoiceCollection $undo)` (a
  refusal toasts the action's message and closes);
- dismissers `closeCollectModal()`, `cancelUndo()`, `dismissInvoiceDialog()` (the two send
  dialogs).
`AuthorizationException` is never caught (403, logged by the action).

## View and hooks
- Header: `<flux:text data-test="order-debt">` "Debt: `<x-money>`" next to
  `order-payment-badge`, amber, rendered when `Order::debt()` > 0.00 (FD-5).
- Panel `data-test="invoices-section"` below `totals-section`, rendered only when
  `canViewInvoices` (FD-4):
  - figures block `data-test="invoice-figures"` with one `<x-money>` per figure,
    `data-test="invoice-figure-{total|billable|net-invoiced|uninvoiced|collected|debt}"`;
  - order-level send button `data-test="send-order-invoices"` (labels and disabled reasons as the
    list row, FD-6);
  - table from `md` up, stacked cards below `md` (same row array, no horizontal page scroll), row
    `data-test="invoice-row-{id}"`: number (mono), the badge components, issue date, total
    (correctives negative, red), remaining, collection (date, method name, type; for a corrective
    "follows the corrected invoice"), corrected number for a corrective;
  - row actions (`flex flex-wrap gap-2`): Download `data-test="download-invoice-{id}"`
    (`<flux:button :href="route('invoices.download', $id)" target="_blank">`, no `wire:click`);
    Send / Resend `data-test="send-invoice-{id}"` (label "Send" when the last delivery is null or
    failed, "Resend (copy)" otherwise); Collect `data-test="collect-invoice-{id}"`; Undo
    `data-test="undo-invoice-{id}"`. Absent without the ability; disabled with
    `__($reasonKey)` in a tooltip when the state blocks;
  - empty states: `data-test="invoices-empty"` "No invoices yet", or for a legacy order
    (`figures.isLegacy`) "Paid before invoicing existed".
- Send dialogs: per invoice `data-test="send-invoice-dialog"` (number, original/copy text from
  0109a's `orders.invoice_send.dialog_original|dialog_copy`, recipient), confirm
  `send-invoice-confirm`; order-level `data-test="order-send-dialog"` with 0109a's texts, confirm
  `order-send-confirm`. Every confirm has `wire:loading.attr="disabled"` + `wire:target`.
- **Shared dialog components (new, FD-10)**, rendered once on the page:
  - `resources/views/components/invoice/collect-dialog.blade.php` —
    `<x-invoice.collect-dialog :invoice-number :methods :types :error />`: a `flux:modal`
    `wire:model="showCollectModal"` `@close="closeCollectModal"`, `data-test="collect-invoice-modal"`;
    `flux:select wire:model.live="collectPaymentMethodId"` `data-test="collect-method"` over
    `methods` (`ListCollectionPaymentMethods` rows); `flux:select wire:model="collectType"`
    `data-test="collect-type"` over `types` (`OrderPaymentType::cases()` with `label()`); field
    errors under each select; the `error` prop in a danger callout `data-test="collect-error"`;
    submit `data-test="collect-submit"` (`wire:click="collectInvoice"`, loading-disabled), dismiss
    `data-test="collect-dismiss"` (`closeCollectModal`).
  - `resources/views/components/invoice/undo-collection-dialog.blade.php` —
    `<x-invoice.undo-collection-dialog :invoice-number />`: `<x-confirm-dialog>` bound to
    `showUndoDialog`, `data-test="undo-collection-dialog"`, the `undo.warning` text, confirm
    `data-test="undo-collection-confirm"` (`undoCollection`), dismiss `undo-collection-dismiss`
    (`cancelUndo`).
- **Shared badge components (new, FD-9)**, each printing escaped text only:
  - `<x-invoice.type-badge :type />` — Invoice / Corrective;
  - `<x-invoice.state-badge :state />` — Live (none) / Voided; renders nothing for a corrective;
  - `<x-invoice.collection-badge :collected :derived="false" />` — Collected / Not collected;
    `derived` (correctives) renders it muted with the tooltip "Follows the corrected invoice";
  - `<x-invoice.delivery-badge :status :is-copy="false" :failure-reason="null" :at="null" />` —
    Queued (zinc, with its age when `at` is given) / Sent (green) / Failed (red,
    `failureReason` in a tooltip), plus a "Copy" marker; renders "—" when `status` is null.
  Props take the enum values and plain scalars that both `DescribeOrderInvoicing` rows and 0110's
  `ListInvoices` rows carry.

## Lang keys (`lang/{en,es}/invoices.php`, groups `panel`, `badges`, `collect`, `undo`; key parity)
| Key | en | es |
| --- | --- | --- |
| `panel.heading` | Invoices | Facturas |
| `panel.figures.total` / `.billable` / `.net_invoiced` / `.uninvoiced` / `.collected` / `.debt` | Order total / Billable / Invoiced / Not yet invoiced / Collected / Debt | Total del pedido / Facturable / Facturado / Pendiente de facturar / Cobrado / Deuda |
| `panel.empty` | No invoices yet | Todavía no hay facturas |
| `panel.legacy` | Paid before invoicing existed | Pagado antes de existir la facturación |
| `panel.download` / `.send` / `.resend` / `.collect` / `.undo` | Download / Send / Resend (copy) / Mark collected / Undo collection | Descargar / Enviar / Reenviar (copia) / Marcar como cobrada / Deshacer cobro |
| `panel.follows_corrected` | Follows the corrected invoice | Sigue a la factura rectificada |
| `badges.standard` / `.corrective` / `.collected` / `.not_collected` / `.voided` | Invoice / Corrective / Collected / Not collected / Voided | Factura / Rectificativa / Cobrada / No cobrada / Anulada |
| `badges.queued` / `.sent` / `.failed` / `.copy` | Queued :age / Sent / Failed / Copy | En cola :age / Enviada / Fallida / Copia |
| `collect.title` | Mark invoice :number as collected | Marcar la factura :number como cobrada |
| `collect.method` / `.type` | Payment method / Payment type | Método de pago / Tipo de pago |
| `collect.confirm` / `.dismiss` | Mark collected / Cancel | Marcar como cobrada / Cancelar |
| `undo.confirm` / `.dismiss` | Undo collection / Keep it | Deshacer cobro / Mantener |
| `collect.done` | Invoice collected. Order debt: :debt (:status). | Factura cobrada. Deuda del pedido: :debt (:status). |
| `undo.title` | Undo the collection of :number? | ¿Deshacer el cobro de :number? |
| `undo.warning` | The invoice becomes uncollected and the order's payment status is recalculated. | La factura pasa a no cobrada y se recalcula el estado de pago del pedido. |
| `undo.done` | Collection undone. | Cobro deshecho. |
| `orders.debt` (in `orders.php`) | Debt | Deuda |

## Decisions
- **FD-1 No standalone "Issue invoice" in the panel** (Q-1, facilitator decision). The panel
  mirrors the list's order-level button, so "Issue and send" (whole uninvoiced amount) is
  available from the order; partial and manual issues stay in Billing
  ([0110](0110-billing-section-ui.md)), which owns amount entry. Reason: one place for amount
  rules; request item 5 puts manual invoices in Billing.
- **FD-2 "Resend (copy)" on a sent invoice** (Q-2, as 0109a FD-4); the label follows the last
  delivery, the original/copy decision stays with 0104.
- **FD-3 The collect modal lists every existing payment method** (Q-3, corrected 2026-11-15).
  `payment_methods` has no active flag today and 0106 FD-6 decided "the method must exist; an
  inactive method is refused once such a state exists". `ListCollectionPaymentMethods` is the
  single place that filter will go. The type defaults from the method
  (`defaultPaymentType()`), editable, because a per-invoice collection can be card or PayPal
  while mark-as-paid keeps its bank-transfer shortcut.
- **FD-4 The panel is absent without `invoices.view`.** No invoice number, delivery or recipient
  is disclosed to an orders-only viewer; not a blurred or disabled panel.
- **FD-5 The debt line is visible to every orders viewer** when debt > 0.00. Reason: debt is an
  order fact derived from `orders` columns (`billable - collected_amount`), the same class of
  data as the payment badge `orders.view` already shows.
- **FD-6 One source for every enabled/disabled decision.** Labels, availability and reasons come
  from `invoicing` (0109a); the view never tests an order or invoice state itself.
- **FD-7 Ids are never trusted.** Openers check membership in the order's rows; writers re-load
  scoped to the order; pending ids are `#[Locked]`; the only new client-writable state is the
  collect method and type, validated by the component and again by the action.
- **FD-8 Download is a plain link.** The route is outside Livewire and gated by its action;
  `target="_blank"` keeps the order page.
- **FD-9 Four small badge components shared with 0110**, so wording and colours match
  everywhere. Four components, not one combined badge, because 0110's table prints type, state,
  collection and last delivery in separate columns.
- **FD-10 The collect and undo dialogs are Blade components shared with 0110a**, not inline
  markup, so both screens record and reverse collections with the same fields, texts and hooks.
  The component binds fixed property and method names (listed under Component changes); each
  host owns its own locked ids and authorization.

## Gherkin
```gherkin
Feature: Invoices panel on the order detail

  Scenario: A store manager reads the invoices of an order
    Given a store manager with permission to view invoices, and an order with a collected and an uncollected invoice
    When she opens the order
    Then she sees each invoice with its total, remaining amount, collection and last delivery
    And she sees the order's total, billable, invoiced, not yet invoiced, collected and debt

  Scenario: A user without permission to view invoices sees no panel
    Given a sales assistant who may view orders but not invoices
    When she opens an invoiced order
    Then the order shows no invoices panel
    And no invoice number appears on the page

  Scenario: The debt is shown next to the payment status
    Given a sales assistant who may view orders, and a "Partially paid" order of 1000 with 600 collected
    When she opens the order
    Then the order shows a debt of 400.00

  Scenario: A legacy paid order explains its empty panel
    Given a store manager with permission to view invoices, and an order paid before invoicing existed
    When she opens the order
    Then the panel says the order was paid before invoicing existed

  Scenario: A store manager downloads an invoice from the order
    Given a store manager with permission to view invoices, and an order with an invoice
    When she downloads the invoice from the panel
    Then she receives the invoice PDF

  Scenario: A store manager resends an invoice as a copy
    Given a store manager with permission to send invoices, and an invoice already sent
    When she confirms resending it from the panel
    Then the customer is sent a copy

  Scenario: A store manager marks an invoice as collected
    Given a store manager with permission to collect invoices, and an uncollected invoice of 400 on an order of 1000 with 600 collected
    When she marks it collected by bank transfer
    Then the invoice shows as collected
    And the order's debt becomes 0.00

  Scenario: A store manager undoes a collection
    Given a store manager with permission to collect invoices, and a collected invoice on an order without refunds
    When she confirms undoing the collection
    Then the invoice shows as not collected
    And the order's debt grows by the invoice total

  Scenario: An undo is refused after a refund
    Given a store manager with permission to collect invoices, and a collected invoice on an order with a refund
    When she opens the order
    Then the undo button is disabled and explains why

  Scenario: A user who may not collect has no collect button
    Given a store manager who may view but not collect invoices
    When she opens an order with an uncollected invoice
    Then the panel offers no collect or undo action
```

## Files to create/modify
- `app/Livewire/Orders/Show.php`, `resources/views/livewire/orders/show.blade.php`.
- `resources/views/components/invoice/{type-badge, state-badge, collection-badge,
  delivery-badge, collect-dialog, undo-collection-dialog}.blade.php` (new, shared with 0110 /
  0110a).
- `app/Actions/Invoices/ListCollectionPaymentMethods.php` (new), `app/Enums/PaymentMethodCode.php`
  (`defaultPaymentType()`).
- `lang/{en,es}/invoices.php` (`panel`, `badges`, `collect`, `undo`), `lang/{en,es}/orders.php`
  (`debt`).
- New `tests/Feature/Orders/{ShowInvoicesPanelTest, ShowInvoiceActionsTest}.php`,
  `tests/Feature/Invoices/ListCollectionPaymentMethodsTest.php`,
  `tests/Unit/Enums/PaymentMethodCodeTest.php` (extend if it exists); extend
  `tests/Feature/Orders/ShowRenderingTest.php`; browser `tests/Browser/Orders/InvoicesPanelTest.php`.

## Tests to perform
Real seeder, `Notification::fake()`, `Pdf::fake()`, `Log::spy()`, factories.

Component level
- [ ] T-1 Invoiced order: `invoices-section` lists number, type badge, date, total, remaining,
  collection, last delivery; figures exactly equal `invoicing.figures`; a corrective shows a
  negative total and the corrected number.
- [ ] T-2 Without `invoices.view`: no `invoices-section`, no invoice number anywhere in the HTML;
  `invoicing` still drives nothing visible.
- [ ] T-3 Legacy paid order: legacy empty state; order without invoices: "No invoices yet".
- [ ] T-4 `PartiallyPaid` order, 600 of 1000: payment badge "Partially paid" (0106's arm) and
  `order-debt` 400.00; Paid order with debt 0.00: no debt line; orders-only viewer sees the debt.
- [ ] T-5 Per-invoice send: original then copy; label follows the last delivery; voided standard
  invoice: send disabled with the action's reason.
- [ ] T-6 Order-level send in the panel: same three modes as the list; issued-not-queued warning.
- [ ] T-7 Collect: valid method/type collects, debt and status refreshed (computeds unset); forged
  `collectType = 'x'` or an unknown method id: error on the property, nothing written; action
  refusal (already collected) shown in `collect-error`, dialog still open; changing the method
  reselects its default type.
- [ ] T-8 Undo: reverses; refused after a refund (disabled with reason; forced call shows the
  action's message).
- [ ] T-9 Each action needs its own ability: without `send` / `collect` the button is absent;
  forced calls 403, logged, no change.
- [ ] T-10 `pendingInvoiceId` pointing at another order's invoice: opener no-op; writer finds
  nothing; `set('pendingInvoiceId', ...)` refused (`#[Locked]`).
- [ ] T-11 Download link targets `route('invoices.download', $id)` with `target="_blank"` and no
  Livewire action.
- [ ] T-12 `ListCollectionPaymentMethods`: every method listed with `defaultType`; refused (logged)
  without `invoices.collect`. `defaultPaymentType()` maps every `PaymentMethodCode` case.
- [ ] T-13 Badge components (rendered with `Blade::render`): every prop combination renders its
  label; `failureReason` escaped; null delivery renders "—". Dialog components render every hook
  listed above and bind the fixed host names.
- [ ] T-14 en/es keys with parity; no raw key rendered.

Browser level (bounded waits, `assertNoJavaScriptErrors`)
- [ ] B-1 Phone viewport: stacked cards, no horizontal page scroll; download opens the PDF route.
- [ ] B-2 Collect modal: choose method and type, confirm; the badge turns "Collected" and the
  debt drops.
- [ ] B-3 Undo dialog: confirm; the badge turns "Not collected" and the debt grows.
- [ ] B-4 Resend from the panel: dialog says "copy", toast shown.
- [ ] B-5 Dark mode: badges and the debt line readable.

## Expected outcome
Inside an order a manager sees every invoice and the order's invoice figures, downloads, sends
or resends any invoice, marks invoices collected or undoes a collection; an orders-only viewer
sees only the debt.

## Acceptance criteria
- [ ] The panel and its figures appear only for `invoices.view` (FD-4); the debt line for every
  orders viewer when debt > 0.00 (FD-5).
- [ ] Download, send/resend, order-level send, collect and undo work through their actions; each
  is absent without its ability and disabled with the action's reason when the state blocks.
- [ ] The collect modal lists every payment method with a default type (FD-3).
- [ ] Ids are scoped to the order and locked (FD-7).
- [ ] `ListCollectionPaymentMethods`, `defaultPaymentType()`, the four badge components and the
  two dialog components exist for reuse by 0110/0110a (FD-9, FD-10).
- [ ] en/es keys with parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor) — invoice disclosure, forged ids, payment inputs
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (panel, new Show methods), the
  invoices API doc (`ListCollectionPaymentMethods`, badge component)
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream (final list): [0102](done/0102-invoice-pdf-rendering-and-templates-backend.md),
  [0104](done/0104-send-invoice-by-email-backend.md),
  [0106](in-progress/0106-invoice-collection-order-debt-partially-paid-backend.md),
  [0109a](0109a-orders-list-invoice-send-ui.md). Downstream:
  [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md), [0110](0110-billing-section-ui.md),
  [0110a](0110a-billing-collect-and-undo-ui.md).
- Risk: `Show` grows again; the new state is grouped behind one pending-invoice pair and one
  clear method, as story 0094 did for the stock dialog.
- Risk: a `queued` delivery that never leaves (no worker) stays queued; the badge shows its age
  (0104 FD-9).
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0109b-1 "Issue invoice" in the order panel: (a) no, only the order-level "Issue and send";
    partial issues in Billing (recommended, FD-1); (b) add an amount-entry issue button here.
  - Q-0109b-2 Debt visibility: (a) every orders viewer (recommended, FD-5); (b) only
    `invoices.view` holders.

## Technical tasks
- [ ] Red tests: panel, actions, payment-method list, enum mapping, badges, browser.
- [ ] `ListCollectionPaymentMethods`, `defaultPaymentType()`.
- [ ] `Show` computeds, pending state, openers and writers, `refreshOrderState()` additions.
- [ ] View: debt line, panel, dialogs, badge component; lang en/es.
- [ ] Pint, Larastan, targeted then full suite; hand the docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa, backend-expert (contract answers). Phase 2 (INVEST) pending.
