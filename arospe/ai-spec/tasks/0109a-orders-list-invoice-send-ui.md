# [0109a] Orders list: invoice send button, and the order invoicing read side (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). First of three stories split from the former 0109
> (INVEST "Small", approved 2026-11-15). Blocked on
> [0104](0104-send-invoice-by-email-backend.md), [0106a](0106a-mark-as-paid-invoice-shortcut-and-real-income-backend.md),
> [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md) and
> [0108](0108-regenerate-invoices-after-order-edit-backend.md) (their refusals are what the
> read side mirrors). Dependents: [0109b](0109b-order-detail-invoices-panel-ui.md),
> [0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md),
> [0110](0110-billing-section-ui.md) and [0110a](0110a-billing-collect-and-undo-ui.md)
> (through `forInvoices`).

## Description
Every orders-list row gains one button that sends the order's invoice: "Issue and send" when the
order has no live invoice, "Send invoice" when an invoice was never sent, "Resend (copy)" when
everything was already sent; disabled with the reason when the order's state blocks sending. A
confirmation dialog names the recipient. The story also ships `DescribeOrderInvoicing`, the
single read collaborator that tells every invoice screen which invoice action is available and
why not, proven equal to the actions by a parity test.

Request item served (owner, 2026-11-14, translated):
> 2. In the orders list, a button on every row sends the invoice; if it was already sent when the
> order was placed, it is sent as a copy. Inside the order the invoice can also be resent or
> downloaded.

The "inside the order" half is [0109b](0109b-order-detail-invoices-panel-ui.md).

Binding owner decisions and accepted defaults:
- U-3 (owner decision, 2026-11-14): the invoice can be issued and sent automatically on order
  creation; otherwise the manager issues it by hand.
- Orchestrator default (2026-11-14, owner may overturn): "the orders-list row button on an order
  with no invoice issues the invoice for the whole uninvoiced amount and sends it, after a
  confirmation".

## Type
frontend (related_task_id: 0104, 0106a, 0107, 0108) | includes database-expert: no

## Ownership across the split
- **0109a (this story):** `DescribeOrderInvoicing` (all three forms) and its parity, query-count
  and reachability tests; the list row button, dialog and `sendInvoices()`; the reflection-test
  edit.
- **[0109b](0109b-order-detail-invoices-panel-ui.md):** the order-detail invoices panel, figures,
  debt line, shared badge component, download, per-invoice send/resend, order-level send in the
  panel, collect/undo, `ListCollectionPaymentMethods`, `PaymentMethodCode::defaultPaymentType()`,
  and the `Show::invoicing` computed.
- **[0109c](0109c-order-detail-regenerate-and-refund-limit-ui.md):** out-of-date callout,
  regenerate dialog, refund/cancel controls disabled with reasons, refund-cap display.
- **Not here:** the order-detail payment line (0106a FD-10), the `PartiallyPaid` badge arm
  (0106 FD-9), `canMarkAsPaid()` (0106a FD-8).

## Verified facts that shaped the story (2026-11-15)
- `App\Livewire\Orders\Index` (view `resources/views/livewire/orders.blade.php`) loads **every**
  order (no pagination) with `customer` `withTrashed()` and maps rows to arrays (`canMarkPaid`
  etc.). Its only mutating method is `markAsPaid()`; `confirmMarkAsPaid(string $orderId)` stores a
  `#[Locked] ?string $confirmingPaidOrderId` only when the id is a listed row; the
  `confirmingPaidRow` computed re-derives the row; `markAsPaid()` re-loads the order, calls the
  action unconditionally, toasts the first validation message, lets `AuthorizationException` 403.
- A reflection test in `tests/Feature/Orders/IndexTest.php` pins the exact public method list
  (allow-list) of `Orders\Index`; a new public method fails it until edited on purpose.
- The list hides controls the actor may not use (`canMarkPaid` hidden, never disabled). The
  page uses `<x-confirm-dialog>` for mark-as-paid. Hooks `order-row-{id}`, `view-order-{id}`,
  `mark-as-paid-{id}` exist.

