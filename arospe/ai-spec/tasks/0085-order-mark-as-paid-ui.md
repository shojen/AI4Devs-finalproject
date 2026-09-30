# [0085] Order detail — "Mark as paid" control (frontend)

> **Status: Phase 1 draft.** Written from the project owner's request on 2026-09-30; the Three Amigos debate and INVEST validation
> have **not** run yet. Items marked **⚑ owner to confirm** are facilitator defaults.
> Backend companion: [0084](0084-order-mark-as-paid-backend.md), which this story is blocked on.

## Description

Expose story [0084](0084-order-mark-as-paid-backend.md)'s `MarkOrderAsPaid` action on the order detail screen
(`App\Livewire\Orders\Show`, story [0055](done/0055-orders-list-detail-editor-ui.md)), so an order administrator can record by hand that
a pending order has been paid (PRD §3.2: a manual admin-set status, no payment gateway).

The owner asked for this from "the dashboard": in this project the dashboard is the whole admin panel, so the control lives where the
order is managed — **the order detail screen**. (The dashboard home's "latest orders" widget from [0083](0083-dashboard-home-overview-ui.md)
only links to that screen; a one-click action in the widget is an option recorded as Q-1, not scope.)

## Type

`frontend | includes database-expert: no`

## Decisions proposed (to be ratified in the debate)

