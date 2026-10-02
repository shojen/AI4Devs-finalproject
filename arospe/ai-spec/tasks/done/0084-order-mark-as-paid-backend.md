# [0084] Order — mark as paid manually (backend)

> **Status: DONE (2026-10-02) — reworked design (`order_payments`); Phases 1-7 complete. Phases recorded before the rework refer to the superseded `orders.paid_at` design.**
> Frontend companion: [0085](../0085-order-mark-as-paid-ui.md), blocked on this story (its scope is flagged for re-debate).
> Items marked **⚑ owner to confirm** are facilitator decisions the project owner has not explicitly ratified.
> **Owner-confirmed (2026-09-30/10-02):** no undo action; the payment moment = the moment of the click; the payment is recorded in a new
> `order_payments` table (one row per order) instead of an `orders.paid_at` column; the action takes the payment method and the payment
> type from its caller; callers are an administrator or any user with `orders.edit`; the dashboard control also lives in the order
> create/edit section (0085).

## Description

[PRD §3.2](../../../docs/PRD/sections/epic-3-customers-orders.md) says an order's payment state is **"a manual admin-set status
only"** — no payment gateway sets it, the administrator selects it by hand. The order module implements every other part of that
sentence (story [0045](0045-orders-core-crud-backend.md) creates orders as `pending_payment`; story
[0051](0051-order-payment-refund-state-backend.md) derives `refunded`/`partially_refunded` from refunds) but **nothing ever moves
an order to `paid`**: a search of `app/` on 2026-09-30 finds only `OrderFactory::paid()` writing it, and `RecordRefund` refuses any order
that is not already `paid`/`partially_refunded`, so **no real order can ever be refunded** and the dashboard's "Real income" measure
(story [0082](0082-dashboard-home-overview-backend.md)) has nothing to count. 0051's D-4 explicitly left a payment-state write path
as a future *decision*; this story is that decision.

It adds a single-purpose action **`MarkOrderAsPaid`** that moves an order from `pending_payment` to `paid` and records the payment in a
new **`order_payments`** table: **which payment method** was actually used, **which payment type** (`transfer` today; `card`/`paypal`
reserved for a future checkout) and **when**. One order has at most one payment. The buttons that call it are story
[0085](../0085-order-mark-as-paid-ui.md).

## Type

`backend | includes database-expert: yes` — one additive `create_order_payments_table` migration, a new model, enum and factory.

## Verified facts that shaped the story

- **Precedent for logging a state refusal is split, and most order actions log nothing at all.** `CancelOrder` logs no state refusal, whereas `AddOrderItem` does (`order_not_editable`, `order_item_limit_reached`) through `LogRefusedPrivilegedAttempt::log()`, as do some non-order actions (`RemoveStoreLanguage`, `DeleteProductCategory`, `SetSalesRegionActive`). There is no `Gate::after` and no logging in
  the policy layer; the only emitter of the "Privileged action refused" warning is `LogRefusedPrivilegedAttempt` when called
  explicitly. `CancelOrder`, `TransitionOrderStatus` and `RecordRefund` use a bare `Gate::authorize()` and log **nothing**; only
  `CreateOrder` and the three line-item actions log. `LogRefusedPrivilegedAttempt::resolveTarget()` auto-resolves only `User` and
  `Role`, so an `Order` target needs explicit `targetType`/`targetId`.
- `OrderPolicy::transitionStatus` reduces to `orders.edit`; `cancel()` needs `orders.edit` **and** `orders.refund` **and** a state rule,
  and its docblock explains why a Gate-mediated state rule is inert against a Super Admin (`Gate::before`).
- The `Administrator` role holds every permission except `roles.manage-administrators`; no other default role exists.
- Every timestamp column uses plain `timestamp` (precision 0); models type them `Carbon|null` (no immutable cast). The app timezone is
  `Europe/Madrid`.
- A full refund auto-cancels an order (story 0052), so `refunded + cancelled` is a legitimate, settled state.
- `OrderFactory` has only a `paid()` state (no `cancelled`/`refunded`/`partially_refunded` states); `DemoDataSeeder` seeds its first order with `OrderFactory::paid()` and the rest as pending.
- **Payment methods** ([schema](../../../docs/database/schema-other/payment-methods.md)): `payment_methods` holds exactly one seeded row,
  `code = bank_transfer` (`App\Enums\PaymentMethodCode::BankTransfer`, `label()` → `payment-methods.names.<value>`); there is no
  create/delete path. `orders.payment_method_id` is a **required**, `restrictOnDelete()` FK — the method chosen at order creation.
- **Domain enum columns** are `VARCHAR(20)` cast to a backed enum (`orders.status`, `orders.payment_status`), never a MySQL `ENUM`, and
  unindexed. `PaymentStatus` deliberately has no `label()`; `PaymentMethodCode` and `UserStatus` do.