## Backend contracts used
- `SendOrderInvoices::__invoke(Order $order): list<InvoiceDelivery>` (0104) — logged
  `authorize('send')` (`invoices.send`); with a live standard invoice: one mail with every live
  standard invoice and every unsent corrective, each original or copy by its own history
  (original = no delivery other than `failed`); with no live standard invoice and uninvoiced >
  0: "Issue and send", which also requires `invoices.create`, pre-checks (customer present and
  not deleted, usable e-mail, not cancelled, not out of date, issuer data complete), issues the
  whole uninvoiced amount in its own transaction, then sends it as the original; nothing to send:
  refused; more than 10 attachments: refused. State refusals: `ValidationException` keyed
  `invoice` (`invoices.errors.*`); issued but delivery failed: `ValidationException` keyed
  `delivery` (`invoices.errors.issued_but_not_queued`); permission: `AuthorizationException`
  only. Each returned delivery reaches its `invoice` and carries `recipient_email`, `is_copy`,
  `status`. A double click yields exactly one original (invoice row lock, 0104 FD-1).
- `SendInvoice::__invoke(Invoice $invoice): InvoiceDelivery` (0104), `CollectInvoice`,
  `UndoInvoiceCollection` (0106), `MarkOrderAsPaid` + `Order::canMarkAsPaid()` (0106a),
  `RecordRefund`, `CancelOrder`, `Order::isRefundable()`, `isManuallyCancellable()`,
  `maxRefundableAmount()` (0107), `RegenerateOrderInvoices` and `PlanRegeneration` (0108),
  `ComputeOrderInvoiceBalance` with `netCollected`, `debt`, `isLegacy` (0101b/0106),
  `Order::isInvoiceOutOfDate()` (0101b), `Invoice::latestDelivery()` (`ofMany` on the UUIDv7
  key), `InvoiceDeliveryStatus` (`Queued`, `Sent`, `Failed`).
- `InvoicePolicy`: `viewAny`/`view` (`invoices.view`), `create` (`invoices.create`), `send`
  (`invoices.send`), `collect`/`uncollect` (`invoices.collect`).

## Backend class owned by this story: `App\Actions\Invoices\DescribeOrderInvoicing`
Read-only, constructor-free, **ungated by design**: it holds no permission data and reads state
the caller may already see; every caller sits behind `orders.view` or `invoices.view`, and the
view asks the policy for permissions. Reason keys are the exact lang keys of the refusals the
actions throw (`invoices.errors.*`, `orders.*`); the describer never invents its own.

- `__invoke(Order $order): array{figures: array{total: string, billable: string, netInvoiced:
  string, uninvoiced: string, collected: string, debt: string, isLegacy: bool}, send: array{mode:
  'issue_and_send'|'send'|'resend'|'none', reasonKey: ?string, recipientUsable: bool,
  recipientEmail: ?string}, regenerate: array{available: bool, reasonKey: ?string}, markPaid:
  array{available: bool, reasonKey: ?string}, refund: array{stateAllowed: bool, reasonKey:
  ?string}, cancel: array{blockedReasonKey: ?string}, invoices: list<array{id: string, number:
  string, type: InvoiceType, issuedAt: CarbonImmutable, total: string, remaining: string, state:
  'live'|'voided'|'corrective', correctsNumber: ?string, collected: bool, collectedAt:
  ?CarbonImmutable, collectionType: ?OrderPaymentType, collectionMethodName: ?string, canSend:
  bool, sendReasonKey: ?string, canCollect: bool, collectReasonKey: ?string, canUndo: bool,
  undoReasonKey: ?string, lastDelivery: ?array{status: InvoiceDeliveryStatus, isCopy: bool,
  recipientEmail: string, at: CarbonImmutable, failureReason: ?string}}>}` — `invoices` ordered
  `issued_at desc, id desc`; `figures` from `ComputeOrderInvoiceBalance`; `markPaid.available`
  equals `Order::canMarkAsPaid()`; `refund.stateAllowed` = `isRefundable()` and not out of date;
  `cancel.blockedReasonKey` = `orders.cancellation.collected_invoices` when a live invoice is
  collected (cancel stays allowed while out of date, 0107 FD-10); `regenerate` from
  `PlanRegeneration` (`blockedReasonKey`) and the out-of-date mark.
