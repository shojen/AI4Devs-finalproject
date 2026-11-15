# [0109c] Order detail: regenerate out-of-date invoices, and the refund limit (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Third of three stories split from the former 0109
> (INVEST "Small", approved 2026-11-15). Blocked on
> [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md) (refund cap, cancel
> block), [0108](0108-regenerate-invoices-after-order-edit-backend.md) (regenerate, preview),
> [0109a](0109a-orders-list-invoice-send-ui.md) (`DescribeOrderInvoicing`) and
> [0109b](0109b-order-detail-invoices-panel-ui.md) (the `Show::invoicing` and `canViewInvoices`
> computeds, and the panel buttons this story's callout explains). No dependents.

## Description
On the order detail, an order edited after it was invoiced shows a warning callout that explains
why its money actions are blocked and, for a holder of the right permissions, a "Regenerate
invoices" button whose confirmation dialog states exactly what will be voided and issued. While
the order is out of date, refund and the invoice actions stay visible but disabled with that
reason; cancellation shows its own block when invoices are collected. The refund modal shows the
maximum refundable amount, the running refund total, and keeps confirm disabled while the
selection exceeds the maximum.

Request item served (owner, 2026-11-14, translated):
> 4. Editing an order must produce a corrective invoice that voids the previous one, plus a new
> invoice with the real content.

Binding owner decisions:
- U-4 (owner decision, 2026-11-14): "Editing an invoiced order marks it 'invoice out of date'. The
  manager presses 'Regenerate invoice': one corrective invoice per live invoice, then one new
  invoice for the real total. Nothing is issued per line change."
- U-10 (owner decision, 2026-11-15): "Regeneration keeps what was collected. Example: order 1000
  with invoices 600 (collected) and 400 (not), edited down to 800: both are corrected, then two
  new invoices are issued, 600 marked collected and 200 uncollected. An edit that would leave the
  order total below the collected amount is refused: the manager records a refund first."
- U-11 (owner decision, 2026-11-15): a "Partially paid" order can be refunded, capped at its net
  collected amount.
- U-12 (owner decision, 2026-11-15): a "Partially refunded" order can be cancelled once nothing
  collected remains; cancelling is allowed while out of date and clears the mark.

## Type
frontend (related_task_id: 0109a, 0109b, 0107, 0108) | includes database-expert: no

## Ownership across the split
- **0109c (this story):** the out-of-date callout, Regenerate button and dialog, the disabled
  reasons on refund and cancel, the refund-limit block in the refund modal.
- **[0109a](0109a-orders-list-invoice-send-ui.md):** `DescribeOrderInvoicing` (whose `regenerate`,
  `refund`, `cancel`, `markPaid` slices this story reads) and the list button (its out-of-date
  disabled state).
- **[0109b](0109b-order-detail-invoices-panel-ui.md):** the panel and its send/collect/undo
  buttons, which already show the out-of-date reason from the describer, and the `invoicing`
  computed.
- **Not here:** "Mark as paid" visibility (`Order::canMarkAsPaid()` excludes out-of-date orders,
  0106a FD-8); the line-edit refusal `orders.edit.below_collected` (raised on `items` by 0108 FD-2
  and already rendered by the existing `line-items-errors` block).

## Verified facts that shaped the story (2026-11-15)
- `Orders\Show` refund flow: `canRefund` (permission `orders.refund`, disables the button with
  the "not allowed" tooltip) and `isRefundable` (state; the button is absent when false);
  `openRefundModal()` authorizes and returns when not refundable; `refundQuantities` is a
  per-line array bound with deferred `wire:model`; `recordRefund()` casts quantities to int,
  refuses an empty selection, and puts the first message of any `ValidationException` into the
  `refund` error (`<flux:error name="refund" />` inside `data-test="refund-modal"`). Hooks
  `record-refund`, `refund-quantity-{itemId}`, `refund-confirm`, `refund-dismiss`.
- Cancel flow: `canCancel` (`Gate::allows('cancel', $order)`), `confirmCancel()` /
  `cancelOrder(CancelOrder)`; any `ValidationException` message goes to the `cancel` error
  (`<flux:error name="cancel" />`). Hook `cancel-order`.
- `lineItems` rows carry `outstandingQuantity` and the unit price; `refreshOrderState()` unsets
  the memoised computeds.

## Backend contracts used
- `DescribeOrderInvoicing::__invoke(Order)` slices (0109a), read through `Show::invoicing`
  (0109b): `regenerate: {available: bool, reasonKey: ?string}`, `refund: {stateAllowed: bool,
  reasonKey: ?string}` (`isRefundable()` and not out of date), `cancel: {blockedReasonKey:
  ?string}` (`orders.cancellation.collected_invoices` when a live invoice is collected).
- `Order::isInvoiceOutOfDate(): bool` (0101b).
- `PreviewRegeneration::__invoke(Order $order): array{liveInvoiceCount: int, voidedTotal: string,
  collected: string, billable: string, newCollectedAmount: string, newUncollectedAmount: string,
  requiresCollect: bool, blockedReasonKey: ?string}` (0108) — read-only, logged
  `authorize('view', Invoice::class)`; same pure `PlanRegeneration` as the action, so the dialog
  shows exactly what the action does. `blockedReasonKey`: not out of date
  (`invoices.errors.not_out_of_date`), collected above billable
  (`invoices.errors.collected_exceeds_billable`), issuer data incomplete
  (`invoices.errors.issuer_incomplete`, the 0101b key; 0108 FD-7 does not name it yet).
- `RegenerateOrderInvoices::__invoke(Order $order): Order` (0108) — requires `invoices.create`,
  plus `invoices.collect` when collected > 0.00; refusals logged with `target_type = order`;
  state refusals are validation errors with the keys above.
- `Order::maxRefundableAmount(): string` (0107 FD-7) — the lower of `collected_amount` and the
  money value of the still-refundable units; `RecordRefund` enforces the same method on the locked
  rows and refuses above it with a validation error on **`items`**, message
  `orders.refunds.exceeds_collected` ("Refunds are limited to the amount collected so far
  (maximum :max)"). `RecordRefund` raises the out-of-date refusal on `items` too.
- `Order::isRefundable()` = `Paid`, `PartiallyRefunded` or `PartiallyPaid` (0107 FD-8);
  `CancelOrder` refuses on `status` with `orders.cancellation.collected_invoices` when a live
  invoice is collected.

## Component changes: `App\Livewire\Orders\Show`
Computeds (all `unset` in `refreshOrderState()`):
- `isInvoiceOutOfDate` — `$this->order->isInvoiceOutOfDate()`;
- `regenerationPreview` — `PreviewRegeneration($this->order)`, read only when out of date and
  `canViewInvoices` (0109b), else null;
- `canRegenerate` — `invoicing.regenerate.available` and `Gate::allows('create', Invoice::class)`
  and, when the preview's `requiresCollect`, `Gate::allows('collect', Invoice::class)`;
  `regenerateBlockedReason` — the first applicable of: the describer/preview reason key, the
  missing-collect hint, the missing-create hint;
- `refundCap` — `$this->order->maxRefundableAmount()` (string), read while the refund modal is
  open;
- `selectedRefundTotal` — the sum of quantity x unit price over `refundQuantities` with `bcmul` /
  `bcadd` (scale 2), each quantity cast to int and clamped to `0..outstandingQuantity`;
- `refundExceedsCap` — `bccomp(selectedRefundTotal, refundCap, 2) === 1`.

State: `public bool $showRegenerateConfirm = false`.

Methods:
- `confirmRegenerate()` — opens only when `isInvoiceOutOfDate` and `canRegenerate`, else a silent
  no-op; `dismissRegenerate()`.
- `regenerateInvoices(RegenerateOrderInvoices $regenerateOrderInvoices)` — calls the action
  unconditionally with `$this->order` (no client parameters); `catch ValidationException` ->
  danger toast with the first message; success -> close, `refreshOrderState()`, success toast;
  `AuthorizationException` not caught (403, logged).
- `openRefundModal()` — additionally returns when `invoicing.refund.stateAllowed` is false.
- `recordRefund()` — unchanged contract: any `ValidationException` (the cap on `items`, the
  out-of-date refusal on `items`) lands in the `refund` error.

## View and hooks
- Callout `flux:callout variant="warning"` `data-test="invoice-out-of-date-callout"` at the top of
  the detail when `isInvoiceOutOfDate`, visible to every orders viewer: "The order changed after it
  was invoiced. Regenerate the invoices to continue." Inside it, when `canViewInvoices`:
  - `canRegenerate`: button `data-test="regenerate-invoices"`;
  - otherwise the same button disabled with `regenerateBlockedReason` in a tooltip;
  - without `canViewInvoices`: no button, the line "Ask someone who can issue invoices to
    regenerate them."
- Regenerate dialog `<x-confirm-dialog>` `data-test="regenerate-dialog"`, body only from
  `regenerationPreview`: ":count invoice(s) totalling :voided are voided with corrective
  invoices", then, when > 0, "A new invoice of :collected, already collected (carried over)" and
  "A new invoice of :uncollected, not collected"; warning "New numbers are used; the previous
  documents stay on record." Confirm `data-test="regenerate-confirm"` (`wire:loading.attr`,
  `wire:target="regenerateInvoices"`), dismiss `regenerate-dismiss`.
- Refund button: when `isRefundable` but `invoicing.refund.stateAllowed` is false, rendered
  disabled with `__($reasonKey)` in a tooltip (instead of absent), so the callout's "blocked" is
  visible where the manager looks.
- Cancel button: when `invoicing.cancel.blockedReasonKey` is set, rendered disabled with that
  reason in a tooltip (instead of the generic "not allowed").
- Refund modal (`data-test="refund-modal"`), refund quantity inputs become
  `wire:model.live.debounce.300ms`; per line, next to the outstanding units, the unit price
  (`data-test="refund-unit-price-{itemId}"`); under the lines a block
  `data-test="refund-limit"` with "Refund total" (`data-test="refund-selected-total"`) and
  "Maximum refundable" (`data-test="refund-max"`), both `<x-money>`; when `refundExceedsCap` a
  danger `flux:callout` `data-test="refund-exceeds-cap"` "Reduce the units: the refund cannot
  exceed :max"; `refund-confirm` gets `:disabled="$this->refundExceedsCap"` (prop binding, not
  the `@disabled` directive).

