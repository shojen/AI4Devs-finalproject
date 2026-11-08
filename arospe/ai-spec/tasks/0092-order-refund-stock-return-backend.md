# [0092] Refunds can return refunded units to stock, and the refund lock order is fixed (backend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 5 of the
> order-stock series 0088–0096. Fullstack pair: **related_task_id 0096** (the refund modal). Blocked on
> [0088](0088-order-stock-adjustment-foundation-backend.md) and [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md).
> 0088's OQ-1 was resolved 2026-11-08 as option (a) (0088 D8): a refund restock is also capped at the units still held out
> of inventory, `quantity - stock_returned_quantity`.

## Description
`RecordRefund` gains `array $stockReturns = []` (order item id → units to put back in stock). By default a refund moves no
stock, exactly as today. When the administrator chooses to return units, each listed line returns at most the units it is
refunding in this same call, inside the refund's transaction, through `AdjustOrderStock`, and its counter grows by the same
amount. The action also takes the `orders` row lock **before** the line rows, closing the documented deadlock with the
line-item actions.

## Type
backend (related_task_id: 0096) | includes database-expert: no

## Verified facts that shaped the story
- Current signature `RecordRefund::__invoke(Order $order, array $items, ?string $reason = null): Order`; `$items` is
  `order_item_id => quantity_to_refund`, shape-validated through `App\Concerns\OrderValidationRules`
  (`refundItemsRules()`, `refundQuantityRules()`).
- Inside its transaction it does `$order->refresh()` (no lock) and then `$order->items()->lockForUpdate()` — the order row is
  only locked implicitly by the final `UPDATE orders`. The line-item actions lock `orders` first, so the two can deadlock
  ([docs/api/orders.md, "Known concurrency gap"](../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities)).
- `OrderFullyRefunded` is dispatched **after** commit; its listener runs `AutoCancelFullyRefundedOrder`, which stays
  stock-free (0091 pins it with a test).
- Refund is gated by the bare permission `orders.refund` (user D7, unchanged).

## Decisions
- **D-1 (user D5)** — Default: no stock movement. New last parameter `array $stockReturns = []`
  (`@param array<string, int> $stockReturns`), so every existing caller is unchanged.
- **D-2 (user D5)** — Validation, all-or-nothing (a refused stock return refuses the whole refund, nothing is written):
  - shape: an array of non-negative integers (new rules in `OrderValidationRules`, e.g. `refundStockReturnRules()`);
  - every key is a line of **this** order (under the lock) **and** a key of `$items` in this call;
  - `0 <= units <= $items[key]` (the units refunded for that line in this refund);
  - **0088 D8 cap:** also `units <= OrderItem::unitsHeldOutOfStock()` = `quantity - stock_returned_quantity` (units still held
    out of inventory), read on the locked item — so units returned on cancellation, or a legacy line never deducted, cannot go
    back twice. Refused with `orders.refunds.stock_return_already_in_stock`.
  - A `0` entry is accepted and ignored.
  Errors are a `ValidationException` on the `stock_returns` key with new lang keys under `orders.refunds.stock_*`
  (en + es, identical key sets, `OrdersLangParityTest`).
- **D-3** — "All refunded units" and "partial" are UI modes only (0096); the action always receives explicit per-line units.
- **D-4 — Lock order fix.** Inside the transaction: re-read the order with `lockForUpdate()` **first**, then the items
  `orderBy('id')->lockForUpdate()`, then stock rows (0088 FD-7). The payment-state guard and every existing guard run on the
  locked rows in their current order; stock validation runs after the outstanding-units guard and before any write.
- **D-5** — Writes, in order: refunds rows + `refunded_quantity` (unchanged) → stock deltas via `AdjustOrderStock` → counter
  `+units` for applied lines (`forceFill`) → `refunded_amount` / `payment_status` (unchanged) → commit → dispatch (unchanged).
- **D-6 (user D5)** — `AutoCancelFullyRefundedOrder` unchanged and stock-free.
- **D-7 (user D7)** — No authorization change; `Gate::authorize('orders.refund')` stays the first statement.

## Gherkin
```gherkin
Feature: A refund can return the refunded units to stock

  Scenario: A refund moves no stock by default
    Given an order administrator with refund rights, with a paid order of 4 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator refunds 2 units of "Mug" without returning stock
    Then the refund is recorded
    And "Mug" still has 10 units in stock

  Scenario: Returning all refunded units to stock
    Given an order administrator with refund rights, with a paid order of 4 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then "Mug" has 12 units in stock

  Scenario: Returning only part of the refunded units to stock
    Given an order administrator with refund rights, with a paid order of 4 units of "Mug" and 2 units of the "Red / M" variant
    When the order administrator refunds 3 units of "Mug" and 2 of "Red / M", returning 1 unit of "Mug" to stock
    Then "Mug" stock rises by 1
    And the "Red / M" variant's stock is unchanged

  Scenario: Returning more units than this refund covers is refused
    Given an order administrator with refund rights, with a paid order of 4 units of "Mug"
    When the order administrator refunds 2 units of "Mug" and asks to return 3 units to stock
    Then the whole refund is refused because more units would return than are refunded
    And no refund is recorded and "Mug" stock is unchanged

  Scenario: Returning units of a line not in this refund is refused
    Given an order administrator with refund rights, with a paid order of 4 units of "Mug" and 2 units of "Plate"
    When the order administrator refunds 2 units of "Mug" and asks to return 1 unit of "Plate" to stock
    Then the whole refund is refused
    And no refund is recorded and no stock changes

  Scenario: Returning units of another order's line is refused
    Given an order administrator with refund rights, with two paid orders
    When the order administrator refunds a line of the first order and asks to return units of a line of the second order
    Then the whole refund is refused
    And no refund is recorded and no stock changes

  Scenario: Units already returned on cancellation are not returned again by a refund
    Given an order administrator with refund rights, with a paid cancelled order of 4 units of "Mug" whose units were returned on cancellation
    When the order administrator refunds 4 units of "Mug" and returns those 4 units to stock
    Then the whole refund is refused because those units are already back in stock
    And "Mug" stock is unchanged

  Scenario: A legacy line's refunded units cannot be returned to stock
    Given an order administrator with refund rights, with a paid order recorded before stock tracking existed, holding 4 units of "Mug"
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then the whole refund is refused because those units are already back in stock
    And "Mug" stock is unchanged

  Scenario: A refund of a line whose variant was deleted records the refund but moves no stock
    Given an order administrator with refund rights, with a paid order holding a line of a variant since deleted
    When the order administrator refunds 1 unit of that line and returns it to stock
    Then the refund is recorded
    And no product or variant stock changes

  Scenario: The last refunded unit still cancels the order automatically without moving more stock
    Given an order administrator with refund rights, with a paid order of 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then "Mug" has 12 units in stock
    And the order is cancelled automatically with no further stock change

  Scenario: An administrator without refund rights cannot refund or move stock
    Given an administrator without the orders refund permission, with a paid order of 4 units of "Mug"
    When the administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then the action is refused as not authorized
    And "Mug" stock is unchanged
```