- `forOrders(Collection $orders): array<string, array{send: array{mode, reasonKey,
  recipientUsable}, markPaid: array{available, reasonKey}}>` — keyed by order id, **at most 4
  queries whatever N** (one `whereIn('order_id')` invoices read with `withSum` of correctives, one
  latest-delivery subquery, one `Customer::withTrashed()` read; the orders are already loaded).
  No `recipientEmail` in the batch form.
- `forInvoices(Collection|array $invoices): array<string, array{canSend: bool, sendReasonKey:
  ?string, canCollect: bool, collectReasonKey: ?string, canUndo: bool, undoReasonKey: ?string,
  lastDelivery: ?array{status: InvoiceDeliveryStatus, isCopy: bool, recipientEmail: string, at:
  CarbonImmutable, failureReason: ?string}}>` — each element an `Invoice` or an invoice id string
  (ids resolved with one `whereKey()` read); same 4-query budget. Used by 0110 and 0110a.

Send-mode rule (mirrors 0104 FD-7): `issue_and_send` = no live standard invoice and uninvoiced >
0; `send` = at least one live standard invoice or unsent corrective never delivered (no delivery
other than `failed`); `resend` = live invoices exist and every one already has a `queued` or
`sent` delivery; `none` = nothing to send. `reasonKey` is set when the mode exists but the action
would refuse (out of date, customer deleted, e-mail unusable, cancelled, issuer incomplete, too
many attachments) or when the mode is `none` (nothing to send). `lastDelivery.failureReason` is
0104's sanitised text (max 500 characters, no paths, credentials or e-mail addresses).

## Component changes: `App\Livewire\Orders\Index`
- Row array gains `invoiceSend: array{mode: string, reasonKey: ?string}` from one
  `DescribeOrderInvoicing::forOrders()` call inside `orders()` (docblock shapes updated).
- Computeds: `canSendInvoices` (`Gate::allows('send', Invoice::class)`), `canCreateInvoices`
  (`Gate::allows('create', Invoice::class)`), `confirmingSendRow` (the row of
  `$confirmingSendOrderId` when its `invoiceSend` is usable, plus `recipientEmail` read through
  `DescribeOrderInvoicing::__invoke()` for that one order only after `canSendInvoices`; never
  stored as state).
- State: `public bool $showSendConfirm = false`; `#[Locked] public ?string $confirmingSendOrderId = null`.
- `confirmSendInvoices(string $orderId)`: no-op unless the id is a listed row whose mode is not
  `none`, whose `reasonKey` is null, and (for `issue_and_send`) `canCreateInvoices`; then opens
  the dialog. No authorization (mirrors `confirmMarkAsPaid`: a forged opener opens nothing; a
  forged `sendInvoices()` reaches the action, which refuses and logs).
- `dismissSendInvoices()`: closes and clears.
- `sendInvoices(SendOrderInvoices $sendOrderInvoices)`: re-load the order by the locked id (gone:
  close + danger toast `orders.payment.not_found`); call the action unconditionally; `catch
  ValidationException`: key `delivery` -> warning toast `orders.invoice_send.issued_not_queued`,
  otherwise danger toast with the first message; success toast
  `orders.invoice_send.queued` (`:numbers`, `:email` from the returned deliveries); always close
  the dialog and `unset($this->orders, $this->confirmingSendRow)`. `AuthorizationException` is
  not caught (403, already logged). Clearing the locked id on the first call makes a second call
  a silent no-op.

## View and hooks
- Actions cell (`flex flex-wrap gap-2`), beside View and Mark as paid, rendered only when
  `canSendInvoices`; every branch carries `data-test="send-invoice-{id}"`:
  - `issue_and_send`: "Issue and send" (`document-plus`); without `canCreateInvoices` disabled,
    tooltip `orders.invoice_send.needs_create`;
  - `send`: "Send invoice" (`paper-airplane`);
  - `resend`: "Resend (copy)" (`arrow-path`);
  - a `reasonKey` or mode `none`: disabled, tooltip `__($reasonKey)`.
  A disabled button sits in `<flux:tooltip>` around a `<span>` (tooltips do not fire on disabled
  buttons).
