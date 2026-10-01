# [0085] Orders — "Mark as paid" control in the orders list and the order detail (frontend)

> **Status: Phase 1 complete (Three Amigos debate held 2026-10-01).** Ready for Phase 2 (INVEST check, not run yet).
> Backend companion: [0084](0084-order-mark-as-paid-backend.md), which this story is blocked on — its action contract, refusal logging
> and `Order::isAwaitingPayment()` are what this story consumes.
> Items marked **⚑ owner to confirm** are facilitator decisions the project owner has not explicitly ratified.
> **Owner-confirmed:** the control exists in the orders **list** and on the order **detail** page, and **not** on the dashboard home's
> "Latest orders" widget; both confirm first; no undo.
>
> **⚠ Scope flagged for re-debate (2026-10-02) — backend contract changed by the owner; this story is NOT yet updated to it.**
> [0084](0084-order-mark-as-paid-backend.md#rework-2026-10-02) was reworked: `MarkOrderAsPaid` now takes
> `(Order $order, PaymentMethod $paymentMethod, OrderPaymentType $type)` — the dashboard must pass the bank-transfer payment method and
> `OrderPaymentType::Transfer` — and the payment moment is no longer `orders.paid_at` but `order_payments.paid_at`, reached through
> `Order::payment()` (nullable for legacy paid orders), so every `$order->paid_at` reference below (e.g. the detail page's payment date)
> is stale. The owner also wants the control placed **in the order create/edit section** of the dashboard, in addition to the list and
> detail page. The decisions, Gherkin, files and tests below still describe the previous contract and placement; re-run the Phase 1
> debate for these changes before Phase 2.

## Description

Expose story [0084](0084-order-mark-as-paid-backend.md)'s `MarkOrderAsPaid` action in the admin so an order administrator can record by
hand that a pending order has been paid (PRD §3.2: a manual admin-set status, no payment gateway).

1. **The orders list** (`App\Livewire\Orders\Index`, `/orders`): a "Mark as paid" button in each eligible row's actions column.
2. **The order detail page** (`App\Livewire\Orders\Show`, `/orders/{order}`): the same button in the totals section, plus the payment date.

It is **not** added to the dashboard home's "Latest orders" widget of [0083](done/0083-dashboard-home-overview-ui.md): that widget links to the
orders list and to each order's page, where the action already lives.

## Type

`frontend | includes database-expert: no`

## Verified facts that shaped the story

- **`Index` is explicitly read-only today**, and three things assert it: `tests/Feature/Orders/IndexTest.php:88-97` pins its public
  methods to exactly `mount, orders, ordersSummary, canViewCustomers`; the class docblock (`Index.php:13-20`, "No public method mutates
  anything") and the view docblock (`orders.blade.php:10-16`, "Read-only"). This story changes that on purpose, so all three are edited.
  `IndexRenderingTest.php:213` ("no create control") stays valid.
- **`<x-confirm-dialog>` binds a bool:** it puts `wire:model="{{ $model }}"` on the `flux:modal` (`components/confirm-dialog.blade.php:36`),
  takes plain-string `heading`/`body`, renders `{{ $slot }}` (`:42`) and puts `wire:loading.attr="disabled"` on its confirm button
  (`:52-54`). A nullable string cannot back it. `Show` already holds `showCancelConfirm`/`showBackwardConfirm` bools.
- **`Show::$orderId` is already `#[Locked]`** (`Show.php:83`); a client cannot repoint `markAsPaid` at a different order.
- **`Show` has no "payment area":** the payment badge sits in the header (`show.blade.php:60-72`); the refund control sits in the totals
  section beside `refunded_amount` (`:337-358`), disabled-with-tooltip for a missing permission.
- **Nothing in `app/Livewire/Orders/**` toasts today;** the precedent is `Flux::toast(variant: 'success', text: …)` in
  `Settings/Profile.php:84` and `Security.php:117`, and the app layout already hosts `<flux:toast.group>` (`layouts/app/sidebar.blade.php:108`).
- `Show::cancelOrder` calls a bare `Gate::authorize` (`Show.php:538`) — an **unlogged** refusal; **not** to be copied here.
- `orders.index.columns.payment` is the only existing `payment` lang key; a new top-level `orders.payment.*` group collides with nothing.
- The list loads **all** orders (no filters, pagination or polling), ordered `created_at desc, id desc`.
- `0083` edits the same blade file (`orders.blade.php`), lines 78-89 only (the order-status badge cell; the payment-badge cell stays inline); this story edits the actions cell.

## Decisions

### D-1 — One rule, three readers

Whether the button shows is decided by **`Order::isAwaitingPayment()`** (0084 D-5) **plus** `Gate::allows('markPaid', $order)` — never by a
copy of the rule in a view. The button is **hidden, not disabled**, when it cannot apply (a deliberate, recorded divergence from the
refund control's disabled-with-tooltip precedent: two dimensions there, one here). Flagged-for-review orders still show it.

### D-2 — Orders list (`Index`)

**Public state:** `public bool $showMarkPaidConfirm = false;` (bound by the modal) and `#[Locked] public ?string $confirmingPaidOrderId = null;`
(the modal can never write the id; a client cannot forge it).

- **`orders()` rows** gain `'canMarkPaid' => $order->isAwaitingPayment() && Gate::allows('markPaid', $order)` (the cheap attribute check first;
  `Gate::allows` reduces to Spatie's cached `orders.edit` lookup, so **no query per row** — pinned by a 1-vs-20-orders query-count test; if
  it ever fails, hoist one `Gate::allows('orders.edit')` outside the `map` like `$canLinkCustomers`, never a lazy first-row memo). Both array-shape
  docblocks (`Index.php:30`, `:60`) are updated.
- **Computed `confirmingPaidRow(): ?array`** — the memoised row whose id equals `confirmingPaidOrderId` **and** whose `canMarkPaid` is true;
  no extra query; `null` for null/garbage/ineligible.
- **`confirmMarkAsPaid(string $orderId)`** — sets `confirmingPaidOrderId` **only if the id matches one of the server-built rows** (any
  row; unknown values are a silent no-op), then sets `showMarkPaidConfirm = true`. It performs **no authorization and no Gate call**.
  The dialog renders only when `confirmingPaidRow !== null` (`:show="$showMarkPaidConfirm && $this->confirmingPaidRow !== null"`), so a forged
  opener by an unauthorized actor opens no dialog. ⚑
- **`dismissMarkAsPaid()`** — clears both properties; wired to Cancel **and** to the modal's `@close` (Esc, backdrop, X).
- **`markAsPaid(MarkOrderAsPaid $action)`** — guards a null id (translated error toast, close, refresh), **re-loads `Order::find($id)`** (never
  trusts the row snapshot; missing → translated `orders.payment.not_found` danger toast), then **calls the action unconditionally**:
  - `ValidationException` → danger `Flux::toast` with `$e->validator->errors()->first()` (the already-paid / cancelled message), close, refresh.
  - `AuthorizationException` is **not caught**: the action has already logged the refusal; it surfaces as a 403.
  - Success → clear state, close, refresh, `Flux::toast(variant: 'success', text: __('orders.payment.marked', ['number' => …]))`.
  - "Refresh" = `unset($this->orders, $this->ordersSummary)`; the row recomputes in place (badge → Paid, button gone); ordering by `created_at`
    means nothing reshuffles, and the list has no filter that could drop the row (a regression test pins "the row stays" so a future filter
    story must revisit it).
- **Dialog:** one page-level `<x-confirm-dialog test-prefix="mark-as-paid" model="showMarkPaidConfirm" confirm-action="markAsPaid"
  dismiss-action="dismissMarkAsPaid">`, appended before the closing `</div>` of the view and **outside** the `@if (count($this->orders) > 0)`
  so it is always in the DOM. Heading/body come from lang with `:number` only; the **amount goes in the slot** as `<x-money :amount="$row['total']"/>`
  under an "Amount received" label (no float cast, no `€` duplicated into a lang string). Both values come from the server row, never client state.
- **Button (actions cell, `orders.blade.php:110-121`):** the existing View button lines stay byte-identical; wrap View + new button in
  `<div class="flex items-center gap-2 whitespace-nowrap">`; new button `data-test="mark-as-paid-{id}"`, visible label "Mark as paid" **plus**
  `<span class="sr-only">{{ $order['orderNumber'] }}</span>` so the accessible name contains the visible label (WCAG 2.5.3); click =
  `wire:click="confirmMarkAsPaid(@js($order['id']))"`. Keep the label on phones (the Flux table scrolls horizontally).
- **Double submit:** the dialog's confirm button is already `wire:loading`-disabled; Livewire serializes requests per component; after a
  success the id is null, so a queued second call stops at the null guard; cross-admin races are the action's compare-and-set. Both layers
  are documented so neither is assumed to cover the other.
- **Focus:** the native `<dialog>` traps focus and handles Esc; on cancel focus returns to the opener; after a **success** the opener leaves
  the DOM and focus falls to `body` — accepted and recorded. ⚑

### D-3 — Order detail (`Show`)

**Public state:** `public bool $showMarkPaidConfirm = false;` only (the order is the locked route-bound `$orderId`).

- **Computed `canMarkPaid(): bool`** = `$this->order->isAwaitingPayment() && Gate::allows('markPaid', $this->order)`; added to the `unset(...)`
  list of `refreshOrderState()` (`Show.php:696-710`) — `isRefundable` is already there, so the refund control appears after marking
  **without a reload**.
- **`confirmMarkAsPaid()`** — `if (! $this->canMarkPaid) { return; }` then opens the dialog (silent no-op, no Gate throw, no logging).
  **`dismissMarkAsPaid()`** closes it (Cancel and `@close`).
- **`markAsPaid(MarkOrderAsPaid $action)`** — `resetErrorBag('payment')`; **calls the action unconditionally and does NOT pre-authorize with a
  bare `Gate::authorize`** (that would skip the action's refusal logging); `ValidationException` → close dialog, `refreshOrderState()`,
  `addError('payment', $this->firstMessage($e))` (`Show.php:733`); success → close, `refreshOrderState()`, success `Flux::toast`.
  `AuthorizationException` propagates (already logged); a missing order is the existing `findOrFail` 404.
- **Markup:** in the **totals section**, immediately above the `@if ($this->isRefundable)` refund block (`show.blade.php:~340`):
  `@if ($this->canMarkPaid)` `<flux:button variant="primary" icon="banknotes" data-test="mark-as-paid" wire:click="confirmMarkAsPaid">`;
  `<flux:error name="payment"/>` **outside** the `@if` and outside the dialog (a raced/forged refusal must stay visible), mirroring
  `show.blade.php:355-358`; the same `<x-confirm-dialog>` after the cancel dialog (`:~420`) with the amount in the slot.
- **Payment date:** in the header flex row after the badge (`show.blade.php:~72`), `@if ($order->paid_at)` →
  `<flux:text data-test="paid-at">{{ __('orders.payment.paid_on', ['date' => $order->paid_at->format('d/m/Y H:i')]) }}</flux:text>` — the fixed
  `d/m/Y H:i` of the list and `placed_at` (the text is localized, the format is not). A paid order with `paid_at = NULL` shows the badge, no
  line and no error.
- **Stale page (another admin marked it first):** the action refuses as already paid; the dialog closes, `refreshOrderState()` shows the
  winner's badge and date, the button disappears.

### D-4 — Refusal logging without noise

The component **never reads the ability before calling the action**; it only hides the control. A non-permitted actor is never offered
the button, so the normal path produces zero refusals. A **forged** `markAsPaid` (a view-only actor flipping `showMarkPaidConfirm` on
`Show`, or calling `confirmMarkAsPaid('<id>')` then `markAsPaid` on the list) reaches the action, which **refuses and logs** it
(`ability = markPaid`, `target_type = order`, the order id). Rendering either screen as a view-only actor logs **nothing**. This is why the
list's opener does not check `canMarkPaid` (D-2); the alternative (opener checks, so a forged list call no-ops silently) was rejected
because the privileged attempt would go unrecorded. ⚑

### D-5 — Copy (en + es, identical key sets)

New top-level `orders.payment.*` (0084 adds `already_paid` and `cancelled_blocked` to the same group):

| key | en | es |
| --- | --- | --- |
| `payment.action` | Mark as paid | Marcar como pagado |
| `payment.dialog_heading` | Mark this order as paid? | ¿Marcar este pedido como pagado? |
| `payment.dialog_body` | This records that the payment for order :number was received. It cannot be undone from here. | Esto registra que se recibió el pago del pedido :number. No se puede deshacer desde aquí. |
| `payment.dialog_amount` | Amount received | Importe recibido |
| `payment.dialog_confirm` | Mark as paid | Marcar como pagado |
| `payment.dialog_dismiss` | Cancel | Cancelar |
| `payment.marked` | Order :number marked as paid. | Pedido :number marcado como pagado. |
| `payment.paid_on` | Paid on :date | Pagado el :date |
| `payment.not_found` | This order no longer exists. | Este pedido ya no existe. |

The status word "Paid"/"Pagado" comes from the existing `payment_statuses.paid`. Placeholders (`:number`, `:date`) are identical in both locales.

### D-6 — Coordination with story 0083 on `orders.blade.php`

- **0083 owns** lines 78-89 only (the order-status badge cell; the payment-badge cell at 93-103 stays inline, confirmed by 0083 D-5 — so neither
  story touches the other's cell).
- **0085 owns** the actions cell (lines 110-121), the appended dialog, and the view/class docblocks.
- **Tests probe by `data-test` hook only**, never by badge markup or class, and never assert the literal word "Pagado" through a badge
  component; expectations use the lang-resolved label. Whichever story lands second rebases mechanically (disjoint hunks).
- **Cross-story guard moved to 0083:** "the dashboard latest-orders widget renders no element whose `data-test` starts with `mark-as-paid`"
  is asserted in **0083's** widget test (a guard written here would be a false green on a page that does not exist yet). The prefix
  `mark-as-paid` is therefore a frozen contract.

### D-7 — Reuse and scope

`<x-confirm-dialog>`, `<x-money>`, `flux:button`/`flux:badge`, `Flux::toast`; **no new component, no dependency.** Bulk marking and a paid-date
column in the list are **out of scope** (recommended: no, ⚑).

## Open questions closed

| Q | Resolution |
| --- | --- |
| Dashboard widget action | **no** (owner) — the control lives in the list and the detail |
| Bulk marking from the list | no (⚑) |
| Paid date in the list | no (⚑) |
| Dialog state type | a bool for the modal plus a `#[Locked]` id (list) / a bool only (detail) |
| Hidden vs disabled when not permitted | hidden (recorded divergence from the refund control) |

## Gherkin

"Order administrator" and the payment-state vocabulary are added to the Gherkin glossary with 0084. Scenarios use the English labels
("Paid", "Pending payment"); Spanish appears only in the locale scenario. Forged-call scenarios state intent, not mechanism.

```gherkin
Scenario: The orders list offers "Mark as paid" for an order awaiting payment
  Given Olga, an order administrator, and an order whose payment state is Pending payment
  When Olga opens the orders list
  Then the order's row offers a "Mark as paid" button

Scenario Outline: The orders list hides "Mark as paid" when the order cannot be marked
  Given Olga, an order administrator, and a <status> order whose payment state is <state>
  When Olga opens the orders list
  Then the order's row does not offer a "Mark as paid" button
  Examples:
    | status     | state              |
    | Processing | Paid               |
    | Processing | Partially refunded |
    | Processing | Refunded           |
    | Cancelled  | Pending payment    |

Scenario: Only the order awaiting payment shows the button in a mixed list
  Given Olga, an order administrator, and 1 order whose payment state is Pending payment, 2 paid orders and 1 cancelled order awaiting payment
  When Olga opens the orders list
  Then exactly 1 row offers a "Mark as paid" button

Scenario: A flagged order awaiting payment still shows the button
  Given Olga, an order administrator, and a flagged order whose payment state is Pending payment
  When Olga opens the orders list
  Then the order's row offers a "Mark as paid" button

Scenario: The list hides the button from a user who may only view orders
  Given Nora, a user who may only view orders, and an order whose payment state is Pending payment
  When Nora opens the orders list
  Then no row offers a "Mark as paid" button
  And no refusal is recorded

Scenario: The confirmation names the order that was clicked
  Given Olga, an order administrator, and three orders whose payment state is Pending payment
  When Olga asks to mark the second of them as paid from the orders list
  Then the confirmation names that order's number and total
  And none of the other two orders' numbers or totals appear in it
  And the order is not yet paid

Scenario: Asking for another order replaces the confirmation's order
  Given Olga, an order administrator, who asked to mark the first of two orders awaiting payment as paid
  When Olga asks to mark the second one as paid without closing the confirmation
  Then the confirmation names the second order

Scenario: Cancelling the confirmation leaves the order unchanged
  Given Olga, an order administrator, who was asked to confirm marking an order as paid from the orders list
  When Olga cancels the confirmation
  Then the confirmation closes
  And the order is still awaiting payment

Scenario: Dismissing the confirmation with the keyboard leaves the order unchanged
  Given Olga, an order administrator, who was asked to confirm marking an order as paid from the orders list
  When Olga dismisses the confirmation with the Escape key
  Then the confirmation closes
  And the order is still awaiting payment

Scenario: Confirming in the list marks that order as paid
  Given Olga, an order administrator, who was asked to confirm marking an order as paid from the orders list
  When Olga confirms
  Then that row shows the payment state Paid and no longer offers the button
  And Olga is told the order was marked as paid
  And the other rows do not change

Scenario: The order detail offers "Mark as paid" for an order awaiting payment
  Given Olga, an order administrator, and an order whose payment state is Pending payment
  When Olga opens the order
  Then Olga sees the "Mark as paid" button

Scenario Outline: The detail hides "Mark as paid" when the order cannot be marked
  Given Olga, an order administrator, and a <status> order whose payment state is <state>
  When Olga opens the order
  Then Olga does not see the "Mark as paid" button
  Examples:
    | status     | state              |
    | Processing | Paid               |
    | Processing | Partially refunded |
    | Processing | Refunded           |
    | Cancelled  | Pending payment    |

Scenario: The detail hides the button from a user who may only view orders
  Given Nora, a user who may only view orders, and an order whose payment state is Pending payment
  When Nora opens the order
  Then Nora does not see the "Mark as paid" button
  And no refusal is recorded

Scenario: Confirming on the detail marks the order as paid and shows the date
  Given Olga, an order administrator, who was asked to confirm marking an order as paid on its page
  When Olga confirms
  Then the order shows the payment state Paid and the date it was paid
  And Olga is told the order was marked as paid
  And the "Mark as paid" button is gone

Scenario: A paid order offers a refund to an administrator who may refund
  Given Olga, an order administrator who may also refund orders, and an order she has just marked as paid
  When Olga looks at the order's page
  Then the refund control is available

Scenario: An administrator who may not refund is not offered a refund after marking
  Given Edu, an order administrator who may not refund orders, and an order he has just marked as paid
  When Edu looks at the order's page
  Then no refund control is offered

Scenario: An order marked by someone else in the meantime is reported, not repeated
  Given Olga, an order administrator, who was asked to confirm marking an order as paid, and Omar has marked it as paid since
  When Olga confirms
  Then Olga is told the order is already paid
  And the order keeps the date Omar's mark recorded

Scenario: An order cancelled in the meantime is reported, not applied
  Given Olga, an order administrator, who was asked to confirm marking an order as paid, and Omar has cancelled that order since
  When Olga confirms
  Then Olga is told a cancelled order cannot be marked as paid
  And the order still has the payment state Pending payment

Scenario: Losing the right to edit orders before confirming is refused
  Given Olga, an order administrator, who was asked to confirm marking an order as paid, and her right to edit orders was removed since
  When Olga confirms
  Then Olga is refused
  And the order does not change
  And the refusal is recorded

Scenario: A refused attempt to mark an order as paid is recorded
  Given Nora, a user who may only view orders, and an order whose payment state is Pending payment
  When Nora tries to mark the order as paid anyway
  Then Nora is refused
  And the refusal is recorded against that order

Scenario Outline: An unusable order reference is reported without an error page
  Given Olga, an order administrator, who was asked to confirm marking an order as paid
  When Olga confirms while the order reference is <reference>
  Then Olga is told the order could not be found
  And no order changes
  Examples:
    | reference                    |
    | missing                      |
    | not a valid id               |
    | an order that does not exist |

Scenario: A second confirmation of the same order is ignored
  Given Olga, an order administrator, who has just confirmed marking an order as paid
  When Olga confirms the same order a second time
  Then Olga is told the order is already paid
  And the order keeps its original payment date

Scenario: An order paid before payment dates were recorded shows no date
  Given Olga, an order administrator, and a paid order without a recorded payment date
  When Olga opens the order
  Then the payment state is shown without a date and without an error

Scenario: The controls speak the administrator's language
  Given Laura, an order administrator whose admin UI language is Spanish
  When Laura opens the orders list with an order awaiting payment
  Then the button, the confirmation and the messages are in Spanish
```

The earlier "latest-orders widget has no button" scenario is **owned by 0083** (D-6).

## Files to create/modify

- `app/Livewire/Orders/Index.php` — properties, `canMarkPaid` row key, `confirmingPaidRow`, `confirmMarkAsPaid`, `dismissMarkAsPaid`,
  `markAsPaid`; **class docblock and both array-shape docblocks updated**
- `resources/views/livewire/orders.blade.php` — actions-cell button, one page-level dialog; **view docblock updated**
- `app/Livewire/Orders/Show.php` — `showMarkPaidConfirm`, `canMarkPaid`, `confirmMarkAsPaid`, `dismissMarkAsPaid`, `markAsPaid`, the
  `refreshOrderState()` unset list, the `@property-read` docblock
- `resources/views/livewire/orders/show.blade.php` — payment-date line, button, `<flux:error name="payment"/>`, dialog
- `lang/en/orders.php`, `lang/es/orders.php` — the D-5 keys
- `tests/Feature/Orders/IndexTest.php` — **edit the public-methods reflection test deliberately**: allow `confirmMarkAsPaid`,
  `dismissMarkAsPaid`, `markAsPaid`, `confirmingPaidRow` and assert **exactly one** public method can reach a write (`markAsPaid`)
- Tests below; docs (Phase 6): `docs/api/orders.md` (both screens' control, the new public methods, the lang group)

## Tests to perform

Livewire feature tests with `OrdersUi::actor([...])` (a non-Super-Admin; a Super Admin makes "hidden" assertions false negatives — used
only in the dedicated positive and "state rule beats `Gate::before`" cases), probing **rendered HTML by `data-test` hook** (count occurrences
of `data-test="mark-as-paid-`, not mere presence):

- `IndexMarkAsPaidTest` —
  - **Visibility:** an explicit-expectation dataset of payment state (4) × order status (5) = 20 (exactly 4 visible: pending payment ×
    the four non-cancelled statuses), for an `orders.view + orders.edit` actor — **expectations are literal, not computed from
    `isAwaitingPayment()`**; permission axis on the visible combination (no permission → 403 on the page; `orders.view` only → hidden;
    `orders.view + orders.edit` → shown; `orders.view + orders.refund` → hidden; Super Admin → shown, and Super Admin + cancelled-pending →
    hidden); mixed list = exactly one hook; flagged order shows it; soft-deleted-customer row still shows it.
  - **Dialog:** with 3+ pending rows of distinct numbers/totals, opening row B shows **B's number and exact formatted amount** and neither A's nor
    C's; opening A then B leaves B; Cancel, Esc, backdrop and X all reset both properties without error; confirm writes B only.
  - **In place:** the row shows Paid, loses the button, the other rows are byte-identical, the success toast is dispatched (`assertDispatched('toast-show')`),
    the row **stays** in the list.
  - **Races and refusals:** already-paid elsewhere → translated message, `paid_at` unchanged; cancelled meanwhile → translated message, no write;
    **permission revoked after the dialog opened** (forget the cached permissions) → refused **and logged**; order removed → translated not-found; double
    call → second is the already-paid message, one write, one success toast.
  - **Forged calls:** a view-only actor calling `confirmMarkAsPaid('<id>')` then `markAsPaid()` is refused **and** `Log::spy()` receives the
    "Privileged action refused" warning with `ability = markPaid`, `target_type = order`, `target_id` = the id, exactly once; **rendering** the
    list as a view-only actor and as an edit actor logs **no** warning; `confirmingPaidOrderId` is `#[Locked]` (a client `set()` is rejected);
    `confirmMarkAsPaid` with garbage / a valid-looking unknown UUID / another model's id is a silent no-op and a following `markAsPaid` gives the
    translated error with **no write and no 500** (dataset: null, `'not-a-uuid'`, unknown UUID, a Customer id).
  - **Query count:** `DB::listen` with 1 vs 20 orders (one pending-payment row in both, permission cache warmed, non-Super-Admin): **equal counts**;
    opening the dialog adds no query beyond the memoised rows.
  - **Public-state audit:** reflection — no public property holds a model/Collection; the only new public properties are the bool and the locked id; the row
    array adds only the `canMarkPaid` bool; the serialized snapshot contains no total or customer name of the confirmed row.
  - **Locale:** `ui_locale = 'es'` on the actor (not `app()->setLocale`): button, dialog, toast and both refusals resolved from the lang files; English absent.
- `ShowMarkAsPaidTest` — the same visibility matrix on the detail (plus `canMarkPaid()` agrees with the rendered button); confirm flow; `paid-at`
  line formatted with a frozen clock (exact string), absent for a pending order, absent and error-free for a legacy paid order with `NULL`; the
  refund control appears after marking for `orders.edit + orders.refund` and **not** for `orders.edit` only; a stale page shows the winner's badge and
  date; both refusals render in the `payment` error slot; forged view-only `markAsPaid` refused **and logged**; `Show::$orderId` cannot be set from
  the client; Esc/backdrop close the dialog; Spanish locale.
- `OrdersLangParityTest` (extended) — the new keys in both locales with identical placeholders.
- `tests/Browser/Orders/MarkAsPaidTest.php` (one journey per test, **five cases**): (1) list — click the **second** of three pending rows, the
  dialog shows that row's number and amount, Escape closes it, the DB is unchanged; (2) list — click, confirm, that row flips to Paid and loses its
  button, the other two keep theirs, the toast appears, no reload, `assertNoJavaScriptErrors()`; (3) detail — confirm, the `paid-at` line and the refund
  control appear without navigation, then a server-side check; (4) stale page — mark the order from the DB while the page is open, confirm, see the
  translated already-paid message and no error page; (5) dialog focus **only if** the dialog is specified to manage it (otherwise dropped).
  `data-test` selectors (the label "Mark as paid" collides with the dialog title), never `networkidle`, `retry(3, …, 250)` on multi-step flows,
  a server-side assertion after each DOM assertion, the actor's `ui_locale` for locale. The toast is the most flake-prone assertion — assert the in-place state first.
- **Browser tests are not run in CI by default** (`selectors-tagging-and-ci.md`), so "full suite green including `tests/Browser`" is a local check;
  do not read a green CI as proof of these journeys.

## Expected outcome

From the orders list or from an order's page an authorized administrator records a payment in two clicks; the order shows Paid (with its date
on the detail page) and becomes refundable.

## Acceptance criteria

- The button renders, on both screens, only for an actor who may mark the order paid **and** only when it awaits payment and is not cancelled;
  flagged orders still show it; it is absent from the dashboard widget (asserted by 0083's suite).
- It is confirmed before it runs; the confirmation names the **clicked** order's number and amount from server rows; Cancel, Esc, backdrop and X
  all close it without error.
- The components never pre-authorize with a bare `Gate::authorize`; a **forged** call is refused and **logged** by the action; rendering as an
  unauthorized actor logs nothing; refusals, races, missing/garbage ids and a revoked permission give a translated message or a 403, never a 500.
- After marking, the list row / the detail page reflects the new state without a reload; the detail also shows the payment date and, for a refunding
  actor, the refund control; a legacy `NULL` date renders cleanly.
- No per-row query is added to the list; the public surface of both components contains only scalars and a locked id; all copy exists in `en` and
  `es` with identical key sets and placeholders; no new component or dependency.
- The now-false read-only claims in `Index` (class docblock, view docblock, reflection test) are rewritten to the new, precise claim.

## Definition of Done

- [x] Phase 1 debate recorded in this file
- [ ] Phase 2 INVEST validation (`code-reviewer`)
- [ ] Tests written first, **full suite** green (unscoped, including `tests/Browser`, locally)
- [ ] Pint (unscoped), Larastan, `npm run build` clean
- [ ] Appsec review (forged calls and ids, refusal logging, public state, focus/keyboard dismissal)
- [ ] Docs synced (orders routes/contracts, lang group, the `Index` read-only claim)
- [ ] `IndexTest` reflection allow-list and the `Index` docblocks updated in the same change

## Risks and follow-ups

- **A future filter/pagination story on the orders list** could drop a row after marking; a regression test ("the row stays") forces that
  story to revisit the interaction.
- **Shared file with 0083** (`orders.blade.php`): disjoint hunks, hook-only tests; whichever lands second rebases.
- **Focus after success** falls to `body` (the opener leaves the DOM) — accepted for now.
- **Toast assertions are flake-prone** in the browser; Livewire `assertDispatched` carries the contract.
- **Hidden vs disabled:** a user without `orders.edit` gets no hint that the action exists (divergence from the refund control).

## Dependencies

- **Blocked on [0084](0084-order-mark-as-paid-backend.md).**
- **`conflict_risk_with` [0083](done/0083-dashboard-home-overview-ui.md):** both edit `resources/views/livewire/orders.blade.php` (disjoint regions, D-6).

## Debate record

Facilitator: Claude (product-owner role). Participants: frontend-expert, frontend-qa (project agents, read-only). Corrections made to the
earlier draft: the single `?string` id cannot drive `<x-confirm-dialog>` (bool-bound) — bool plus a `#[Locked]` id on the list, a bool only on
the detail; `IndexTest`'s reflection test and two "read-only" docblocks would have gone red or false and were missing; `Show` had no "payment area" —
placement fixed (totals section and header); `Show::markAsPaid` must not pre-authorize with a bare `Gate::authorize` or forged calls go unlogged;
there is no domain exception (only `ValidationException`); the amount belongs in the dialog slot via `<x-money>`; toasts use `Flux::toast`;
the dashboard-widget scenario belongs to 0083; glossary terms and Spanish labels in English scenarios were corrected; the `:amount` lang
placeholder and a `orders.payment.*` collision worry were resolved.
