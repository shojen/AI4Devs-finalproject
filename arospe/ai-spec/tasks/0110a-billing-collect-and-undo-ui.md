# [0110a] Billing: collect and undo a collection from the invoice list (frontend)

> **Status: New (Phase 1 complete 2026-11-15).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Split out of [0110](0110-billing-section-ui.md) on
> INVEST "Small". Blocked on [0110](0110-billing-section-ui.md) (the list and its row-actions slot),
> [0106](in-progress/0106-invoice-collection-order-debt-partially-paid-backend.md) (`CollectInvoice`,
> `UndoInvoiceCollection`) and [0109b](0109b-order-detail-invoices-panel-ui.md) (the collect dialog,
> `ListCollectionPaymentMethods`, `PaymentMethodCode::defaultPaymentType()`). No dependents.

## Description
From each Billing row, a holder of `invoices.collect` marks a live uncollected invoice as collected
(choosing the payment method and type) or undoes a collection recorded by mistake. The dialog and
its rules are the same as in the order-detail invoices panel; refusals are shown inside the open
dialog and the row updates after success, with the order's new debt in the confirmation.

Request item served (owner, 2026-11-14, translated):
> 5. A sidebar entry "Facturación" (Billing) opens a screen listing invoices. ... The manager can
> keep issuing invoices until the order's debt is 0.

Binding decisions:
- U-2 (owner decision, 2026-11-14): "every invoice can be marked as collected (paid). Debt = order
  total minus collected invoices." U-10 (owner decision, 2026-11-15): collection is a whole-invoice
  fact (no partial amount).
- Accepted default (owner, 2026-11-15; Q-0106-2 (a), overturnable): a collection can be undone with
  `invoices.collect` after a confirmation, within 0106's limits.

## Type
frontend | includes database-expert: no

## Verified facts that shaped the story
- `payment_methods` has no active flag; `PaymentMethodCode` has the single case `BankTransfer`;
  `OrderPaymentType` has `Transfer`, `Card`, `Paypal`.
- 0110 ships `Invoices\Index` with an empty per-row actions slot and pins its mutating methods in
  `IndexMutationSurfaceTest`; 0110 computes `rowStates` with `DescribeOrderInvoicing::forInvoices`.

## Backend contracts used
- `CollectInvoice::__invoke(Invoice $invoice, PaymentMethod $method, OrderPaymentType $type):
  Invoice` (0106): returns the invoice refreshed with its `order` loaded (new debt and payment
  status); state refusals are `ValidationException`s keyed `invoice` (voided, corrective, already
  collected, cancelled order, out-of-date order); permission refusal `invoices.collect`, logged.
- `UndoInvoiceCollection::__invoke(Invoice $invoice): Invoice` (0106): refusals keyed `invoice` (not
  collected, voided, has a corrective, order has a refund, cancelled order, out-of-date order).
- `ListCollectionPaymentMethods::__invoke(): list<array{id: string, name: string, code: string,
  defaultType: ?OrderPaymentType}>` (owned by 0109b), logged `authorize('collect',
  Invoice::class)`; lists every method (no active flag); `defaultType =
  PaymentMethodCode::tryFrom($code)?->defaultPaymentType()` (`BankTransfer` -> `Transfer`; null for
  an unknown code, then the type must be chosen).
- `DescribeOrderInvoicing::forInvoices` (0109a): `canCollect`, `collectReasonKey`, `canUndo`,
  `undoReasonKey` per row.

## Component changes: `App\Livewire\Invoices\Index` (from 0110)
- Computed `canCollectInvoices` (`allowsSafely('collect', Invoice::class)`).
- State: `#[Locked] ?string $collectInvoiceId`, `bool $showCollectModal`, `string
  $collectPaymentMethodId`, `string $collectType` (client-writable strings), `#[Locked] ?string
  $undoInvoiceId`, `bool $showUndoDialog`, `?string $collectError`.
- Methods: `openCollectModal(string $invoiceId)` (resolves with `findOrFail`, locks the id,
  preselects the first method and its `defaultType`); `updatedCollectPaymentMethodId()` selects the
  new method's `defaultType` when it has one; `collectInvoice(CollectInvoice $collectInvoice)`
  (`PaymentMethod::findOrFail`, `OrderPaymentType::tryFrom`, null -> error under the type, then the
  action unconditionally); `confirmUndoCollection(string $invoiceId)`;
  `undoCollection(UndoInvoiceCollection $undo)`; dismissers `closeCollectModal()`,
  `cancelUndo()`. Each success clears the memoised rows and row states.