- Dialog `<x-confirm-dialog>` (`data-test="send-invoice-dialog"`): order number, the mode text
  (original / copy / issue then send, with the attachment count), the recipient e-mail; confirm
  `data-test="send-invoice-confirm"` with `wire:loading.attr="disabled"` and
  `wire:target="sendInvoices"`; dismiss `data-test="send-invoice-dismiss"`.

## Lang keys (`lang/{en,es}/orders.php`, group `invoice_send`, key parity)
| Key | en | es |
| --- | --- | --- |
| `issue_and_send` | Issue and send | Emitir y enviar |
| `send` | Send invoice | Enviar factura |
| `resend` | Resend (copy) | Reenviar (copia) |
| `needs_create` | You also need permission to issue invoices | También necesitas permiso para emitir facturas |
| `dialog_title` | Send the invoice of order :number? | ¿Enviar la factura del pedido :number? |
| `dialog_original` | The customer receives the invoice at :email. | El cliente recibe la factura en :email. |
| `dialog_copy` | The customer receives a copy at :email. | El cliente recibe una copia en :email. |
| `dialog_issue` | An invoice for :amount is issued (it takes the next number) and sent to :email. | Se emite una factura de :amount (toma el siguiente número) y se envía a :email. |
| `dialog_count` | :count invoices in one e-mail | :count facturas en un solo correo |
| `queued` | Invoice :numbers queued for sending to :email. | Factura :numbers en cola para enviarse a :email. |
| `issued_not_queued` | The invoice was issued but could not be queued. Send it from the order. | La factura se emitió, pero no se pudo poner en cola. Envíala desde el pedido. |

Refusal reasons reuse the action keys (`invoices.errors.*`, `orders.*`).

## Decisions
- **FD-1 One read collaborator decides modes and reasons.** Otherwise the list, the panel, the
  Billing rows and the dialogs would each re-implement about twelve rules of 0104/0106/0107/0108
  and drift. Enforcement stays in the actions; the describer is a hint.
- **FD-2 Parity by test, not by shared code** (resolves QA gap G-3). Sharing the actions' guard
  code would need patches to composed stories; instead a dataset of order/invoice states x actions
  asserts "the describer allows if and only if the action does not refuse, and the reason key
  equals the thrown message key".
- **FD-3 Button absent without `invoices.send`; disabled with a reason for state blocks**
  (BR-0109-1, BR-0109-5, list convention). "Issue and send" without `invoices.create` is the one
  permission-driven disabled state (BR-0104-7), because the actor can still send other orders.
- **FD-4 "Resend (copy)" is shown on a fully sent order** (Q-2, facilitator decision). Reason:
  request item 2 asks for the copy from the row; 0104 decides copy vs original per invoice.
- **FD-5 Every send is confirmed, "Issue and send" included.** An e-mail cannot be recalled and
  issuing consumes a number.
- **FD-6 The recipient e-mail is read per render after the send check, never kept in state or
  logged.** Its only appearances are the dialog and the success toast.
- **FD-7 The reflection allow-list is edited on purpose** (BR-0109-6): it admits
  `confirmSendInvoices`, `dismissSendInvoices`, `sendInvoices` and the three computeds; the class
  docblock now names `markAsPaid()` and `sendInvoices()` as the list's two writers.
- **FD-8 Batch read with a fixed query budget.** The list loads every order; `forOrders()` must
  stay at four queries whatever N.

