# [0089] Orders deduct stock automatically when an order is created or a line is added (backend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 2 of the
> order-stock series 0088–0096. Blocked on [0088](0088-order-stock-adjustment-foundation-backend.md) (column, `stockTarget()`,
> `AdjustOrderStock`). 0088's OQ-1/OQ-2 were resolved 2026-11-08 (0088 D8, D9); this story writes D9's
> `ordered_as_variant` flag at creation, and D8 does not affect it (a new line always starts with counter `0`).

## Description
`CreateOrder` and `AddOrderItem` take every new line's units out of the matching variant or product stock, in the same
database transaction that writes the line, through `AdjustOrderStock`. There is no prompt and no opt-out: an order line
that exists has its units held out of inventory. Insufficient stock never blocks the write (stock may go negative).

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- `CreateOrder` has **no stock validation today** (no reference to `stock` anywhere in `app/Actions/Orders/`), so there is no
  existing "insufficient stock" refusal to keep or remove.
- `CreateOrder` writes the order and its lines inside `DB::transaction()` within a retry loop for `order_number` collisions
  (MySQL 1062); each retry is a **new** transaction, so a deduction made by a rolled-back attempt is rolled back with it.
- `AddOrderItem` **always creates a new line** (`OrderItem::forceCreate`) — it never merges into an existing line of the same
  product/variant. D2's "when a line merges, deduct only the added units" therefore has no code path today; the rule is kept
  as an acceptance criterion for any future merge (see D-2 below).
- `AddOrderItem` already locks the `orders` row first inside its transaction (F-4), then counts and inserts items, then calls
  `RecalculateOrderTotals`.
- There is **no order-creation screen** today (story 0085's record); `CreateOrder` is reached from code paths and tests only.

## Decisions
- **D-1 (user D2)** — Automatic deduction, same transaction, row-locked through `AdjustOrderStock` (FD-5 lock order), no prompt.
- **D-2 (user D2)** — Deduct exactly the units added by the call: the whole `quantity` for a new line. If a later story makes
  `AddOrderItem` merge into an existing line, it deducts only the added units — recorded here so the merge story inherits it.
- **D-3** — Deduction happens **after** the line rows are inserted and **before** the transaction commits, so a failed insert,
  a ceiling refusal or a column-overflow refusal leaves stock untouched.
- **D-4** — New lines are written with `stock_returned_quantity = 0` (explicitly via `forceCreate`, not only the column default),
  so the invariant does not depend on a DB default. Each new line is also written with `ordered_as_variant = true` when it
  names a variant and `false` otherwise (0088 D9), so a later variant deletion is recognised and moves no stock.
- **D-5** — Same target aggregated: two lines of the same variant in one `CreateOrder` call are one locked `UPDATE`
  (0088 FD-4).
- **D-6** — No new refusal for insufficient stock (negative allowed, user decision), no warning (there is no creation screen;
  the add-line flow on the order screen gets no prompt per D2).
- **D-7 (user D7)** — Authorization unchanged: `CreateOrder` keeps its own gate; `AddOrderItem` keeps `orders.edit`
  (`OrderPolicy::update`). No `products.edit` needed.

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
    And the order administrator was not asked about stock

  Scenario: A refused line addition takes nothing out of stock
    Given an order administrator, with a shipped order, and the product "Mug" has 10 units in stock
    When the order administrator adds 2 units of "Mug" to the order
    Then the line is refused because the order can no longer be edited
    And "Mug" still has 10 units in stock

  Scenario: An administrator without order editing rights cannot add a line or move stock
    Given an administrator without the orders edit permission, with a pending order, and "Mug" has 10 units in stock
    When the administrator adds 2 units of "Mug" to the order
    Then the action is refused as not authorized
    And "Mug" still has 10 units in stock
```

## Files to create/modify
- `app/Actions/Orders/CreateOrder.php` — inject `AdjustOrderStock`; inside the transaction closure, after the line inserts, one
  call with `delta = -quantity` per created line; write `stock_returned_quantity = 0` and `ordered_as_variant` in each line's `forceCreate`; docblock update.
- `app/Actions/Orders/AddOrderItem.php` — inject `AdjustOrderStock`; write `stock_returned_quantity = 0` and `ordered_as_variant` in `forceCreate`; after it and before `RecalculateOrderTotals`,
  `delta = -$quantity`; docblock update (D-2 merge rule).
- `tests/Feature/Orders/CreateOrderStockTest.php` — new.
- `tests/Feature/Orders/AddOrderItemStockTest.php` — new.

## Tests to perform
- [ ] Feature: create with product line, variant line, mixed lines, two lines of the same target (aggregated), stock going negative.
- [ ] Feature: the counter of every new line is `0`; `ordered_as_variant` is `true` for a variant line and `false` for a plain-product line (create and add).
- [ ] Feature: after the variant of a line created here is deleted, the line's `stockTarget()` is `null` (D9 end to end).
- [ ] Negative: `CreateOrder` refused by validation, by the column ceiling and by a non-1062 `QueryException` → stock unchanged.
- [ ] Retry: a forced 1062 on the first attempt then success → stock deducted **once** (the rolled-back attempt left no trace).
- [ ] Feature: `AddOrderItem` deducts; refused by the hard block (Shipped/Delivered), by the line-item ceiling, by the column ceiling, by authorization → stock unchanged.
- [ ] Concurrency/lock-order: the query log shows `orders` lock → `order_items` insert → `product_variants`/`products` lock and `UPDATE stock = stock + ?`.
- [ ] Regression: existing `CreateOrderTest`, `AddOrderItemTest`, `HardBlockTest`, `PriceSnapshotTest` stay green unchanged.

## Expected outcome
Every order or added line created from now on holds its units out of the right stock row, atomically with the write.

## Acceptance criteria
- [ ] `CreateOrder` and `AddOrderItem` deduct the created units through `AdjustOrderStock` inside their own transaction.
- [ ] A refused or rolled-back create/add never changes stock.
- [ ] Insufficient stock never refuses the write; the resulting stock may be negative.
- [ ] New lines are written with counter `0` and the correct `ordered_as_variant` flag.
- [ ] No authorization change.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (stock side effect of create/add), `docs/database/schema-orders/snapshots-totals-and-rules.md` if it lists per-action writes, `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs only 0088. Negotiable: the merge rule (D-2) is future-facing. Valuable: closes the core bug — orders finally consume inventory. Estimable/Small: two actions, one injected call each. Testable: stock asserted before/after every create/add path, including refusals and the retry loop.

## Dependencies, risks, open technical questions
**Depends on:** [0088](0088-order-stock-adjustment-foundation-backend.md) (hard). **Blocks:**
[0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md), [0091](0091-order-cancellation-stock-return-opt-in-backend.md),
[0092](0092-order-refund-stock-return-backend.md) — none of them may return stock before deductions exist (0088 R-1).

- **R-1** — Ship together with 0088 (0088 R-1).
- **R-2** — `CreateOrder` does not lock product/variant rows today when it reads prices; this story locks them only at the
  deduction step. A price read and the deduction are not one snapshot — acceptable, the price snapshot rule is unchanged.
- **Open (non-blocking)** — none (0088 OQ-2 resolved as D9).

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the backend-expert design and decisions D1, D2, D7.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner). Phase 2: pending.
