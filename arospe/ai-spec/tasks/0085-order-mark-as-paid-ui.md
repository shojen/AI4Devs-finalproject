# [0085] Orders — "Mark as paid" control in the orders list and the order detail (frontend)

> **Status: Phase 1 draft.** Written from the project owner's request on 2026-09-30 and amended 2026-10-01 with the owner's answer on
> where the control lives. The Three Amigos debate and INVEST validation have **not** run yet. Items marked **⚑ owner to confirm**
> are facilitator defaults. Backend companion: [0084](0084-order-mark-as-paid-backend.md), which this story is blocked on.

## Description

Expose story [0084](0084-order-mark-as-paid-backend.md)'s `MarkOrderAsPaid` action in the admin so an order administrator can record by
hand that a pending order has been paid (PRD §3.2: a manual admin-set status, no payment gateway).

**Owner decision (2026-10-01): the control exists in two places, and only there:**

1. **The orders list** (`App\Livewire\Orders\Index`, the table at `/orders`): a "Mark as paid" action in each row's actions column.
2. **The order edit/detail page** (`App\Livewire\Orders\Show`, `/orders/{order}`).

It is **deliberately not added to the dashboard home's "Latest orders" widget** of [0083](0083-dashboard-home-overview-ui.md): that widget
links to the orders list ("Orders") and to each order's page, so the action is one click away in both. (This closes the earlier Q-1.)

## Type

`frontend | includes database-expert: no`

## Decisions proposed (to be ratified in the debate)