## Gherkin
```gherkin
Feature: Send the invoice from the orders list

  Scenario: A store manager sends the invoice from the orders list
    Given a store manager with permission to send invoices, and an order with an invoice never sent
    When she confirms sending the invoice from the order's row
    Then she is told the invoice is queued for the customer's e-mail
    And the invoice's last delivery is the original

  Scenario: Sending again from the list sends a copy
    Given a store manager with permission to send invoices, and an order whose invoice was already sent
    When she confirms resending from the order's row
    Then the customer is sent a copy

  Scenario: Issue and send for an order without an invoice
    Given a store manager with permission to send and create invoices, and an order without an invoice
    When she confirms "Issue and send" from the order's row
    Then an invoice for the whole order is issued and sent as the original

  Scenario Outline: The row button reflects what can be done
    Given a store manager with permission to send invoices, and an order that <situation>
    When she opens the orders list
    Then the row shows <button>

    Examples:
      | situation                                   | button                           |
      | has no invoice                              | "Issue and send"                 |
      | has an invoice never sent                   | "Send invoice"                   |
      | has only invoices already sent              | "Resend (copy)"                  |
      | has an out-of-date invoice                  | a disabled button explaining why |
      | belongs to a customer with no usable e-mail | a disabled button explaining why |

  Scenario: Issue and send needs permission to issue
    Given a store manager who may send but not create invoices, and an order without an invoice
    When she opens the orders list
    Then the row's "Issue and send" button is disabled and says she also needs permission to issue invoices

  Scenario: A user who may not send invoices has no send button
    Given a sales assistant without permission to send invoices
    When she opens the orders list
    Then no order row offers to send an invoice

  Scenario: A double click sends one original
    Given a store manager with permission to send invoices, and an order with an invoice never sent
    When she presses the confirm button twice quickly
    Then exactly one original is sent

  Scenario: An invoice that was issued but not queued is reported
    Given a store manager with permission to send and create invoices, and a queue that refuses jobs
    When she confirms "Issue and send" for an order without an invoice
    Then she is told the invoice was issued but must be sent from the order
    And the row now offers "Send invoice"
```

## Files to create/modify
- `app/Actions/Invoices/DescribeOrderInvoicing.php` (new).
- `app/Livewire/Orders/Index.php`, `resources/views/livewire/orders.blade.php`.
- `lang/{en,es}/orders.php` — `invoice_send.*`.
- `tests/Feature/Orders/IndexTest.php` (allow-list), new `tests/Feature/Orders/IndexSendInvoiceTest.php`,
  `tests/Feature/Invoices/DescribeOrderInvoicingTest.php`,
  `DescribeOrderInvoicingParityTest.php`, `DescribeOrderInvoicingQueryCountTest.php`,
  `DescribeOrderInvoicingReachabilityTest.php`; browser `tests/Browser/Orders/InvoiceSendTest.php`.

## Tests to perform
Real `RolePermissionSeeder`, `Notification::fake()` / `Queue::fake()` as 0104 does, `Pdf::fake()`,
`Log::spy()`, factories for invoices and deliveries.

Read side
- [ ] D-1 `__invoke` shape and figures equal `ComputeOrderInvoiceBalance` for: no invoice,
  legacy paid (`isLegacy` true, debt 0.00), one live uncollected, 600 collected + 400 not,
  voided with corrective, cancelled.
- [ ] D-2 Parity dataset (FD-2): states x {`SendOrderInvoices`, `SendInvoice`, `CollectInvoice`,
  `UndoInvoiceCollection`, `MarkOrderAsPaid`, `RecordRefund` state check, `CancelOrder` collected
  block, `RegenerateOrderInvoices`}: allowed iff the action succeeds (run in a rolled-back
  transaction with an all-permissions actor), reason key equals the thrown message key.
- [ ] D-3 Send-mode dataset: issue_and_send, send (incl. only failed deliveries), resend, none,
  unsent corrective after a voided invoice.
- [ ] D-4 `forOrders` and `forInvoices` (models and ids): at most 4 queries for 1 and for 30
  orders (`DomainQueryLog`); results equal `__invoke` slice by slice.
- [ ] D-5 Reachability: only `Orders\Index`, `Orders\Show` and (when they exist) the 0110/0110a
  components reference the class.
- [ ] D-6 `failureReason` never contains an e-mail address or a path.

Component level
- [ ] T-1 Dataset situation x actor (`send` only; `send` + `create`; none): label, disabled
  reason (`out_of_date`, `customer_deleted`, `customer_email_unusable`, `issuer_incomplete`,
  nothing to send) or absence; "Issue and send" without `create` disabled with
  `needs_create`.
- [ ] T-2 Absence asserted on the DOM: `assertDontSeeHtml('data-test="send-invoice-'.$id.'"')`.
- [ ] T-3 Never sent: `confirmSendInvoices` then `sendInvoices`: one original delivery, dialog
  closed, success toast with number and e-mail.
