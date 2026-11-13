# [0088a] Orders deduct stock automatically when an order is created or a line is added — `AdjustOrderStock` (backend)

> **Status: Done — closed 2026-11-10 (created 2026-11-08 by splitting 0088, user decision OQ-5 option (a)).** Epic 3 follow-up bug fix, order-stock series. Suffix numbering follows the `0076a` precedent so the story
> sorts right after [0088](../done/0088-order-stock-adjustment-foundation-backend.md), on which it depends, and before 0090/0091/0092b,
> which depend on it. No UI pair (there is no order-creation screen; adding a line gets no prompt, user decision D2).

## Description
Add the transaction-bound action `App\Actions\Orders\AdjustOrderStock`, which applies signed stock deltas to products and
variants atomically, with deterministic locking, skipping lines whose catalog row no longer exists. Make `CreateOrder` and
`AddOrderItem` deduct every new line's units **automatically**, with no prompt, in the same transaction; from this story on,
new lines are written as deducted (`stock_undeducted_quantity = 0`) instead of 0088's stock-neutral lines. This story fixes
the core bug: orders finally consume inventory.

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- 0088 ships the counters, `OrderItem::stockTarget()` and the helpers, and writes new lines stock-neutral
  (`U = quantity`, `K = 0`); lines created before this story keep R = 0 forever, like legacy lines.
- **Existing catalog lock order: `products` before `product_variants`.** `CreateProduct`, `UpdateProduct`, `CreateProductVariant`
  and `SyncProductAttributeValues` all lock `products` rows before `product_variants` rows (story 0029). This story follows
  the same order, so an order write and a catalog write can never deadlock on each other.
- Existing order lock order: the line-item actions lock **`orders` → `order_items`**. `RecordRefund` is the known
  exception ([docs/api/orders.md — orders.show, "Known concurrency gap"](../../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities));
  [0092a](0092a-record-refund-lock-order-backend.md) fixes it.
- `CreateOrder` has **no stock validation** today. It writes the order and its lines inside `DB::transaction()`, within a
  retry loop for `order_number` collisions (MySQL 1062). Each retry is a **new** transaction, so a deduction made by a
  rolled-back attempt is rolled back with it.
- `AddOrderItem` **always creates a new line** and never merges into an existing one. Inside its transaction it locks the
  `orders` row first (F-4), then counts and inserts items, then calls `RecalculateOrderTotals`.
- There is **no order-creation screen** today; `CreateOrder` is reached from code paths and tests only.

## Decisions (user-confirmed)
- **D1 (from 0088)** — stock target: variant when named, else product (virtual products included); deleted catalog rows move
  nothing.
- **D2 — Automatic deduction on create / add (user decision).** `CreateOrder` and `AddOrderItem` deduct the units they create,
  in the same transaction, row-locked, with no prompt. Lock order:
  - `AddOrderItem`: `orders` lock → `products` locks (by id) → `product_variants` locks (by id) →
    `UPDATE … stock = stock + ?` → `order_items` insert (changed by D10).
  - `CreateOrder`: `orders` insert → the same stock locks and `UPDATE`s → `order_items` inserts (changed by D10).

  If a later story makes `AddOrderItem` merge into an existing line, it deducts only the added units. Insufficient stock never
  refuses the write (stock may go negative).
- **D10 — Stock rows are locked before the `order_items` insert (user decision, Phase 4 finding F-1, 2026-11-10).** The
  `order_items` FK check takes a shared lock on the referenced `products` / `product_variants` row; a later `FOR UPDATE` on the
  same row in the same transaction is a lock upgrade, and two concurrent orders for the same product deadlock (MySQL 1213).
  Both actions therefore lock and update the stock rows **before** inserting the lines, so the FK check hits rows this
  transaction already holds exclusively. No stock read is needed for race safety: the write is the relative
  `UPDATE … stock = stock + ?` under the row lock, and insufficient stock never refuses a write, so nothing depends on a
  value read earlier. Mechanism (e.g. building the line models with their ids and `order_id` before persisting them) is the
  implementer's call, as long as `AdjustOrderStock` still never writes `order_items` or `orders` and the FD-3 ownership guard holds.
- **D7 — Authorization unchanged.** `AdjustOrderStock` has **no** `Gate` call of its own. It runs only inside a caller's
  transaction that is already authorized (the same shape as `RecalculateOrderTotals`). `CreateOrder` keeps its own gate and
  `AddOrderItem` keeps `orders.edit`. No new permission, and no `products.edit` requirement.

## Technical design (backend-expert, accepted)
- **FD-1 — Signature.** `AdjustOrderStock::__invoke(Order $order, array $deltas): array`, with
  `@param list<array{order_item: OrderItem, delta: int}> $deltas` (positive = units back into stock, negative = units out)
  and `@return list<string>` = ids of the order items whose delta was actually applied. Resolved from the container.