- **D-1 — A "Mark as paid" button in the payment area of `orders/{order}`** (`resources/views/livewire/orders/show.blade.php`),
  visible **only** when `Show::canMarkPaid()` is true: the actor passes `Gate::allows('markPaid', $order)` **and**
  `$order->isAwaitingPayment()` (0084's shared helper — the UI cannot drift from the rule that refuses). Hidden, not disabled, in every
  other state, like the refund control that "omits the control entirely in the two refused states".
- **D-2 — Confirmation:** clicking opens the existing shared `<x-confirm-dialog>` ("Mark order #ORD-… as paid? This records that the
  payment of €X was received. It can't be undone from here.") — a one-way action deserves a confirm because 0084 ships no undo.
  Confirm → `markAsPaid(MarkOrderAsPaid $action)`; cancel closes it. ⚑
- **D-3 — The component method re-authorizes** (`Gate::authorize('markPaid', $order)` first, like `openRefundModal()`), catches the
  action's `ValidationException`/domain exception and shows its **translated** message inline (never a 500), refreshes the order state
  (`refreshOrderState()` precedent) and dispatches a success toast. A hidden button is not protection: a forged Livewire call by a user
  without `orders.edit` is refused and logged.
- **D-4 — Show the payment state and date:** the payment badge (already rendered from `orders.payment_statuses.*`) stays; when
  `paid_at` is set, add a line "Paid on `d/m/Y H:i`" (the orders list's date format); a paid order with `paid_at = NULL` (created before
  0084) shows the badge with no date and no error.
- **D-5 — The refund control appears for the order as soon as it is paid** without a page reload (the existing
  `isRefundable()` already reads `payment_status`; the component refresh must re-evaluate it).
- **D-6 — Copy:** new keys under `orders.payment.*` in `lang/en/orders.php` and `lang/es/orders.php` (button, dialog title/body with
  `:number` and `:amount`, success toast, the two refusals already added by 0084, "Paid on :date"); parity test stays green.
- **D-7 — Reuse:** `<x-confirm-dialog>`, `<x-money>`, `flux:button`/`flux:badge`; no new component.

## Open questions

- **Q-1 — Quick action in the dashboard home widget:** add a "Mark as paid" action to each pending row of the latest-orders widget
  (0083)? Recommend **no** for this story (one place, one confirm, and the widget stays read-only); a follow-up once both ship.
- **Q-2 — Mark several orders at once** from the orders list? Recommend **no** (bulk money actions need their own safeguards).

## Gherkin (draft)

```gherkin
Scenario: The order detail offers "Mark as paid" for an order awaiting payment
  Given Olga, an order administrator, and an order awaiting payment
  When Olga opens the order
  Then Olga sees the "Mark as paid" button

Scenario Outline: The button is hidden when it cannot apply
  Given Olga, an order administrator, and an order whose payment state is "<state>"
  When Olga opens the order
  Then Olga does not see the "Mark as paid" button
  Examples:
    | state                    |
    | Pagado                   |
    | Parcialmente reembolsado |
    | Reembolsado              |

Scenario: The button is hidden for a cancelled order
  Given Olga, an order administrator, and a cancelled order awaiting payment
  When Olga opens the order
  Then Olga does not see the "Mark as paid" button

Scenario: The button is hidden for a user who may not edit orders
  Given Nora, a user who may only view orders, and an order awaiting payment
  When Nora opens the order
  Then Nora does not see the "Mark as paid" button

Scenario: Marking as paid asks for confirmation first
  Given Olga, an order administrator, on the detail of an order awaiting payment
  When Olga clicks "Mark as paid"
  Then Olga is asked to confirm that the payment of the order's total was received
  And the order is not yet paid

Scenario: Cancelling the confirmation leaves the order unchanged
  Given Olga, an order administrator, who was asked to confirm marking an order as paid
  When Olga cancels the confirmation
  Then the order is still awaiting payment

Scenario: Confirming marks the order as paid
  Given Olga, an order administrator, who was asked to confirm marking an order as paid
  When Olga confirms
  Then the order shows the payment state "Pagado" and the date it was paid
  And Olga sees a success message
  And the "Mark as paid" button is gone

Scenario: A paid order can be refunded straight away
  Given Olga, an order administrator who may refund, who has just marked an order as paid
  When the order refreshes
  Then Olga sees the refund control

Scenario: A second administrator's earlier mark is reported, not repeated
  Given Olga, an order administrator, viewing an order that Omar has just marked as paid
  When Olga confirms marking it as paid
  Then Olga is told the order is already paid
  And the order keeps the date Omar's mark recorded

Scenario: A forged request from a user who may not edit is refused
  Given Nora, a user who may only view orders
  When Nora's browser asks to mark an order as paid
  Then Nora is refused and the order does not change

Scenario: An order paid before payment dates were recorded shows no date
  Given Olga, an order administrator, and a paid order without a recorded payment date
  When Olga opens the order
  Then the payment state is shown without a date and without error

Scenario: The control speaks the administrator's language
  Given Laura, an administrator whose admin UI language is Spanish
  When Laura opens an order awaiting payment
  Then the button, the confirmation and the messages are in Spanish
```

## Files to create/modify

- `app/Livewire/Orders/Show.php` (`canMarkPaid()`, `markAsPaid()`, confirm open/close state as scalars, refresh), 
  `resources/views/livewire/orders/show.blade.php` (button, confirm dialog, payment-date line; `data-test` hooks `mark-as-paid`,
  `mark-as-paid-confirm`, `paid-at`)
- `lang/en/orders.php`, `lang/es/orders.php`
- Tests: `tests/Feature/Orders/ShowMarkAsPaidTest.php` (Livewire), `tests/Browser/Orders/MarkAsPaidTest.php` (one end-to-end click + confirm)
- Docs (Phase 6): `docs/api/orders.md` (the detail screen's new control and contract)

## Tests to perform

Livewire feature tests per Gherkin scenario using the `OrdersUi::actor()` helper (never a Super Admin for a "hidden" assertion —
`Gate::before` makes it a false negative): visibility matrix over payment state × order status × permission; confirm flow; the
method re-authorizes (forged call by an `orders.view`-only actor is refused and logged); already-paid race shows the translated
message; state refresh exposes the refund control; `paid_at` formatting incl. `NULL`; Spanish locale render via the actor's
`ui_locale`; `OrdersLangParityTest` green. One browser test: click → confirm → badge/date update without a reload, no JS errors
(`data-test` selectors, no `networkidle`).

## Expected outcome

From the order detail screen an authorized administrator records a payment in two clicks; the order shows "Pagado" with its date and
becomes refundable.

## Acceptance criteria

- The button renders only for an actor who may mark the order paid **and** only when it is awaiting payment and not cancelled.
- The action is confirmed before it runs and re-authorized server-side; refusals and races show a translated message, never a 500.
- After marking, the page reflects the new state, the date and the refund control without a reload.
- All copy exists in `en` and `es` (identical key sets); no new component or dependency.

## Definition of Done

- [ ] Phase 1 debate + Phase 2 INVEST recorded in this file
- [ ] Tests written first, **full suite** green (unscoped, including `tests/Browser`)
- [ ] Pint (unscoped), Larastan, `npm run build` clean
- [ ] Appsec review (forged calls, refusal logging, no state leak through public properties)
- [ ] Docs synced (orders routes/contracts)

## Dependencies

- **Blocked on [0084](0084-order-mark-as-paid-backend.md).**
- `conflict_risk_with`: none pending (0083 edits the orders **list** view only; this story edits the **detail** view).