- `IndexMutationSurfaceTest` allow-list gains `openCollectModal`, `collectInvoice`,
  `confirmUndoCollection`, `undoCollection` and the two dismissers.

## View and `data-test` hooks
| Hook | Element |
| --- | --- |
| `collect-invoice-{id}`, `undo-invoice-{id}` | buttons placed in 0110's `invoice-row-actions-slot-{id}` |
| `collect-invoice-modal` | `<x-invoice.collect-dialog>` |
| `collect-method`, `collect-type` | method and type selects |
| `collect-submit`, `collect-dismiss` | confirm and dismiss |
| `collect-error` | the inline refusal callout inside the dialog |
| `undo-collection-dialog`, `undo-collection-confirm`, `undo-collection-dismiss` | `<x-invoice.undo-collection-dialog>` |

Both dialogs are 0109b's shared components (its FD-10), rendered once on the page:
`<x-invoice.collect-dialog :invoice-number :methods :types :error />` (methods =
`ListCollectionPaymentMethods` rows, types = `OrderPaymentType::cases()` with `label()`, error =
`$collectError`) and `<x-invoice.undo-collection-dialog :invoice-number />`. They bind fixed host
names, which `Invoices\Index` provides exactly: `showCollectModal`, `collectPaymentMethodId`,
`collectType`, `collectError`, `collectInvoice`, `closeCollectModal`, `showUndoDialog`,
`undoCollection`, `cancelUndo`. The host keeps its own locked ids (`collectInvoiceId`,
`undoInvoiceId`) and authorization.

Buttons: Collect on a live, uncollected standard invoice; Undo on a collected one; both absent from
the DOM without `invoices.collect`; disabled with `__($collectReasonKey)` / `__($undoReasonKey)` when
the describer says blocked (for example out of date); never on correctives or voided rows.

## Lang keys (`lang/en` and `lang/es`, `invoices.php`, key for key)
Reused from 0109b (groups `collect` and `undo`): the dialogs' title, method, type, confirm,
dismiss and undo warning texts. Added here:
`index.actions.collect`, `index.actions.undo`, `index.collected_toast` (`:number`, `:debt`),
`index.undone_toast` (`:number`). Refusal messages are 0106's `invoices.errors.*`.

## Decisions
- **FD-1 Same dialog as the order panel.** The collect dialog, its method list and the default
  type come from 0109b, so the two screens record collections identically.
- **FD-2 Every method listed.** No active flag exists; when one is introduced, inactive methods are
  refused by 0106 and dropped from the list by 0109b's read action.