- **FD-2 — Transaction precondition.** The first statement checks `DB::transactionLevel() > 0`, or else throws `LogicException`
  (a programming error). The caller already holds the `orders` row lock. For a `RecordRefund` stock call (0092b), that holds
  only after [0092a](0092a-record-refund-lock-order-backend.md)'s lock-order fix.
- **FD-3 — Ownership guard.** Every `order_item` must belong to `$order`, or else `LogicException`.
- **FD-4 — Aggregation.** Deltas are summed per target key (`variant:<id>` / `product:<id>`). A net-zero target is not
  written, and lines whose `stockTarget()` is `null` are skipped.
- **FD-5 — Deterministic stock-row locking.** `products` first, then `product_variants`, each locked with
  `whereIn(...)->orderBy('id')->lockForUpdate()`. A target row that no longer exists at lock time is skipped like a `null`
  target, never thrown.
- **FD-6 — Atomic write.** One `UPDATE … SET stock = stock + ?` per target (`increment('stock', $delta)`). It is **never** a
  read-modify-write, and there is no lower bound.
- **FD-7 — Project-wide lock-order convention** (docs-keeper documents it): `orders` → `products` (by id) →
  `product_variants` (by id) → `order_items` inserts (D10; this order avoids the FK shared-to-exclusive lock upgrade).
