# [0088] Order stock foundation — stock-tracking columns, counter helpers and counter maintenance, no stock movement (backend)

> **Status: Done — closed 2026-11-10 (Phase 2 approved at re-review #5).**
> Epic 3 follow-up bug fix (Orders, Payments & Fulfilment), no new PRD requirement: no order flow touches stock today
> (`CreateOrder`, `AddOrderItem`, `UpdateOrderItemQuantity`, `RemoveOrderItem`, `CancelOrder`,
> `AutoCancelFullyRefundedOrder`, `RecordRefund` — verified against `main`).
> First story of the order-stock series (0088, 0088a, 0090, 0091, 0092a, 0092b, 0093, 0094, 0094a, 0095, 0096).
>
> **History of the scope.** Former story 0089 (automatic deduction) was merged into this story at the first Phase 2 review
> (its number is retired). At the fourth review **the user decided to split it again (OQ-5 option (a), 2026-11-08)**:
> this story keeps the **counters only** and moves **no stock**; [0088a](0088a-order-stock-auto-deduct-on-create-and-add-backend.md)
> adds `AdjustOrderStock` and the automatic deduction. **This story is safe to ship on its own:** every line it creates is
> written stock-neutral (`stock_undeducted_quantity = quantity`, exactly like a legacy line), so a line created before 0088a
> can never return stock it did not take — there is no release window between the two stories.
>
> **User-confirmed decisions (2026-11-08):** OQ-1 → D8 (two counters, corrected after review #3), OQ-2 → D9, OQ-4 → keep D1
> for virtual products, OQ-5 → split. See the [counter model](#counter-model-d8-two-counters-user-confirmed-2026-11-08-corrected-after-review-3).

## Description
Add three stock-tracking columns to `order_items`:
- `stock_undeducted_quantity` (U): **non-refunded** units of the line that are not currently taken out of inventory (never
  deducted, or given back on cancellation).
- `stock_refunded_not_out_quantity` (K): **refunded** units of the line that are not out of inventory (never deducted, or put
  back in stock through a refund).
- `ordered_as_variant`: a flag that recognises a line whose variant was later deleted.

The backfill makes every existing order "stock-neutral". Add the `OrderItem` helpers that name a line's stock target, its
returnable units and the refund rule. Make every **existing** order writer keep both counters correct from the moment the
columns exist: `CreateOrder` and `AddOrderItem` write new lines stock-neutral, `RecordRefund` applies the refund rule (refunds
without restock exist today), and `UpdateOrderItemQuantity` applies the "stock not changed" rules (quantity changes exist
today). **No stock row is read or written by this story.**

## Type
backend | includes database-expert: **yes** (three new columns + data backfill migration)

## Verified facts that shaped the story
- `products.stock` and `product_variants.stock` are signed `INT NOT NULL DEFAULT 0`; a negative value is a legal
  business state ([categories-and-products.md](../../../docs/database/schema-products/categories-and-products.md),
  [variants.md](../../../docs/database/schema-products/variants.md)).
- **No stock-tracking flag exists** on `Product` or `ProductVariant`, so no line is skipped for that reason. `Product::$type` is
  `physical`/`virtual` — see [OQ-4](#oq-4--virtual-products--resolved-user-confirmed-2026-11-08).
- `Product` and `ProductVariant` have **no `SoftDeletes`**. A deleted catalog row is gone, and `order_items.product_id` /
  `product_variant_id` are `nullOnDelete()`. After its variant is deleted, a line looks exactly like a plain-product line.
  D9 resolves this.
- `OrderItem::#[Fillable]` is `product_id`, `product_variant_id`, `quantity`; every derived column is written with
  `forceFill()`/`forceCreate()` ([items-and-refunds.md#order_items](../../../docs/database/schema-orders/items-and-refunds.md#order_items)).
- `RecordRefund` already records refunds without any stock effect, and `UpdateOrderItemQuantity` already changes quantities
  without any stock effect — both happen today, so the counter rules for them must ship with the columns (D10).
- `CreateOrder` writes the order and its lines inside `DB::transaction()` (with a retry loop for `order_number` collisions);
  `AddOrderItem` always creates a new line and never merges into an existing one.

## Decisions (user-confirmed, binding for the whole series)
- **D1 — Stock target.** A line moves `product_variants.stock` when it names a variant, otherwise `products.stock`
  (**virtual products included — OQ-4, user-confirmed 2026-11-08**). When the line's variant (on a variant line) or its
  product was deleted, **nothing** is adjusted for that line, and the stock dialogs do not list it.
- **D2** — automatic deduction on create / add: **moved to [0088a](0088a-order-stock-auto-deduct-on-create-and-add-backend.md)**
  (split, user decision 2026-11-08). In this story new lines are written stock-neutral (FD-4).
- **D6 — Counters.** Two new columns, both `INT UNSIGNED NOT NULL DEFAULT 0`: `order_items.stock_undeducted_quantity` (U) and
  `order_items.stock_refunded_not_out_quantity` (K). The migration backfills every existing row with
  **`U = quantity − refunded_quantity` and `K = refunded_quantity`**: a legacy order's units were never deducted (refunded or
  not), so nothing is ever returned for it, whether it is later cancelled, re-quantified or refunded.
- **D7 — Authorization unchanged.** No new permission, no `products.edit`; every touched action keeps its own gate.
- **D8 — Returnable units, two counters (OQ-1 option (a); user-confirmed 2026-11-08 (two counters); corrected after the
  third Phase 2 review).** With Q = `quantity`, F = `refunded_quantity`, U = `stock_undeducted_quantity`,
  K = `stock_refunded_not_out_quantity`:
  - **Invariants:** `0 <= U <= Q − F`; `0 <= K <= F`.
  - **Returnable units** (non-refunded units out of inventory): R = `(Q − F) − U`.
  - **Refunded units still out of inventory:** H = `F − K`. Invariant: units actually out of inventory for the line = R + H.
  - **Every refund of f units** (any caller, with or without restock; k = units restocked by that refund, `k <= min(f, R)`
    with R taken **before** the refund): `over = max(0, f − R)`; `U' = U − over`; `K' = K + k + over`. A refund takes units
    that are out of inventory first; the `over` units it has to take from the undeducted ones move from U to K, so U keeps
    holding only non-refunded units.
  - **Refund-restock cap:** `k <= min(f, R)` with R read on the locked line before the refund.
  - This replaces D4's literal formula, the single-counter D8 and the first two-counter D8 everywhere.
  - **Why two counters:** with one counter S, "raised without deducting" and "refunded and restocked" both increased S, so the
    formula could not tell them apart (stock 10; line of 5; refund 2 without restock; raise to 7 without deducting; lower to 2
    with return → old formula stock 10, correct 8).
  - **Why the third correction:** the first two-counter version let U also hold refunded never-deducted units. Counterexample
    found by the reviewer's 300 000-sequence ledger simulation: stock 10; line of 2 → stock 8; raise to 5 without deducting
    (U 3); refund 3 without restock (F 3); raise to 7 deducting 2 → stock 6; then Q − F = 4, U = 3 → R = 1, but 2
    non-refunded units are out; lowering to 3 with return gave stock 7, correct 8. The corrected rule was re-simulated
    (300 000 random sequences, 0 mismatches against a unit-level ledger; re-run by the facilitator with the same result).
  - **Naming:** `stock_undeducted_quantity` (not "never deducted") because a cancellation with return also moves units into
    it; `stock_refunded_not_out_quantity` (not "refund returned") because it also holds refunded units that were never
    deducted.
- **D9 — Deleted-variant detection (OQ-2 option (a)).** New column `order_items.ordered_as_variant`
  (`BOOLEAN NOT NULL DEFAULT false`).
  - **Written at creation:** `true` whenever the new line names a variant (this story, FD-4).
  - **Backfill:** `true` where `product_variant_id IS NOT NULL` **or** the `product_sku` snapshot differs from the product's
    current SKU.
  - **Effect:** a line with `ordered_as_variant = true` and a `null` variant id adjusts nothing (D1).
  - **Accepted blind spot:** the backfill also flags a *legacy plain-product line whose product SKU was edited after the
    order*. That line then adjusts nothing. The impact is limited to a later quantity increase with deduction on that legacy
    line: its returnable units are 0 anyway (D6), so returns are unaffected. Accepted; pinned by a migration test.
  - **Second accepted blind spot:** a line whose variant was deleted before the upgrade **and whose variant SKU equals its
    product's SKU** is backfilled as a plain-product line (`ordered_as_variant = false`) and would move the product's stock on
    a later increase with deduction. Returns are unaffected (legacy R = 0). Accepted; pinned by a migration test.
- **D10 — The counter rules ship with the columns.** Refunds without restock and quantity changes without stock adjustment
  already exist today. If the counters only started being maintained by later stories, every refund or quantity change made
  in between would corrupt U and K. So this story implements, with **no stock movement**:
  - `RecordRefund`: the D8 refund rule with k = 0 for every refunded line (null-target lines included);
  - `UpdateOrderItemQuantity`: increase → `U + d`; decrease → `U' = max(0, U − d)` (the "stock not changed" rows);
  - `CreateOrder` / `AddOrderItem`: new lines stock-neutral (`U = quantity`, `K = 0`) until 0088a makes them deduct.
  0088a, 0090 and 0092b add the stock-moving branches; none of them changes these rules.

## Technical design (backend-expert, accepted)
- **FD-1 — Stock target helper.**
  ```php
  /** @return array{type: 'variant'|'product', id: string}|null */
  public function stockTarget(): ?array
  ```
  It is pure, with no query: `variant` when `product_variant_id` is set; `null` when `ordered_as_variant` is true and the
  variant id is null (D9); `product` when `product_id` is set on a non-variant line; `null` when the product is gone (D1).
- **FD-2 — Unit helpers** (the one copy of D8's formulas).
  - `OrderItem::returnableStockUnits(): int` = R = `(Q − F) − U`. 0090, 0091, 0094, 0094a, 0095 read it; 0092b uses it as the
    refund-restock cap (`min($items[key], returnableStockUnits())`, read before the refund).
  - `OrderItem::refundedUnitsOutOfStock(): int` = H = `F − K` (no parameter; used by the invariant tests and documentation).
  - `OrderItem::applyRefundToStockCounters(int $refunded, int $restocked): void` — the one copy of the refund rule
    (`over = max(0, f − R)`, `U − over`, `K + k + over`), called by `RecordRefund` for every refunded line before
    `refunded_quantity` is incremented; throws `LogicException` when `$restocked > min($refunded, R)`.
- **FD-3 — Columns on the model.** All three new columns stay out of `#[Fillable]` (`integer` / `boolean` casts) and are
  written with `forceFill()` / `forceCreate()`. Each caller owns the counter rule for its operation (see the table below).
- **FD-4 — Stock-neutral new lines (until 0088a).** `CreateOrder` and `AddOrderItem` write each new line with
  `stock_undeducted_quantity = quantity`, `stock_refunded_not_out_quantity = 0` and `ordered_as_variant` (true when the line
  names a variant). Nothing else in either action changes.
- **FD-5 — Factory.** `OrderItemFactory` sets `stock_undeducted_quantity` and `stock_refunded_not_out_quantity` default `0`
  (a line as it is after 0088a, i.e. fully deducted — what later stories' tests need) and `ordered_as_variant` from whether
  the factory line names a variant. New states:
  - `legacyStockUntracked()` — `stock_undeducted_quantity = quantity − refunded_quantity`,
    `stock_refunded_not_out_quantity = refunded_quantity` (also the shape this story's `CreateOrder` / `AddOrderItem` write).
    It computes both values in an `afterMaking` callback, because a plain `state()` closure does not see the final
    `quantity` / `refunded_quantity` passed to `create([...])`;
  - `orphanedVariant()` — `ordered_as_variant = true`, `product_variant_id = null`, `product_id` set.

  The factory's existing `configure()` (`afterMaking`) must not overwrite these values: `ordered_as_variant` is derived there as
  `ordered_as_variant || product_variant_id !== null` (or set in the `forVariant()` state), never reset to `false`, so
  `orphanedVariant()` keeps its `true`.
- **FD-5b — `DemoDataSeeder`.** `database/seeders/DemoDataSeeder.php` creates its order lines through the factory without ever
  deducting stock; it uses `->legacyStockUntracked()` so no seeded line can later return stock it never took.
- **FD-6 — `RecordRefund` counter maintenance (D10).** Inside its existing transaction, for every refunded line (null-target
  lines included), before incrementing `refunded_quantity`: `applyRefundToStockCounters($quantityToRefund, 0)`; persisted with
  the same `forceFill()->save()` as `refunded_quantity`. No stock movement, no new parameter, no new refusal.
- **FD-7 — `UpdateOrderItemQuantity` counter maintenance (D10).** Inside its existing transaction, after the existing guards:
  increase by d → `U + d`; decrease by d → `max(0, U − d)`; persisted with the same `forceFill()->save()` as `quantity`. No
  stock movement, no new parameter.

## Counter model (D8, two counters, user-confirmed 2026-11-08, corrected after review #3)
- **Meaning:** Q = `quantity`, F = `refunded_quantity`, U = `stock_undeducted_quantity` (non-refunded units not out of
  inventory), K = `stock_refunded_not_out_quantity` (refunded units not out of inventory). Invariants: `0 <= U <= Q − F`,
  `0 <= K <= F`.
- **Derived:** R = `(Q − F) − U` (returnable); H = `F − K` (refunded, still out). Units out of inventory = R + H.
- **Per operation** (d = units removed or added, `newQty` = quantity after the change; a decrease never goes below F; f =
  units refunded, k = units restocked by that refund, R read before the operation):

  | Operation | Stock delta | U after | K after |
  | --- | --- | --- | --- |
  | Create order / add line, before 0088a (this story) | none | `quantity` | `0` |
  | Create order / add line, after 0088a | `-quantity` | `0` | `0` |
  | Quantity +d, admin deducts (0090) | `-d` | `U` | `K` |
  | Quantity +d, stock not changed (this story, D10) | none | `U + d` | `K` |
  | Quantity −d, admin returns units (0090) | `+k`, `k = max(0, d − U)` (= `R − (newQty − F)`) | `max(0, U − d)` | `K` |
  | Quantity −d, stock not changed (this story, D10) | none | `max(0, U − d)` | `K` |
  | Remove line, admin returns units (0090; only lines with F = 0) | `+R` (= Q − U) | row deleted | — |
  | Cancel, admin returns units (0091) | `+R` per line | `U + R` (= Q − F) | `K` |
  | Cancel, admin does not change stock (0091) | none | `U` | `K` |
  | **Refund f, any caller** (this story with k = 0; 0092b with k > 0) | `+k`, `k <= min(f, R)` | `U − over`, `over = max(0, f − R)` | `K + k + over` |
  | Automatic cancellation after a full refund | none | `U` | `K` |

  A decrease removes undeducted units first, so a raise that was never deducted is never "returned"; both decrease rows give
  `U' = max(0, U − d)`. A line with a `null` stock target never moves stock; its counters still follow every row above (the
  refund rule runs for every refunded line), except a cancellation, which updates only the lines `AdjustOrderStock` reports
  as applied. Either way such a line can never move stock again. A line created before 0088a has R = 0 (U = Q), so nothing is
  ever returned for it, exactly like a legacy line.

- **Every row re-verified** (stock of "Mug" starts at 10 unless stated; lines are deducted at creation, i.e. after 0088a,
  unless stated; the reviewer's simulation scripts were re-run with 0 mismatches):
  1. **Round trip:** line of 2 (stock 8, U 0) → raise to 5 without deducting (U 3) → lower to 2 with return: k =
     `max(0, 3 − 3) = 0`, U 0 → cancel with return: R = 2 → stock 10. ✔
  2. **Single-counter failing sequence:** line of 5 (stock 5) → refund 2 without restock (R 5, over 0; F 2, K 0) → raise to 7
     without deducting (U 2) → lower to 2 with return: k = `max(0, 5 − 2) = 3` → **stock 8** ✔; U 0; R 0, H 2. ✔
  3. **Review #3 counterexample:** line of 2 (stock 8) → raise to 5 without deducting (U 3) → refund 3 without restock
     (R 2, over 1; U 2, F 3, K 1) → raise to 7 deducting 2 (stock 6) → R = `(7 − 3) − 2 = 2` ✔ → lower to 3 with return:
     k = `max(0, 4 − 2) = 2` → **stock 8** ✔; U 0; Q 3, F 3 → R 0, H `3 − 1 = 2`: 2 units out (10 − 8). ✔
  4. **Legacy variant:** legacy line of 3 (backfill U 3, K 0; stock 7) → refund 3 without restock (R 0, over 3; U 0, F 3,
     K 3) → raise to 4 deducting 1 (stock 6) → R = `1 − 0 = 1` ✔ → lower to 3 with return: k = `max(0, 1 − 0) = 1` →
     **stock 7** ✔.
  5. **Refund with restock, then lower:** line of 5 (stock 5) → refund 2 returning 2 (R 5, k 2, over 0; F 2, K 2, stock 7)
     → lower to 3 with return: k = `max(0, 2 − 0) = 2` → stock 9; R 1, H 0; 1 unit out. ✔
  6. **Refund then cancel:** a partially refunded order cannot be cancelled; a fully refunded order before the automatic
     cancellation has Q − F = 0 → R = 0 → nothing returned. ✔
  7. **Refund after cancel:** line of 4 (stock 6) → cancel with return (R 4, U 4, stock 10) → refund 4 asking to return 4:
     cap `min(4, R = 0) = 0` → refused; the same refund without restock: over 4 → U 0, K 4; R 0, H 0. ✔
  8. **Legacy line, no refund:** U = Q, K = 0 → R 0, H 0 → nothing ever returns. Legacy line with F refunded: U = Q − F,
     K = F → R 0, H 0. ✔
  9. **Raise without deducting, then refund with restock:** line of 2 (stock 8) → raise to 5 without deducting (U 3) →
     refund 3 returning 3: cap `min(3, R = 2) = 2` → only 2 may return (stock 10); over 1 → U 2, K `0 + 2 + 1 = 3`; R 0, H 0. ✔
  10. **Increase with deduction, then cancel:** line of 2 (stock 8) → raise to 5 deducting (stock 5) → cancel with return:
      R 5 → stock 10. ✔
  11. **Line created before 0088a (this story alone):** line of 3 written stock-neutral (U 3; stock 10, nothing deducted) →
      refund 1 without restock (R 0, over 1; U 2, K 1) → cancel with return after 0091 ships: R = `(3 − 1) − 2 = 0` → stock
      10. ✔

## Gherkin
```gherkin
Feature: Order lines track which units are out of stock

  Scenario: Legacy order lines are marked as never deducted
    Given an order administrator, with an order recorded before stock tracking existed, holding a line of 3 units
    When the stock-tracking upgrade is applied to the store
    Then that line counts all 3 units as not taken out of inventory

  Scenario: A line whose variant was deleted before the upgrade is recognised as a variant line
    Given an order administrator, with an order recorded before stock tracking existed, holding a line of a variant that was deleted before the upgrade
    When the stock-tracking upgrade is applied to the store
    Then that line is recognised as a variant line that moves no stock

  Scenario: A line created before automatic deduction exists never claims units out of stock
    Given an order administrator, and the product "Mug" has 10 units in stock
    When the order administrator creates an order with 3 units of "Mug"
    Then "Mug" still has 10 units in stock
    And the new line counts all 3 units as not taken out of inventory

  Scenario: A line added to an open order is also stock-neutral for now
    Given an order administrator, with a pending order, and the product "Mug" has 10 units in stock
    When the order administrator adds 2 units of "Mug" to the order
    Then "Mug" still has 10 units in stock
    And the new line counts both units as not taken out of inventory

  Scenario: Raising a quantity keeps the added units counted as not taken out
    Given an order administrator, with a pending order whose 2-unit "Mug" line was deducted at creation
    When the order administrator changes the line to 5 units
    Then no stock changes
    And the line counts 2 units as able to go back to stock

  Scenario: A refund without restock keeps the line's counts of units out of stock right
    Given an order administrator who may refund, with a paid order whose 2-unit "Mug" line, deducted at creation, was raised to 5 units without deducting
    When the order administrator refunds 3 units of "Mug" without returning stock
    Then no non-refunded unit of that line is counted as out of stock
    And the 2 refunded units that were taken out are counted as still out of stock

  Scenario: A line raised with deduction after such a refund counts the newly deducted units as returnable
    Given an order administrator, with a paid order whose 2-unit "Mug" line was raised to 5 without deducting, refunded 3 units without returning stock, and then raised to 7 deducting the 2 added units
    When the order administrator reviews the stock counts of that line
    Then 2 non-refunded units can go back to stock
    And 2 refunded units are still out of stock

  Scenario: A legacy line refunded and then raised with deduction counts only the new unit as returnable
    Given an order administrator, with an order recorded before stock tracking existed whose 3-unit "Mug" line was fully refunded and then raised to 4 deducting the added unit
    When the order administrator reviews the stock counts of that line
    Then 1 non-refunded unit can go back to stock
    And no refunded unit is counted as out of stock

  Scenario: A refused refund leaves the counts unchanged
    Given an order administrator who may refund, with an order still awaiting payment
    When the order administrator tries to refund 1 unit of "Mug"
    Then the refund is refused
    And the line's counts of units out of stock are unchanged
```

## Files to create/modify
- `database/migrations/<timestamp>_add_stock_tracking_columns_to_order_items_table.php` — new (database-expert). In `up()`:
  - `boolean('ordered_as_variant')->default(false)->after('product_variant_id')`.
  - `unsignedInteger('stock_undeducted_quantity')->default(0)->after('refunded_quantity')`.
  - `unsignedInteger('stock_refunded_not_out_quantity')->default(0)->after('stock_undeducted_quantity')`.
  - Backfill, for every row: `stock_undeducted_quantity = quantity − refunded_quantity`, `stock_refunded_not_out_quantity =
    refunded_quantity` (one `UPDATE … SET … = …` statement).
  - Backfill `ordered_as_variant` (D9) with `UPDATE order_items LEFT JOIN products … SET ordered_as_variant = true WHERE
    product_variant_id IS NOT NULL OR (products.id IS NOT NULL AND order_items.product_sku <> products.sku)`.

  `down()` drops the three columns. Follow [docs/database/migrations.md](../../../docs/database/migrations.md).
- `app/Models/OrderItem.php` — `@property int $stock_undeducted_quantity`, `@property int $stock_refunded_not_out_quantity`,
  `@property bool $ordered_as_variant`, casts, `stockTarget()`, `returnableStockUnits()`, `refundedUnitsOutOfStock()`,
  `applyRefundToStockCounters()`; all three columns stay out of `#[Fillable]`.
- `app/Actions/Orders/CreateOrder.php` — FD-4 (stock-neutral new lines, `ordered_as_variant`); docblock update.
- `app/Actions/Orders/AddOrderItem.php` — FD-4; docblock update.
- `app/Actions/Orders/RecordRefund.php` — FD-6: counter maintenance for every refunded line (no stock movement); docblock step.
- `app/Actions/Orders/UpdateOrderItemQuantity.php` — FD-7: counter maintenance on increase/decrease (no stock movement).
- `database/factories/OrderItemFactory.php` — FD-5.
- `database/seeders/DemoDataSeeder.php` — FD-5b (`->legacyStockUntracked()` on seeded lines).
- `tests/Feature/Orders/DemoDataSeederStockTest.php` — new: seeded order lines have `returnableStockUnits() === 0`
  (extend an existing seeder test instead if one already covers `DemoDataSeeder`).
- `tests/Feature/Orders/OrderStockMigrationTest.php` — new.
- `tests/Feature/Orders/OrderStockCountersTest.php` — new (create/add/refund/quantity counter maintenance, sequences, property test).
- `tests/Feature/Orders/OrderModelTest.php` — extend with the helper cases.

## Tests to perform
- [ ] Migration: existing rows get `stock_undeducted_quantity = quantity − refunded_quantity` and
  `stock_refunded_not_out_quantity = refunded_quantity` (rows with and without refunds); a later row defaults both to `0`;
  columns `unsigned`, `NOT NULL`; after the backfill every row has `returnableStockUnits() = 0` and `refundedUnitsOutOfStock() = 0`.
- [ ] Migration (D9 backfill): `ordered_as_variant` is
  - true for a row with a variant id;
  - true for a row with a null variant id whose SKU snapshot differs from its product's SKU;
  - false for a plain-product row with a matching SKU;
  - false for a row whose product was deleted;
  - **true for a legacy plain-product row whose product SKU was edited later** (accepted blind spot, pinned);
  - **false for a row whose variant was deleted and whose variant SKU equalled the product SKU** (second accepted blind spot, pinned).
- [ ] Unit: `stockTarget()` — variant line → variant; product line → product; deleted product → `null`; `orphanedVariant()` → `null`.
- [ ] Unit (D8): `returnableStockUnits()` = `(Q − F) − U` and `refundedUnitsOutOfStock()` = `F − K` on: factory line (Q, 0);
  legacy without refunds (0, 0); legacy with F refunded (0, 0); stock-neutral new line (0, 0).
- [ ] Unit (D8): `applyRefundToStockCounters(f, k)` — R before; `over = max(0, f − R)`; U − over; K + k + over; refuses
  `k > min(f, R)`; keeps `0 <= U <= Q − F` and `K <= F`. Cases: R 5, f 2, k 0 → U, K+0; R 2 (U 3, Q 5), f 3, k 0 → U 2, K 1;
  R 0 (after cancel, U 4), f 4, k 0 → U 0, K 4; R 2, f 3, k 2 → U 2, K 3.
- [ ] `CreateOrder` / `AddOrderItem` (FD-4): no stock row read or written (query log); every new line has U = quantity, K = 0
  and the correct `ordered_as_variant`; refused or rolled-back writes leave no line; existing results unchanged.
- [ ] `RecordRefund` (FD-6): every refunded line's counters follow the rule (null-target lines included); no stock change;
  refused refunds leave the counters unchanged; existing refund behaviour and results unchanged.
- [ ] `UpdateOrderItemQuantity` (FD-7): increase → U + d; decrease → `max(0, U − d)`; no stock change; existing refusals
  leave the counters unchanged.
- [ ] **Sequence tests** — through the real actions this story touches (refund without restock, quantity changes), with the
  deducted starting lines and the deducting steps arranged with the factory (deduction ships in 0088a/0090), asserting the
  helpers:
  - the review #3 counterexample: after line of 2 (deducted) → raise to 5 → refund 3 → (arranged) raise to 7 deducting 2,
    `returnableStockUnits() === 2` and `refundedUnitsOutOfStock() === 2` (0090 asserts the final stock 8 through its action);
  - the legacy variant: legacy line of 3 → refund 3 → (arranged) raise to 4 deducting 1 → `returnableStockUnits() === 1`;
  - sequence 11 (a stock-neutral line refunded then cancelled returns nothing);
  - every other numbered sequence of the counter model, at helper level.
- [ ] Invariant property test (seeded random sequences of create / raise / lower / refund / cancel with a unit-level ledger,
  mirroring the reviewer's simulation, small sample in CI): `R = ledger non-refunded out`, `H = ledger refunded out`,
  `0 <= U <= Q − F`, `K <= F`.
- [ ] Regression: `CreateOrderTest`, `AddOrderItemTest`, `HardBlockTest`, `PriceSnapshotTest`, `RecordRefundTest`,
  `UpdateOrderItemQuantityTest` green unchanged.

## Expected outcome
Every order line — legacy, new, refunded or re-quantified — carries correct counts of which units are out of stock, without
any stock change yet. Shipping this story alone is safe; 0088a then starts deducting new lines.

## Acceptance criteria
- [ ] The three columns exist and are backfilled per D6 / D9.
- [ ] `stockTarget()`, `returnableStockUnits()`, `refundedUnitsOutOfStock()` and `applyRefundToStockCounters()` implement D1,
  D8 and D9; every sequence in the counter model holds at helper level, including the review #3 counterexample and the
  legacy variant.
- [ ] `CreateOrder` / `AddOrderItem` write stock-neutral lines; `RecordRefund` and `UpdateOrderItemQuantity` keep both counters
  correct (D10).
- [ ] No stock row is read or written by any code in this story; no behaviour change; no authorization change; the full suite
  is green.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper):
  - `docs/database/schema-orders/items-and-refunds.md#order_items` — the three columns, their backfills, D8 (two counters, refund rule), D10 and D9 with both blind spots;
  - the ER diagram in `docs/database/schema.md`;
  - `docs/api/orders.md` — counter side effects of create/add (stock-neutral), refund and quantity change;
  - `ai-spec/tasks/_digests/epic-3.md`.
- [ ] Acceptance criteria met

## INVEST note
- **Independent:** no pending dependency; safe to ship alone (new lines are stock-neutral).
- **Negotiable:** counter model and orphan detection were settled with the user (D8, D9).
- **Valuable:** every later stock feature depends on correct counts; shipping them first means no order written from now on
  can be miscounted.
- **Estimable:** one migration, one model (four helpers), one factory, four small action edits (no stock call).
- **Small:** yes after the split — no new action, no stock writes, no lock-order change.
- **Testable:** migration, unit, action and property tests; no UI.

## Dependencies, risks, open technical questions
**Depends on:** nothing pending. **Blocks:** [0088a](0088a-order-stock-auto-deduct-on-create-and-add-backend.md) (and, through it,
[0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md), [0091](../0091-order-cancellation-stock-return-opt-in-backend.md),
[0092b](../in-progress/0092b-order-refund-stock-return-backend.md)).
**File overlap without a dependency:** [0092a](0092a-record-refund-lock-order-backend.md) also edits `RecordRefund.php` —
`conflict_risk_with`; run them in sequence or coordinate.

- **R-1 — Two stories touch `CreateOrder` / `AddOrderItem` in a row** (this one, then 0088a). Expected; 0088a's diff is the
  deduction only.

### OQ-1 — The returned-units counter and the cancel formula — resolved (option a, then two counters)
**Resolved 2026-11-08 by the user: option (a)** (D8). **Revised after the Phase 2 re-review: user-confirmed 2026-11-08 (two
counters)**, and **corrected after review #3** (U holds only non-refunded units; every refund moves its `over` units from U
to K; K renamed `stock_refunded_not_out_quantity`) — see D8. Original analysis, kept for the record: D4's
`quantity − refunded_quantity − S` subtracted refunded-and-restocked units twice, and without a cap a refund restock after a
cancel-with-return returned the same units twice.

### OQ-2 — How a deleted-variant line is recognised — resolved (option a)
**Resolved 2026-11-08 by the user: option (a)** (D9, including both accepted backfill blind spots).

### OQ-3 — A line with zero returnable units — resolved (user-confirmed 2026-11-08)
The dialog is still shown, with a note and only "Save without changing stock" / "Keep editing". See
[0094](../0094-order-line-quantity-stock-dialog-ui.md) and [0094a](../0094a-order-line-removal-stock-dialog-ui.md).

### OQ-4 — Virtual products — resolved (user-confirmed 2026-11-08)
D1 applies as written: every product line moves `products.stock`, including `virtual` products.

### OQ-5 — Story size — resolved (user-confirmed 2026-11-08: split, option (a))
This story keeps the counters (no stock movement); [0088a](0088a-order-stock-auto-deduct-on-create-and-add-backend.md) adds
`AdjustOrderStock` and the automatic deduction; 0090, 0091 and 0092b depend on 0088a.

### Backlog (technical tasks for later)
- **B-1:** make the product and variant editors write stock as a delta, or with optimistic locking (see 0088a R-2).
- **B-2:** low/zero-stock notifications triggered from `AdjustOrderStock`'s applied targets (see 0088a R-3).
- **B-3:** a per-line stock movement history, if an audit trail is wanted (not requested).

## Debate record
- **2026-11-08 — Three Amigos.** Facilitated by product-owner from the backend-expert and frontend-expert designs and user
  decisions D1–D9. backend-qa test list folded in; database-expert designed the migration shape and backfills.
- **2026-11-08 — Phase 2 reviews #1–#3 applied.** 0089 merged in; lock order and decrease formula corrected; `stockTarget()`
  signature pinned; two counters, then the review #3 refund rule; D10 counter maintenance.
- **2026-11-08 — Phase 2 review #4 applied.** Split per the user's OQ-5 decision: deduction and `AdjustOrderStock` moved to
  0088a; new lines stock-neutral here; FD items renumbered (FD-1 … FD-7); OQ-4 user-confirmed.

## Approval record
- **Phase 1:** complete 2026-11-08 (product-owner). OQ-1 and OQ-2 resolved by the user 2026-11-08.
- **Phase 2:** INVEST review 2026-11-08, findings received (including the user's merge decision) → rewritten, pending re-review.
- **Phase 2:** INVEST re-review 2026-11-08: single-counter model found unsound (user decision: two counters, D8 revised), second
  D9 blind spot added → rewritten, pending re-review.
- **Phase 2:** INVEST re-review #3 2026-11-08: refund rule corrected (U only non-refunded, `over` transfer on every refund), K renamed, backfill U = Q − F / K = F, `RecordRefund` and `UpdateOrderItemQuantity` counter maintenance added (D10), sequences and property test added, size concern raised as OQ-5 → rewritten, pending re-review.
- **Phase 2:** INVEST re-review #4 2026-11-08 → rewritten, pending re-review (split into 0088 + 0088a per the user's OQ-5 decision; this story is counters only and safe alone; OQ-4 user-confirmed).
- **Phase 2:** INVEST re-review #5 2026-11-10 (code-reviewer) → APPROVED: counters/sequences/D8–D10 verified against CreateOrder, AddOrderItem, RecordRefund, UpdateOrderItemQuantity, OrderItem and OrderItemFactory; Small and safe alone, with amendments applied: DemoDataSeeder uses legacyStockUntracked() (FD-5b, added to files + test), factory configure() must not overwrite ordered_as_variant and legacyStockUntracked() computes in afterMaking, two helper-level Gherkin When steps reworded.
- **Phase 3:** TDD 2026-11-10 — backend-qa wrote the red tests (migration, counters, seeder, model helpers), database-expert the migration, backend-expert the model, actions, factory and seeder; all green.
- **Phase 4:** security audit 2026-11-10 (appsec-auditor) → APPROVED: no Critical/High/Medium findings; new stock columns kept out of `#[Fillable]` and written only via `forceCreate`/`forceFill` on rows locked in a transaction; no authorization, input or behaviour change; migration SQL is static. One Low (unsigned-subtraction backfill could abort on a legacy row with `refunded_quantity > quantity`) fixed in `eb9a595` (`GREATEST` over signed casts); pre-flight count `SELECT COUNT(*) FROM order_items WHERE refunded_quantity > quantity` recommended before deploy.
- **Phase 5:** code review 2026-11-10 (code-reviewer) → APPROVED: AC 1–4 and FD-1..FD-7/FD-5b verified; no stock row written or locked. Follow-ups applied: migration-test cleanup scoped to the current schema and the over-refunded clamp pinned (`566de62`). Carried to later stories: extract the FD-7 quantity rule into an `OrderItem` helper in 0090, and floor `R` at 0 before 0091 uses it as a stock delta. Full suite unscoped, run in six directory chunks on per-worktree databases, all green: 1662 + 2234 (5 skipped) + 847 + 1023 + 694 + 279 (3 skipped) = 6739 tests; Pint and Larastan clean.
- **Phase 6:** documentation 2026-11-10 (docs-keeper): `docs/database/schema-orders/items-and-refunds.md`, ER diagram in `docs/database/schema.md`, `docs/api/orders.md`, `ai-spec/tasks/_digests/epic-3.md`.
- **Phase 7:** closed 2026-11-10; moved to `done/`.
