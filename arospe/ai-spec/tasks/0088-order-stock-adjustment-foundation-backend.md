# [0088] Order stock adjustment foundation — returned-units counter and `AdjustOrderStock` (backend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix
> (Orders, Payments & Fulfilment), no new PRD requirement: no order flow touches stock today
> (`CreateOrder`, `AddOrderItem`, `UpdateOrderItemQuantity`, `RemoveOrderItem`, `CancelOrder`,
> `AutoCancelFullyRefundedOrder`, `RecordRefund` — verified against `main` at `64f6499`).
> This story is the first of the order-stock series **0088–0096**; it ships the shared building blocks only,
> with **no caller** (no order flow changes behaviour here).
>
> **Resolved 2026-11-08 (user decision):** [OQ-1](#oq-1--the-returned-units-counter-and-the-cancel-formula--resolved-option-a)
> and [OQ-2](#oq-2--how-a-deleted-variant-line-is-recognised--resolved-option-a) are both settled as option (a) — now
> decisions D8 and D9 below. No blocking question remains; OQ-3 and OQ-4 run on assumed defaults, not yet user-confirmed.

## Description
Add the per-line counter `order_items.stock_returned_quantity` (with a backfill that makes every existing order
"stock-neutral"), the `OrderItem::stockTarget()` helper that names which stock row a line moves, and the
transaction-bound action `App\Actions\Orders\AdjustOrderStock` that applies signed stock deltas to products and
variants atomically, deterministically locked, and skipping lines whose catalog row no longer exists.
Every later story in the series (0089–0092) calls this action instead of writing `stock` itself.

## Type
backend | includes database-expert: **yes** (new column + data backfill migration)

## Verified facts that shaped the story
- `products.stock` and `product_variants.stock` are signed `INT NOT NULL DEFAULT 0`; a negative value is a legal
  business state ([categories-and-products.md](../../docs/database/schema-products/categories-and-products.md),
  [variants.md](../../docs/database/schema-products/variants.md)).
- **No stock-tracking flag exists** on `Product` or `ProductVariant` (checked `app/Models/Product.php`,
  `app/Models/ProductVariant.php`), so no line is skipped for that reason. `Product::$type` is `physical`/`virtual`
  — see [OQ-4](#oq-4--virtual-products--assumed-default-not-yet-user-confirmed).
- `Product` and `ProductVariant` have **no `SoftDeletes`** — a deleted catalog row is gone, and
  `order_items.product_id` / `product_variant_id` are `nullOnDelete()`. After a variant is deleted, its line looks
  exactly like a plain-product line (`product_variant_id = null`, `product_id` still set) — resolved by D9.
- `OrderItem::#[Fillable]` is `product_id`, `product_variant_id`, `quantity`; every derived column is written with
  `forceFill()`/`forceCreate()` ([items-and-refunds.md#order_items](../../docs/database/schema-orders/items-and-refunds.md#order_items)).
- Existing lock order in the line-item actions is **order row → order item rows**; `RecordRefund` is the known
  exception ([docs/api/orders.md — orders.show, "Known concurrency gap"](../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities)),
  fixed in [0092](0092-order-refund-stock-return-backend.md).

## Decisions (user-confirmed, binding for the whole series)
- **D1 — Stock target.** `product_variants.stock` when the line names a variant, otherwise `products.stock`. When the
  line's variant (on a variant line) or its product was deleted, **nothing** is adjusted for that line, and the stock
  dialogs (0094–0096) do not list it.
- **D6 — Counter.** New column `order_items.stock_returned_quantity`, `INT UNSIGNED NOT NULL DEFAULT 0`. The migration
  backfills **every existing row** with `stock_returned_quantity = quantity`, so a legacy order (whose units were never
  deducted) can never inflate inventory when it is later cancelled, re-quantified or refunded.
- **D7 — Authorization unchanged.** `AdjustOrderStock` has **no** `Gate` call of its own: it is an internal step that
  only runs inside an already-authorized caller's transaction (same documented shape as `RecalculateOrderTotals`).
  No new permission; no `products.edit` requirement.
- **D8 — Returnable units (OQ-1 option (a), user decision 2026-11-08).** Every restock increments the counter (D6), and the
  returnable units of a line are `quantity - max(refunded_quantity, stock_returned_quantity)` (never below 0). A refund
  restock is additionally capped at `quantity - stock_returned_quantity` (units still held out of inventory). This replaces
  D4's literal formula everywhere; for a manual cancellation it gives the same number as D4.
- **D9 — Deleted-variant detection (OQ-2 option (a), user decision 2026-11-08).** New column `order_items.ordered_as_variant`
  (`BOOLEAN NOT NULL DEFAULT false`), added in this story's migration. It is written `true` at creation whenever the line
  names a variant (0089). Backfill: `true` where `product_variant_id IS NOT NULL` **or** the `product_sku` snapshot differs from
  the product's current SKU (a line whose variant was deleted before this migration). A line with `ordered_as_variant = true`
  and a `null` variant id adjusts nothing (D1).

## Facilitator decisions (technical design, from backend-expert, accepted)
- **FD-1 — Signature.** `AdjustOrderStock::__invoke(Order $order, array $deltas): array`, with
  `@param list<array{order_item: OrderItem, delta: int}> $deltas` (positive = units back into stock, negative = units out)
  and `@return list<string>` = the ids of the order items whose delta was actually applied (callers use it to decide
  whether to move the counter — see [the counter model](#counter-model-decided-d8)). Resolved from the
  container, never `new`-ed.
- **FD-2 — Transaction precondition.** First statement: `DB::transactionLevel() > 0` or throw `LogicException`
  (a programming error, never a user-facing refusal). The caller already holds the `orders` row lock.
- **FD-3 — Ownership guard.** Every `order_item` must belong to `$order` (`order_id === $order->id`) or the action throws
  `LogicException` — defence in depth against a caller passing another order's line.
- **FD-4 — Aggregation.** Deltas are summed per target key (`variant:<id>` / `product:<id>`); a net-zero target is not
  written. Lines whose `stockTarget()` is `null` are skipped (D1).
- **FD-5 — Deterministic stock-row locking.** Variants first, then products, each `whereIn(...)->orderBy('id')->lockForUpdate()`.
  A target row that no longer exists at lock time is skipped exactly like a `null` target (D1), never an exception.
- **FD-6 — Atomic write.** One `UPDATE … SET stock = stock + ?` per target (Eloquent/Query Builder `increment('stock', $delta)`
  with a signed delta) — **never** read-modify-write of `stock` in PHP. No lower bound: stock may go negative.
- **FD-7 — Project-wide lock-order convention** (documented by docs-keeper): `orders` → `order_items` (by id) →
  `product_variants` (by id) → `products` (by id).
- **FD-8 — `OrderItem::stockTarget(): ?array{type: 'variant'|'product', id: string}`** — pure, no query. Returns
  `variant` when `product_variant_id` is set; `null` when `ordered_as_variant` is true and the variant id is null (D9);
  `product` when `product_id` is set on a non-variant line; `null` when the product is gone (D1).
- **FD-11 — `OrderItem::returnableStockUnits(): int`** — the one copy of D8's formula, `max(0, quantity - max(refunded_quantity,
  stock_returned_quantity))`, read by 0090, 0091 and the UI preview (0094/0095), so the dialog and the action never diverge.
  `OrderItem::unitsHeldOutOfStock(): int` = `quantity - stock_returned_quantity`, read by the refund-restock cap (0092).
- **FD-12 — `ordered_as_variant`** stays out of `#[Fillable]`, `boolean` cast, written with `forceCreate()`.
- **FD-9 — The counter is not written by `AdjustOrderStock`.** Each caller owns the counter rule for its own operation
  (they differ — see the model below); `AdjustOrderStock` moves `stock` only. `stock_returned_quantity` stays out of
  `#[Fillable]` and is written with `forceFill()`.
- **FD-10 — Factory.** `OrderItemFactory` defaults `stock_returned_quantity` to `0` (a line created after this series ships)
  and sets `ordered_as_variant` from whether it names a variant; a `legacyStockUntracked()` state sets the counter to
  `quantity`; an `orphanedVariant()` state sets `ordered_as_variant = true` with a null variant id.

## Counter model (decided, D8)
The brief asks for the counter to be "defined precisely". This is the definition adopted with OQ-1 option (a) (user decision
2026-11-08); every later story implements it:

- **Meaning:** `stock_returned_quantity` (S) = units of the line that are **not currently held out of inventory**
  (already returned, or never deducted). Invariant `0 <= S <= quantity`. Units currently held out = `quantity - S`.
- **Returnable units of a line** (R) = units held out that are not refunded:
  `R = max(0, quantity - max(refunded_quantity, S))` (D8) — see OQ-1 for why D4's literal
  `quantity - refunded_quantity - S` is not used as written.
- **Per operation:**

  | Operation | Stock delta | Counter after |
  | --- | --- | --- |
  | Create order / add line (0089) | `-quantity` | `0` |
  | Quantity +d, admin deducts (0090) | `-d` | unchanged |
  | Quantity +d, admin does not change stock (0090) | none | `S + d` (those units were never taken out) |
  | Quantity -d, admin returns units (0090) | `+min(d, R)` | `min(S, new quantity)` |
  | Quantity -d, admin does not change stock (0090) | none | `min(S, new quantity)` |
  | Remove line, admin returns units (0090) | `+R` | row deleted |
  | Cancel, admin returns units (0091) | `+R` per line | `S + R` |
  | Cancel, admin does not change stock (0091) | none | unchanged |
  | Refund with stock return of k units (0092) | `+k`, `k <= min(units refunded now, quantity - S)` (D5 + D8 cap) | `S + k` |
  | Automatic cancellation after a full refund | none | unchanged |

  A line with a `null` stock target never moves stock and its counter is left unchanged.
  The brief's phrase "deductions reduce it down to 0 accordingly" is covered by the clamp `min(S, new quantity)` on a
  decrease; a deduction on an increase leaves the counter unchanged (the new units are taken out, the old ones are as before).

## Gherkin
```gherkin
Feature: Stock adjustment for order lines

  Scenario: Legacy order lines are marked as never deducted
    Given an order administrator, with an order recorded before stock tracking existed, holding a line of 3 units
    When the stock-tracking upgrade is applied to the store
    Then that line counts all 3 units as not held out of inventory

  Scenario: A variant line moves the variant's stock, not the product's
    Given an order administrator, with an order holding 2 units of the "Red / M" variant of "T-shirt"
    When the order's stock is adjusted by returning 2 units for that line
    Then the "Red / M" variant's stock rises by 2
    And the "T-shirt" product's own stock is unchanged

  Scenario: A plain product line moves the product's stock
    Given an order administrator, with an order holding 4 units of the product "Mug", which has no variants
    When the order's stock is adjusted by taking out 4 units for that line
    Then the "Mug" product's stock falls by 4

  Scenario: Two lines of the same variant are adjusted as one total
    Given an order administrator, with an order holding two separate lines of the "Red / M" variant, of 1 and 2 units
    When the order's stock is adjusted by taking out the units of both lines
    Then the "Red / M" variant's stock falls by 3

  Scenario: Returnable units never count refunded and returned units twice
    Given an order administrator, with an order holding 5 units of "Mug" of which 2 were refunded and returned to stock
    When the order administrator looks at how many units of that line can still go back to stock
    Then 3 units can still go back to stock

  Scenario: Stock may go below zero
    Given an order administrator, with an order holding 5 units of "Mug", and "Mug" has 2 units in stock
    When the order's stock is adjusted by taking out 5 units for that line
    Then the "Mug" product's stock reads -3

  Scenario: A line whose variant was deleted before the upgrade is recognised as a variant line
    Given an order administrator, with an order recorded before stock tracking existed, holding a line of a variant that was deleted before the upgrade
    When the stock-tracking upgrade is applied to the store
    Then that line is recognised as a variant line
    And it will never move the parent product's stock

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
- `database/migrations/<timestamp>_add_stock_returned_quantity_to_order_items_table.php` — new (database-expert):
  `unsignedInteger('stock_returned_quantity')->default(0)->after('refunded_quantity')`, then in the same `up()` a single
  `DB::table('order_items')->update(['stock_returned_quantity' => DB::raw('quantity')])` backfill; `down()` drops the column.
  Follow [docs/database/migrations.md](../../docs/database/migrations.md) for the data-migration convention.
  The same migration adds `boolean('ordered_as_variant')->default(false)->after('product_variant_id')` and backfills it
  (D9) with one `UPDATE order_items LEFT JOIN products … SET ordered_as_variant = true WHERE product_variant_id IS NOT NULL
  OR (products.id IS NOT NULL AND order_items.product_sku <> products.sku)`.
- `app/Models/OrderItem.php` — `@property int $stock_returned_quantity`, `@property bool $ordered_as_variant`, casts,
  `stockTarget()`, `returnableStockUnits()`, `unitsHeldOutOfStock()`; both columns stay out of `#[Fillable]`.
- `app/Actions/Orders/AdjustOrderStock.php` — new action (FD-1 … FD-6).
- `database/factories/OrderItemFactory.php` — default `0`, new `legacyStockUntracked()` state.
- `tests/Feature/Orders/AdjustOrderStockTest.php` — new.
- `tests/Feature/Orders/OrderStockMigrationTest.php` — new (backfill).
- `tests/Feature/Orders/OrderModelTest.php` — extend with `stockTarget()` cases.

```php
// Shape only — callers already hold the order lock.
$applied = ($this->adjustOrderStock)($lockedOrder, [
    ['order_item' => $item, 'delta' => -$item->quantity],
]);
```

## Tests to perform
- [ ] Migration test: existing rows get `stock_returned_quantity = quantity`; a row inserted after the migration defaults to `0`; column is `unsigned`, `NOT NULL`.
- [ ] Migration test (D9 backfill): `ordered_as_variant` true for a row with a variant id; true for a row with a null variant id whose SKU snapshot differs from its product's SKU; false for a plain-product row with a matching SKU; false for a row whose product was deleted (no join match) — it adjusts nothing anyway.
- [ ] Unit/feature: `stockTarget()` — variant line → variant; product line → product; deleted product → `null`; `ordered_as_variant = true` with a null variant id → `null`.
- [ ] Unit: `returnableStockUnits()` — fresh line (Q); legacy line (0); refunded 2 and restocked 2 of 5 (3); refunded 2 not restocked of 5 (3); counter above quantity is impossible; never negative. `unitsHeldOutOfStock()` = `quantity - counter`.
- [ ] Feature: `AdjustOrderStock` positive and negative deltas, variant vs product target, aggregation of two lines of the same target, net-zero target writes nothing, negative resulting stock allowed.
- [ ] Feature: returns only the ids actually applied; `null`-target lines and target rows deleted before locking are skipped and not returned.
- [ ] Negative: called outside a transaction → `LogicException`, stock untouched.
- [ ] Negative: an `order_item` of another order → `LogicException`, stock untouched (transaction rolled back).
- [ ] Atomicity: the write is an `UPDATE … stock = stock + ?` (assert via query log that no `SELECT stock` precedes it beyond the lock), and the final value is correct when the stock row was changed by another write between the caller's read and this call.
- [ ] Lock order: variants are locked before products, each ordered by id (query-log assertion).
- [ ] Architecture: `AdjustOrderStock` performs no `Gate`/`authorize` call and is not reachable from a route or Livewire method directly (only from `App\Actions\Orders\*`).

## Expected outcome
The schema holds the counter, every legacy line is stock-neutral, and a reusable, tested action can move product and
variant stock atomically inside an order write. No user-visible behaviour changes yet.

## Acceptance criteria
- [ ] `order_items.stock_returned_quantity` exists (`INT UNSIGNED NOT NULL DEFAULT 0`) and every pre-existing row equals its `quantity`.
- [ ] `order_items.ordered_as_variant` exists (`BOOLEAN NOT NULL DEFAULT false`) and is backfilled per D9.
- [ ] `OrderItem::stockTarget()` implements D1 and D9; `returnableStockUnits()` / `unitsHeldOutOfStock()` implement D8.
- [ ] `AdjustOrderStock` implements FD-1 … FD-6 and touches neither `order_items` nor `orders`.
- [ ] No existing test changes behaviour; the full suite is green.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/database/schema-orders/items-and-refunds.md#order_items` (both new columns, both backfills, counter model D8, D9), the ER diagram in `docs/database/schema.md`, `docs/api/orders.md` (new `AdjustOrderStock` contract and the lock-order convention FD-7), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: no pending dependency; ships no behaviour change. Negotiable: counter model and orphan detection were negotiated and settled (D8, D9). Valuable: it is the single, tested place every later stock write goes through (an enabler, valuable only together with 0089 — see R-1). Estimable: one migration, one model helper, one action. Small: about 3 production files plus tests. Testable: every rule has a feature test; no UI.

## Dependencies, risks, open technical questions
**Depends on:** nothing pending. **Blocks:** [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md),
[0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md),
[0091](0091-order-cancellation-stock-return-opt-in-backend.md),
[0092](0092-order-refund-stock-return-backend.md).

- **R-1 — Release window.** A line created **after** this migration but **before** 0089 ships gets `S = 0` without being
  deducted, and a later cancel-with-return would inflate stock. Mitigation (recommended): 0088 and 0089 reach `main` and
  production together (0089 is next in line; 0090–0092 depend on 0089 so nothing reads the counter earlier).
- **R-2 — Product editor lost update.** `UpdateProduct`/`UpdateProductVariant` save `stock` from a form value; an editor
  open while an order deducts will overwrite the deduction on save. Pre-existing shape, out of scope; backlog item B-1.
- **R-3 — Low-stock notifications** (PRD foundations, "Low or zero stock") are not implemented; this action is the natural
  trigger point later. Out of scope; backlog item B-2.

### OQ-1 — The returned-units counter and the cancel formula — resolved (option a)
**Resolved 2026-11-08 by the user: option (a)**, recorded as D8. The analysis is kept below for the record.

D6 says the counter is updated on **every** restock, including refund restocks (D5). D4 says the returnable units are
`quantity - refunded_quantity - stock_returned_quantity`. Together they subtract refunded-and-restocked units twice.

Where it bites (verified against `Order::isManuallyCancellable()`): **not** in a normal manual cancellation — a
`PartiallyRefunded` order cannot be cancelled, so a cancellable order has no refunded units and D4 reduces to
`quantity - counter` (0091 only needs a clamp at 0 for the post-full-refund window before the automatic cancellation runs).
It bites in the two other places the same "returnable units" quantity is needed:
1. **Lowering a quantity after a refund-with-restock (0090).** 5 units, refund 2 and return them (refunded 2, counter 2),
   lower to 3 with return: D4 says at most `5 - 2 - 2 = 1` unit returns, but 3 non-refunded units are out of inventory and
   2 of them are being removed, so 1 unit is lost from stock forever.
2. **Refund restock after a cancellation with return (0092).** 5 units, cancel with return (counter 5), then refund 5 and
   choose "return all": D5 bounds the restock only by the refund's units, so the same 5 units go back twice (inflation).
   The same happens for a legacy line (counter = quantity, never deducted).
Options:
- **(a) (chosen)** Keep D6 (every restock increments the counter) and define returnable units as
  `quantity - max(refunded_quantity, stock_returned_quantity)`; bound a refund restock by `quantity - stock_returned_quantity`
  too. Correct in every case we traced (legacy, refund-then-lower, cancel-then-refund, partial restock) and never inflates;
  for a manual cancellation it gives the same number as D4.
- **(b)** Keep D4 literally and make refund restocks **not** increment the counter. Fixes case 1, but case 2 (and the
  legacy line) still return the same units twice.
- **(c)** Keep D4 and D6 literally, and only add the `quantity - counter` bound to refund restocks. Fixes case 2 (no
  inflation); case 1 still under-returns (inventory drifts low, never high).
The [per-operation table](#counter-model-decided-d8) is the model adopted with option (a).

### OQ-2 — How a deleted-variant line is recognised — resolved (option a)
**Resolved 2026-11-08 by the user: option (a)**, recorded as D9.

Variants are hard-deleted and the FK is `nullOnDelete()`, so a line of a deleted variant becomes indistinguishable from a
plain-product line, and D1's "adjust nothing" cannot be implemented from the current columns. Options:
- **(a) (chosen)** Add `order_items.ordered_as_variant` (`BOOLEAN NOT NULL DEFAULT false`) in this story's migration,
  written at creation (0089) from the resolved variant; backfill `true` where `product_variant_id IS NOT NULL` **or** the
  `product_sku` snapshot differs from the product's current SKU. `stockTarget()` returns `null` when the flag is true and the
  variant id is null. Exact, cheap, one more column in the ER diagram.
- **(b)** Runtime heuristic: treat the line as an orphaned variant line when its `product_sku` snapshot differs from the
  product's current SKU. No schema change, but breaks if a product's SKU is edited after the order.
- **(c)** Runtime heuristic: treat it as orphaned when the product currently has variants. Wrong for a product that gained
  or lost variants after the order.

### OQ-3 — A line with zero returnable units (affects 0094–0096 copy) — assumed default, not yet user-confirmed
See [0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md#dependencies-risks-open-technical-questions).

### OQ-4 — Virtual products — assumed default, not yet user-confirmed
D1 makes every product line move `products.stock`, including `virtual` products. **Assumed default (recommended): keep D1 as
written** (no type exception, simplest, and the dashboard low-stock widget already reads virtual stock). Alternative: skip
`virtual` products entirely. Not yet user-confirmed.

### Backlog (technical tasks for later)
- B-1 Make the product/variant editors write stock as a delta or with optimistic locking (R-2).
- B-2 Low/zero-stock notifications triggered from `AdjustOrderStock`'s applied targets (R-3).
- B-3 A per-line stock movement history (who/when/why) if an audit trail is wanted — not requested.

## Debate record
- 2026-11-08 — Three Amigos (facilitated by product-owner from the backend-expert and frontend-expert designs and the
  user-confirmed decisions D1–D7). backend-qa test list folded into *Tests to perform*. database-expert: migration shape and
  backfill. OQ-1 and OQ-2 resolved by the user the same day as option (a) (D8, D9); OQ-3/OQ-4 on assumed defaults.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner); OQ-1/OQ-2 resolved by the user 2026-11-08 (option (a) each). Phase 2: pending.