## Lang keys (`lang/{en,es}/orders.php`, key parity)
| Key | en | es |
| --- | --- | --- |
| `invoicing.out_of_date` | The order changed after it was invoiced. Regenerate the invoices to continue. | El pedido cambió después de facturarse. Regenera las facturas para continuar. |
| `invoicing.ask_regenerate` | Ask someone who can issue invoices to regenerate them. | Pide a alguien con permiso para emitir facturas que las regenere. |
| `invoicing.regenerate` | Regenerate invoices | Regenerar facturas |
| `invoicing.needs_create` | You need permission to issue invoices | Necesitas permiso para emitir facturas |
| `invoicing.needs_collect` | This order has collected money: you also need permission to record collections | Este pedido tiene importes cobrados: también necesitas permiso para registrar cobros |
| `invoicing.regenerate_title` | Regenerate the invoices of order :number? | ¿Regenerar las facturas del pedido :number? |
| `invoicing.regenerate_voids` | :count invoice(s) totalling :voided are voided with corrective invoices. | Se anulan :count factura(s) por un total de :voided con facturas rectificativas. |
| `invoicing.regenerate_collected` | A new invoice of :amount, already collected (carried over). | Una factura nueva de :amount, ya cobrada (se traslada el cobro). |
| `invoicing.regenerate_uncollected` | A new invoice of :amount, not collected. | Una factura nueva de :amount, sin cobrar. |
| `invoicing.regenerate_warning` | New numbers are used; the previous documents stay on record. | Se usan números nuevos; los documentos anteriores se conservan. |
| `invoicing.regenerated` | Invoices regenerated. | Facturas regeneradas. |
| `refunds.selected_total` | Refund total | Total a reembolsar |
| `refunds.max_refundable` | Maximum refundable | Máximo reembolsable |
| `refunds.reduce_units` | Reduce the units: the refund cannot exceed :max. | Reduce las unidades: el reembolso no puede superar :max. |

