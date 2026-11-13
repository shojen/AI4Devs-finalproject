# [0092b] Refunds can return refunded units to stock (backend)

> **Status: New — created 2026-11-08 by splitting former story 0092 after the Phase 2 INVEST review (user decision); pending
> review.** Epic 3 follow-up bug fix, order-stock series. Fullstack pair: **related_task_id 0096** (the refund modal).
> Blocked on [0088a](../done/0088a-order-stock-auto-deduct-on-create-and-add-backend.md) (`AdjustOrderStock`; and, through it, [0088](../done/0088-order-stock-adjustment-foundation-backend.md)'s counters and refund rule) and
> [0092a](../done/0092a-record-refund-lock-order-backend.md) (the order-first lock order this story's stock call relies on: 0088a FD-7 and the forward rule in
> `docs/api/orders.md#lock-order-convention-project-wide`, appsec L-1 — `AdjustOrderStock` before any `FOR UPDATE` on `order_items`).

## Description
`RecordRefund` gains `array $stockReturns = []` (order item id → units to put back in stock). By default a refund moves no stock,
exactly as today. When the administrator chooses to return units, each listed line returns at most the units it is refunding in
this same call **and** at most the units still held out of inventory, inside the refund's transaction, through
`AdjustOrderStock`. Its counters follow 0088's refund rule (every refunded line, `over` transfer included; this story only adds
the restocked units k).

## Type
backend (related_task_id: 0096) | includes database-expert: no

## Verified facts that shaped the story
- Current signature `RecordRefund::__invoke(Order $order, array $items, ?string $reason = null): Order`; `$items` is
  `order_item_id => quantity_to_refund`, shape-validated through `App\Concerns\OrderValidationRules`.
- After 0092a the transaction locks `orders` first, then `order_items` (`orderBy('id')->lockForUpdate()`), and runs every guard on
  the locked items. This story moves the item lock after the stock call (D-4); `RecordRefundLockOrderTest` (`orders` before
  `order_items`) still holds.
- Error reporting today: shape is checked by `Validator::make()` **before** the transaction (`items` / `items.<id>`, Laravel's
  default rule messages, no custom lang key); business refusals inside the transaction use
  `ValidationException::withMessages(['items' => __('orders.refunds.*')])` (field-level `items`, custom message).
- Every writer of an existing order's lines (`AddOrderItem`, `UpdateOrderItemQuantity`, `RemoveOrderItem`, `RecordRefund`) locks
  the `orders` row first (0092a, 0090 D-7), which is what makes the plain read in D-4 safe.
- `OrderFullyRefunded` is dispatched after commit; its listener runs `AutoCancelFullyRefundedOrder`, which stays stock-free (0091).

## Decisions
- **D-1 (user D5)** — Default: no stock movement. New last parameter `array $stockReturns = []`
  (`@param array<string, int> $stockReturns`); every existing caller unchanged.