## Files to create/modify
- `app/Actions/Orders/RecordRefund.php` — new parameter; order lock first (D-4); stock validation (D-2); `AdjustOrderStock`;
  counter; docblock step list renumbered.
- `app/Concerns/OrderValidationRules.php` — shape rules for `stock_returns`.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.refunds.stock_return_exceeds_refunded`, `orders.refunds.stock_return_not_in_refund`,
  `orders.refunds.stock_return_already_in_stock` (final names decided in Phase 3; identical key sets).
- `tests/Feature/Orders/RecordRefundStockTest.php` — new.
- `tests/Feature/Orders/RecordRefundLockOrderTest.php` — new (or a case in the stock test).

## Tests to perform
- [ ] Feature: every Gherkin scenario, product and variant lines; `0` entries ignored; default call unchanged.
- [ ] Validation: non-integer, negative, unknown key, key of another order, key not in `$items`, units > refunded now, (0088 D8) units > held out (cancel-then-refund, legacy line) → `ValidationException` on `stock_returns`, nothing written (refunds rows, `refunded_quantity`, `refunded_amount`, `payment_status`, stock, counter all unchanged).
- [ ] Lock order: query log shows `SELECT … FROM orders … FOR UPDATE` **before** `SELECT … FROM order_items … FOR UPDATE`, then stock rows.
- [ ] Concurrency: a refund and an `UpdateOrderItemQuantity` on the same order no longer deadlock (two-connection test per the project's existing concurrency pattern).
- [ ] After commit: `OrderFullyRefunded` still dispatched only when `Refunded`; auto-cancel moves no stock.
- [ ] Regression: `RecordRefundTest`, `ShowRefundTest`, `MarkOrderAsPaidRefundInteractionTest`, `OrdersLangParityTest` green.

## Expected outcome
The refund modal (0096) can return none, all or some of the refunded units to stock, and refunds no longer risk a deadlock
with concurrent line edits.

## Acceptance criteria
- [ ] `RecordRefund` accepts `stockReturns` (default `[]`) and moves stock only for listed lines, bounded per D-2.
- [ ] Any invalid stock return refuses the entire refund with nothing written.
- [ ] The `orders` row is locked before the line rows.
- [ ] `AutoCancelFullyRefundedOrder` remains stock-free.
- [ ] No authorization change; en/es lang key parity holds.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (RecordRefund contract; remove the "Known concurrency gap" note), `docs/database/schema-orders/items-and-refunds.md` (counter written by refunds), `docs/errors-log.md` if the deadlock is logged there, `ai-spec/tasks/_digests/epic-3.md` (0055 backlog item on lock-order inversion closed)
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088/0089 only. Negotiable: the held-out bound is settled (0088 D8); lang key names open. Valuable: returned goods go back to inventory and the documented refund deadlock is closed. Estimable: one action plus rules and lang keys. Small: the largest backend story of the series; still one action. If Phase 2 judges it too big, the lock-order fix (D-4) can split into its own story numbered before it. Testable: validation matrix, stock/counter assertions, query-log lock order.

## Dependencies, risks, open technical questions
**Depends on:** [0088](0088-order-stock-adjustment-foundation-backend.md), [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md)
(hard). **Blocks:** [0096](0096-order-refund-modal-stock-return-ui.md).
Soft: [0091](0091-order-cancellation-stock-return-opt-in-backend.md) (the cancel-then-refund scenario needs cancel-with-return to
arrange its state; if 0091 is not merged, arrange the counter directly with the factory).

- **Resolved:** 0088 OQ-1 (D8, the held-out cap) and OQ-2 (D9), user decision 2026-11-08 — nothing blocks Phase 2.
- **R-1** — Changing the lock order is a behaviour change on a hot path; the concurrency test is the guard.
- **R-2** — The refunds table does not record how many units each refund returned to stock (only the line counter does). If a
  per-refund history is wanted later, it needs a column on `refunds` — not requested (0088 B-3).

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the backend-expert design and decisions D1, D5, D6, D7.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner); 0088 OQ-1 resolved 2026-11-08. Phase 2: pending.
