# [0084] Order — mark as paid manually (backend)

> **Status: Phase 1 draft.** Written from the project owner's request on 2026-09-30; the Three Amigos debate (Phase 1) and
> INVEST validation (Phase 2) have **not** run yet. Items marked **⚑ owner to confirm** are facilitator defaults.
> Frontend companion: [0085](0085-order-mark-as-paid-ui.md), blocked on this story.

## Description

[PRD §3.2](../../docs/PRD/sections/epic-3-customers-orders.md) says an order's payment state is **"a manual admin-set status
only"** — no payment gateway sets it, the administrator selects it by hand. The order module implements every other part of that
sentence (story [0045](done/0045-orders-core-crud-backend.md) creates orders as `pending_payment`; story
[0051](done/0051-order-payment-refund-state-backend.md) derives `refunded`/`partially_refunded` from refunds) but **nothing ever
moves an order to `paid`**: a search of `app/` on 2026-09-30 finds only `OrderFactory::paid()` writing it, and `RecordRefund`
refuses any order that is not already `paid`/`partially_refunded`, so **no real order can ever be refunded** and the dashboard's
"Real income" measure (story [0082](0082-dashboard-home-overview-backend.md)) has nothing to count.

This story adds the missing write: a single-purpose action **`MarkOrderAsPaid`** that moves an order from `pending_payment` to
`paid` and records **when** in a new nullable **`orders.paid_at`** column, so income can later be dated by the moment of payment.
The button that calls it is story [0085](0085-order-mark-as-paid-ui.md).

## Type

`backend | includes database-expert: yes` — one additive migration (`paid_at`).

## Decisions proposed (to be ratified in the debate)