- **FD-8 — Counter ownership.** `AdjustOrderStock` moves `stock` only; each caller owns the counter rule for its operation
  (0088's counter model).
- **FD-9 — `CreateOrder` integration.**
  - Inside the transaction closure, `CreateOrder` collects the `OrderItem` models it creates (`forceCreate` returns them).
  - It passes all of them to **one** `AdjustOrderStock` call, with `delta = -quantity` each, **before** any line is inserted (D10).
  - Each line is now written with `stock_undeducted_quantity = 0` (replacing 0088's `= quantity`),
    `stock_refunded_not_out_quantity = 0` and `ordered_as_variant`.
- **FD-10 — `AddOrderItem` integration.**
  - It writes `stock_undeducted_quantity = 0` (replacing 0088's `= quantity`), `stock_refunded_not_out_quantity = 0` and
    `ordered_as_variant` in its `forceCreate`.
  - It calls `AdjustOrderStock` with `delta = -$quantity` **before** the insert (D10) and before `RecalculateOrderTotals`.
  - Every existing refusal (hard block, line ceiling, column ceiling) runs first, so a refused add never moves stock.

## Gherkin
```gherkin
Feature: Stock is deducted when order lines are created

  Scenario: Creating an order takes its units out of stock
    Given an order administrator, and the product "Mug" has 10 units in stock
    When the order administrator creates an order with 3 units of "Mug"
    Then "Mug" has 7 units in stock

  Scenario: Creating an order with a variant takes the units out of that variant
    Given an order administrator, and the "Red / M" variant of "T-shirt" has 5 units in stock
    When the order administrator creates an order with 2 units of the "Red / M" variant
    Then the "Red / M" variant has 3 units in stock
    And the "T-shirt" product's own stock is unchanged

  Scenario: Two lines of the same variant are deducted as one total
    Given an order administrator, and the "Red / M" variant has 5 units in stock
    When the order administrator creates an order with two separate lines of the "Red / M" variant, of 1 and 2 units
    Then the "Red / M" variant has 2 units in stock

  Scenario: Creating an order with more units than in stock is allowed
    Given an order administrator, and the product "Mug" has 1 unit in stock
    When the order administrator creates an order with 4 units of "Mug"
    Then the order is created
    And "Mug" has -3 units in stock

  Scenario: A refused order creation takes nothing out of stock
    Given an order administrator, and the product "Mug" has 10 units in stock
    When the order administrator creates an order whose line total exceeds the allowed amount
    Then the order is refused
    And "Mug" still has 10 units in stock

  Scenario: Adding a line to an open order takes its units out of stock
    Given an order administrator, with a pending order, and the product "Mug" has 10 units in stock
    When the order administrator adds 2 units of "Mug" to the order
    Then "Mug" has 8 units in stock
    And the new line counts both units as able to go back to stock

  Scenario: A refused line addition takes nothing out of stock
    Given an order administrator, with a shipped order, and the product "Mug" has 10 units in stock
    When the order administrator adds 2 units of "Mug" to the order
    Then the line is refused because the order can no longer be edited
    And "Mug" still has 10 units in stock

  Scenario: An administrator who may not edit orders cannot add a line or move stock
    Given an administrator who may not edit orders, with a pending order, and "Mug" has 10 units in stock
    When the administrator adds 2 units of "Mug" to the order
    Then the action is refused as not authorized
    And "Mug" still has 10 units in stock

  Scenario: A line created before automatic deduction still returns nothing
    Given an order administrator who may refund, with a pending order whose 3-unit "Mug" line was created before automatic deduction existed, and "Mug" has 10 units in stock
    When the units of that line that can go back to stock are worked out
    Then no unit of that line can go back to stock

  Scenario: A line whose variant was deleted moves no stock
    Given an order administrator, with an order holding a line of a variant that has since been deleted from the catalog
    When the order's stock is adjusted by returning that line's units
    Then no product or variant stock changes
    And the line is reported as not adjusted

  Scenario: A line whose product was deleted moves no stock
    Given an order administrator, with an order holding a line of a product that has since been deleted from the catalog
    When the order's stock is adjusted by returning that line's units
    Then no product or variant stock changes

  Scenario: A line of another order is rejected
    Given an order administrator, with two orders
    When the first order's stock is adjusted with a line that belongs to the second order
    Then the adjustment is refused as a programming error
    And no stock changes

  Scenario: Stock is never adjusted outside an order's protected write
    Given an order administrator, with an order holding a line of "Mug"
    When the order's stock is adjusted without an open database transaction
    Then the adjustment is refused as a programming error
    And no stock changes
```

## Files to create/modify
- `app/Actions/Orders/AdjustOrderStock.php` — new action (FD-1 … FD-7).
- `app/Actions/Orders/CreateOrder.php` — FD-9; docblock update.
- `app/Actions/Orders/AddOrderItem.php` — FD-10; docblock update (merge rule).
- `tests/Feature/Orders/AdjustOrderStockTest.php` — new.
- `tests/Feature/Orders/CreateOrderStockTest.php` — new.
- `tests/Feature/Orders/AddOrderItemStockTest.php` — new.
- `tests/Feature/Orders/OrderStockCountersTest.php` (from 0088) — update the create/add cases from stock-neutral to deducted
  (`U = 0`).

## Tests to perform
- [x] `AdjustOrderStock`:
  - positive and negative deltas, variant vs product target;
  - aggregation of two lines of the same target; a net-zero target writes nothing;
  - negative resulting stock allowed;
  - returns only the ids actually applied; null targets (incl. `orphanedVariant()`) and rows deleted before locking are skipped.
- [x] Negative: outside a transaction → `LogicException`; a line of another order → `LogicException`; stock untouched.
- [x] Atomicity: the write is `UPDATE … stock = stock + ?`, and the final value is correct when another write changed the row
  between the caller's read and this call.
- [x] Lock order (query log):
  - `AdjustOrderStock` locks `products` before `product_variants`, each by id;
  - **`AddOrderItem`**: `orders` lock → product/variant locks → `UPDATE stock` → `order_items` insert (D10);
  - **`CreateOrder`**: `orders` insert → stock locks → `UPDATE stock` → `order_items` inserts (D10).
- [x] `CreateOrder`: product, variant and mixed lines; same-target aggregation in **one** `AdjustOrderStock` call; negative
  stock allowed; counters `U = 0`, `K = 0` and correct `ordered_as_variant` per line.
- [x] `CreateOrder` refusals and retry: refused by validation, by the column ceiling, or by a non-1062 `QueryException` →
  stock unchanged; a forced 1062 then success → stock deducted **once**.
- [x] `AddOrderItem`: deducts, with `U = 0`, `K = 0` and the correct flag; refused by the hard block, the line ceiling, the
  column ceiling, or authorization → stock unchanged.
- [x] A line written stock-neutral by 0088 (arranged with `legacyStockUntracked()`) keeps `returnableStockUnits() === 0`.
- [x] End to end (D9): after the variant of a line created here is deleted, `stockTarget()` is `null` and `AdjustOrderStock`
  skips it.
- [x] Architecture: `AdjustOrderStock` has no `Gate`/`authorize` call and is used only from `App\Actions\Orders\*`.
- [x] Regression: `CreateOrderTest`, `AddOrderItemTest`, `HardBlockTest`, `PriceSnapshotTest`, 0088's `OrderStockCountersTest`
  (updated create/add cases) green.

## Expected outcome
Every order or added line created from now on holds its units out of the right stock row, atomically with the write, and
every later stock change in the series goes through one tested action.

## Acceptance criteria
- [x] `AdjustOrderStock` implements FD-1 … FD-7 and touches neither `order_items` nor `orders`.
- [x] `CreateOrder` and `AddOrderItem` deduct created units in their own transaction, in the FD-7 lock order, and write new
  lines with `U = 0`.
- [x] A refused or rolled-back create/add never changes stock; insufficient stock never refuses a write.
- [x] No authorization change; the full suite is green.

## Definition of Done
- [x] Tests written and green (full suite, unscoped)
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor)
- [x] Documentation updated (docs-keeper):
  - `docs/database/schema-orders/snapshots-totals-and-rules.md` — stock side effect of create / add line;
  - `docs/api/orders.md` — `AdjustOrderStock` contract, stock side effect of create/add, lock-order convention FD-7;
  - `docs/database/schema-orders/items-and-refunds.md#order_items` — new lines now written deducted (`U = 0`);
  - `ai-spec/tasks/_digests/epic-3.md`.
- [x] Acceptance criteria met

## INVEST note
- **Independent:** needs 0088 only; 0088 is safe alone, so the two can ship in separate releases.
- **Negotiable:** the merge rule for a future line merge (D2).
- **Valuable:** fixes the core bug — orders consume inventory.
- **Estimable / Small:** one new action and two action integrations.
- **Testable:** stock asserted before/after every create/add path, including refusals and the retry loop, plus query-log lock
  order.

## Dependencies, risks, open technical questions
**Depends on:** [0088](../done/0088-order-stock-adjustment-foundation-backend.md) (hard). **Blocks:**
[0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md), [0091](../0091-order-cancellation-stock-return-opt-in-backend.md),
[0092b](../in-progress/0092b-order-refund-stock-return-backend.md).

- **R-2 — Product editor lost update.** The product and variant editors save `stock` from a form value, so an editor left
  open overwrites a deduction made in the meantime. Pre-existing shape, out of scope (0088 backlog B-1).
- **R-3 — Low-stock notifications** (PRD foundations) are not implemented. This action is the natural trigger point (0088
  backlog B-2).
- **R-4 — Price read vs deduction.** `CreateOrder` reads prices without locking catalog rows; the stock lock happens only at
  the deduction step. Acceptable, because the price snapshot rule is unchanged.
- **R-5 — Stock range (Phase 4 F-2, accepted).** `stock` is a signed `INT` with no lower bound by design; summed deltas could in
  theory leave the `INT` range, which strict mode turns into a 1264 error (no silent wrap). Insider-only, low impact; no change here.
- **Open** — none.

## Debate record
- 2026-11-08 — Three Amigos (as former 0089, later part of 0088) facilitated by product-owner from the backend-expert design and
  decisions D1, D2, D7. Split out of 0088 at the fourth Phase 2 review per the user's OQ-5 decision.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner, split from 0088).
- Phase 4: appsec-auditor 2026-11-10 → changes required (F-1 Medium: FK shared lock then `FOR UPDATE` deadlock); user chose to lock stock before the insert (D10); F-2 accepted (R-5); F-3 already covered by the arch test; re-audit 2026-11-10 → **approved** (no High/Medium; F-1 fixed for product and variant lines, `AdjustOrderStock` also locks a variant line's parent product in the same products read). Notes for docs-keeper and follow-ups, none blocking: L-1 (0090/0091/0092b must call `AdjustOrderStock` before locking `order_items` FOR UPDATE, to keep the FD-7 order and avoid a cross-order gap-lock deadlock); L-2 (product/variant editors overwrite `stock` from the form, so an automatic deduction can be lost; follow-up story); I-1 (optional assertion that the applied ids equal the line count); I-2 (1213/1205 not retried, fails safe).
- Phase 2: INVEST re-review #4 2026-11-08 → rewritten, pending re-review (created by the split of 0088).
- Phase 3: TDD 2026-11-10 (backend-qa red tests, backend-expert green); tests updated after D10 (lock order) and after the Phase 5 findings L-1/L-2/L-3.
- Phase 5: code-reviewer 2026-11-10 → **approved** (Low findings L-1 stale headers, L-2 weak assertion, L-3 deleted-product end-to-end test: all fixed). Full suite unscoped, in chunks, green: Unit + Orders + Localization 1475/1475; Feature Actions…Database 2316 passed (5 skipped) of 2321; Feature Http…Products 1619/1619; Feature Providers…Users 1132/1132; Browser 276 passed (3 skipped) of 279. Pint unscoped passed; Larastan clean on the three actions.
- Phase 6: docs-keeper 2026-11-10 → `docs/api/orders.md` (AdjustOrderStock contract, FD-7/D10 lock order, forward rule for 0090/0091/0092b), `docs/database/schema-orders/{snapshots-totals-and-rules,items-and-refunds}.md`, `docs/security/model-instance-trust.md` (FK shared lock lesson), `_digests/epic-3.md`. Link and anchor check passed.
- Phase 7: closed 2026-11-10; moved to `done/`.