- **FD-3 Refusals inside the dialog.** A refusal (key `invoice`) is shown in `collect-error` and the
  dialog stays open; nothing changes; after dismissing, the row refreshes (for example "already
  collected" by another manager).
- **FD-4 Confirmation names the new debt.** The success toast names the invoice number and the
  order's new debt, read from the returned invoice's `order`.
- **FD-5 Undo warns.** The undo dialog says it reverses a recorded payment and changes the order's
  debt; refusals appear in the dialog.
- **FD-6 Rules never in Blade.** Availability and reasons come from the describer; every action
  re-authorizes and re-validates.

## Gherkin
```gherkin
Feature: Collect and undo from Billing

  Scenario: A store manager marks an invoice as collected from Billing
    Given a store manager with permission to collect invoices, and an uncollected invoice
    When she records its collection by bank transfer
    Then the row shows it as collected
    And she is told the order's new debt

  Scenario: The payment type follows the chosen method
    Given a store manager with permission to collect invoices, opening the collect dialog
    When she chooses the bank transfer method
    Then the payment type "Transfer" is preselected

  Scenario: A store manager undoes a collection
    Given a store manager with permission to collect invoices, and a collected invoice whose order has no refund
    When she confirms undoing the collection
    Then the row shows it as not collected

  Scenario Outline: A refused collection is explained in the dialog
    Given a store manager with permission to collect invoices, and an invoice that <situation>
    When she confirms the action
    Then the dialog shows the refusal and nothing changes

    Examples:
      | situation                                    |
      | belongs to an out-of-date order              |
      | was collected by another manager meanwhile   |
      | has a refund and she undoes its collection   |

  Scenario: A user without permission to collect has no collect or undo buttons
    Given a sales assistant with permission to view but not collect invoices
    When she opens Billing
    Then no row offers collect or undo
```

## Files to create/modify
- `app/Livewire/Invoices/Index.php`, `resources/views/livewire/invoices.blade.php` — state, methods,
  dialogs.
- `resources/views/components/invoice-row-actions.blade.php` — Collect and Undo in the slot.
- `lang/{en,es}/invoices.php` — the four keys above.
- `tests/Feature/Invoices/IndexCollectionTest.php`; `IndexMutationSurfaceTest.php` (allow-list
  extended with the six methods, as planned by 0110); `tests/Browser/Invoices/CollectInvoiceTest.php`.

## Tests to perform
Component level
- [ ] T-24 `openCollectModal` on an eligible invoice: methods from `ListCollectionPaymentMethods`,
  type preselected from the method; collect with a valid pair: invoice collected, toast with the
  number and the order's new debt.
- [ ] T-25 Collect refusal dataset (voided, corrective, already collected, cancelled order, out of
  date): error in `collect-error`, dialog open, nothing written.
- [ ] T-26 Undo refusal dataset (not collected, voided, has a corrective, order has a refund,
  cancelled, out of date): error in the dialog, columns unchanged.
- [ ] T-27 Forged `collectType` `'x'`, unknown method id, array values: error under the type or
  not-found, nothing written.
- [ ] T-28 Without `invoices.collect`: buttons absent; forged `collectInvoice` / `undoCollection`
  403, logged, no change.
- [ ] T-29 Locked ids: a client write is refused; an opener with an unknown id is a no-op.
- [ ] T-30 Two managers: the invoice is already collected when the second confirms: "already
  collected" in the dialog; the row refreshes after dismissing.
- [ ] T-31 Buttons never on corrective or voided rows; disabled with the reason when out of date.
- [ ] T-32 Mutation surface equals 0110's list plus this story's six methods.
- [ ] T-33 en/es parity of the new keys.

Browser level
- [ ] B-7 Collect dialog: choose method and type, confirm, the badge turns collected; on an
  out-of-date order the refusal appears inside the open dialog; the undo dialog warns and then
  reverses; `assertNoJavaScriptErrors`.

## Expected outcome
From Billing, holders of `invoices.collect` record and correct collections without opening each
order, with exactly the rules and dialog of the order panel.

## Acceptance criteria
- [ ] Collect and Undo appear only with `invoices.collect` and only on eligible rows.
- [ ] The dialog reuses 0109b's methods, default type and markup (FD-1, FD-2).
- [ ] Refusals stay in the dialog (FD-3); success names the new debt (FD-4); undo warns (FD-5).
- [ ] The listed hooks exist; the mutation surface is pinned; en/es parity.

## Definition of Done
- [ ] Tests written and green (component and browser)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): the invoices API doc (Billing collect and undo)
- [ ] Acceptance criteria met
- [ ] `npm run build` passes; Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open questions
- Upstream: [0110](0110-billing-section-ui.md),
  [0106](in-progress/0106-invoice-collection-order-debt-partially-paid-backend.md),
  [0109b](0109b-order-detail-invoices-panel-ui.md) (the two dialog components, reused, never
  copied).
- Tampering: ids locked and re-resolved; method and type validated by `findOrFail` / `tryFrom` and
  the action; availability is a hint, the action decides.
- A holder of `invoices.collect` can reverse a money fact; the action logs undo at warning level.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3):
  - Q-0106-2 Undo a collection: (a) allowed with `invoices.collect` and a confirmation, within
    0106's limits (recommended, accepted default); (b) never.

## Technical tasks
- [ ] Collect and undo state, methods and dialogs in `Invoices\Index`, reusing the 0109b dialog.
- [ ] Row buttons in the 0110 slot.
- [ ] Lang en/es; component and browser tests; mutation-surface allow-list.
- [ ] `npm run build`; Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  frontend-expert, frontend-qa. Phase 2 (INVEST) pending.