- **Order-domain child tables** ([schema](../../../docs/database/schema-orders/items-and-refunds.md)): UUIDv7 PK via `HasUuids`; only
  FK-created indexes, no hand-written ones; `order_items.order_id` is the domain's only cascade (a line item is *part of* its order);
  `refunds` restricts both its FKs to protect a recorded financial fact; derived/actor columns are out of `#[Fillable]` and written
  with `forceCreate()` from one action. The [three-way delete rule](../../../docs/database/migrations/delete-behaviour-and-vendored.md#the-three-way-delete-behaviour-rule-cascade-restrict-or-null)
  is: cascade = part of the parent, restrict = a peer whose data a delete would destroy, null = a snapshot makes the reference optional.
  Orders are never hard-deleted this phase (cancel is a status), although `OrderPolicy::delete` exists.

## Decisions

### D-1 — `App\Actions\Orders\MarkOrderAsPaid::__invoke(Order $order, PaymentMethod $paymentMethod, OrderPaymentType $type): Order`

Three parameters, **no date argument** (the moment is always "now"). The caller supplies the payment method and type: today the dashboard
passes the bank-transfer method and `OrderPaymentType::Transfer`; a future checkout will pass card/paypal. Modelled on `CancelOrder`.
Steps, each pinned by its own test:

1. **Authorize and log:** `$this->logRefusedPrivilegedAttempt->authorize('markPaid', $order, targetType: 'order', targetId: $order->id)`
   (constructor-injected `LogRefusedPrivilegedAttempt`, the `AddOrderItem` form). The permission refusal always wins and reveals nothing
   about the order's state. **This deliberately deviates from `CancelOrder`'s bare `Gate::authorize`** — it is the only way a forged
   call is refused *and recorded* — and is documented in the action's docblock.
2. **Already paid** (`payment_status ≠ PendingPayment`: paid, partially refunded, refunded) → `ValidationException::withMessages(['payment_status' => __('orders.payment.already_paid')])`.
3. **Cancelled** (`status = Cancelled`, payment still pending) → `ValidationException` on `payment_status` with
   `orders.payment.cancelled_blocked`. It is a **direct throw** (no second `Gate` check), so it also binds a Super Admin. **Guard order is
   deliberate:** already-paid is checked before cancelled, so `cancelled + refunded` reports "already paid" and
   `cancelled + pending_payment` reports "cancelled" ⚑ (pinned by named tests).
4. **Transactional write (D-3):** the compare-and-set on `orders`, then the `order_payments` insert.

**No new exception class.** Both refusals are `ValidationException` (as `CancelOrder`'s already-cancelled refusal is): mark-as-paid has no
retryable sibling that needs distinguishing, and the UI catches one type. The two state refusals are **not logged** by recommendation (⚑ owner to confirm: `CancelOrder` does not log them, `AddOrderItem` does; both are valid precedents) (a state refusal is
not a privilege attempt — same as `CancelOrder`) ⚑; only the authorization refusal is, and a test pins that neither state refusal logs.

**No validation of the method/type pair** in this story: any existing `PaymentMethod` and any `OrderPaymentType` case is accepted
(the enum parameter already rejects unknown types at the type level). Whether `type` must be consistent with the method's `code`
(e.g. `bank_transfer` ⇒ `transfer`) is **⚑ owner to confirm** (Q-4).

### D-2 — Authorization: `OrderPolicy::markPaid(User $actor, Order $order): bool` = `hasPermissionTo('orders.edit')`

Unchanged by the rework. Flat, same shape as `transitionStatus`, with the unused `$order` parameter kept for the same reason. Callers:
an administrator (seeded role) or any user holding `orders.edit` (owner-confirmed 2026-10-02). **No state clause** (unlike `cancel()`):
a state clause inside a Gate-mediated check would turn "already paid"/"cancelled" for an ordinary actor into an `AuthorizationException`,
contradicting the scenarios, and would be inert for a Super Admin anyway; the state rules live in the action, which binds every actor.
**No new permission, no seeder change, no `orders.refund` requirement**, and **no `payment-methods.*` permission** is required to *use*
a method (the caller does not configure it). ⚑ Risk the owner accepts: anyone with `orders.edit` can now move a money-state that the
dashboard counts, with no undo (D-6).

### D-3 — Write path: one transaction, status-aware compare-and-set, then the payment row

```php
$now = $order->freshTimestamp()->startOfSecond();          // timestamp columns are second-precision
$payment = DB::transaction(function () use ($order, $paymentMethod, $type, $now): ?OrderPayment {
    $affected = Order::query()->whereKey($order->getKey())
        ->where('payment_status', PaymentStatus::PendingPayment->value)
        ->where('status', '!=', OrderStatus::Cancelled->value)  // closes the race with CancelOrder
        ->update(['payment_status' => PaymentStatus::Paid->value, 'updated_at' => $now]);

    if ($affected !== 1) {
        return null;                                         // nothing written; the transaction commits nothing
    }

    return OrderPayment::forceCreate([
        'order_id' => $order->getKey(),
        'payment_method_id' => $paymentMethod->getKey(),
        'type' => $type,
        'paid_at' => $now,
    ]);
});
```

- **Ordering inside the transaction is fixed:** the `UPDATE` first, the `INSERT` **only** on exactly 1 affected row. The `UPDATE`'s row
  lock serializes concurrent marks; the loser's `UPDATE` matches nothing, so it inserts nothing.
- **The unique `order_payments.order_id` is a second safety net**, not the primary guard. If it ever fires (only reachable through a data
  anomaly: a `pending_payment` order that already has a payment row), the `UniqueConstraintViolationException` rolls back the whole
  transaction — the order stays `pending_payment` — and **is not caught** (it signals corrupted data, not a user error) ⚑ (Q-6).
- **1 row:** sync the caller's instance without a second read or `refresh()` (story 0064c's precedent): `setAttribute` for
  `payment_status`/`updated_at`, `syncOriginalAttributes([...])`, and `setRelation('payment', $payment)` so the returned order exposes
  the new payment; return the same instance.
- **0 rows:** change nothing on the instance; after the transaction, re-read `payment_status`/`status` once and map: row missing →
  `ModelNotFoundException`; `payment_status ≠ PendingPayment` → the already-paid refusal (**lost race**); otherwise → the cancelled refusal.
- "First mark wins": the loser writes nothing, so the first payment row (method, type, `paid_at`) and `orders.updated_at` are never rewritten.
- A query-builder `update()` fires no model events; `OrderPayment::forceCreate()` fires the `OrderPayment` model's own `creating`/`created`
  events, which have no listener today. There is no `Order`/`OrderPayment` observer; the action's docblock says so for any future author.
- **`MarkOrderAsPaid` is the only production writer** of `PaymentStatus::Paid` and of `order_payments` rows (factories/tests excepted).

### D-4 — `order_payments` table (database-expert)

Migration `<date>_create_order_payments_table.php` (`2026_10_02_…` or later), a greenfield UUID `create_*` migration shaped like
`create_refunds_table`, with a docblock justifying the decisions below. **The unmerged `2026_10_01_225052_add_paid_at_to_orders_table.php`
migration and every `orders.paid_at` reference (model property, cast, factory, docs, tests) are deleted** — never merged (PR #53), so no
down-migration of a deployed column is needed.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid (v7) PK | `HasUuids` |
| `order_id` | `CHAR(36)` FK → `orders.id`, **unique**, `restrictOnDelete()` ⚑ | one payment per order. Its unique index also serves the FK (no separate `_foreign` index) |
| `payment_method_id` | `CHAR(36)` FK → `payment_methods.id`, not nullable, `restrictOnDelete()` | the method **actually used** to pay; `orders.payment_method_id` stays the method chosen at creation and is not touched |
| `type` | `VARCHAR(20)`, not nullable, no default | cast to `App\Enums\OrderPaymentType`; unindexed (low-cardinality token, same reasoning as `orders.status`) |
| `paid_at` | `TIMESTAMP`, not nullable, no default | the click moment, second precision. Separate from `created_at` on purpose: equal today, but a future gateway may report a payment time different from the row's insert time |
| `created_at` / `updated_at` | timestamp, nullable | |

- **Delete behaviour of `order_id`: `restrictOnDelete()` (recommended) ⚑ (Q-5)** — a recorded payment is a financial fact like a refund
  (`refunds.order_item_id` restricts), so a future hard-delete of a paid order must be refused by the database rather than silently
  destroy the payment record. The alternative, `cascadeOnDelete()` (a payment as *part of* its order, like `order_items`), was considered
  and not recommended. Unreachable today either way (orders are never hard-deleted).
- **`type` as `VARCHAR(20)` + PHP enum, not a MySQL `ENUM` column** — the repo convention for every domain enum; adding `card`/`paypal`
  needs no `ALTER TABLE`. (The owner's wording was "DB/PHP enum"; this reading is ⚑ to confirm, Q-7.)
- **No `amount` column** — the whole `total` is considered paid (D-6); `refunded_amount` stays the only money counter.
- **No actor column** (`paid_by`) — out of scope; carried in Risks (the Phase 4 finding "a successful mark records no actor" stands).
- **Indexes:** exactly `primary`, `order_payments_order_id_unique` and `order_payments_payment_method_id_foreign`; no hand-written index
  (no index on `paid_at` — measured-trigger follow-up with 0082, see D-7).
- **No backfill.** Existing paid/refunded orders can only come from factories/tests. **A paid order without a payment row is valid**
  ("no recorded payment": legacy rows), so readers must not assume `payment()` is non-null for a paid order; `payment_status` on `orders`
  stays the **source of truth**. The invariant "`pending_payment` ⇒ no payment row" holds because nothing un-pays and the action writes
  both in one transaction.
- **Model `App\Models\OrderPayment`** (`HasUuids`, `HasFactory`): `@property string $id`, `string $order_id`, `string $payment_method_id`,
  `OrderPaymentType $type`, `Carbon $paid_at`, `Carbon|null $created_at/$updated_at`; `casts()`: `type` → `OrderPaymentType`, `paid_at`
  → `datetime`. **No column is mass-assignable** (empty `#[Fillable]`): every value is derived by the action and written with
  `forceCreate()`; a test pins it. Relations `order(): BelongsTo` and `paymentMethod(): BelongsTo`.
- **`Order::payment(): HasOne`** (docblock: at most one, legacy paid orders may have none). `Order` loses the `paid_at` property/cast.
- **Enum `App\Enums\OrderPaymentType: string`** — cases `Transfer = 'transfer'`, `Card = 'card'`, `Paypal = 'paypal'`; `label()` →
  `__('orders.payment.types.'.$this->value)` (the `PaymentMethodCode::label()` shape). Only `Transfer` is offered today (bank transfer is
  the only payment method); `Card`/`Paypal` exist for the future checkout and are not offered by any UI in this story or 0085.
- **Factory `OrderPaymentFactory`:** default state = a new or given order, any existing `PaymentMethod` row (`PaymentMethod::query()->value('id')`, as `OrderFactory` does; only bank transfer exists today) (reused if seeded,
  created otherwise — the same lookup `OrderFactory` uses for `payment_method_id`), `type = transfer`, `paid_at = now()`.
- **`OrderFactory::paid()`** sets `payment_status = paid` **and**, via `afterCreating`, creates the order's payment with the **order's own
  `payment_method_id`** and `type = transfer`, `paid_at = $order->created_at ?? now()` (never earlier than `created_at`) ⚑ (Q-8). Tests
  that need a legacy paid order without a payment row use an inline `->state(['payment_status' => 'paid'])`. 0082's demo seeder uses
  `paid()` and so now also creates payment rows — harmless.

### D-5 — `Order::isAwaitingPayment(): bool`

Unchanged. `payment_status === PaymentStatus::PendingPayment && status !== OrderStatus::Cancelled` — "can be marked as paid". It is the
predicate **0085 reads** to show the control, so the UI cannot drift from the action. Docblock shaped like `isRefundable()`: says nothing
about the actor. **The action reads the two clauses separately** (the two refusals differ) and must not "simplify" into this helper. It
does **not** consult `payment()` (`payment_status` is the source of truth). `isRefundable()` and `isManuallyCancellable()` are unchanged.

### D-6 — Scope boundaries (owner-confirmed where marked)

- **No undo** (owner): a wrong click has no in-app correction (the payment row cannot be deleted or edited in-app); the only path is a
  refund (and a developer fix for a never-refunded order). Recorded as a known limitation.
- **Payment moment = the moment of the click** (owner): the action takes no date parameter (pinned by a reflection test on the exact
  three-parameter signature), and `order_payments.paid_at` is written from the server clock.
- **One payment per order** (owner): no split/partial payments; a second payment row cannot exist (unique `order_id`).
- **Method actually used vs. method chosen** (owner): the payment row stores the method the caller passes; `orders.payment_method_id`
  is never changed by this action.
- **Whole `total` is considered paid**; no amount stored (PRD: manual status only).
- **`flagged_for_review` orders can be marked paid**: that flag is a tax-region ambiguity, independent of payment; blocking it would invent
  a PRD rule.
- **Data anomaly not guarded:** an order with refunds recorded but still `pending_payment` is unreachable through the app; the action
  would mark it paid without touching refunds. Accepted limitation, characterized by a test.
- **No event, no notification, no change to `status`** — the two dimensions stay independent. **Extension point:** when a consumer exists
  (e.g. a confirmation email), add `OrderMarkedPaid` carrying the order id, dispatched only after the transaction commits on the 1-row
  branch, following the `OrderFullyRefunded` precedent.

### D-7 — Dashboard follow-up (not this story)

[0082](0082-dashboard-home-overview-backend.md) dates Real income by `orders.created_at`. A later change may date it by
`order_payments.paid_at` (falling back to `orders.created_at` for legacy paid orders without a payment row); **caution recorded for it:**
the payment row survives a full refund, so "has a payment row" does not mean "currently paid" — that measure must keep filtering on
`orders.payment_status` / subtracting `refunded_amount`. An index on `order_payments.paid_at` is the measured-trigger follow-up for that
change (e.g. p95 > 200 ms or `EXPLAIN` shows a full scan on ~100k rows), not this story's.

## Rework (2026-10-02)

The first design of this story (Phase 1 2026-09-30/10-01, implemented and approved through Phases 2–6, unmerged in PR #53) stored the
payment moment in a nullable **`orders.paid_at`** column written by a single compare-and-set `UPDATE`. On 2026-10-02 the **project owner
changed the design**: the payment is a record of its own in a new **`order_payments`** table (one row per order) carrying the payment
method actually used, a payment type (`transfer`/`card`/`paypal`) and `paid_at`; the action takes the method and the type from its
caller; the write is one transaction (compare-and-set, then insert); the control will also be placed in the order create/edit section
(0085). `orders.paid_at` and its migration are dropped. Unchanged: policy (`orders.edit`), refusal guard order and messages, the logging
wrapper with an explicit order target, no logging of state refusals, no events, `isAwaitingPayment()`, lang keys `already_paid` /
`cancelled_blocked`.

**The Phase 2–6 approvals recorded in [Approval records](#approval-records) refer to the previous design** and do not carry over; the DoD
below is reset for the rework.

## Open questions closed

| Q | Resolution |
| --- | --- |
| Q-1 undo | **no** (owner); consequence recorded in D-6 |
| Q-2 payment moment | the click moment (owner), second precision (`startOfSecond()`), stored in `order_payments.paid_at` |
| Q-3 paid amount | the whole `total`; nothing stored about the amount |
| Q-R1 where the payment lives | new `order_payments` table, unique per order (owner, 2026-10-02) |
| Q-R2 method/type | supplied by the caller; dashboard passes bank transfer / `transfer` (owner, 2026-10-02) |

Still **⚑ owner to confirm:**

| Q | Item | Recommendation |
| --- | --- | --- |
| Q-4 | Must `type` be consistent with the method's `code` (`bank_transfer` ⇒ `transfer`)? | **No validation now (recommended)** — only one method and one offered type exist, so a mismatch is unreachable from the UI; add a mapping when card/paypal methods exist. Alternative: refuse a mismatched pair with a `ValidationException` |
| Q-5 | `order_payments.order_id` delete behaviour | **`restrictOnDelete()` (recommended)** — financial fact, `refunds` precedent; alternative `cascadeOnDelete()` |
| Q-6 | A unique-constraint violation on insert is rolled back and not caught | **Not caught (recommended)** — only a data anomaly reaches it |
| Q-7 | `type` stored as `VARCHAR(20)` + PHP enum rather than a MySQL `ENUM` | **`VARCHAR(20)` (recommended)** — repo convention, no `ALTER` to add cases |
| Q-8 | `OrderFactory::paid()` also creates a payment row (order's method, `transfer`) | **Yes (recommended)** — keeps factory data coherent with the production invariant; legacy rows via inline state |
| — | carried from the first design | `orders.edit` as the only ability; state refusals are not logged; already-paid reported before cancelled; flagged orders markable; the data-anomaly limitation; no actor recorded on a successful mark |

## Gherkin

"Order administrator" (an actor who may edit orders) is added to the Gherkin glossary when this story is ratified, with "payment state"
and its values (Pending payment, Paid, Partially refunded, Refunded), and "payment" (the record of how and when an order was paid).
Scenarios use the English labels; Spanish appears only in 0085's locale scenario. No code values (`pending_payment`, `payment_status`,
`transfer`) in scenarios.

```gherkin
Scenario: An order administrator marks an order awaiting payment as paid
  Given Olga, an order administrator, and an order whose payment state is Pending payment
  When Olga marks the order as paid by bank transfer
  Then the order's payment state becomes Paid
  And the order has a payment recording the moment it was paid

Scenario: The payment records the method and the type used
  Given Olga, an order administrator, and an order awaiting payment that was placed with the bank transfer method
  When Olga marks the order as paid by bank transfer
  Then the order's payment records the bank transfer method
  And the order's payment records the payment type Transfer
  And the order still references the payment method it was placed with

Scenario: An order cannot have a second payment
  Given Olga, an order administrator, and an order she has already marked as paid
  When Olga marks the order as paid by bank transfer
  Then Olga is told the order is already paid
  And the order still has exactly one payment

Scenario Outline: Marking as paid never changes the fulfilment status
  Given Olga, an order administrator, and an order with status "<status>" whose payment state is Pending payment
  When Olga marks the order as paid by bank transfer
  Then the order is still "<status>"
  Examples:
    | status     |
    | Pending    |
    | Processing |
    | Shipped    |
    | Delivered  |

Scenario Outline: An order that is already settled cannot be marked as paid again
  Given Olga, an order administrator, and an order whose payment state is <state>
  When Olga marks the order as paid by bank transfer
  Then Olga is told the order is already paid
  And the order does not change
  And no payment is recorded
  Examples:
    | state              |
    | Paid               |
    | Partially refunded |
    | Refunded           |

Scenario: A fully refunded order that was cancelled reports that it is already paid
  Given Olga, an order administrator, and a cancelled order whose payment state is Refunded
  When Olga marks the order as paid by bank transfer
  Then Olga is told the order is already paid
  And the order does not change

Scenario: A cancelled order awaiting payment cannot be marked as paid
  Given Olga, an order administrator, and a cancelled order whose payment state is Pending payment
  When Olga marks the order as paid by bank transfer
  Then Olga is told a cancelled order cannot be marked as paid
  And the order does not change
  And the order has no payment

Scenario: A super administrator is bound by the same state rules
  Given Sara, a super administrator, and a cancelled order whose payment state is Pending payment
  When Sara marks the order as paid by bank transfer
  Then Sara is told a cancelled order cannot be marked as paid
  And the order does not change

Scenario: A super administrator can mark an order as paid
  Given Sara, a super administrator, and an order whose payment state is Pending payment
  When Sara marks the order as paid by bank transfer
  Then the order's payment state becomes Paid

Scenario: An administrator who may not refund can still mark an order as paid
  Given Edu, an order administrator who may not refund orders, and an order whose payment state is Pending payment
  When Edu marks the order as paid by bank transfer
  Then the order's payment state becomes Paid

Scenario: A user who may only view orders cannot mark one as paid
  Given Nora, a user who may only view orders, and an order whose payment state is Pending payment
  When Nora tries to mark the order as paid by bank transfer
  Then Nora is refused
  And the order does not change
  And the order has no payment
  And the refusal is recorded against that order

Scenario Outline: An unauthorized user learns nothing about the order's state
  Given Nora, a user who may only view orders, and an order whose payment state is <state>
  When Nora tries to mark the order as paid by bank transfer
  Then Nora is refused as unauthorized
  And Nora is not told anything about the order's payment
  Examples:
    | state              |
    | Paid               |
    | Refunded           |
    | Pending payment    |

Scenario: A second attempt keeps the original payment
  Given Olga, an order administrator, and an order she marked as paid yesterday
  When Olga marks the order as paid by bank transfer
  Then Olga is told the order is already paid
  And the order's payment still records the moment it was paid yesterday

Scenario: A stale view cannot overwrite an earlier payment
  Given Omar, an order administrator, whose screen still shows an order awaiting payment that Olga has already marked as paid
  When Omar marks the order as paid by bank transfer
  Then Omar is told the order is already paid
  And the order keeps the single payment Olga's mark recorded

Scenario: An order cancelled while an administrator's screen was open cannot be marked as paid
  Given Olga, an order administrator, whose screen shows an order awaiting payment that Omar has since cancelled
  When Olga marks the order as paid by bank transfer
  Then Olga is told a cancelled order cannot be marked as paid
  And the order is not paid
  And the order has no payment

Scenario: A flagged order can be marked as paid
  Given Olga, an order administrator, and an order flagged for review whose payment state is Pending payment
  When Olga marks the order as paid by bank transfer
  Then the order's payment state becomes Paid
  And the order is still flagged for review

Scenario: An order paid before payments were recorded is still a paid order
  Given Olga, an order administrator, and an order whose payment state is Paid but that has no payment recorded
  When Olga marks the order as paid by bank transfer
  Then Olga is told the order is already paid
  And no payment is recorded

Scenario: A refund is refused while the order awaits payment
  Given Olga, an order administrator who may refund, and an order whose payment state is Pending payment
  When Olga records a refund on the order
  Then the refund is refused

Scenario: A paid order can be refunded
  Given Olga, an order administrator who may refund, and an order she has marked as paid
  When Olga records a refund on the order
  Then the refund is accepted

Scenario: A paid, then fully refunded order cannot be marked as paid again
  Given Olga, an order administrator who may refund, and an order she marked as paid and then fully refunded
  When Olga marks the order as paid by bank transfer
  Then Olga is told the order is already paid
  And the order still has exactly one payment
```

## Files to create/modify

Create:
- `app/Actions/Orders/MarkOrderAsPaid.php` (exists from the first design — **rewritten** to the D-1/D-3 contract)
- `app/Enums/OrderPaymentType.php`
- `app/Models/OrderPayment.php`
- `database/factories/OrderPaymentFactory.php`
- `database/migrations/<date>_create_order_payments_table.php`

Delete (first design, never merged):
- `database/migrations/2026_10_01_225052_add_paid_at_to_orders_table.php`
- `tests/Feature/Orders/PaidAtColumnTest.php` (replaced by `OrderPaymentsTableTest.php`)

Modify:
- `app/Policies/OrderPolicy.php` (`markPaid`, with the "no state clause" docblock — unchanged from the first design)
- `app/Models/Order.php` (remove `paid_at` property/cast/omitted-columns mention; add `payment(): HasOne`; keep `isAwaitingPayment()`)
- `database/factories/OrderFactory.php` (`paid()` no longer sets `paid_at`; creates the payment row per D-4)
- `lang/en/orders.php`, `lang/es/orders.php` (`payment` group: `already_paid`, `cancelled_blocked` unchanged; add `types.transfer`,
  `types.card`, `types.paypal`; 0085 adds its own keys to the same group)
- Tests (below), and the existing `OrderModelTest.php`, `OrdersLangParityTest.php` (extended)
- Docs (Phase 6): `docs/database/schema-orders/` (remove the `orders.paid_at` row and its `#[Fillable]` mention; a new `order_payments`
  section — in `items-and-refunds.md` or a new part listed in the `schema-orders.md` hub, docs-keeper's call — plus the delete-behaviour
  note), `docs/database/schema.md` (ER diagram: remove `paid_at` from `ORDERS`; new `ORDER_PAYMENTS` entity with
  `ORDERS ||--o| ORDER_PAYMENTS` and `PAYMENT_METHODS ||--o{ ORDER_PAYMENTS`), `docs/database/migrations/delete-behaviour-and-vendored.md`
  (the new restrict instance), `docs/database/schema-other/payment-methods.md` (second FK referencing `payment_methods`),
  `docs/api/orders.md` (action contract with the three parameters, the transaction, the two refusals), `docs/architecture/authorization.md`
  part for `OrderPolicy` (`markPaid` row, "state rule lives in the action", logging wrapper), the conventions UUID-model list
  (`OrderPayment`), and the Gherkin glossary ("payment")

No exception class, no seeder/permission change, no route.

## Tests to perform

Pest feature tests on MySQL, factories (inline `->state([...])` for the states the factory lacks), `RolePermissionSeeder` and
`PaymentMethodSeeder` seeded with the permission cache forgotten in `beforeEach`, `Carbon::setTestNow()` fixed. On every refusal the
`orders` row is **byte-identical** afterwards (`payment_status`, `status`, `updated_at`, `refunded_amount`, `payment_method_id`) **and the
`order_payments` row count for the order is unchanged**.

Files under `tests/Feature/Orders/`:

1. `MarkOrderAsPaidTest.php` (rewritten) — **state grid:** payment state (4) × order status (5) = 20 cells in one explicit dataset
   (pending payment × the four non-cancelled statuses → OK; pending × cancelled → cancelled refusal; every other cell → already-paid
   refusal), split into readable tests; `cancelled + refunded` → already-paid; cancelled refusal binds Super Admin; the two refusals
   are distinguishable messages/keys; happy path sets `paid` and creates **exactly one** `order_payments` row with the passed method id,
   `type = transfer` and `paid_at = now()` (raw column value via `DB::table`, then the cast round-trips); `orders.payment_method_id`
   unchanged; returned model equals the stored row and its `payment` relation is the new row; **fulfilment status and every other
   `orders` column untouched** (compare `getAttributes()` minus `payment_status`/`updated_at`) for each non-cancelled status; a second
   attempt (clock advanced) is refused and leaves the single payment row byte-identical; timezone summer and winter instants under
   `Europe/Madrid` (avoid the repeated DST hour); `payment_status` not mass-assignable on `Order`; flagged order markable and still
   flagged; refunds-but-pending anomaly characterized; **legacy paid order without a payment row** reads back fine (`payment` is null) and
   is refused, still with no row; a `pending_payment` order that already has a payment row (seeded anomaly) → the insert's unique
   violation propagates **and the `orders` update is rolled back** (still `pending_payment`) — per Q-6; **no side effects**
   (`Event`/`Notification`/`Queue` fakes record nothing order-related; no `refunds`/`order_items`/stock change); query log shows exactly
   one `UPDATE` on `orders` carrying both predicates and, on success only, one `INSERT` on `order_payments`, inside one transaction (prove it by recording `DB::transactionLevel()` inside a `DB::listen` callback for both statements and asserting it equals the level before the call plus one — `BEGIN`/`SAVEPOINT` never appear in the query log; the unique-violation test expects `Illuminate\Database\UniqueConstraintViolationException`);
   signature is exactly `(Order, PaymentMethod, OrderPaymentType)` (reflection).
2. `MarkOrderAsPaidAuthorizationTest.php` — permission matrix: none, `orders.view` only, `orders.create`/`orders.delete` only,
   `orders.refund` only → refused; `orders.edit` only (**no refund or payment-methods permission needed**) → OK; `orders.edit +
   orders.refund` → OK; Super Admin → OK; seeded Administrator role → OK; `Gate::forUser($u)->allows('markPaid', $order)` is true for an
   `orders.edit` holder for paid, cancelled and pending orders (the policy has no state clause); an actor without `orders.edit` gets the
   **same** `AuthorizationException` for paid, cancelled, pending, refunded and partially refunded orders (no state leak) and no payment
   row is written; guest refused.
3. `MarkOrderAsPaidRefusalLoggingTest.php` — `Log::spy()` + `shouldHaveReceived('warning')` with message "Privileged action refused",
   `ability === 'markPaid'`, **`target_type === 'order'` and `target_id === $order->id`** (value assertions), exactly once; a successful
   mark logs nothing; **neither state refusal logs**.
4. `MarkOrderAsPaidConcurrencyTest.php` — deterministic, no real parallelism: (C-1) two stale instances, second call refused, one payment
   row with the first call's method/type/`paid_at`, `orders.updated_at` stays the first call's; (C-2) a one-shot `Gate::after` hook that
   marks the row paid (and inserts its payment) between authorization and the transaction (assert the hook fired) → already-paid
   refusal, no second row; (C-3) the row turns `cancelled` between the pre-check and the write → cancelled refusal, never `paid +
   cancelled`, no payment row; (C-4) the interleaved writer leaves `refunded`/`partially_refunded` → already-paid; (C-5) same with a
   Super Admin.
5. `MarkOrderAsPaidRefundInteractionTest.php` — mark paid then `RecordRefund` accepted (real `RecordRefund`); before marking,
   `RecordRefund` refuses `pending_payment`; mark → full refund → auto-cancel → mark again refused as already paid, still one payment row;
   `CancelOrder` on a pending order then mark → cancelled refusal, no payment row.
6. `OrderPaymentsTableTest.php` (**replaces `PaidAtColumnTest.php`**) — schema assertions against the migrated schema
   (`Schema::hasTable`/`getColumns`/`getIndexes`/`getForeignKeys`): columns, types and nullability per D-4 in physical order; `order_id`
   unique; FKs to `orders.id` (restrict, per Q-5) and `payment_methods.id` (restrict); exactly the three indexes of D-4; a DB-level
   duplicate insert for the same `order_id` raises a unique violation; deleting a `PaymentMethod` referenced by a payment is refused;
   `orders` has **no** `paid_at` column; the migration file contains no data update; `down()` drops the table (file-content check unless the
   owner accepts DDL in a test).
7. `OrderPaymentModelTest.php` (new) — `HasUuids` UUIDv7 id; casts (`type` → `OrderPaymentType`, `paid_at` → `Carbon`); empty
   `#[Fillable]` (`create()`/`fill()` ignore every column); `order()` and `paymentMethod()` relations; `Order::payment()` returns the row or
   `null`; `OrderPaymentType` cases and values exactly `transfer`/`card`/`paypal`, `label()` keys resolve in both locales;
   `OrderPaymentFactory` default is coherent (bank transfer, `transfer`).
8. Extend `OrderModelTest.php` (fillable pin excludes `payment_status`; no `paid_at` attribute/cast remains; the `isAwaitingPayment()`
   20-cell grid as booleans; `OrderFactory::paid()` yields `paid` **with exactly one payment row using the order's `payment_method_id`,
   `type = transfer`, `paid_at ≥ created_at`**; an inline-state paid order has no payment row and is valid) and `OrdersLangParityTest.php`
   (`already_paid`, `cancelled_blocked` and the three `types.*` keys exist in both locales, are non-empty; the refusals do not interpolate
   the order number).
9. The 20-cell dataset lives in `tests/Feature/Orders/Datasets.php`; do not use `tests/Support/Orders/OrdersUi.php` (UI helper).

## Expected outcome

An administrator (or any `orders.edit` holder) can move a pending order to `paid`, recording a single payment with the method used, the
payment type and the moment, safely under concurrency; refunds and the dashboard's Real income become usable on real data; a future
checkout can record card/PayPal payments through the same action.

## Acceptance criteria

- `MarkOrderAsPaid(Order, PaymentMethod, OrderPaymentType)` is the only **production** writer of `PaymentStatus::Paid` and of
  `order_payments` (factories/tests excepted); it refuses every non-pending payment state and every cancelled order, with the guard order
  of D-1.
- Authorization is `orders.edit` through `OrderPolicy::markPaid` (no state clause, no new permission) and a refusal is **logged** with
  `ability = markPaid`, `target_type = order` and the order id; state refusals are not logged.
- The write is one transaction: a status-aware compare-and-set on `orders`, then — only on success — one `order_payments` insert; a
  concurrent second mark or cancel neither creates a second payment nor rewrites the first, nor produces `paid + cancelled`; the returned
  instance reflects the stored state including its payment.
- An order has at most one payment (unique `order_id`); the payment stores the method passed by the caller, the type and `paid_at`;
  `orders.payment_method_id` is never changed.
- `status`, `refunded_amount`, line items and stock are never touched; no event, notification or side effect.
- The migration is additive (new table only), reversible and modifies no existing row; `orders.paid_at` does not exist; no
  `OrderPayment` column is mass-assignable; a paid order without a payment row remains valid.
- `Order::isAwaitingPayment()` matches exactly what the action accepts.

## Definition of Done

- [x] Phase 1 debate recorded in this file (rework recorded 2026-10-02)
- [x] Phase 2 INVEST re-validation (`code-reviewer`) of the reworked story
- [x] `orders.paid_at` migration, cast, factory line, docs and tests removed
- [x] Tests written first (red) then green, including `OrderPaymentsTableTest` and `OrderPaymentModelTest`
- [x] **Full suite** green (unscoped) — 2026-10-02, reworked design: `tests/Unit` + `tests/Feature` in one run 5306 tests, 5301 passed, 5 skipped, 0 failed; `tests/Browser` alone 229 tests, 226 passed, 3 skipped, 0 failed
- [x] Pint (unscoped) and Larastan (unscoped, 0 errors) clean
- [x] Appsec re-audit of the new write path (transaction, unique safety net, caller-supplied method/type, mass assignment on `OrderPayment`)
- [x] Final code review (`code-reviewer`)
- [x] Docs resynced: `docs/database/schema.md` ER diagram (`ORDER_PAYMENTS` entity and its two relationships; `paid_at` removed from
      `ORDERS`), schema-orders docs, migrations delete-behaviour note, payment-methods schema part, `docs/api/orders.md`, authorization
      docs, UUID-model list, Gherkin glossary

## Risks and follow-ups

- **No undo** (owner decision): a mistaken mark has no in-app correction.
- **State refusals are not logged; only authorization refusals are** — ⚑ to ratify.
- **Payment row semantics for 0082** (D-7): survives refunds; legacy paid orders have none.
- **Unindexed `orders.created_at`/`order_payments.paid_at`** — measured-trigger follow-up (D-7).
- **Method/type consistency not validated** (Q-4) — becomes relevant when a second payment method exists.
- **Factory gap:** no `cancelled()`/`refunded()` states; tests use inline states (adding them is optional).
- **A successful mark records no actor** (Phase 4 finding, first design; still true): no log line and no `paid_by` column. For the owner
  to decide; `order_payments` is now the natural home for a future `paid_by`.
- **Test-file deviation (first design):** `MarkOrderAsPaidRefusalLoggingTest.php` is its own file instead of an extension of `RefusalLoggingTest.php`.
- **Timestamp limits:** MySQL `timestamp` shares its 2038 range with `created_at`; no new risk.
- **F-1 (Medium, Phase 4 re-audit): `order_payments` has no actor column**, unlike `refunds.refunded_by`, and a successful mark logs nothing, so no one can later answer who marked an order paid. Owner decision; cheapest to add now, while the story is unmerged.
- **F-2 (Low, Phase 4 re-audit): method/type are caller-trusted.** 0085 must validate the type with `Rule::enum(OrderPaymentType::class)`, resolve the method server-side with `findOrFail` (never a posted model) and add a method-code-to-type mapping before a second payment method ships. Noted in [0085](../0085-order-mark-as-paid-ui.md).
- **F-3 / F-4:** informational findings of the same re-audit, no action required.
- **0085 scope change:** the owner also wants the control in the order create/edit section; 0085 must be re-debated (see its note).

## Approval records

_First design (`orders.paid_at`) — superseded by the [Rework (2026-10-02)](#rework-2026-10-02); kept for history, not valid for the new design:_

- **Phase 2 (INVEST, `code-reviewer`) — APPROVED 2026-10-01.**
- **Phase 4 (security audit, `appsec-auditor`) — APPROVED 2026-10-01.** Finding carried to Risks: a successful mark records no actor.
- **Phase 5 (final code review, `code-reviewer`) — APPROVED 2026-10-01.** Full suite green 2026-10-02 (Unit + Orders + Localization
  1094/1094; other Feature folders 4170 passed, 5 skipped; Browser 226 passed, 3 skipped).
- **Phase 6 (docs)** — synced for the first design; must be redone.

_Reworked design (`order_payments`):_

- **Phase 4 re-audit (security, `appsec-auditor`) — APPROVED 2026-10-02.** Findings: F-1 Medium (no actor column on `order_payments`; owner decision), F-2 Low (0085 must use `Rule::enum`, a server-side `findOrFail` method lookup and a code-to-type mapping before a second method ships), F-3/F-4 informational. Both are carried to Risks.
- **Phase 5 re-review (final code review, `code-reviewer`) — APPROVED 2026-10-02**, after comment-only fixes (no behaviour change). The full-suite gate is still open (see Definition of Done).
- **Phase 6 (docs)** — resynced 2026-10-02 for the reworked design.

**Still open:** **⚑ Owner-to-confirm items unratified:** Q-4 to Q-8 and the carried items listed under
[Open questions closed](#open-questions-closed).

## Dependencies

- None pending (`depends_on: []`). Consumed by [0085](../0085-order-mark-as-paid-ui.md).
- Related, no blocking: [0082](0082-dashboard-home-overview-backend.md) (Real income; its demo seeder uses `OrderFactory::paid()`),
  [0038](0038-payment-methods-bank-transfer-backend.md) (payment methods catalog).

## Debate record

Facilitator: Claude (product-owner role). Participants (first design): backend-expert, backend-qa, database-expert (project agents,
read-only). Corrections made to the first draft: the claim that refusals are "logged through the project wrapper like every other order
action" was false (only the `AddOrderItem`-style actions log; `CancelOrder` does not) — the action now calls the wrapper with explicit
target arguments; the compare-and-set lacked the `status ≠ cancelled` clause and so allowed `paid + cancelled`; timestamp types are
`Carbon`, not `CarbonImmutable`; a separate exception class is unnecessary (`ValidationException` for both refusals);
`isAwaitingPayment()` must include the cancelled clause and the policy must not.

Rework (2026-10-02): owner-directed design change recorded by the product-owner from the owner's decisions; no new specialist debate
was held. The table shape, transaction ordering and the ⚑ Q-4..Q-8 recommendations are facilitator proposals for Phase 2 to challenge.

## Phase 2 re-validation record (2026-10-02)

- **`code-reviewer` first verdict: REJECTED, text corrections only** — (1) the claim that no action logs a state refusal was false (`AddOrderItem` logs `order_not_editable`/`order_item_limit_reached`); restated as split precedent and the ⚑ now names both options; (2) the `DemoDataSeeder` fact contradicted the code (it already uses `OrderFactory::paid()`). Both corrected in this file by the orchestrator; no design change.
- **Notes carried to TDD:** the existing "refused mark issues no write against orders" test must also assert no write to `order_payments`; the static "only writer" test (`MarkOrderAsPaidTest.php`) becomes "only writer of `PaymentStatus::Paid` and of `OrderPayment` creation (`create`/`forceCreate`/`DB::table('order_payments')`)"; the implementer must verify the `order_payments` index set against the migrated schema (an FK that is also unique has no precedent here: Laravel adds the FK's implicit index before `unique()`); the ~14 test files and `DemoDataSeeder` that call `OrderFactory::paid()` must still pass once it creates the payment row; for 0085's re-debate: calling the action inside an outer transaction turns its transaction into a savepoint.
- **Phase 2: APPROVED after the corrections (2026-10-02).**
