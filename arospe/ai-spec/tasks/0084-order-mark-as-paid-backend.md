# [0084] Order — mark as paid manually (backend)

> **Status: Phase 1 complete (Three Amigos debate held 2026-10-01).** Ready for Phase 2 (INVEST check by `code-reviewer`, not run
> yet). Frontend companion: [0085](0085-order-mark-as-paid-ui.md), blocked on this story.
> Items marked **⚑ owner to confirm** are facilitator decisions the project owner has not explicitly ratified.
> **Owner-confirmed (2026-09-30/10-01):** no undo action; `paid_at` = the moment of the click; the control lives in the orders list
> and the order detail page only (0085).

## Description

[PRD §3.2](../../docs/PRD/sections/epic-3-customers-orders.md) says an order's payment state is **"a manual admin-set status
only"** — no payment gateway sets it, the administrator selects it by hand. The order module implements every other part of that
sentence (story [0045](done/0045-orders-core-crud-backend.md) creates orders as `pending_payment`; story
[0051](done/0051-order-payment-refund-state-backend.md) derives `refunded`/`partially_refunded` from refunds) but **nothing ever moves
an order to `paid`**: a search of `app/` on 2026-09-30 finds only `OrderFactory::paid()` writing it, and `RecordRefund` refuses any order
that is not already `paid`/`partially_refunded`, so **no real order can ever be refunded** and the dashboard's "Real income" measure
(story [0082](done/0082-dashboard-home-overview-backend.md)) has nothing to count. 0051's D-4 explicitly left a payment-state write path
as a future *decision*; this story is that decision.

It adds a single-purpose action **`MarkOrderAsPaid`** that moves an order from `pending_payment` to `paid` and records **when** in a new
nullable **`orders.paid_at`** column. The buttons that call it are story [0085](0085-order-mark-as-paid-ui.md).

## Type

`backend | includes database-expert: yes` — one additive migration (`paid_at`).

## Verified facts that shaped the story

- **No existing action logs a state refusal, and most order actions log nothing at all.** There is no `Gate::after` and no logging in
  the policy layer; the only emitter of the "Privileged action refused" warning is `LogRefusedPrivilegedAttempt` when called
  explicitly. `CancelOrder`, `TransitionOrderStatus` and `RecordRefund` use a bare `Gate::authorize()` and log **nothing**; only
  `CreateOrder` and the three line-item actions log. `LogRefusedPrivilegedAttempt::resolveTarget()` auto-resolves only `User` and
  `Role`, so an `Order` target needs explicit `targetType`/`targetId`.
- `OrderPolicy::transitionStatus` reduces to `orders.edit`; `cancel()` needs `orders.edit` **and** `orders.refund` **and** a state rule,
  and its docblock explains why a Gate-mediated state rule is inert against a Super Admin (`Gate::before`).
- The `Administrator` role holds every permission except `roles.manage-administrators`; no other default role exists.
- `Order` types its timestamps as `Carbon|null` (no immutable cast); `created_at` and every other timestamp column use plain
  `timestamp` (precision 0). The app timezone is `Europe/Madrid`.
- A full refund auto-cancels an order (story 0052), so `refunded + cancelled` is a legitimate, settled state.
- `OrderFactory` has only a `paid()` state (no `cancelled`/`refunded`/`partially_refunded` states); `DemoDataSeeder` seeds only pending orders.
- `orders.created_at` is unindexed; `docs/database/schema-orders/orders.md` documents exactly the FK indexes plus the `order_number` unique.

## Decisions

### D-1 — `App\Actions\Orders\MarkOrderAsPaid::__invoke(Order $order): Order`

One parameter (no date argument — the moment is always "now"), modelled on `CancelOrder`. Steps, each pinned by its own test:

1. **Authorize and log:** `$this->logRefusedPrivilegedAttempt->authorize('markPaid', $order, targetType: 'order', targetId: $order->id)`
   (constructor-injected `LogRefusedPrivilegedAttempt`, the `AddOrderItem` form). The permission refusal always wins and reveals nothing
   about the order's state. **This deliberately deviates from `CancelOrder`'s bare `Gate::authorize`** — it is the only way a forged
   call is refused *and recorded* — and is documented in the action's docblock.