## Decisions
- **FD-1 The callout is visible to every orders viewer; the button only to permitted users.**
  Reason: anyone on the order meets disabled refund and invoice actions and needs the why (0108
  risk); hiding the callout behind `invoices.view` would leave them unexplained. The button needs
  `invoices.view` to show the preview and the regenerate abilities to act.
- **FD-2 Regenerate needs `invoices.create`, plus `invoices.collect` when money is carried over**
  (0108 FD-6, Q-0108-4 (a); amends the "`invoices.create`" of BR-0109-4). The disabled tooltip
  names the missing permission.
- **FD-3 The dialog text is the preview, never UI arithmetic.** Worked example (U-10): 600
  collected + 400 not, edited to 800 -> "2 invoices totalling 1000.00 are voided", "a new
  invoice of 600.00, already collected", "a new invoice of 200.00, not collected".
- **FD-4 Blocked controls stay visible and disabled with the reason.** Refund (state allowed
  but out of date) and cancel (collected invoices) show their reason; send/collect/undo do the
  same through 0109b and 0109a; Mark as paid stays hidden by `canMarkAsPaid()` (0106a), the
  callout explaining it. Line edits and status transitions stay enabled (0108 FD-4).
- **FD-5 The refund maximum is `Order::maxRefundableAmount()`** (QA gap G-2, settled by 0107
  FD-7): one method for the screen and the action, so the shown maximum, the disabled state and
  the refusal's `:max` never differ. It may be lower than the net collected when fewer units
  remain.