- [ ] T-4 Already sent: copy delivery.
- [ ] T-5 No invoice, uninvoiced > 0: invoice issued for the whole uninvoiced amount, one original.
- [ ] T-6 Unusable e-mail, soft-deleted customer, out of date, forced past the UI: danger toast
  with the action's message, no delivery, no invoice issued.
- [ ] T-7 `sendInvoices` called twice after one confirm: one original only; the second call is a
  silent no-op.
- [ ] T-8 `confirmSendInvoices` with an unlisted id or a disabled row: no-op, id stays null.
- [ ] T-9 `set('confirmingSendOrderId', $other)` refused (`#[Locked]`).
- [ ] T-10 Without `invoices.send`, `sendInvoices` called directly: 403, one logged refusal, no
  delivery; "Issue and send" without `invoices.create`: 403, logged, no invoice.
- [ ] T-11 Reflection allow-list equals the edited list (FD-7).
- [ ] T-12 Query count of the list with 20 invoiced orders equals that with 2 (bounded).
- [ ] T-13 `delivery` refusal: warning toast, invoice exists, row mode becomes `send` after refresh.
- [ ] T-14 The recipient e-mail is not a public property and not in any log line.
- [ ] T-15 en/es keys with parity; no raw key rendered.

Browser level (bounded waits, `assertNoJavaScriptErrors`)
- [ ] B-1 Click `send-invoice-{id}`, the dialog shows number and recipient, confirm, toast; after
  refresh the label is "Resend (copy)".
- [ ] B-2 Two rapid clicks on `send-invoice-confirm`, then (after a bounded wait) one delivery
  row (the race itself is 0104's concurrency test).
- [ ] B-3 Disabled branch: hovering the wrapper shows the reason tooltip.
- [ ] B-4 Phone viewport: the actions cell wraps, no horizontal page scroll.

## Expected outcome
From the orders list a manager sends, resends as a copy, or issues and sends an order's invoice
after one confirmation, and sees why the button is disabled when it is; every invoice screen can
ask one tested collaborator what is possible.

## Acceptance criteria
- [ ] `DescribeOrderInvoicing` exists with the three forms above, parity-tested against the
  actions, within the 4-query budget, reachable only from the listed components.
- [ ] The row button follows FD-3/FD-4 and the send-mode rule; disabled states show the action's
  own reason.
- [ ] Every send is confirmed; success, refusal and issued-not-queued are reported by toast.
- [ ] Forged openers, locked-id writes and unauthorized sends are refused (logged by the action).
- [ ] The reflection allow-list is edited deliberately.
- [ ] en/es keys with parity.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor) — e-mail disclosure, forged sends
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (list writers, row button),
  the invoices API doc (`DescribeOrderInvoicing`)
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream (final list): [0104](0104-send-invoice-by-email-backend.md),
  [0106a](0106a-mark-as-paid-invoice-shortcut-and-real-income-backend.md),
  [0107](0107-corrective-invoice-on-refund-and-cancellation-backend.md),
  [0108](0108-regenerate-invoices-after-order-edit-backend.md) (transitively 0101b and 0106). The
  send dialog texts (`orders.invoice_send.dialog_*`) and hooks `send-invoice-dialog` /
  `send-invoice-confirm` are reused by [0110](0110-billing-section-ui.md).
- Risk: the parity test is the only guard against drift; any later change to an action's refusal
  must update the describer in the same story (stated in the class docblock).
- Risk: the list has no pagination; the 4-query budget bounds queries, not hydration of a very
  large store (pre-existing).
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0109a-1 Row button on an order with no invoice: (a) "Issue and send" after a confirmation
    (recommended, orchestrator default); (b) disabled, issuing only from Billing.
  - Q-0109a-2 Fully sent order: (a) "Resend (copy)" offered (recommended, FD-4); (b) hidden.

## Technical tasks
- [ ] Red tests: describer (shape, parity, query count, reachability), list component, browser.
- [ ] `DescribeOrderInvoicing` with `__invoke`, `forOrders`, `forInvoices`.
- [ ] `Orders\Index` row key, computeds, confirm/dismiss/send; view and dialog; lang en/es.
- [ ] Edit the reflection allow-list and the class docblock.
- [ ] Pint, Larastan, targeted then full suite; hand the docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa, backend-expert (contract answers). Phase 2 (INVEST) pending.