- **D-1 — One action, `App\Actions\Orders\MarkOrderAsPaid`, `__invoke(Order $order): Order`.** It follows `CancelOrder`'s shape
  (story 0050): authorize first, then state guards, then a single `forceFill()` write (`payment_status` and `paid_at` are not in
  `Order`'s `#[Fillable]`). Order of operations, each pinned by its own test:
  1. `Gate::authorize('markPaid', $order)` — the permission refusal always wins, so an unauthorized caller learns nothing about the
     order's state.
  2. Already paid (`paid`, `partially_refunded`, `refunded`) → `ValidationException` on `payment_status`
     (`orders.payment.already_paid`).
  3. `status = cancelled` → **direct throw** of a domain exception (like `OrderCancellationBlockedException`, **never** a second
     `Gate` check, so the rule also binds a Super Admin): a cancelled order cannot be marked paid. ⚑
  4. Write `payment_status = Paid`, `paid_at = now()`.
- **D-2 — Authorization:** a new `OrderPolicy::markPaid(User, Order)` that **reduces to `orders.edit`** (the same ability
  `transitionStatus()` uses), so **no new permission and no seeder change** — an administrator who may move an order through its
  fulfilment states may also record that it was paid. Refusals are logged through the project wrapper like every other order action.
  ⚑ (alternative: a dedicated `orders.mark-paid` permission — rejected as over-granular for a one-click manual status; revisit if the
  owner wants accountants to mark payments without editing orders.)
- **D-3 — Concurrency:** two administrators marking the same order at once must not write twice or overwrite `paid_at`. The write is a
  **compare-and-set**: `UPDATE orders SET payment_status='paid', paid_at=? WHERE id=? AND payment_status='pending_payment'`; zero rows
  affected → re-read and raise the "already paid" refusal (the precedent is story 0064c's status race). Wrapped so the order model
  reflects the stored row afterwards.
- **D-4 — `paid_at`:** nullable `timestamp`, no index, placed after `refunded_amount`; set once by this action, never edited
  afterwards, never mass-assignable; stored in the application timezone like every other timestamp. **No backfill** (existing rows
  stay `NULL` = "paid, date unknown"): a backfill would invent data. `OrderFactory::paid()` is updated to set `paid_at` so factory
  data is coherent. ⚑
- **D-5 — No reverse action in this story.** There is deliberately no "mark as unpaid": a wrong click is corrected by the existing
  flows (a refund for money that was returned), and an "undo" needs its own rules (what if a refund already exists?). Recorded as a
  known limitation and an open question (Q-1). ⚑
- **D-6 — No side effects:** no notification, no event, no change to `status` (an order can be paid while still `pending`, or
  shipped while still unpaid — the two dimensions stay independent, per `PaymentStatus`'s own docblock), no refund logic.
- **D-7 — Dashboard follow-up (not this story):** [0082](0082-dashboard-home-overview-backend.md) dates Real income by
  `orders.created_at`. Once this story ships, a later change can switch that measure to `COALESCE(paid_at, created_at)`; 0082 keeps
  working unchanged meanwhile.

## Open questions

- **Q-1 — Undo:** should an order be revertible from `paid` to `pending_payment` when it has **no refunds**? Recommend **no** for
  now (D-5); a mistaken mark of a never-refunded order would then need a database fix — confirm that risk is acceptable for a
  back-office demo.
- **Q-2 — `paid_at` precision:** record the moment of the click (recommended), or let the administrator enter the actual payment date
  (useful for a bank transfer that arrived earlier)? Recommend the click moment for this story; a date field can follow.
- **Q-3 — Paid amount:** this phase the whole `total` is considered paid; partial payments are out of scope (PRD: manual status only).

## Gherkin (draft)

```gherkin
Scenario: An order administrator marks a pending order as paid
  Given Olga, an order administrator, and an order awaiting payment
  When Olga marks the order as paid
  Then the order's payment state becomes "Pagado"
  And the order records the moment it was paid

Scenario: Marking an order as paid does not change its fulfilment status
  Given Olga, an order administrator, and a shipped order awaiting payment
  When Olga marks the order as paid
  Then the order is still "Enviado"
  And its payment state is "Pagado"

Scenario Outline: An order that is already paid cannot be marked again
  Given Olga, an order administrator, and an order whose payment state is "<state>"
  When Olga marks the order as paid
  Then Olga is told the order is already paid
  And the order does not change
  Examples:
    | state                    |
    | Pagado                   |
    | Parcialmente reembolsado |
    | Reembolsado              |

Scenario: A cancelled order cannot be marked as paid
  Given Olga, an order administrator, and a cancelled order awaiting payment
  When Olga marks the order as paid
  Then Olga is told a cancelled order cannot be paid
  And the order does not change

Scenario: A super administrator is bound by the same rules
  Given Sara, a super administrator, and a cancelled order awaiting payment
  When Sara marks the order as paid
  Then Sara is told a cancelled order cannot be paid

Scenario: A user who may not edit orders cannot mark one as paid
  Given Nora, a user who may only view orders, and an order awaiting payment
  When Nora marks the order as paid
  Then Nora is refused
  And the order does not change
  And the refusal is logged

Scenario: Two administrators marking the same order leave one payment record
  Given Olga and Omar, order administrators, and an order awaiting payment
  When both mark the order as paid at the same time
  Then the order is paid once
  And the moment it was paid is that of the first mark

Scenario: A paid order can now be refunded
  Given Olga, an order administrator who may refund, and an order she has just marked as paid
  When Olga records a refund on the order
  Then the refund is accepted
```

## Files to create/modify

- `app/Actions/Orders/MarkOrderAsPaid.php`; `app/Exceptions/OrderPaymentBlockedException.php` (or the repo's existing exception naming)
- `app/Policies/OrderPolicy.php` (`markPaid`), `app/Models/Order.php` (`@property CarbonImmutable|null $paid_at`, cast, a
  `isAwaitingPayment()` helper that the action and 0085 both read, like `isRefundable()`)
- `database/migrations/<date>_add_paid_at_to_orders_table.php`; `database/factories/OrderFactory.php` (`paid()` sets `paid_at`)
- `lang/en/orders.php`, `lang/es/orders.php` (`payment.already_paid`, `payment.cancelled_blocked`; keep the existing
  `OrdersLangParityTest` green)
- Tests: `tests/Feature/Orders/MarkOrderAsPaidTest.php`, `tests/Feature/Orders/OrderPolicyMarkPaidTest.php` (or an addition to
  `AuthorizationTest`), a concurrency test
- Docs (Phase 6): `docs/database/schema-orders/orders.md` and the ER diagram (`docs/database/schema.md`), `docs/api/orders.md`,
  `docs/architecture/authorization.md` (the new policy method)

## Tests to perform

Pest feature tests on MySQL with factories: the happy path; every state guard (paid / partially refunded / refunded / cancelled);
cancelled binds a Super Admin; authorization refusal wins over a state error and is logged (`RefusalLoggingTest` precedent); a user
with only `orders.view` is refused; fulfilment `status` untouched for each of the five statuses; `paid_at` set once and **unchanged**
by a second attempt; compare-and-set under a simulated race (the second call refuses, one row written); `forceFill` only (mass
assignment of `payment_status`/`paid_at` through `Order::create()`/`fill()` is ignored); migration test that the column is nullable and
existing rows keep `NULL`; `OrderFactory::paid()` sets `paid_at`; after marking, `RecordRefund` accepts the order.

## Expected outcome

An administrator can move a pending order to `paid` with the moment recorded; refunds and the dashboard's Real income become usable
on real data.

## Acceptance criteria

- `MarkOrderAsPaid` is the only writer of `Paid` and `paid_at`; it refuses every non-`pending_payment` order and every cancelled order.
- Authorization is `orders.edit` through `OrderPolicy::markPaid`; no new permission; refusals are logged.
- Race-safe: a concurrent second mark neither re-writes `paid_at` nor succeeds.
- `status` is never touched; no notification or other side effect.
- Migration is additive and reversible; existing rows are not modified.

## Definition of Done

- [ ] Phase 1 debate + Phase 2 INVEST recorded in this file
- [ ] Tests written first (red) then green; **full suite** green (unscoped)
- [ ] Pint (unscoped) and Larastan clean
- [ ] Appsec review (authorization, race, mass assignment, refusal logging)
- [ ] Docs synced (orders schema + ER diagram, routes/contracts, authorization)

## Dependencies

- None pending (`depends_on: []`). Consumed by [0085](0085-order-mark-as-paid-ui.md).
- Related, no blocking: [0082](0082-dashboard-home-overview-backend.md) (Real income; its demo seeder uses `OrderFactory::paid()`,
  which this story extends to set `paid_at` — harmless in either merge order).