2. **Already paid** (`payment_status ≠ PendingPayment`: paid, partially refunded, refunded) → `ValidationException::withMessages(['payment_status' => __('orders.payment.already_paid')])`.
3. **Cancelled** (`status = Cancelled`, payment still pending) → `ValidationException` on `payment_status` with
   `orders.payment.cancelled_blocked`. It is a **direct throw** (no second `Gate` check), so it also binds a Super Admin. **Guard order is
   deliberate:** already-paid is checked before cancelled, so `cancelled + refunded` reports "already paid" and
   `cancelled + pending_payment` reports "cancelled" ⚑ (pinned by named tests).
4. **Compare-and-set write (D-3).**

**No new exception class.** Both refusals are `ValidationException` (as `CancelOrder`'s already-cancelled refusal is): mark-as-paid has no
retryable sibling that needs distinguishing, and the UI catches one type. The two state refusals are **not logged** (a state refusal is
not a privilege attempt — same as `CancelOrder`) ⚑; only the authorization refusal is, and a test pins that neither state refusal logs.

### D-2 — Authorization: `OrderPolicy::markPaid(User $actor, Order $order): bool` = `hasPermissionTo('orders.edit')`

Flat, same shape as `transitionStatus`, with the unused `$order` parameter kept for the same reason. **No state clause** (unlike
`cancel()`): a state clause inside a Gate-mediated check would turn "already paid"/"cancelled" for an ordinary actor into an
`AuthorizationException`, contradicting the scenarios, and would be inert for a Super Admin anyway; the state rules live in the action,
which binds every actor. **No new permission, no seeder change, no `orders.refund` requirement** — `orders.refund` is the wrong axis (it
authorizes giving money back), and `orders.edit` reduces in practice to "Administrator" today. ⚑ Risk the owner accepts: anyone with
`orders.edit` can now move a money-state that the dashboard counts, with no undo (D-5).

### D-3 — Compare-and-set, status-aware

```php
$now = $order->freshTimestamp()->startOfSecond();          // timestamp columns are second-precision
$affected = Order::query()->whereKey($order->getKey())
    ->where('payment_status', PaymentStatus::PendingPayment->value)
    ->where('status', '!=', OrderStatus::Cancelled->value)  // closes the race with CancelOrder
    ->update(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => $now, 'updated_at' => $now]);
```

- **1 row:** sync the caller's instance without a second write or `refresh()` (story 0064c's precedent): `setAttribute` for
  `payment_status`/`paid_at`/`updated_at`, then `syncOriginalAttributes([...])`; return the same instance.
- **0 rows:** change nothing on the instance; re-read `payment_status`/`status` once and map: row missing → `ModelNotFoundException`;
  `payment_status ≠ PendingPayment` → the already-paid refusal (**lost race**); otherwise → the cancelled refusal.
- "First mark wins": the loser's `UPDATE` matches nothing, so `paid_at` and `updated_at` are never rewritten.
- **No `DB::transaction()`**: one statement, no follow-up row, no side effect.
- A query-builder `update()` fires **no model events**; today there is no `Order` observer, and the action's docblock says so for any future author.

### D-4 — `orders.paid_at` (database-expert)

`$table->timestamp('paid_at')->nullable()->after('refunded_amount');` — plain `timestamp` (precision 0 like every other nullable
timestamp in the repo), **explicit `nullable()`**, no `useCurrent()`/`useCurrentOnUpdate()` (either would stamp existing rows), `down()`
drops the column. File `<date>_add_paid_at_to_orders_table.php` (`2026_10_01_…` or later, sorted after `2026_09_21_100000`), with a
docblock justifying the decisions below in the style of `add_refunded_amount_to_orders_table`.

- **No backfill.** Existing paid/refunded rows can only come from factories/tests; `updated_at` moves on refunds and shipping, so a
  backfill would invent data. `NULL` means **"no recorded payment moment"** (pending orders, and legacy rows paid with unknown date); it
  does **not** mean "unpaid" — `payment_status` stays the source of truth, and readers must not assume `paid_at IS NOT NULL` for paid orders.
- **No CHECK constraint** (no precedent in the repo; it would reject legacy/factory rows and be coupled to refunds that keep `paid_at`
  while moving `payment_status`). The invariant "`pending_payment` ⇒ `paid_at IS NULL`" holds because nothing un-pays; the action writes
  both columns in one statement, so `paid_at` set while `pending_payment` is impossible.