- **D-2 (user D5 + 0088 D8, two counters user-confirmed 2026-11-08, corrected after review #3)** — Validation, all-or-nothing (a refused stock return refuses the whole refund, nothing written):
  - shape: an array of non-negative integers (`OrderValidationRules::refundStockReturnRules()`);
  - every key is a key of `$items` in this call (and therefore, after the existing `item_not_owned` guard, a line of **this**
    order). An unknown id, a line of this order not refunded now, **and a line belonging to another order** are all refused with
    `stock_return_not_in_refund`;
  - `units <= $items[key]` (refunded in this refund);
  - **cap:** `units <= min($items[key], OrderItem::returnableStockUnits())`, with `returnableStockUnits()` (R = `(Q − F) − U`)
    read on the item's plain read (D-4 step 3) **before** the refund. A refund takes out-of-inventory units first, so at most R of
    the units it refunds were ever taken out; units given back on cancellation and units never deducted (legacy line, or a raise
    without deduction) can therefore never go back;
  - a `0` entry is accepted and ignored.
  **Error shape (follows how `RecordRefund` reports `items` today):**
  - *Shape* refusals come from `Validator::make()` before the transaction, with `'stock_returns' => ['array']` and
    `'stock_returns.*' => ['integer', 'min:0']` (`OrderValidationRules::refundStockReturnRules()` /
    `refundStockReturnUnitRules()`): a non-array reports on `stock_returns`, a non-integer or negative entry on
    `stock_returns.<order item id>`, with **Laravel's default rule messages** (no custom lang key, exactly as `items` / `items.<id>`).
  - *Business* refusals inside the transaction use `ValidationException::withMessages(['stock_returns' => __(...)])` — field-level
    `stock_returns` (never `stock_returns.<id>`), custom message: `orders.refunds.stock_return_not_in_refund`,
    `orders.refunds.stock_return_exceeds_refunded` (units > `$items[key]`, checked first) and
    **`orders.refunds.stock_return_not_out_of_stock`** (units > R — used for every cap refusal: units given back on
    cancellation, a legacy line, and units added by a raise without deduction; en "Those units are not out of stock, so they
    cannot be returned to it.") — en + es, identical key sets.
  - The former `orders.refunds.stock_return_invalid` key is **dropped**: shape errors use the default messages like `items`, and
    every business refusal has its own key, so nothing would use it.
- **D-3** — "All refunded units" and "Some units" are UI modes only (0096); the action always receives explicit per-line units.
- **D-4 — Step order and lock order (0088a FD-7, forward rule appsec L-1; same pattern as 0090 D-7).** The order is
  `orders → products (by id) → product_variants (by id) → order_items`; `AdjustOrderStock` is called **before any `FOR UPDATE` on
  `order_items`**. `RecordRefund`:
  1. `Gate::authorize('orders.refund')` (unchanged), then the shape `Validator` for `items` and `stock_returns` (D-2), before the transaction.
  2. In the transaction, lock the `orders` row (`Order::query()->whereKey(..)->lockForUpdate()`, unchanged from 0092a).
  3. Read the order's items with a **plain (non-locking) read** (`$lockedOrder->items()->orderBy('id')->get()->keyBy('id')`) and take
     Q, F, U, `refunded_quantity` and the stock target per line from it.
  4. Run **every guard on the plain read**, in today's order — `isRefundable()` on the locked order, `item_not_owned`,
     `exceeds_outstanding_units` — then the D-2 stock validation (`stock_return_not_in_refund`, `stock_return_exceeds_refunded`,
     `stock_return_not_out_of_stock` with R from this read, before the refund). Any refusal: nothing written.
  5. If any `stockReturns` entry is non-zero, call `AdjustOrderStock` for those lines (it locks `products`, then `product_variants`,
     writes stock and reports the applied ids; a null-target line is skipped).
  6. Lock the items `orderBy('id')->lockForUpdate()->get()->keyBy('id')` and **re-verify**, for every line in `$items`, that Q, F, U
     and `refunded_quantity` equal the step-3 values; on any mismatch throw a `LogicException` (programming-error path; the
     transaction rolls back, the stock change included).
  7. Writes, in order: refunds rows → **`OrderItem::applyRefundToStockCounters(f, k_applied)` for every refunded line, null-target
     lines included** (0088 FD-2/FD-6: the `over` transfer U → K always runs; `k_applied` is the requested units for lines whose
     stock was actually adjusted, 0 otherwise — so k depends on the ids `AdjustOrderStock` reported in step 5, the `over` transfer
     does not) + `refunded_quantity` (unchanged) → `refunded_amount` / `payment_status` (unchanged) → commit → dispatch (unchanged).

  With no non-zero stock return, step 5 is skipped and the rest keeps the same order. Why the plain read is correct: every writer
  of an existing order's lines locks the `orders` row first (see *Verified facts*), so step 2 serializes them, and the InnoDB read
  view is created at the first consistent read, i.e. after the `orders` lock is granted. Why step 6 re-verifies anyway: a stale
  snapshot (a future outer transaction that already read `order_items`, or a future writer that skips the `orders` lock) can then
  only cause a refusal, never a wrong refund or stock delta. **This changes 0092a's locking rationale** (guards were decided on
  the locked items; now on the plain read, confirmed on the locked re-read); **Phase 4 (appsec-auditor) must re-check it and the
  `RecordRefund` docblock.**
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
    Then the whole refund is refused because those units are not part of this refund
    And no refund is recorded and no stock changes

  Scenario: Units already returned on cancellation are not returned again by a refund
    Given an order administrator who may refund, with a paid cancelled order of 4 units of "Mug" whose units were returned on cancellation
    When the order administrator refunds 4 units of "Mug" and returns those 4 units to stock
    Then the whole refund is refused because those units are not out of stock
    And "Mug" stock is unchanged

  Scenario: A legacy line's refunded units cannot be returned to stock
    Given an order administrator who may refund, with a paid order recorded before stock tracking existed, holding 4 units of "Mug"
    When the order administrator refunds 2 units of "Mug" and returns those 2 units to stock
    Then the whole refund is refused because those units are not out of stock
    And "Mug" stock is unchanged

  Scenario: Units added without deducting cannot come back through a refund
    Given an order administrator who may refund, with a paid order whose 2-unit "Mug" line was raised to 5 units without deducting, and "Mug" has 8 units in stock
    When the order administrator refunds 3 units of "Mug" and asks to return those 3 units to stock
    Then the whole refund is refused because those units are not out of stock
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
- `app/Actions/Orders/RecordRefund.php` — new parameter; stock validation (D-2); D-4 step order (plain read and guards,
  `AdjustOrderStock`, then the item lock and re-verify); restocked units passed to `applyRefundToStockCounters()`; docblock steps and
  the revised locking rationale.
- `app/Concerns/OrderValidationRules.php` — `refundStockReturnRules()` and `refundStockReturnUnitRules()`.
- `lang/en/orders.php`, `lang/es/orders.php` — the three `orders.refunds.stock_return_*` keys (`not_in_refund`, `exceeds_refunded`, `not_out_of_stock`).
- `tests/Feature/Orders/RecordRefundStockTest.php` — new.

## Tests to perform
- [ ] Every Gherkin scenario, product and variant lines; `0` entries ignored; a default call unchanged.
- [ ] **Validation matrix** (each → `ValidationException`, and refunds rows, `refunded_quantity`, `refunded_amount`, `payment_status`, stock and both counters all unchanged). Shape (D-2, default rule messages): not an array → error on `stock_returns`; non-integer, negative → error on `stock_returns.<id>`. Business (error on `stock_returns`, custom message): unknown key, key of another order's line, key of this order not in `$items` → `stock_return_not_in_refund`; units > refunded now → `stock_return_exceeds_refunded`; units > R (cancel-then-refund, 0088 sequence 7) → `stock_return_not_out_of_stock`; units > R (legacy line) → same key; units > R (raise without deduction then refund 3 asking 3, 0088 sequence 9 — 2 accepted, 3 refused) → same key; units > `$items[key]` and > R at once → `stock_return_exceeds_refunded` (checked first).
- [ ] A second refund of different units of a line whose earlier refunded units were already returned may return its own units (Q 4: refund 2 returning 2, then refund 2 returning 2 → both accepted, stock +4, K 4).
- [ ] Accepted boundaries: 0088 sequence 9 with 2 units → accepted, stock +2, U 2, K 3 (2 restocked + 1 over); the two-counter sequence (refund 2 without restock, raise to 7 without deducting, then refund 2 more returning 2) → accepted, U 2, K 2; afterwards `returnableStockUnits() + refundedUnitsOutOfStock()` equals the units actually out of stock.
- [ ] Counters: for every refunded line `U − over` and `K + k + over` (k = 0 for null-target lines and lines not listed; the `over` transfer still runs for them).
- [ ] The review #3 counterexample through the real actions once 0090 is merged (or arranged with the factory): after a refund of 3 without restock on a 5-unit line raised from 2 without deducting, U 2, K 1 and R = (5 − 3) − 2 = 0, so a later refund of the remaining 2 units asking to restock any of them is **refused (R = 0)** with `stock_return_not_out_of_stock`, and the same refund without restock is accepted.
- [ ] Lock order (query log), with a non-zero stock return on a product line and a variant line: `orders` `FOR UPDATE` → `products` `FOR UPDATE` → `product_variants` `FOR UPDATE` → stock `UPDATE` → first `order_items` `FOR UPDATE` → refunds/item writes; **no** `order_items` locking read appears before the last stock lock/`UPDATE` (forward rule, appsec L-1). Without stock returns: `orders` `FOR UPDATE` before `order_items` `FOR UPDATE` (compatible with `RecordRefundLockOrderTest`).
- [ ] D-4 re-verify: a locked re-read whose Q/F/U or `refunded_quantity` differ from the step-3 plain read throws `LogicException` and rolls back the stock change and every write (arrange by changing the row between the reads, e.g. through a model event hook or `DB::listen` in the test, as 0090 did).
- [ ] Every refusal (authorization, shape, payment state, `item_not_owned`, outstanding units, every D-2 refusal) happens before `AdjustOrderStock`, so stock and counters are unchanged.
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
- [ ] `AdjustOrderStock` runs before any `FOR UPDATE` on `order_items` (D-4, 0088a FD-7, appsec L-1).

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (RecordRefund contract; the lock-order convention's forward rule now lists `RecordRefund` as following it, and the "every order-mutating action locks `orders` first" note describes the plain read → stock → item lock order), `docs/database/schema-orders/items-and-refunds.md` (counter written by refunds), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088a (and 0088) and 0092a only. Negotiable: lang key names. Valuable: returned goods go back to inventory with the refund.
Estimable/Small: one parameter, one rule set, three lang keys, a step reorder already proven in 0090. Testable: a full validation matrix plus stock/counter assertions.

## Dependencies, risks, open technical questions
**Depends on:** [0088a](../done/0088a-order-stock-auto-deduct-on-create-and-add-backend.md), [0092a](../done/0092a-record-refund-lock-order-backend.md) and [0088](../done/0088-order-stock-adjustment-foundation-backend.md) (transitively)
(hard). **Blocks:** [0096](../0096-order-refund-modal-stock-return-ui.md). Soft: [0091](../0091-order-cancellation-stock-return-opt-in-backend.md)
(the cancel-then-refund case; if not merged, arrange the counters with the factory). Shares `lang/*/orders.php` with
[0094](../0094-order-line-quantity-stock-dialog-ui.md), [0094a](../0094a-order-line-removal-stock-dialog-ui.md) and
[0095](../0095-order-cancellation-stock-dialog-ui.md).
- **R-1** — The refunds table does not record how many units each refund returned (only the line's refund-returned counter does); a per-refund
  history would need a column (0088 B-3) — not requested.

## Debate record
- 2026-11-08 — Three Amigos (as part of former 0092) facilitated by product-owner from the backend-expert design and decisions D1,
  D5, D6, D7, D8. Split out per the Phase 2 review and the user's decision; the lock-order fix moved to 0092a.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner, split from 0092).
- Phase 2: INVEST review 2026-11-08 (of former 0092): findings received → split and rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: refund cap redefined for the two-counter model (user decision) and validation matrix extended → rewritten, pending re-review.
- Phase 2: INVEST re-review #3 2026-11-08 → rewritten, pending re-review (cap = min($items[key], returnableStockUnits()) before the refund, over transfer for every refunded line incl. null-target, single lang key stock_return_not_out_of_stock for every cap refusal with matching Then texts, K renamed).
- Phase 2: INVEST re-review #4 2026-11-08 → rewritten, pending re-review (dependency moved to 0088a after the 0088 split, FD references renumbered).
- Phase 2: INVEST re-review #5 2026-11-13 (code-reviewer) → REJECTED. Blocking: D-4 calls `AdjustOrderStock` after `RecordRefund` has already locked `order_items` FOR UPDATE, contradicting 0088a FD-7 and the forward rule in `docs/api/orders.md#lock-order-convention-project-wide` (appsec L-1, names 0092b explicitly) — same defect that rejected 0090 at its re-review #5. Fix: adopt 0090 D-7's pattern (lock `orders`; plain read of the items; all guards incl. the D-2 stock validation and R on the plain read; `AdjustOrderStock`; then items `orderBy('id')->lockForUpdate()` and re-verify Q/F/U per line, mismatch `LogicException`; then writes), add a test that no `order_items` FOR UPDATE precedes the stock locks, and fix the header's "0088a FD-2" reference to FD-7/L-1. Non-blocking: error key for shape refusals (`stock_returns` vs `stock_returns.<id>`, when `stock_return_invalid` is used), lang key for another order's line, review #3 counterexample wording (R is 0 there, so every restock is refused). Facts, counter arithmetic, Gherkin form, dependencies (0088/0088a/0092a done) and independence from 0091 verified OK.
- Phase 1/2: rewritten after re-review #5, pending re-review #6 (2026-11-13, product-owner: D-4 rewritten to the 0090 D-7 pattern with lock-order and re-verify tests; header reference now 0088a FD-7 / appsec L-1; error shape stated — shape on `stock_returns` / `stock_returns.<id>` with default messages, business refusals field-level `stock_returns` with custom keys, `stock_return_invalid` dropped; another order's line → `stock_return_not_in_refund`; review #3 counterexample now "refused (R = 0)"; `docs/api/orders.md` forward rule added to the DoD).
- Phase 2: INVEST re-review #6 2026-11-13 (code-reviewer) → APPROVED. Verified: D-4 7-step order matches `UpdateOrderItemQuantity`/`RemoveOrderItem` (0090 D-7) and the forward rule in `docs/api/orders.md`; `AdjustOrderStock` locks products → variants → UPDATEs, so the lock-order test sequence is achievable; `RecordRefundLockOrderTest` (orders before order_items, FOR UPDATE only) still holds; LogicException inside `DB::transaction` rolls back the stock change; error shapes match today's `RecordRefund` (`Validator::make` default messages on `items`/`items.<id>`, business refusals field-level `items`); three lang keys consistent across D-2, Gherkin, tests, files list and 0096; no `stock_return_invalid` reference remains elsewhere; prior findings resolved; no file overlap with 0091 (CancelOrder + cancel/auto-cancel/remove tests). Non-blocking for Phase 3: (1) step 6 "Q, F, U and `refunded_quantity`" — F is `refunded_quantity`; also treat a listed line missing from the locked re-read as the same `LogicException`; (2) step 7 must write from the step-6 locked rows (`applyRefundToStockCounters` requires a locked row and K is not re-verified), as 0090 does with `$item = $lockedItem`; (3) the "not an array → `stock_returns`" matrix case is unreachable through the typed `array $stockReturns` parameter (TypeError) — drop it or note the `array` rule is defensive; (4) state whether a `0` entry for a key not in `$items` is ignored or refused with `stock_return_not_in_refund` (recommend refused: key check before the zero-skip).