- **FD-6 The refusal is shown in the modal's `refund` error** (QA gap G-1). The action raises it
  on `items`; the existing `recordRefund()` maps the first message of any validation error into
  `refund`, so the modal shows it under the lines. Tests assert both: the action's key is `items`,
  the modal's message is in `refund` and contains the maximum.
- **FD-7 The running total is computed on the server, debounced** (300 ms), with bcmath on decimal
  strings: one money implementation, no float or client arithmetic. The disabled confirm is a
  hint; `RecordRefund` enforces the cap.
- **FD-8 Refund offered for Paid, Partially refunded and Partially paid, never while out of
  date** (BR-0109-7, U-11): absent when not refundable (existing convention), disabled with the
  reason while out of date.

## Gherkin
```gherkin
Feature: Regenerate out-of-date invoices and the refund limit

  Scenario: An out-of-date order explains itself and offers regeneration
    Given a store manager with permission to view and create invoices, and an order edited after it was invoiced, with nothing collected
    When she opens the order
    Then a callout says the invoices are out of date
    And the "Regenerate invoices" button is offered

  Scenario: A viewer without invoice permissions sees the explanation only
    Given a sales assistant who may view orders but not invoices, and an out-of-date order
    When she opens the order
    Then the callout says the invoices are out of date
    And it asks for someone who can issue invoices

  Scenario: Regeneration with collected money needs the collect permission
    Given a store manager who may create but not collect invoices, and an out-of-date order with 600 collected
    When she opens the order
    Then the "Regenerate invoices" button is disabled and says she also needs permission to record collections

  Scenario: The regeneration dialog states what will happen
    Given a store manager with permission to create and collect invoices, and an out-of-date order with 600 collected and 400 uncollected, now worth 800
    When she opens the regeneration dialog
    Then it says 2 invoices totalling 1000.00 are voided
    And it announces a new collected invoice of 600.00 and a new uncollected invoice of 200.00

  Scenario: A store manager regenerates the invoices
    Given a store manager with permission to create and collect invoices, and an out-of-date order with 600 collected and 400 uncollected, now worth 800
    When she confirms the regeneration
    Then the callout disappears
    And the order shows a debt of 200.00

  Scenario: Refunds are blocked while the invoices are out of date
    Given a store manager with permission to refund, and a paid order that is out of date
    When she opens the order
    Then the refund button is disabled and says the invoices must be regenerated first

  Scenario: Cancellation explains the collected-invoice block
    Given a store manager with permission to cancel, and an order with a collected invoice
    When she opens the order
    Then the cancel button is disabled and says a refund must be recorded first

  Scenario: The refund dialog shows the most that can be refunded
    Given a store manager with permission to refund, and a "Partially paid" order with 400.00 collected
    When she opens the refund dialog
    Then it shows a maximum refundable of 400.00

  Scenario: A refund above the maximum cannot be submitted
    Given a store manager with permission to refund, and a "Partially paid" order with 400.00 collected
    When she selects units worth 500.00
    Then she is told to reduce the units
    And the refund confirm button is disabled

  Scenario: A forged refund above the maximum is refused in the dialog
    Given a store manager with permission to refund, and a "Partially paid" order with 400.00 collected
    When she submits units worth 500.00 bypassing the disabled button
    Then the dialog shows that refunds are limited to 400.00
    And no refund is recorded
```

## Files to create/modify
- `app/Livewire/Orders/Show.php`, `resources/views/livewire/orders/show.blade.php`.
- `lang/{en,es}/orders.php` (`invoicing.*`, `refunds.*` additions).
- New `tests/Feature/Orders/{ShowRegenerateTest, ShowRefundLimitTest, ShowInvoiceBlockedControlsTest}.php`;
  extend `tests/Feature/Orders/ShowRefundTest.php`; browser
  `tests/Browser/Orders/RegenerateAndRefundLimitTest.php`.

## Tests to perform
Real seeder, `Pdf::fake()`, `Log::spy()`, factories (invoices, collected state, out-of-date mark).

Component level
- [ ] T-1 Out-of-date order rendered as an orders-only viewer, as `invoices.view` + `create`, and
  as `create` without `collect` while collected > 0: callout for all; button enabled for the
  second; disabled with `needs_collect` for the third; "ask" line for the first.
