# [0090] Line quantity changes and line removal adjust stock only on request (backend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 3 of the
> order-stock series 0088–0096. Fullstack pair: **related_task_id 0094** (the dialog). Blocked on
> [0088](0088-order-stock-adjustment-foundation-backend.md) and [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md).
> 0088's OQ-1 was resolved 2026-11-08 as option (a) (0088 D8); the counter rules for a quantity change are rows of 0088's
> [per-operation table](0088-order-stock-adjustment-foundation-backend.md#counter-model-decided-d8).

## Description
`UpdateOrderItemQuantity` and `RemoveOrderItem` gain a `bool $adjustStock = false` parameter. With `false` (default) stock is
never touched, exactly as today. With `true`, a quantity increase takes the added units out of stock, a decrease returns the
removed units, and a removal returns the line's returnable units — all inside the action's existing transaction, after its
existing order-row and item-row locks, through `AdjustOrderStock`. The counter is maintained in both modes.

## Type
backend (related_task_id: 0094) | includes database-expert: no

## Verified facts that shaped the story
- Both actions already: authorize `orders.edit` (`OrderPolicy::update`) via the refusal-logging wrapper; run the hard block;
  open `DB::transaction()`; lock the `orders` row then the item row (`lockForUpdate()`).
- `UpdateOrderItemQuantity` refuses `quantity < refunded_quantity` and `quantity < 1`; `RemoveOrderItem` refuses a line with
  refunded units and the order's last line (story 0055).
- Current signatures: `UpdateOrderItemQuantity::__invoke(Order $order, string $orderItemId, int $quantity): OrderItem`,
  `RemoveOrderItem::__invoke(Order $order, string $orderItemId): void`.
- `Order::isLineItemEditable()` is true on a `Cancelled` order (story 0055 open product question).

## Decisions
- **D-1 (user D3)** — Explicit opt-in `bool $adjustStock = false`, appended as the **last** parameter (existing callers and
  tests unchanged). The default is never `true`. The parameter is enforced server-side only: no client value is trusted
  beyond this bool, and the delta is always computed from the locked rows, never from the request.
- **D-2 (user D3)** — Increase by d with `adjustStock: true` → stock `-d`; it may go negative (the UI warns, 0094; the action
  never refuses for it).
- **D-3 (user D3 + 0088 D8)** — Decrease by d with `adjustStock: true` → stock `+min(d, R)` where
  `R = OrderItem::returnableStockUnits()` = `max(0, quantity - max(refunded_quantity, stock_returned_quantity))` read on the
  locked item before the change (a legacy line returns 0; refunded-and-restocked units are never subtracted twice).
- **D-4 (user D3)** — Removal with `adjustStock: true` → stock `+R` (refunded lines cannot be removed, so `R = returnableStockUnits() = quantity - counter`).
- **D-5** — Counter updates follow 0088's table in **both** modes (with and without `adjustStock`) — otherwise a later cancel
  would return the wrong units.
- **D-6** — An unchanged quantity is a no-op for stock regardless of `adjustStock`.
- **D-7** — All existing guards keep their order and run **before** any stock change; a refused call never moves stock.
- **D-8 (user D7)** — No new permission, no `products.edit`.
- **D-9** — The stock call runs after the item write and before `RecalculateOrderTotals`, inside the same transaction; lock
  order `orders → order_items → product_variants → products` (0088 FD-7).

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

  Scenario: Lowering a quantity after a refund with stock return returns all the removed units
    Given an order administrator, with a paid order holding 5 units of "Mug" of which 2 were refunded and returned to stock, and "Mug" has 10 units in stock
    When the order administrator changes the line to 3 units and adjusts stock
    Then "Mug" has 12 units in stock

  Scenario: Units added without adjusting stock are not returned later
    Given an order administrator, with a pending order whose "Mug" line was raised from 2 to 5 units without adjusting stock
    When the order administrator changes the line back to 2 units and adjusts stock
    Then "Mug" stock is unchanged

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

  Scenario: An administrator without order editing rights cannot move stock through a line change
    Given an administrator without the orders edit permission, with a pending order holding 2 units of "Mug"
    When the administrator changes the line to 5 units and adjusts stock
    Then the action is refused as not authorized
    And "Mug" stock is unchanged
```

## Files to create/modify
- `app/Actions/Orders/UpdateOrderItemQuantity.php` — `bool $adjustStock = false`; compute delta and counter from the locked
  item; call `AdjustOrderStock` when `adjustStock` and delta ≠ 0; docblock.
- `app/Actions/Orders/RemoveOrderItem.php` — `bool $adjustStock = false`; return `R` before `$item->delete()`; docblock.
- `tests/Feature/Orders/UpdateOrderItemQuantityStockTest.php` — new.
- `tests/Feature/Orders/RemoveOrderItemStockTest.php` — new.

## Tests to perform
- [ ] Feature: every Gherkin scenario above, for both a product line and a variant line (dataset).
- [ ] Feature: counter after each operation matches 0088's table (with and without `adjustStock`), incl. clamp `min(S, new quantity)`.
- [ ] Feature (0088 D8): decrease after a refund-with-restock returns `min(d, quantity - max(refunded, counter))` — the 5/2/2 → 3 case returns 2, not 1.
- [ ] Feature (0088 D9): a line with `ordered_as_variant = true` and a null variant moves no stock in either action.
- [ ] Negative: every existing refusal (authorization, hard block, `< refunded`, `< 1`, last line, refunded line, missing item) leaves stock and counter unchanged.
- [ ] Default: calling without the parameter never touches stock (regression for every existing caller).
- [ ] Lock order: query log shows `orders` → `order_items` → stock rows.
- [ ] Regression: `UpdateOrderItemQuantityTest`, `RemoveOrderItemTest`, `HardBlockTest`, `OrderLineItemAuthorizationTest` green unchanged.

## Expected outcome
The order screen (0094) can offer "return/deduct units" or "save without changing stock" and the backend applies exactly
that choice atomically, without ever double-returning a unit.

## Acceptance criteria
- [ ] Both actions accept `adjustStock` (default `false`) as the last parameter.
- [ ] With `false`, stock never changes; the counter still follows 0088's table.
- [ ] With `true`, stock changes by the computed delta through `AdjustOrderStock`, negative allowed.
- [ ] A refused call never changes stock or the counter.
- [ ] No authorization change.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (new parameter and its rules), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088/0089 only; no UI. Negotiable: counter rows settled by 0088 D8; OQ-5 on an assumed default. Valuable: lets an administrator correct an order without corrupting inventory. Estimable/Small: two actions with one parameter each. Testable: deterministic stock/counter assertions per scenario.

## Dependencies, risks, open technical questions
**Depends on:** [0088](0088-order-stock-adjustment-foundation-backend.md), [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md)
(hard). **Blocks:** [0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md).

- **Resolved:** 0088 OQ-1 (D8) and OQ-2 (D9), user decision 2026-11-08 — nothing blocks Phase 2.
- **OQ-5 — Editing lines of a cancelled order — assumed default (a), not yet user-confirmed.** `isLineItemEditable()` is true on `Cancelled`; with this
  story, raising a cancelled order's quantity with `adjustStock` takes stock out for an order that will never ship.
  Options: **(a) keep as is (recommended)** — the admin is always asked (D3) and can pick "save without changing stock";
  the editable-cancelled question stays the separate product question 0055 recorded. (b) Refuse `adjustStock: true` on a
  cancelled order.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the backend-expert design and decisions D1, D3, D6, D7.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner); 0088 OQ-1 resolved 2026-11-08. Phase 2: pending.