- **No index now.** `created_at` is unindexed too; `COALESCE(paid_at, created_at)` (0082's possible later switch) is non-sargable, so an
  index on `paid_at` alone would not help. **Follow-up trigger:** add a composite/generated-column index, together with 0082, when a
  measured dashboard query exceeds its budget (e.g. p95 > 200 ms) or `EXPLAIN` shows a full scan on ~100k rows.
- **Model:** `@property Carbon|null $paid_at` (not `CarbonImmutable`), `'paid_at' => 'datetime'` in `casts()`, **out of `#[Fillable]`**
  and listed in the docblock's omitted columns next to `refunded_amount`; only the action writes it.
- **Factory:** `OrderFactory::paid()` also sets `paid_at` (`$attributes['created_at'] ?? now()` — never earlier than `created_at`; tests
  that compare exact values pass `paid_at` explicitly). No new factory states are required.

### D-5 — `Order::isAwaitingPayment(): bool`

`payment_status === PaymentStatus::PendingPayment && status !== OrderStatus::Cancelled` — "can be marked as paid". It is the predicate
**0085 reads** to show the control, so the UI cannot drift from the action. Docblock shaped like `isRefundable()`: says nothing about
the actor. **The action reads the two clauses separately** (the two refusals differ) and must not "simplify" into this helper.
`isRefundable()` and `isManuallyCancellable()` are unchanged.

### D-6 — Scope boundaries (owner-confirmed where marked)

- **No undo** (owner): a wrong click has no in-app correction; the only path is a refund (and a developer fix for a never-refunded
  order). Recorded as a known limitation.
- **`paid_at` = the moment of the click** (owner): the action takes no date parameter (pinned by a reflection test).
- **Whole `total` is considered paid**; no `paid_amount` column (PRD: manual status only). `refunded_amount` stays the only money counter.
- **`flagged_for_review` orders can be marked paid**: that flag is a tax-region ambiguity, independent of payment; blocking it would invent
  a PRD rule.
- **Data anomaly not guarded:** an order with refunds recorded but still `pending_payment` is unreachable through the app; the action
  would mark it paid without touching refunds. Accepted limitation, characterized by a test.
- **No event, no notification, no change to `status`** — the two dimensions stay independent. **Extension point:** when a consumer exists
  (e.g. a confirmation email), add `OrderMarkedPaid` carrying the order id, dispatched only on the **1-row** branch after the write,
  following the `OrderFullyRefunded` precedent.

### D-7 — Dashboard follow-up (not this story)

[0082](done/0082-dashboard-home-overview-backend.md) dates Real income by `orders.created_at`. A later change may switch to
`COALESCE(paid_at, created_at)`; **caution recorded for it:** `paid_at` survives a full refund, so "paid_at is set" does not mean
"currently paid" — that measure must keep filtering on `payment_status` / subtracting `refunded_amount`. 0082's demo seeder uses
`OrderFactory::paid()`, which this story extends; harmless in either merge order.

## Open questions closed

| Q | Resolution |
| --- | --- |
| Q-1 undo | **no** (owner); consequence recorded in D-6 |
| Q-2 `paid_at` | the click moment (owner), second precision (`startOfSecond()`) |
| Q-3 paid amount | the whole `total`; nothing stored about the amount |

Still **⚑ owner to confirm:** `orders.edit` as the only ability; state refusals are not logged; already-paid reported before cancelled;
flagged orders markable; the data-anomaly limitation.

## Gherkin

"Order administrator" (an actor who may edit orders) is added to the Gherkin glossary when this story is ratified, with "payment state"
and its values (Pending payment, Paid, Partially refunded, Refunded). Scenarios use the English labels; Spanish appears only in 0085's
locale scenario. No code values (`pending_payment`, `payment_status`) in scenarios.

```gherkin
Scenario: An order administrator marks an order awaiting payment as paid
  Given Olga, an order administrator, and an order whose payment state is Pending payment
  When Olga marks the order as paid
  Then the order's payment state becomes Paid
  And the order records the moment it was paid

Scenario Outline: Marking as paid never changes the fulfilment status
  Given Olga, an order administrator, and an order with status "<status>" whose payment state is Pending payment
  When Olga marks the order as paid
  Then the order is still "<status>"
  Examples:
    | status     |
    | Pending    |
    | Processing |
    | Shipped    |
    | Delivered  |

Scenario Outline: An order that is already settled cannot be marked as paid again
  Given Olga, an order administrator, and an order whose payment state is <state>
  When Olga marks the order as paid
  Then Olga is told the order is already paid
  And the order does not change
  Examples:
    | state              |
    | Paid               |
    | Partially refunded |
    | Refunded           |

Scenario: A fully refunded order that was cancelled reports that it is already paid
  Given Olga, an order administrator, and a cancelled order whose payment state is Refunded
  When Olga marks the order as paid
  Then Olga is told the order is already paid
  And the order does not change

Scenario: A cancelled order awaiting payment cannot be marked as paid
  Given Olga, an order administrator, and a cancelled order whose payment state is Pending payment
  When Olga marks the order as paid
  Then Olga is told a cancelled order cannot be marked as paid
  And the order does not change

Scenario: A super administrator is bound by the same state rules
  Given Sara, a super administrator, and a cancelled order whose payment state is Pending payment
  When Sara marks the order as paid
  Then Sara is told a cancelled order cannot be marked as paid
  And the order does not change

Scenario: A super administrator can mark an order as paid
  Given Sara, a super administrator, and an order whose payment state is Pending payment
  When Sara marks the order as paid
  Then the order's payment state becomes Paid

Scenario: An administrator who may not refund can still mark an order as paid
  Given Edu, an order administrator who may not refund orders, and an order whose payment state is Pending payment
  When Edu marks the order as paid
  Then the order's payment state becomes Paid

Scenario: A user who may only view orders cannot mark one as paid
  Given Nora, a user who may only view orders, and an order whose payment state is Pending payment
  When Nora tries to mark the order as paid
  Then Nora is refused
  And the order does not change
  And the refusal is recorded against that order

Scenario Outline: An unauthorized user learns nothing about the order's state
  Given Nora, a user who may only view orders, and an order whose payment state is <state>
  When Nora tries to mark the order as paid
  Then Nora is refused as unauthorized
  And Nora is not told anything about the order's payment
  Examples:
    | state              |
    | Paid               |
    | Refunded           |
    | Pending payment    |

Scenario: A second attempt keeps the original payment moment
  Given Olga, an order administrator, and an order she marked as paid yesterday
  When Olga marks the order as paid
  Then Olga is told the order is already paid
  And the order still records the moment it was paid yesterday

Scenario: A stale view cannot overwrite an earlier payment
  Given Omar, an order administrator, whose screen still shows an order awaiting payment that Olga has already marked as paid
  When Omar marks the order as paid
  Then Omar is told the order is already paid
  And the order keeps the moment Olga's mark recorded

Scenario: An order cancelled while an administrator's screen was open cannot be marked as paid
  Given Olga, an order administrator, whose screen shows an order awaiting payment that Omar has since cancelled
  When Olga marks the order as paid
  Then Olga is told a cancelled order cannot be marked as paid
  And the order is not paid

Scenario: A flagged order can be marked as paid
  Given Olga, an order administrator, and an order flagged for review whose payment state is Pending payment
  When Olga marks the order as paid
  Then the order's payment state becomes Paid
  And the order is still flagged for review

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
  When Olga marks the order as paid
  Then Olga is told the order is already paid
```

## Files to create/modify

Create:
- `app/Actions/Orders/MarkOrderAsPaid.php`
- `database/migrations/<date>_add_paid_at_to_orders_table.php`

Modify:
- `app/Policies/OrderPolicy.php` (`markPaid`, with the "no state clause" docblock)
- `app/Models/Order.php` (`@property Carbon|null $paid_at`, `casts()`, omitted-columns docblock, `isAwaitingPayment()`)
- `database/factories/OrderFactory.php` (`paid()` sets `paid_at`)
- `lang/en/orders.php`, `lang/es/orders.php` (new top-level `payment` group: `already_paid`, `cancelled_blocked`; 0085 adds its own keys to the same group; no collision — the existing `payment` label lives at `orders.index.columns.payment`)
- Tests (below), and the existing `OrderModelTest.php` and `RefusalLoggingTest.php` (extended)
- Docs (Phase 6): `docs/database/schema-orders/orders.md` (a `paid_at` row after `refunded_amount`; the `#[Fillable]` omitted list), `docs/database/schema.md`
  (ER diagram: `timestamp paid_at` in the `ORDERS` block — no new table), `docs/api/orders.md` (action contract and the two refusals),
  `docs/architecture/authorization.md` (`markPaid` row, the "state rule lives in the action" note, and that this action uses the logging wrapper unlike its siblings)

No exception class, no seeder/permission change, no route.

## Tests to perform

Pest feature tests on MySQL, factories (inline `->state([...])` for the states the factory lacks), `RolePermissionSeeder` seeded with the
permission cache forgotten in `beforeEach`, `Carbon::setTestNow()` fixed. On every refusal the row is **byte-identical** afterwards
(`payment_status`, `status`, `paid_at`, `updated_at`, `refunded_amount`).

Files under `tests/Feature/Orders/`:

1. `MarkOrderAsPaidTest.php` — **state grid:** payment state (4) × order status (5) = 20 cells in one explicit dataset
   (pending/payment × the four non-cancelled statuses → OK; pending × cancelled → cancelled refusal; every other cell → already-paid
   refusal), split into readable tests; `cancelled + refunded` → already-paid; cancelled refusal binds Super Admin; the two refusals
   are distinguishable messages/keys; happy path sets `paid`, `paid_at = now()` (raw column value via `DB::table`, then the cast
   round-trips), returned model equals the stored row; **fulfilment status and every other column untouched** (compare `getAttributes()`
   minus `payment_status`/`paid_at`/`updated_at`) for each non-cancelled status; `paid_at` set once and **unchanged** by a second
   attempt (clock advanced between calls); timezone summer and winter instants under `Europe/Madrid` (avoid the repeated DST hour);
   not mass-assignable (`create()`/`fill()` ignore `payment_status` and `paid_at`); flagged order markable and still flagged; refunds-but-pending anomaly characterized;
   legacy paid row with `paid_at = NULL` reads back fine and is refused; **no side effects** (`Event`/`Notification`/`Queue` fakes record
   nothing; no `refunds`/`order_items`/stock change); exactly one `UPDATE` on `orders` carrying both the payment and the cancelled
   predicates; signature has exactly one parameter (reflection).
2. `MarkOrderAsPaidAuthorizationTest.php` — permission matrix: none, `orders.view` only, `orders.create`/`orders.delete` only,
   `orders.refund` only → refused; `orders.edit` only (**no refund needed**) → OK; `orders.edit + orders.refund` → OK; Super Admin → OK;
   seeded Administrator role → OK; `Gate::forUser($u)->allows('markPaid', $order)` is true for an `orders.edit` holder for paid,
   cancelled and pending orders (the policy has no state clause); an actor without `orders.edit` gets the **same** `AuthorizationException`
   for paid, cancelled, pending, refunded and partially refunded orders (no state leak); guest refused.
3. `MarkOrderAsPaidRefusalLoggingTest.php` (or appended to `RefusalLoggingTest.php`) — `Log::spy()` + `shouldHaveReceived('warning')` with
   message "Privileged action refused", `ability === 'markPaid'`, **`target_type === 'order'` and `target_id === $order->id`** (value
   assertions, not key existence — they are `null` if the explicit arguments are forgotten), exactly once; a successful mark logs
   nothing; **neither state refusal logs**.
4. `MarkOrderAsPaidConcurrencyTest.php` — deterministic, no real parallelism: (C-1) two stale instances, second call refused and
   `paid_at`/`updated_at` stay the first call's; (C-2) a one-shot `Gate::after` hook that writes the row between authorization and the
   `UPDATE` (assert the hook fired) → already-paid refusal, row keeps the interleaved values; (C-3) the row turns `cancelled` between
   the pre-check and the write → cancelled refusal, never `paid + cancelled`, `paid_at` null; (C-4) the interleaved writer leaves
   `refunded`/`partially_refunded` → already-paid; (C-5) same with a Super Admin.
5. `MarkOrderAsPaidRefundInteractionTest.php` — mark paid then `RecordRefund` accepted (real `RecordRefund`, not mocked); before marking,
   `RecordRefund` refuses `pending_payment` (regression guard for the story's premise); mark → full refund → auto-cancel → mark again is
   refused as already paid; `CancelOrder` on a pending order then mark → cancelled refusal.
6. `PaidAtColumnTest.php` — schema assertions against the migrated schema (repo precedent: `Schema::hasColumn`/`getColumns`/`getIndexes`):
   column exists, `timestamp`, nullable, default null, sits after `refunded_amount`, **no index on it**; a default-state factory order
   has `paid_at` null; the migration file contains no data update; a `down()`/`up()` round-trip test **only if** the owner accepts DDL in a
   test (MySQL DDL implicitly commits) — otherwise a file-content check for `dropColumn('paid_at')`.
7. Extend `OrderModelTest.php` (fillable pin excludes `payment_status` and `paid_at`; `paid_at` casts to `Carbon`/`null`; the
   `isAwaitingPayment()` 20-cell grid as booleans; `OrderFactory::paid()` yields `paid` with a coherent non-null `paid_at`) and
   `OrdersLangParityTest.php` (the two new keys exist in both locales, are non-empty and do not interpolate the order number).
8. The 20-cell dataset lives in `tests/Feature/Orders/Datasets.php`; do not use `tests/Support/Orders/OrdersUi.php` (UI helper).

## Expected outcome

An administrator can move a pending order to `paid` with the moment recorded, safely under concurrency; refunds and the dashboard's
Real income become usable on real data.

## Acceptance criteria

- `MarkOrderAsPaid` is the only **production** writer of `PaymentStatus::Paid` and `paid_at` (factories/tests excepted); it refuses every
  non-pending payment state and every cancelled order, with the guard order of D-1.
- Authorization is `orders.edit` through `OrderPolicy::markPaid` (no state clause, no new permission) and a refusal is **logged** with
  `ability = markPaid`, `target_type = order` and the order id; state refusals are not logged.
- The write is a status-aware compare-and-set: a concurrent second mark or cancel neither re-writes `paid_at` nor produces
  `paid + cancelled`; the returned instance reflects the stored state.
- `status`, `refunded_amount`, line items and stock are never touched; no event, notification or side effect.
- Migration is additive, nullable, unindexed, reversible and does not modify existing rows; `paid_at` is not mass-assignable.
- `Order::isAwaitingPayment()` matches exactly what the action accepts.

## Definition of Done

- [x] Phase 1 debate recorded in this file
- [ ] Phase 2 INVEST validation (`code-reviewer`)
- [ ] Tests written first (red) then green; **full suite** green (unscoped)
- [ ] Pint (unscoped) and Larastan clean
- [ ] Appsec review (authorization, refusal logging, race, mass assignment)
- [ ] Docs synced (orders schema + ER diagram, routes/contracts, authorization, glossary row for "order administrator"/payment states)

## Risks and follow-ups

- **No undo** (owner decision): a mistaken mark has no in-app correction.
- **State refusals are not logged; only authorization refusals are** — ⚑ to ratify.
- **`paid_at` semantics for 0082** (D-7): survives refunds; legacy rows are `NULL`.
- **Unindexed `orders.created_at`/`paid_at`** — measured-trigger follow-up (D-4).
- **Factory gap:** no `cancelled()`/`refunded()` states; tests use inline states (adding them is optional).
- **Timestamp limits:** MySQL `timestamp` shares its 2038 range with `created_at`; no new risk.

## Dependencies

- None pending (`depends_on: []`). Consumed by [0085](0085-order-mark-as-paid-ui.md).
- Related, no blocking: [0082](done/0082-dashboard-home-overview-backend.md) (Real income; its demo seeder uses `OrderFactory::paid()`).

## Debate record

Facilitator: Claude (product-owner role). Participants: backend-expert, backend-qa, database-expert (project agents, read-only).
Corrections made to the earlier draft: the claim that refusals are "logged through the project wrapper like every other order action" was
false (only the `AddOrderItem`-style actions log; `CancelOrder` does not) — the action now calls the wrapper with explicit target
arguments; the compare-and-set lacked the `status ≠ cancelled` clause and so allowed `paid + cancelled`; the `paid_at` type is `Carbon`,
not `CarbonImmutable`; a separate exception class is unnecessary (`ValidationException` for both refusals); `isAwaitingPayment()` must
include the cancelled clause and the policy must not; the "stored in the application timezone" wording was dropped; the `paid()`
factory state, the index decision and the ER-diagram column line were specified.