- [ ] T-2 Dialog text equals `PreviewRegeneration` output for the U-10 example and for a
  nothing-collected order (no "collected" line); no amount computed in the component.
- [ ] T-3 Billable below collected (corrupted data): button disabled with
  `invoices.errors.collected_exceeds_billable`.
- [ ] T-4 `regenerateInvoices`: correctives plus new invoices issued, callout gone, figures and
  panel refreshed (computeds unset); not out of date: refused with a toast, nothing changes;
  without `invoices.create`: 403, logged; with collected > 0 and no `collect`: 403, logged.
- [ ] T-5 `confirmRegenerate` forged when not allowed: no dialog.
- [ ] T-6 Out of date: refund disabled with the reason (not absent); `openRefundModal` forced:
  no modal; send/collect/undo disabled with the same reason (0109b buttons); Mark as paid absent.
- [ ] T-7 Cancel with a collected invoice: disabled with `orders.cancellation.collected_invoices`;
  forced `cancelOrder`: the message in the `cancel` error, nothing changes. Out of date with
  nothing collected: cancel enabled.
- [ ] T-8 Refund modal on Paid, PartiallyPaid and PartiallyRefunded orders: `refund-max` equals
  `maxRefundableAmount()`; not refundable: no refund control.
- [ ] T-9 Quantities worth 500.00 over a cap of 400.00: total 500.00, `refund-exceeds-cap`
  shown, confirm `disabled`; exactly 400.00 enabled; 400.01 disabled (boundary).
- [ ] T-10 Forged `recordRefund` above the cap: the action threw on `items`; the modal's `refund`
  error holds `exceeds_collected` with the formatted maximum; no refund row, no corrective.
- [ ] T-11 PartiallyPaid with cap 0.00: maximum 0.00 shown, confirm disabled for any selection.
- [ ] T-12 Tampered quantities (negative, above outstanding, non-numeric) are clamped in the
  running total and refused by the action.
- [ ] T-13 en/es keys with parity; no raw key rendered.

Browser level (bounded waits, `assertNoJavaScriptErrors`)
- [ ] B-1 Out-of-date order: callout, click `regenerate-invoices`, the dialog shows the amounts,
  confirm, callout gone, debt updated.
- [ ] B-2 Refund modal: typing units updates the total after the debounce; over the cap the
  callout appears and confirm disables; reducing the units re-enables it.
- [ ] B-3 Disabled refund and cancel show their reasons on hover.
- [ ] B-4 Phone viewport and dark mode: callout and refund-limit block readable, no horizontal
  scroll.

## Expected outcome
An edited, invoiced order tells everyone why its money actions are blocked and lets a permitted
manager regenerate its invoices after reading exactly what will happen; the refund dialog shows
the maximum and prevents an over-limit submission.

## Acceptance criteria
- [ ] The callout, button and dialog follow FD-1..FD-3; regeneration refreshes the page state.
- [ ] Refund and cancel show their block reasons (FD-4).
- [ ] The refund modal shows `maxRefundableAmount()`, the running total, and disables confirm over
  it; a forged submission shows the action's message (FD-5..FD-7).
- [ ] Refund is offered only for the three refundable statuses and never enabled while out of
  date (FD-8).
- [ ] en/es keys with parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor) — forged regenerate and refund calls
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (callout, regenerate, refund
  limit)
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream (final list): [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md),
  [0108](0108-regenerate-invoices-after-order-edit-backend.md),
  [0109a](0109a-orders-list-invoice-send-ui.md), [0109b](0109b-order-detail-invoices-panel-ui.md).
  0109b is a real dependency: this story reads `Show::invoicing` and `canViewInvoices`, which
  0109b creates.
- Risk: `refundQuantities` becoming live adds a round trip per keystroke (debounced); acceptable
  for a modal with few lines.
- Risk: a holder of refund or collect rights without `invoices.create` stays blocked on an
  out-of-date order; the callout tells them to ask (0108 risk).
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0109c-1 Who sees the out-of-date callout: (a) every orders viewer (recommended, FD-1); (b)
    only `invoices.view` holders.
  - Q-0108-4 (0108, accepted default): regeneration with carried-over money also needs
    `invoices.collect`; this story follows it.

## Technical tasks
- [ ] Red tests: regenerate, blocked controls, refund limit, browser.
- [ ] `Show` computeds, regenerate methods, refund-modal additions, `refreshOrderState()`.
- [ ] View: callout, dialog, disabled reasons, refund-limit block; lang en/es.
- [ ] Pint, Larastan, targeted then full suite; hand the docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa, backend-expert (contract answers). Phase 2 (INVEST) pending.
