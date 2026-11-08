# [0092b] Refunds can return refunded units to stock (backend)

> **Status: New — created 2026-11-08 by splitting former story 0092 after the Phase 2 INVEST review (user decision); pending
> review.** Epic 3 follow-up bug fix, order-stock series. Fullstack pair: **related_task_id 0096** (the refund modal).
> Blocked on [0088](0088-order-stock-adjustment-foundation-backend.md) and [0092a](0092a-record-refund-lock-order-backend.md)
> (the order-first lock order this story's stock call relies on, 0088 FD-2).

## Description
`RecordRefund` gains `array $stockReturns = []` (order item id → units to put back in stock). By default a refund moves no stock,
exactly as today. When the administrator chooses to return units, each listed line returns at most the units it is refunding in
this same call **and** at most the units still held out of inventory, inside the refund's transaction, through
`AdjustOrderStock`; its counter grows by the same amount.

## Type
backend (related_task_id: 0096) | includes database-expert: no

## Verified facts that shaped the story
- Current signature `RecordRefund::__invoke(Order $order, array $items, ?string $reason = null): Order`; `$items` is
  `order_item_id => quantity_to_refund`, shape-validated through `App\Concerns\OrderValidationRules`.
- After 0092a the transaction locks `orders` first, then `order_items`.
- `OrderFullyRefunded` is dispatched after commit; its listener runs `AutoCancelFullyRefundedOrder`, which stays stock-free (0091).

## Decisions
- **D-1 (user D5)** — Default: no stock movement. New last parameter `array $stockReturns = []`
  (`@param array<string, int> $stockReturns`); every existing caller unchanged.
- **D-2 (user D5 + 0088 D8)** — Validation, all-or-nothing (a refused stock return refuses the whole refund, nothing written):
  - shape: an array of non-negative integers (`OrderValidationRules::refundStockReturnRules()`);
  - every key is a line of **this** order (under the lock) **and** a key of `$items` in this call;
  - `units <= $items[key]` (refunded in this refund);
  - `units <= OrderItem::unitsHeldOutOfStock()` (`quantity − stock_returned_quantity`, read on the locked item) — units returned on
    cancellation, or a legacy line never deducted, cannot go back twice;
  - a `0` entry is accepted and ignored.
  Errors: `ValidationException` on `stock_returns`; lang keys `orders.refunds.stock_return_invalid`,
  `orders.refunds.stock_return_not_in_refund`, `orders.refunds.stock_return_exceeds_refunded`,
  `orders.refunds.stock_return_already_in_stock` (en + es, identical key sets).
- **D-3** — "All refunded units" and "Some units" are UI modes only (0096); the action always receives explicit per-line units.
- **D-4** — Stock validation runs after the existing outstanding-units guard and before any write. Writes, in order: refunds rows
  + `refunded_quantity` (unchanged) → `AdjustOrderStock` → counter `S + units` for applied ids (`forceFill`) → `refunded_amount` /
  `payment_status` (unchanged) → commit → dispatch (unchanged). Lines with a `null` stock target record the refund but move no stock
  and keep their counter.
- **D-5 (user D5)** — `AutoCancelFullyRefundedOrder` unchanged and stock-free.
- **D-6 (user D7)** — No authorization change; `Gate::authorize('orders.refund')` stays first.

## Gherkin
```gherkin
Feature: A refund can return the refunded units to stock

  Scenario: A refund moves no stock by default
    Given an order administrator who may refund, with a paid order of 4 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator refunds 2 units of "Mug" without returning stock
    Then the refund is recorded
    And "Mug" still has 10 units in stock

  Scenario: Returning all refunded units to stock
    Given an order administrator who may refund, with a paid order of 4 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then "Mug" has 12 units in stock

  Scenario: Returning only part of the refunded units to stock
    Given an order administrator who may refund, with a paid order of 4 units of "Mug" and 2 units of the "Red / M" variant
    When the order administrator refunds 3 units of "Mug" and 2 of "Red / M", returning 1 unit of "Mug" to stock
    Then "Mug" stock rises by 1
    And the "Red / M" variant's stock is unchanged

  Scenario: Returning more units than this refund covers is refused
    Given an order administrator who may refund, with a paid order of 4 units of "Mug"
    When the order administrator refunds 2 units of "Mug" and asks to return 3 units to stock
    Then the whole refund is refused because more units would return than are refunded
    And no refund is recorded and "Mug" stock is unchanged

  Scenario: Returning units of a line not in this refund is refused
    Given an order administrator who may refund, with a paid order of 4 units of "Mug" and 2 units of "Plate"
    When the order administrator refunds 2 units of "Mug" and asks to return 1 unit of "Plate" to stock
    Then the whole refund is refused
    And no refund is recorded and no stock changes

  Scenario: Returning units of another order's line is refused
    Given an order administrator who may refund, with two paid orders
    When the order administrator refunds a line of the first order and asks to return units of a line of the second order
    Then the whole refund is refused
    And no refund is recorded and no stock changes

  Scenario: Units already returned on cancellation are not returned again by a refund
    Given an order administrator who may refund, with a paid cancelled order of 4 units of "Mug" whose units were returned on cancellation
    When the order administrator refunds 4 units of "Mug" and returns those 4 units to stock
    Then the whole refund is refused because those units are already back in stock
    And "Mug" stock is unchanged

  Scenario: A legacy line's refunded units cannot be returned to stock
    Given an order administrator who may refund, with a paid order recorded before stock tracking existed, holding 4 units of "Mug"
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then the whole refund is refused because those units are already back in stock
    And "Mug" stock is unchanged

  Scenario: A refund of a line whose variant was deleted records the refund but moves no stock
    Given an order administrator who may refund, with a paid order holding a line of a variant since deleted
    When the order administrator refunds 1 unit of that line and returns it to stock
    Then the refund is recorded
    And no product or variant stock changes

  Scenario: The last refunded unit still cancels the order automatically without moving more stock
    Given an order administrator who may refund, with a paid order of 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then "Mug" has 12 units in stock
    And the order is cancelled automatically with no further stock change

  Scenario: An administrator who may not refund cannot refund or move stock
    Given an administrator who may not refund, with a paid order of 4 units of "Mug"
    When the administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then the action is refused as not authorized
    And "Mug" stock is unchanged
```

## Files to create/modify
- `app/Actions/Orders/RecordRefund.php` — new parameter; stock validation (D-2); `AdjustOrderStock`; counter; docblock steps.
- `app/Concerns/OrderValidationRules.php` — `refundStockReturnRules()`.
- `lang/en/orders.php`, `lang/es/orders.php` — the four `orders.refunds.stock_return_*` keys.
- `tests/Feature/Orders/RecordRefundStockTest.php` — new.

## Tests to perform
- [ ] Every Gherkin scenario, product and variant lines; `0` entries ignored; a default call unchanged.
- [ ] **Validation matrix** (each → `ValidationException` on `stock_returns`, and refunds rows, `refunded_quantity`, `refunded_amount`, `payment_status`, stock and counter all unchanged): non-integer; negative; unknown key; key of another order; key not in `$items`; units > refunded now; units > held out (cancel-then-refund); units > held out (legacy line).
- [ ] Counter = `S + units` for applied lines; unchanged for null-target lines.
- [ ] After commit: `OrderFullyRefunded` still dispatched only when `Refunded`; auto-cancel moves no stock.
- [ ] Lang parity (`OrdersLangParityTest`).
- [ ] Regression: `RecordRefundTest`, `RecordRefundLockOrderTest` (0092a), `ShowRefundTest`, `MarkOrderAsPaidRefundInteractionTest` green.

## Expected outcome
The refund modal (0096) can return none, all or some of the refunded units to stock, never more than are refunded now or still
out of inventory.

## Acceptance criteria
- [ ] `RecordRefund` accepts `stockReturns` (default `[]`) and moves stock only for listed lines, bounded per D-2.
- [ ] Any invalid stock return refuses the entire refund with nothing written.
- [ ] `AutoCancelFullyRefundedOrder` remains stock-free.
- [ ] No authorization change; en/es lang key parity holds.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (RecordRefund contract), `docs/database/schema-orders/items-and-refunds.md` (counter written by refunds), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088 and 0092a only. Negotiable: lang key names. Valuable: returned goods go back to inventory with the refund.
Estimable/Small: one parameter, one rule set, four lang keys. Testable: a full validation matrix plus stock/counter assertions.

## Dependencies, risks, open technical questions
**Depends on:** [0088](0088-order-stock-adjustment-foundation-backend.md), [0092a](0092a-record-refund-lock-order-backend.md)
(hard). **Blocks:** [0096](0096-order-refund-modal-stock-return-ui.md). Soft: [0091](0091-order-cancellation-stock-return-opt-in-backend.md)
(the cancel-then-refund case; if not merged, arrange the counter with the factory). Shares `lang/*/orders.php` with
[0094](0094-order-line-quantity-stock-dialog-ui.md), [0094a](0094a-order-line-removal-stock-dialog-ui.md) and
[0095](0095-order-cancellation-stock-dialog-ui.md).
- **R-1** — The refunds table does not record how many units each refund returned (only the line counter does); a per-refund
  history would need a column (0088 B-3) — not requested.

## Debate record
- 2026-11-08 — Three Amigos (as part of former 0092) facilitated by product-owner from the backend-expert design and decisions D1,
  D5, D6, D7, D8. Split out per the Phase 2 review and the user's decision; the lock-order fix moved to 0092a.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner, split from 0092).
- Phase 2: INVEST review 2026-11-08 (of former 0092): findings received → split and rewritten, pending re-review.
