# [0088] Order stock foundation — stock-tracking columns, `AdjustOrderStock` and automatic deduction on create / add line (backend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.**
> Epic 3 follow-up bug fix (Orders, Payments & Fulfilment), no new PRD requirement: no order flow touches stock today
> (`CreateOrder`, `AddOrderItem`, `UpdateOrderItemQuantity`, `RemoveOrderItem`, `CancelOrder`,
> `AutoCancelFullyRefundedOrder`, `RecordRefund` — verified against `main`).
> First story of the order-stock series (0088, 0090, 0091, 0092a, 0092b, 0093, 0094, 0094a, 0095, 0096).
>
> **Merged with former story 0089 (user decision 2026-11-08, Phase 2 review).** 0089 ("Orders deduct stock automatically
> when an order is created or a line is added") is merged into this story so that the counter columns and the first
> deductions ship together. The 0089 file was deleted and its number is retired. This removes the old release-window
> risk, where lines created between the two releases would get a counter of 0 without a deduction.
>
> **User-confirmed decisions (2026-11-08):** OQ-1 → D8, OQ-2 → D9 (option (a) each), and the corrected decrease formula in the
> [counter model](#counter-model-decided-d8-corrected-2026-11-08). OQ-4 runs on an assumed default that the user has not confirmed yet.

## Description
Add two stock-tracking columns to `order_items`:
- `stock_returned_quantity`: a per-line counter. Its backfill makes every existing order "stock-neutral".
- `ordered_as_variant`: a flag that recognises a line whose variant was later deleted.

Add the `OrderItem` helpers that name a line's stock target and its returnable units. Add the transaction-bound action
`App\Actions\Orders\AdjustOrderStock`, which applies signed stock deltas to products and variants atomically, with
deterministic locking, and skips lines whose catalog row no longer exists. Finally, make `CreateOrder` and `AddOrderItem`
deduct every new line's units **automatically**, with no prompt, in the same transaction. This story fixes the core bug:
orders finally consume inventory.

## Type
backend | includes database-expert: **yes** (two new columns + data backfill migration)

## Verified facts that shaped the story
- `products.stock` and `product_variants.stock` are signed `INT NOT NULL DEFAULT 0`; a negative value is a legal
  business state ([categories-and-products.md](../../docs/database/schema-products/categories-and-products.md),
  [variants.md](../../docs/database/schema-products/variants.md)).
- **No stock-tracking flag exists** on `Product` or `ProductVariant`, so no line is skipped for that reason. `Product::$type` is
  `physical`/`virtual` — see [OQ-4](#oq-4--virtual-products--assumed-default-not-yet-user-confirmed).
- `Product` and `ProductVariant` have **no `SoftDeletes`**. A deleted catalog row is gone, and `order_items.product_id` /
  `product_variant_id` are `nullOnDelete()`. After its variant is deleted, a line looks exactly like a plain-product line.
  D9 resolves this.
- `OrderItem::#[Fillable]` is `product_id`, `product_variant_id`, `quantity`; every derived column is written with
  `forceFill()`/`forceCreate()` ([items-and-refunds.md#order_items](../../docs/database/schema-orders/items-and-refunds.md#order_items)).
- **Existing catalog lock order: `products` before `product_variants`.** `CreateProduct`, `UpdateProduct`, `CreateProductVariant`
  and `SyncProductAttributeValues` all lock `products` rows before `product_variants` rows (story 0029). This story follows
  the same order, so an order write and a catalog write can never deadlock on each other.
- Existing order lock order: the line-item actions lock **`orders` → `order_items`**. `RecordRefund` is the known
  exception ([docs/api/orders.md — orders.show, "Known concurrency gap"](../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities));
  [0092a](0092a-record-refund-lock-order-backend.md) fixes it.
- `CreateOrder` has **no stock validation** today. It writes the order and its lines inside `DB::transaction()`, within a
  retry loop for `order_number` collisions (MySQL 1062). Each retry is a **new** transaction, so a deduction made by a
  rolled-back attempt is rolled back with it.
- `AddOrderItem` **always creates a new line** and never merges into an existing one. Inside its transaction it locks the
  `orders` row first (F-4), then counts and inserts items, then calls `RecalculateOrderTotals`.
- There is **no order-creation screen** today; `CreateOrder` is reached from code paths and tests only.

## Decisions (user-confirmed, binding for the whole series)
- **D1 — Stock target.** A line moves `product_variants.stock` when it names a variant, otherwise `products.stock`. When
  the line's variant (on a variant line) or its product was deleted, **nothing** is adjusted for that line, and the stock
  dialogs do not list it.
- **D2 — Automatic deduction on create / add.** `CreateOrder` and `AddOrderItem` deduct the units they create, in the same
  transaction, row-locked, with no prompt. Lock order:
  - `AddOrderItem`: `orders` lock → `order_items` insert → `products` locks (by id) → `product_variants` locks (by id) →
    `UPDATE … stock = stock + ?`.
  - `CreateOrder`: `orders` insert → `order_items` inserts → the same stock locks and `UPDATE`s.

  If a later story makes `AddOrderItem` merge into an existing line, it deducts only the added units.
- **D6 — Counter.** New column `order_items.stock_returned_quantity`, `INT UNSIGNED NOT NULL DEFAULT 0`. The migration backfills
  every existing row with `stock_returned_quantity = quantity`. A legacy order's units were never deducted, so this stops
  it from inflating inventory when it is later cancelled, re-quantified or refunded.
- **D7 — Authorization unchanged.** `AdjustOrderStock` has **no** `Gate` call of its own. It runs only inside a caller's
  transaction that is already authorized (the same shape as `RecalculateOrderTotals`). `CreateOrder` keeps its own gate and
  `AddOrderItem` keeps `orders.edit`. No new permission, and no `products.edit` requirement.
- **D8 — Returnable units (OQ-1 option (a)).**
  - Every restock increments the counter.
  - Returnable units of a line: `max(0, quantity - max(refunded_quantity, stock_returned_quantity))`.
  - A refund restock is additionally capped at `quantity - stock_returned_quantity`.
  - This replaces D4's literal formula everywhere.
- **D9 — Deleted-variant detection (OQ-2 option (a)).** New column `order_items.ordered_as_variant`
  (`BOOLEAN NOT NULL DEFAULT false`).
  - **Written at creation:** `true` whenever the new line names a variant.
  - **Backfill:** `true` where `product_variant_id IS NOT NULL` **or** the `product_sku` snapshot differs from the product's
    current SKU.
  - **Effect:** a line with `ordered_as_variant = true` and a `null` variant id adjusts nothing (D1).
  - **Accepted blind spot:** the backfill also flags a *legacy plain-product line whose product SKU was edited after the
    order*. That line then adjusts nothing. The impact is limited to a later quantity increase with deduction on that legacy
    line: its returnable units are 0 anyway (D6), so returns are unaffected. The owner accepted this at the Phase 2 review,
    and a migration test pins it.

## Technical design (backend-expert, accepted)
- **FD-1 — Signature.** `AdjustOrderStock::__invoke(Order $order, array $deltas): array`, with
  `@param list<array{order_item: OrderItem, delta: int}> $deltas` (positive = units back into stock, negative = units out)
  and `@return list<string>` = ids of the order items whose delta was actually applied. Resolved from the container.
- **FD-2 — Transaction precondition.** The first statement checks `DB::transactionLevel() > 0`, or else throws `LogicException`
  (a programming error). The caller already holds the `orders` row lock. For `RecordRefund` (0092b), that holds only after
  [0092a](0092a-record-refund-lock-order-backend.md)'s lock-order fix.
- **FD-3 — Ownership guard.** Every `order_item` must belong to `$order`, or else `LogicException`.
- **FD-4 — Aggregation.** Deltas are summed per target key (`variant:<id>` / `product:<id>`). A net-zero target is not
  written, and lines whose `stockTarget()` is `null` are skipped.
- **FD-5 — Deterministic stock-row locking.** `products` first, then `product_variants`, each locked with
  `whereIn(...)->orderBy('id')->lockForUpdate()`. A target row that no longer exists at lock time is skipped like a `null`
  target, never thrown.
- **FD-6 — Atomic write.** One `UPDATE … SET stock = stock + ?` per target (`increment('stock', $delta)`). It is **never** a
  read-modify-write, and there is no lower bound.
- **FD-7 — Project-wide lock-order convention** (docs-keeper documents it): `orders` → `order_items` (by id) → `products` (by id) →
  `product_variants` (by id).
- **FD-8 — Stock target helper.**
  ```php
  /** @return array{type: 'variant'|'product', id: string}|null */
  public function stockTarget(): ?array
  ```
  It is pure, with no query:
  - `variant` when `product_variant_id` is set;
  - `null` when `ordered_as_variant` is true and the variant id is null (D9);
  - `product` when `product_id` is set on a non-variant line;
  - `null` when the product is gone (D1).
- **FD-9 — Unit helpers.**
  - `OrderItem::returnableStockUnits(): int` is the one copy of D8's formula. 0090, 0091, 0094, 0094a and 0095 read it.
  - `OrderItem::unitsHeldOutOfStock(): int` = `quantity - stock_returned_quantity`. The refund cap in 0092b reads it.
- **FD-10 — Counter ownership.** `AdjustOrderStock` moves `stock` only; each caller owns the counter rule for its operation.
  Both new columns stay out of `#[Fillable]` (`integer` / `boolean` casts) and are written with `forceFill()` / `forceCreate()`.
- **FD-11 — `CreateOrder` integration.**
  - Inside the transaction closure, `CreateOrder` collects the `OrderItem` models it creates (`forceCreate` returns them).
  - It then passes them to **one** `AdjustOrderStock` call, with `delta = -quantity` each, after all lines are inserted.
  - Each line is written with `stock_returned_quantity = 0` and `ordered_as_variant`.
- **FD-12 — `AddOrderItem` integration.**
  - It writes `stock_returned_quantity = 0` and `ordered_as_variant` in its `forceCreate`.
  - It calls `AdjustOrderStock` with `delta = -$quantity` after the insert and before `RecalculateOrderTotals`.
  - Every existing refusal (hard block, line ceiling, column ceiling) runs first, so a refused add never moves stock.
- **FD-13 — Factory.** `OrderItemFactory` sets:
  - `stock_returned_quantity` default `0`;
  - `ordered_as_variant` default `true` when the factory line names a variant, `false` otherwise.

  New states:
  - `legacyStockUntracked()` — counter = `quantity`;
  - `orphanedVariant()` — `ordered_as_variant = true`, `product_variant_id = null`, `product_id` set.

## Counter model (decided, D8; corrected 2026-11-08)
- **Meaning:** `stock_returned_quantity` (S) = units of the line **not currently held out of inventory** (returned, or never
  deducted). Invariant `0 <= S <= quantity`. Units held out = `quantity - S`. F = `refunded_quantity`.
- **Returnable units** R = `max(0, quantity - max(F, S))`.
- **Per operation** (d = units removed or added, `newQty` = quantity after the change):

  | Operation | Stock delta | Counter after |
  | --- | --- | --- |
  | Create order / add line (this story) | `-quantity` | `0` |
  | Quantity +d, admin deducts (0090) | `-d` | `S` (unchanged) |
  | Quantity +d, admin does not change stock (0090) | none | `S + d` |
  | Quantity −d, admin returns units (0090) | `+k`, `k = max(0, R − (newQty − F))` | `max(0, S − d + k)` |
  | Quantity −d, admin does not change stock (0090) | none | `max(0, S − d)` |
  | Remove line, admin returns units (0090) | `+R` | row deleted |
  | Cancel, admin returns units (0091) | `+R` per line | `S + R` |
  | Cancel, admin does not change stock (0091) | none | `S` |
  | Refund with stock return of k units (0092b) | `+k`, `k <= min(units refunded now, quantity − S)` | `S + k` |
  | Automatic cancellation after a full refund | none | `S` |

  A line with a `null` stock target never moves stock and keeps its counter.
  Worked check: 2 units (S=0) raised to 5 without deduction (S=3), lowered back to 2 with return:
  R = `5 − max(0,3) = 2` and k = `max(0, 2 − (2 − 0)) = 0`, so nothing returns and S' = `max(0, 3 − 3 + 0) = 0`. A later
  cancel with return then returns R = `2 − 0 = 2`, exactly the 2 units deducted at creation.

## Gherkin
```gherkin
Feature: Order lines hold their units out of stock

  Scenario: Legacy order lines are marked as never deducted
    Given an order administrator, with an order recorded before stock tracking existed, holding a line of 3 units
    When the stock-tracking upgrade is applied to the store
    Then that line counts all 3 units as not held out of inventory

  Scenario: A line whose variant was deleted before the upgrade is recognised as a variant line
    Given an order administrator, with an order recorded before stock tracking existed, holding a line of a variant that was deleted before the upgrade
    When the stock-tracking upgrade is applied to the store
    Then that line is recognised as a variant line that moves no stock

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
- `database/migrations/<timestamp>_add_stock_tracking_columns_to_order_items_table.php` — new (database-expert). In `up()`:
  - `boolean('ordered_as_variant')->default(false)->after('product_variant_id')`.
  - `unsignedInteger('stock_returned_quantity')->default(0)->after('refunded_quantity')`.
  - Backfill `stock_returned_quantity = quantity` for every row.
  - Backfill `ordered_as_variant` (D9) with `UPDATE order_items LEFT JOIN products … SET ordered_as_variant = true WHERE
    product_variant_id IS NOT NULL OR (products.id IS NOT NULL AND order_items.product_sku <> products.sku)`.

  `down()` drops both columns. Follow [docs/database/migrations.md](../../docs/database/migrations.md).
- `app/Models/OrderItem.php` — `@property int $stock_returned_quantity`, `@property bool $ordered_as_variant`, casts,
  `stockTarget()`, `returnableStockUnits()`, `unitsHeldOutOfStock()`; both columns stay out of `#[Fillable]`.
- `app/Actions/Orders/AdjustOrderStock.php` — new action (FD-1 … FD-7).
- `app/Actions/Orders/CreateOrder.php` — FD-11; docblock update.
- `app/Actions/Orders/AddOrderItem.php` — FD-12; docblock update (merge rule).
- `database/factories/OrderItemFactory.php` — FD-13: `stock_returned_quantity` default `0`, `ordered_as_variant` default from
  the variant, states `legacyStockUntracked()` and `orphanedVariant()`.
- `tests/Feature/Orders/AdjustOrderStockTest.php` — new.
- `tests/Feature/Orders/OrderStockMigrationTest.php` — new.
- `tests/Feature/Orders/CreateOrderStockTest.php` — new.
- `tests/Feature/Orders/AddOrderItemStockTest.php` — new.
- `tests/Feature/Orders/OrderModelTest.php` — extend with the helper cases.

## Tests to perform
- [ ] Migration: existing rows get `stock_returned_quantity = quantity`; a later row defaults to `0`; column `unsigned`,
  `NOT NULL`.
- [ ] Migration (D9 backfill): `ordered_as_variant` is
  - true for a row with a variant id;
  - true for a row with a null variant id whose SKU snapshot differs from its product's SKU;
  - false for a plain-product row with a matching SKU;
  - false for a row whose product was deleted;
  - **true for a legacy plain-product row whose product SKU was edited later** (accepted blind spot, pinned).
- [ ] Unit: `stockTarget()` — variant line → variant; product line → product; deleted product → `null`; `orphanedVariant()` → `null`.
- [ ] Unit: `returnableStockUnits()` — fresh line (Q); legacy (0); 5 with 2 refunded and 2 restocked (3); 5 with 2 refunded
  not restocked (3); never negative. `unitsHeldOutOfStock()` = `quantity - counter`.
- [ ] `AdjustOrderStock`:
  - positive and negative deltas, variant vs product target;
  - aggregation of two lines of the same target; a net-zero target writes nothing;
  - negative resulting stock allowed;
  - returns only the ids actually applied; null targets and rows deleted before locking are skipped.
- [ ] Negative: outside a transaction → `LogicException`; a line of another order → `LogicException`; stock untouched.
- [ ] Atomicity: the write is `UPDATE … stock = stock + ?`, and the final value is correct when another write changed the row
  between the caller's read and this call.
- [ ] Lock order (query log):
  - `AdjustOrderStock` locks `products` before `product_variants`, each by id;
  - **`AddOrderItem`**: `orders` lock → `order_items` insert → product/variant locks → `UPDATE stock`;
  - **`CreateOrder`**: `orders` insert → `order_items` inserts → stock locks → `UPDATE stock`.
- [ ] `CreateOrder`:
  - product, variant and mixed lines; same-target aggregation in **one** `AdjustOrderStock` call;
  - negative stock allowed;
  - counter `0` and correct `ordered_as_variant` per line.
- [ ] `CreateOrder` refusals and retry:
  - refused by validation, by the column ceiling, or by a non-1062 `QueryException` → stock unchanged;
  - a forced 1062 then success → stock deducted **once**.
- [ ] `AddOrderItem`:
  - deducts, with counter `0` and the correct flag;
  - refused by the hard block, the line ceiling, the column ceiling, or authorization → stock unchanged.
- [ ] End to end (D9): after the variant of a line created here is deleted, `stockTarget()` is `null`.
- [ ] Architecture: `AdjustOrderStock` has no `Gate`/`authorize` call and is used only from `App\Actions\Orders\*`.
- [ ] Regression: `CreateOrderTest`, `AddOrderItemTest`, `HardBlockTest`, `PriceSnapshotTest` green unchanged.

## Expected outcome
Every order or added line created from now on holds its units out of the right stock row, atomically with the write. Legacy
lines are stock-neutral, and every later stock change in the series goes through one tested action.

## Acceptance criteria
- [ ] Both columns exist and are backfilled per D6 / D9.
- [ ] `stockTarget()`, `returnableStockUnits()` and `unitsHeldOutOfStock()` implement D1, D8 and D9.
- [ ] `AdjustOrderStock` implements FD-1 … FD-7 and touches neither `order_items` nor `orders`.
- [ ] `CreateOrder` and `AddOrderItem` deduct created units in their own transaction, in the FD-7 lock order.
- [ ] A refused or rolled-back create/add never changes stock; insufficient stock never refuses a write.
- [ ] No authorization change; the full suite is green.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper):
  - `docs/database/schema-orders/items-and-refunds.md#order_items` — both columns, both backfills, D8, D9 and its blind spot;
  - the ER diagram in `docs/database/schema.md`;
  - `docs/database/schema-orders/snapshots-totals-and-rules.md` — stock side effect of create / add line;
  - `docs/api/orders.md` — `AdjustOrderStock` contract, stock side effect of create/add, lock-order convention FD-7;
  - `ai-spec/tasks/_digests/epic-3.md`.
- [ ] Acceptance criteria met

## INVEST note
- **Independent:** no pending dependency.
- **Negotiable:** counter model and orphan detection were negotiated and settled with the user (D8, D9).
- **Valuable:** it fixes the core bug (orders consume inventory) and ships the counter with the first deductions. The
  merge with former 0089 removed the release-window risk.
- **Estimable:** one migration, one model, one new action, two action integrations, one factory.
- **Small:** the largest backend story in the series. Its additions are mechanical and each is covered by its own test file.
- **Testable:** every rule has a feature or migration test; no UI.

## Dependencies, risks, open technical questions
**Depends on:** nothing pending.

**Blocks:**
- [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md)
- [0091](0091-order-cancellation-stock-return-opt-in-backend.md)
- [0092b](0092b-order-refund-stock-return-backend.md)

**Risks:**
- **R-2 — Product editor lost update.** The product and variant editors save `stock` from a form value, so an editor left
  open overwrites a deduction made in the meantime. This is a pre-existing shape and out of scope (backlog B-1).
- **R-3 — Low-stock notifications** (PRD foundations) are not implemented. This action is the natural trigger point
  (backlog B-2).
- **R-4 — Price read vs deduction.** `CreateOrder` reads prices without locking catalog rows. The stock lock happens only at
  the deduction step. Acceptable, because the price snapshot rule is unchanged.

### OQ-1 — The returned-units counter and the cancel formula — resolved (option a)
**Resolved 2026-11-08 by the user: option (a)** (D8).

The decrease row was corrected after the Phase 2 review. Under the earlier rule (return `min(d, R)`, clamp the counter to
`min(S, newQty)`), lowering a line whose raised units were never deducted could return units it never took out. It also
left a later cancel short. The corrected rule returns only the held-out non-refunded units that no longer fit in the new
quantity.

Original analysis, kept for the record:
- D4's `quantity - refunded_quantity - S` subtracts refunded-and-restocked units twice. It under-returns when a line is
  lowered after a refund with restock.
- Without a held-out cap, a refund restock after a cancel-with-return returns the same units twice.

### OQ-2 — How a deleted-variant line is recognised — resolved (option a)
**Resolved 2026-11-08 by the user: option (a)** (D9, including the accepted backfill blind spot).

### OQ-3 — A line with zero returnable units — resolved (user-confirmed 2026-11-08)
The dialog is still shown, with a note and only "Save without changing stock" / "Keep editing". See
[0094](0094-order-line-quantity-stock-dialog-ui.md) and [0094a](0094a-order-line-removal-stock-dialog-ui.md).

### OQ-4 — Virtual products — assumed default, not yet user-confirmed
D1 makes every product line move `products.stock`, including `virtual` products.
- **Assumed default (recommended):** keep D1 as written.
- **Alternative:** skip `virtual` products entirely.

### Backlog (technical tasks for later)
- **B-1:** make the product and variant editors write stock as a delta, or with optimistic locking (R-2).
- **B-2:** low/zero-stock notifications triggered from `AdjustOrderStock`'s applied targets (R-3).
- **B-3:** a per-line stock movement history, if an audit trail is wanted (not requested).

## Debate record
- **2026-11-08 — Three Amigos.** Facilitated by product-owner from the backend-expert and frontend-expert designs and user
  decisions D1–D9. backend-qa test list folded in; database-expert designed the migration shape and backfills.
- **2026-11-08 — Phase 2 review applied.**
  - Merged former 0089 into this story.
  - Corrected the lock order to products → variants and the decrease formula.
  - Pinned the `stockTarget()` signature and documented the D9 blind spot.
  - Renamed the migration, completed the factory and renumbered the FD items.
  - Removed an untestable "was not asked" assertion.

## Approval record
- **Phase 1:** complete 2026-11-08 (product-owner). OQ-1 and OQ-2 resolved by the user 2026-11-08.
- **Phase 2:** INVEST review 2026-11-08, findings received (including the user's merge decision) → rewritten, pending re-review.
