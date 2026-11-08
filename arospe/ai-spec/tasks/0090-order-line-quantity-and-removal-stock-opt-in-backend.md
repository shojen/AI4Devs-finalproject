# [0090] Line quantity changes **and** line removal adjust stock only on request (backend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.** Epic 3
> follow-up bug fix, order-stock series. **This story covers both operations**: changing a line's quantity
> (`UpdateOrderItemQuantity`) **and** removing a line (`RemoveOrderItem`). Fullstack pairs: **related_task_id 0094**
> (quantity dialog) and **0094a** (removal dialog). Blocked on [0088](0088-order-stock-adjustment-foundation-backend.md)
> (which now includes the automatic deduction formerly planned as 0089).
> **User-confirmed (2026-11-08):** the corrected decrease formula in 0088's
> [counter model](0088-order-stock-adjustment-foundation-backend.md#counter-model-decided-d8-corrected-2026-11-08).

## Description
`UpdateOrderItemQuantity` and `RemoveOrderItem` gain `bool $adjustStock = false`. With `false` (default) stock is never touched,
as today. With `true`, a quantity increase takes the added units out of stock, a decrease returns only the held-out units that
no longer fit in the new quantity, and a removal returns the line's returnable units — inside the action's existing
transaction, after its order-row and item-row locks, through `AdjustOrderStock`. The counter is maintained in both modes.

## Type
backend (related_task_id: 0094, 0094a) | includes database-expert: no

## Verified facts that shaped the story
- Both actions already: authorize `orders.edit` (`OrderPolicy::update`) through the refusal-logging wrapper; run the hard block;
  open `DB::transaction()`; lock the `orders` row, then the item row.
- `UpdateOrderItemQuantity` refuses `quantity < refunded_quantity` and `quantity < 1`; `RemoveOrderItem` refuses a line with
  refunded units and the order's last line (story 0055).
- Current signatures: `UpdateOrderItemQuantity::__invoke(Order $order, string $orderItemId, int $quantity): OrderItem`,
  `RemoveOrderItem::__invoke(Order $order, string $orderItemId): void`.
- `Order::isLineItemEditable()` is true on a `Cancelled` order (story 0055 open product question).

## Decisions
- **D-1 (user D3)** — Explicit `bool $adjustStock = false`, appended as the **last** parameter; the default is never `true`; the
  delta is computed from the locked rows, never from the request.
- **D-2 (user D3) — Increase by d.** With `adjustStock: true`: stock `-d` (may go negative), counter unchanged. Without: counter
  `S + d` (those units were never taken out).
- **D-3 (user decision 2026-11-08, corrected formula) — Decrease by d.** With F = `refunded_quantity`, S = counter,
  R = `returnableStockUnits()` read on the locked item **before** the change, and `newQty = quantity − d`:
  - with `adjustStock: true`: k = `max(0, R − (newQty − F))`; stock `+k`; counter `max(0, S − d + k)`;
  - with `adjustStock: false`: stock unchanged; counter `max(0, S − d)`.
  k returns only the held-out, non-refunded units that no longer fit in the new quantity, so a raised quantity that was never
  deducted is never "returned" later.
- **D-4 (user D3) — Removal.** With `adjustStock: true`: stock `+R` (refunded lines cannot be removed, so `R = quantity − S`).
  Without: stock unchanged. The row is deleted either way.
- **D-5** — An unchanged quantity is a no-op for stock and counter regardless of `adjustStock`.
- **D-6** — Every existing guard keeps its order and runs **before** any stock change; a refused call never moves stock or the
  counter.
- **D-7** — The stock call runs after the item write and before `RecalculateOrderTotals`, in the same transaction; lock order
  `orders → order_items → products → product_variants` (0088 FD-7).
- **D-8** — A line with a `null` stock target (0088 D1/D9) moves no stock in either mode; its counter still follows D-2/D-3 (it is
  kept consistent in case it is read).
- **D-9 (user D7)** — No new permission, no `products.edit`.

## Gherkin
```gherkin
Feature: Stock follows a line change only when the order administrator asks for it

  Scenario: Raising a quantity without adjusting stock leaves stock unchanged
    Given an order administrator, with a pending order holding 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator changes the line to 5 units without adjusting stock
    Then the line has 5 units
    And "Mug" still has 10 units in stock

  Scenario: Raising a quantity with stock adjustment takes the added units out
    Given an order administrator, with a pending order holding 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator changes the line to 5 units and adjusts stock
    Then "Mug" has 7 units in stock

  Scenario: Raising a quantity beyond the available stock is allowed
    Given an order administrator, with a pending order holding 2 units of "Mug", and "Mug" has 1 unit in stock
    When the order administrator changes the line to 5 units and adjusts stock
    Then the line has 5 units
    And "Mug" has -2 units in stock

  Scenario: Lowering a quantity with stock adjustment returns the removed units
    Given an order administrator, with a pending order holding 5 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator changes the line to 2 units and adjusts stock
    Then "Mug" has 13 units in stock

  Scenario: Lowering a quantity without adjusting stock leaves stock unchanged
    Given an order administrator, with a pending order holding 5 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator changes the line to 2 units without adjusting stock
    Then "Mug" still has 10 units in stock

  Scenario: Lowering a legacy line returns nothing even when stock adjustment is requested
    Given an order administrator, with an order recorded before stock tracking existed, holding 5 units of "Mug"
    When the order administrator changes the line to 2 units and adjusts stock
    Then "Mug" stock is unchanged

  Scenario: Units added without adjusting stock are not returned when the quantity goes back down
    Given an order administrator, with a pending order whose 2-unit "Mug" line was raised to 5 units without adjusting stock
    When the order administrator changes the line back to 2 units and adjusts stock
    Then "Mug" stock is unchanged

  Scenario: After raising and lowering again, a cancellation returns exactly the units first taken out
    Given an order administrator who may refund, with a pending order whose 2-unit "Mug" line was raised to 5 without adjusting stock and lowered back to 2 with stock adjustment, and "Mug" has 8 units in stock
    When the order administrator cancels the order and returns its units to stock
    Then "Mug" has 10 units in stock

  Scenario: Lowering a quantity after a refund with stock return returns only the units that no longer fit
    Given an order administrator, with a paid order holding 5 units of "Mug" of which 2 were refunded and returned to stock, and "Mug" has 10 units in stock
    When the order administrator changes the line to 3 units and adjusts stock
    Then "Mug" has 12 units in stock

  Scenario: Removing a line with stock adjustment returns its units
    Given an order administrator, with a pending order holding lines of 3 units of "Mug" and 1 unit of "Plate", and "Mug" has 10 units in stock
    When the order administrator removes the "Mug" line and adjusts stock
    Then the "Mug" line is gone
    And "Mug" has 13 units in stock

  Scenario: Removing a line without adjusting stock leaves stock unchanged
    Given an order administrator, with a pending order holding lines of 3 units of "Mug" and 1 unit of "Plate", and "Mug" has 10 units in stock
    When the order administrator removes the "Mug" line without adjusting stock
    Then "Mug" still has 10 units in stock

  Scenario: A line whose variant was deleted moves no stock
    Given an order administrator, with a pending order holding a line of a variant that has since been deleted
    When the order administrator changes that line to a lower quantity and adjusts stock
    Then the quantity changes
    And no product or variant stock changes

  Scenario: A refused quantity change moves no stock
    Given an order administrator, with a shipped order holding 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator changes the line to 5 units and adjusts stock
    Then the change is refused because the order can no longer be edited
    And "Mug" still has 10 units in stock

  Scenario: A quantity below the refunded units is refused without moving stock
    Given an order administrator, with a paid order whose 4-unit "Mug" line has 3 refunded units
    When the order administrator changes the line to 2 units and adjusts stock
    Then the change is refused because units are already refunded
    And "Mug" stock is unchanged

  Scenario: Removing an already removed line moves no stock
    Given an order administrator, with a pending order whose "Mug" line was just removed with stock adjustment
    When the order administrator removes the same line again and adjusts stock
    Then the removal is refused because the line no longer exists
    And "Mug" stock is unchanged

  Scenario: An administrator who may not edit orders cannot move stock through a line change
    Given an administrator who may not edit orders, with a pending order holding 2 units of "Mug"
    When the administrator changes the line to 5 units and adjusts stock
    Then the action is refused as not authorized
    And "Mug" stock is unchanged
```

## Files to create/modify
- `app/Actions/Orders/UpdateOrderItemQuantity.php` — `bool $adjustStock = false`; delta and counter from the locked item
  (D-2/D-3); `AdjustOrderStock` when `adjustStock` and delta ≠ 0; docblock.
- `app/Actions/Orders/RemoveOrderItem.php` — `bool $adjustStock = false`; return `R` before `$item->delete()` (D-4); docblock.
- `tests/Feature/Orders/UpdateOrderItemQuantityStockTest.php` — new.
- `tests/Feature/Orders/RemoveOrderItemStockTest.php` — new.

## Tests to perform
- [ ] Every Gherkin scenario, for a product line and a variant line (dataset).
- [ ] Counter after each operation matches 0088's corrected table, with and without `adjustStock`, incl. `max(0, S − d + k)` and `max(0, S − d)`.
- [ ] **Counter round-trip:** 2 → 5 (no adjust, counter 3) → 2 (adjust, k = 0, counter 0) → `CancelOrder` with `adjustStock: true` returns **2** (arrange the cancel through the real action once 0091 is merged; until then assert `returnableStockUnits() === 2`).
- [ ] D-3 numeric table: (Q 5, F 0, S 0 → 2: +3, S 0); (Q 5, F 0, S 5 → 2: +0, S 2); (Q 5, F 2, S 2 → 3: +2, S 2); (Q 5, F 0, S 3 → 4: +0, S 2).
- [ ] A line with `ordered_as_variant = true` and a null variant moves no stock in either action.
- [ ] Every existing refusal (authorization, hard block, `< refunded`, `< 1`, last line, refunded line, missing item) leaves stock and counter unchanged.
- [ ] A call without the parameter never touches stock or the counter's semantics for increases (regression for existing callers).
- [ ] Lock order (query log): `orders` → `order_items` → `products` → `product_variants`.
- [ ] Regression: `UpdateOrderItemQuantityTest`, `RemoveOrderItemTest`, `HardBlockTest`, `OrderLineItemAuthorizationTest` green unchanged.

## Expected outcome
The quantity dialog (0094) and the removal dialog (0094a) can offer "deduct/return units" or "save without changing stock",
and the backend applies exactly that choice atomically, never returning a unit that was not taken out and never twice.

## Acceptance criteria
- [ ] Both actions accept `adjustStock` (default `false`) as the last parameter.
- [ ] With `false`, stock never changes; the counter follows D-2/D-3.
- [ ] With `true`, stock changes by exactly D-2/D-3/D-4 through `AdjustOrderStock`; negative allowed.
- [ ] The 2 → 5 → 2 → cancel round-trip returns exactly 2 units.
- [ ] A refused call never changes stock or the counter; no authorization change.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (new parameter and its rules), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088 only; no UI. Negotiable: counter rules settled with the user (corrected formula); OQ-5 on an assumed
default. Valuable: lets an administrator correct an order without corrupting inventory. Estimable/Small: two actions, one
parameter each, one formula. Testable: deterministic stock/counter assertions per scenario plus a numeric table.

## Dependencies, risks, open technical questions
**Depends on:** [0088](0088-order-stock-adjustment-foundation-backend.md) (hard). **Blocks:**
[0094](0094-order-line-quantity-stock-dialog-ui.md), [0094a](0094a-order-line-removal-stock-dialog-ui.md).
Shares `RemoveOrderItemStockTest.php` with [0091](0091-order-cancellation-stock-return-opt-in-backend.md) — sequence or coordinate.

- **OQ-5 — Editing lines of a cancelled order — assumed default (a), not yet user-confirmed.** `isLineItemEditable()` is true on
  `Cancelled`; raising a cancelled order's quantity with `adjustStock` takes stock out for an order that will never ship.
  Options: **(a) keep as is (recommended, assumed)** — the admin is always asked (D3) and can pick "save without changing stock";
  (b) refuse `adjustStock: true` on a cancelled order.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the backend-expert design and decisions D1, D3, D6, D7.
- 2026-11-08 — Phase 2 review applied: corrected decrease formula (user decision), round-trip test, scope stated as quantity
  **and** removal.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner).
- Phase 2: INVEST review 2026-11-08: findings received → rewritten, pending re-review.