- **D-1 — One rule, three readers.** Whether the button shows is decided by **0084's `Order::isAwaitingPayment()`** plus
  `Gate::allows('markPaid', $order)`, never by a copy of the rule in a view. The list computes it per row (`canMarkPaid` in the `orders()`
  row array, via the model — no extra query: `payment_status`, `status` and the actor's ability are already at hand); the detail page
  exposes `Show::canMarkPaid()`. The button is **hidden, not disabled**, when it cannot apply, matching the refund control's precedent.
- **D-2 — Orders list (`Index`):** a `flux:button` (small, icon + label "Mark as paid") in the actions column next to "View", with
  `data-test="mark-as-paid-{id}"`. Clicking it **does not act**: it sets one scalar public property
  (`public ?string $confirmingPaidOrderId = null`) and opens **one page-level `<x-confirm-dialog>`** shared by all rows ("Mark order
  #ORD-… as paid? This records that the payment of €X was received. It can't be undone from here."). Confirm →
  `markAsPaid(MarkOrderAsPaid $action)`: it **re-loads the order from the id, re-authorizes** through the action (the action's first
  statement is `Gate::authorize('markPaid', …)`), and never trusts the row array. The id is a client-writable scalar, so it is
  validated as an existing order id and every failure path is a translated message, never a 500. ⚑
- **D-3 — After marking, the list updates in place:** the row's payment badge becomes "Pagado", the button disappears, and a success
  toast appears; no reload and no reordering (the list is ordered by `created_at`, which does not change). The list has no payment-state
  filter today, so no row can vanish; if one is added later, that story owns the interaction.
- **D-4 — Order detail (`Show`):** the same button in the payment area of `orders/{order}`, same dialog copy and flow;
  `Show::markAsPaid()` re-authorizes (`Gate::authorize('markPaid', $order)` first, like `openRefundModal()`), catches the action's
  `ValidationException`/domain exception and shows its **translated** message inline, refreshes the order state
  (`refreshOrderState()` precedent) and toasts success. After marking, the refund control appears without reload (`isRefundable()`
  already reads `payment_status`; the refresh must re-evaluate it).
- **D-5 — Show the payment state and date:** on the detail page, when `paid_at` is set add a line "Paid on `d/m/Y H:i`" (the list's date
  format); a paid order with `paid_at = NULL` (created before 0084) shows the badge with no date and no error. The list stays
  unchanged apart from the button (no extra column) — ⚑.
- **D-6 — Shared confirm copy:** one set of keys under `orders.payment.*` in `lang/en/orders.php` and `lang/es/orders.php` (button,
  dialog title/body with `:number` and `:amount`, success toast, "Paid on :date"; the two refusals come from 0084); parity test stays
  green. Both screens read the same keys, so the wording cannot drift.
- **D-7 — Reuse:** `<x-confirm-dialog>`, `<x-money>`, `flux:button`/`flux:badge`; **no new component or dependency.** The list's existing
  inline status-badge markup is untouched by this story (story 0083 extracts badge components on the same file — see conflicts).
- **D-8 — Forged calls:** a hidden button is not protection. A user without `orders.edit` who posts `markAsPaid` (either component) is
  refused and logged by the existing refusal-logging wrapper; the list's `confirmingPaidOrderId` holds nothing privileged.

## Open questions

- **Q-1 — Bulk marking** several rows at once from the list: recommend **no** (bulk money actions need their own safeguards). ⚑
- **Q-2 — Show the paid date in the list** as a tooltip/column: recommend **no** for now (D-5).

## Gherkin (draft)

```gherkin
Scenario: The orders list offers "Mark as paid" on an order awaiting payment
  Given Olga, an order administrator, and an order awaiting payment
  When Olga opens the orders list
  Then the order's row offers a "Mark as paid" button

Scenario Outline: The list hides the button when it cannot apply
  Given Olga, an order administrator, and an order that is <case>
  When Olga opens the orders list
  Then the order's row does not offer a "Mark as paid" button
  Examples:
    | case                                      |
    | already paid                              |
    | partially refunded                        |
    | fully refunded                            |
    | cancelled and awaiting payment            |

Scenario: Only orders awaiting payment show the button in a mixed list
  Given Olga, an order administrator, and 1 order awaiting payment and 2 paid orders
  When Olga opens the orders list
  Then exactly 1 row offers a "Mark as paid" button

Scenario: The list hides the button from a user who may not edit orders
  Given Nora, a user who may only view orders, and an order awaiting payment
  When Nora opens the orders list
  Then no row offers a "Mark as paid" button

Scenario: Marking from the list asks for confirmation first
  Given Olga, an order administrator, viewing the orders list with an order awaiting payment
  When Olga clicks "Mark as paid" on that order
  Then Olga is asked to confirm that the payment of that order's total was received
  And the order is not yet paid

Scenario: Cancelling the confirmation leaves the order unchanged
  Given Olga, an order administrator, who was asked to confirm marking an order as paid from the list
  When Olga cancels the confirmation
  Then the order is still awaiting payment

Scenario: Confirming in the list marks that order as paid
  Given Olga, an order administrator, who was asked to confirm marking an order as paid from the list
  When Olga confirms
  Then that row shows the payment state "Pagado" and no longer offers the button
  And Olga sees a success message
  And the other rows do not change

Scenario: The order detail offers "Mark as paid" for an order awaiting payment
  Given Olga, an order administrator, and an order awaiting payment
  When Olga opens the order
  Then Olga sees the "Mark as paid" button

Scenario Outline: The detail hides the button when it cannot apply
  Given Olga, an order administrator, and an order that is <case>
  When Olga opens the order
  Then Olga does not see the "Mark as paid" button
  Examples:
    | case                                      |
    | already paid                              |
    | partially refunded                        |
    | fully refunded                            |
    | cancelled and awaiting payment            |

Scenario: The detail hides the button from a user who may not edit orders
  Given Nora, a user who may only view orders, and an order awaiting payment
  When Nora opens the order
  Then Nora does not see the "Mark as paid" button

Scenario: Confirming on the detail marks the order as paid and shows the date
  Given Olga, an order administrator, who was asked to confirm marking an order as paid on its page
  When Olga confirms
  Then the order shows the payment state "Pagado" and the date it was paid
  And Olga sees a success message
  And the "Mark as paid" button is gone

Scenario: A paid order can be refunded straight away
  Given Olga, an order administrator who may refund, who has just marked an order as paid on its page
  When the page refreshes
  Then Olga sees the refund control

Scenario: An order marked elsewhere in the meantime is reported, not repeated
  Given Olga, an order administrator, viewing an order that Omar has just marked as paid
  When Olga confirms marking it as paid
  Then Olga is told the order is already paid
  And the order keeps the date Omar's mark recorded

Scenario: A forged request from a user who may not edit is refused
  Given Nora, a user who may only view orders
  When Nora's browser asks to mark an order as paid
  Then Nora is refused and the order does not change

Scenario: A forged order identifier is handled without an error
  Given Olga, an order administrator, on the orders list
  When Olga's browser asks to mark an order that does not exist as paid
  Then Olga sees an error message and no order changes

Scenario: An order paid before payment dates were recorded shows no date
  Given Olga, an order administrator, and a paid order without a recorded payment date
  When Olga opens the order
  Then the payment state is shown without a date and without error

Scenario: The latest-orders widget on the dashboard home does not offer the action
  Given Olga, an order administrator, and an order awaiting payment
  When Olga opens the dashboard home
  Then the latest-orders widget lists the order without a "Mark as paid" button
  And the order links to its page

Scenario: The controls speak the administrator's language
  Given Laura, an administrator whose admin UI language is Spanish
  When Laura opens the orders list with an order awaiting payment
  Then the button, the confirmation and the messages are in Spanish
```

## Files to create/modify

- `app/Livewire/Orders/Index.php` (`canMarkPaid` per row in `orders()`, `confirmingPaidOrderId`, `confirmMarkAsPaid()`,
  `cancelMarkAsPaid()`, `markAsPaid(MarkOrderAsPaid $action)`), `resources/views/livewire/orders.blade.php` (button in the actions
  cell, one page-level confirm dialog; `data-test` hooks `mark-as-paid-{id}`, `mark-as-paid-confirm`)
- `app/Livewire/Orders/Show.php` (`canMarkPaid()`, `markAsPaid()`, confirm state as scalars, refresh),
  `resources/views/livewire/orders/show.blade.php` (button, dialog, payment-date line; hooks `mark-as-paid`, `mark-as-paid-confirm`, `paid-at`)
- `lang/en/orders.php`, `lang/es/orders.php`
- Tests: `tests/Feature/Orders/IndexMarkAsPaidTest.php`, `tests/Feature/Orders/ShowMarkAsPaidTest.php` (Livewire),
  `tests/Browser/Orders/MarkAsPaidTest.php` (list flow + detail flow, end to end)
- Docs (Phase 6): `docs/api/orders.md` (the two screens' new control and contract)

## Tests to perform

Livewire feature tests per Gherkin scenario using the `OrdersUi::actor()` helper (never a Super Admin for a "hidden" assertion —
`Gate::before` makes it a false negative): visibility matrix over payment state × order status × permission, **on both screens**; per-row
visibility in a mixed list; confirm flow (open → cancel leaves state; open → confirm writes); the methods re-authorize (a forged call by
an `orders.view`-only actor is refused **and logged**, `RefusalLoggingTest` precedent); forged/garbage `confirmingPaidOrderId` (missing
order, non-UUID, array) → a translated error, no 500, no write; already-paid race shows the translated message; list row updates in place
and other rows are untouched; no extra queries per row (query-count assertion on the list with 1 vs 20 orders); detail refresh exposes the
refund control; `paid_at` formatting incl. `NULL`; Spanish locale via the actor's `ui_locale`; `OrdersLangParityTest` green; the
dashboard latest-orders widget (when 0083 lands) renders no such button. Browser: list → click → confirm → row updates without reload,
then the same on the detail page, `assertNoJavaScriptErrors()`, `data-test` selectors, no `networkidle`.

## Expected outcome

From the orders list or from an order's page an authorized administrator records a payment in two clicks; the order shows "Pagado"
(with its date on the detail page) and becomes refundable.

## Acceptance criteria

- The button renders, on both screens, only for an actor who may mark the order paid **and** only when it is awaiting payment and not
  cancelled; it is absent from the dashboard home widget.
- The action is confirmed before it runs and re-authorized server-side; refusals, races and forged ids show a translated message, never
  a 500; nothing privileged is held in client-writable state.
- After marking, the list row / the detail page reflects the new state without a reload; the detail page also shows the payment date
  and the refund control.
- No per-row query is added to the list; all copy exists in `en` and `es` (identical key sets); no new component or dependency.

## Definition of Done

- [ ] Phase 1 debate + Phase 2 INVEST recorded in this file
- [ ] Tests written first, **full suite** green (unscoped, including `tests/Browser`)
- [ ] Pint (unscoped), Larastan, `npm run build` clean
- [ ] Appsec review (forged calls and ids, refusal logging, no state leak through public properties)
- [ ] Docs synced (orders routes/contracts)

## Dependencies

- **Blocked on [0084](0084-order-mark-as-paid-backend.md).**
- **`conflict_risk_with` [0083](0083-dashboard-home-overview-ui.md):** both edit `resources/views/livewire/orders.blade.php` (0083 extracts
  the status-badge markup into components; this story adds a button and a dialog to the actions cell) and 0083 also touches
  `Orders/Index.php`'s view only through that extraction. Different regions of the same file: whichever lands second rebases; neither
  blocks the other.
