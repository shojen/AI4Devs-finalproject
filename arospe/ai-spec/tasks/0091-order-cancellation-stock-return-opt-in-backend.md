# [0091] Order cancellation returns stock only on request (backend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 4 of the
> order-stock series 0088–0096. Fullstack pair: **related_task_id 0095** (the cancel dialog). Blocked on
> [0088](0088-order-stock-adjustment-foundation-backend.md) and [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md).
> 0088's OQ-1 was resolved 2026-11-08 as option (a) (0088 D8): this story uses the shared `returnableStockUnits()` helper.
> A manual cancellation is refused while the order is `PartiallyRefunded`, so at cancel time D8 and D4's literal formula give
> the same number (see D-3).

## Description
`CancelOrder` gains `bool $adjustStock = false`. With `true`, every line returns its returnable units to its variant or
product stock and its counter grows by the same amount, in one transaction under the order lock. With `false` stock is
untouched, as today. `AutoCancelFullyRefundedOrder` stays stock-free (pinned by a regression test).

## Type
backend (related_task_id: 0095) | includes database-expert: no

## Verified facts that shaped the story
- `CancelOrder` today: `Gate::authorize('cancel', $order)` → refuse already `Cancelled` (`ValidationException` on `status`) →
  refuse `! isManuallyCancellable()` (`OrderCancellationBlockedException`, 409) → `forceFill(['status' => Cancelled])->save()`.
  **It has no `DB::transaction()` and no row lock** (its docblock says so deliberately: a single-row write). The brief's
  "cancel twice is already rejected under the order lock" is therefore **not** true today; this story adds the lock.
- `OrderPolicy::cancel` requires `orders.edit` **and** `orders.refund` (user D7, unchanged).
- `AutoCancelFullyRefundedOrder` locks the order in its own transaction and writes only `status`.
- `Order::isLineItemEditable()` is true on `Cancelled`, so a line **can** be removed or re-quantified after cancellation
  (the brief's "remove after cancel is already rejected" is not true either); 0090's rules plus this story's counter update
  keep that path from returning units twice.

## Decisions
- **D-1 (user D3)** — `CancelOrder::__invoke(Order $order, bool $adjustStock = false): Order`; default `false`, server-side only.
- **D-2** — The action now opens `DB::transaction()`, re-reads the order under `lockForUpdate()`, and runs **both** existing
  state guards against the locked row (after `Gate::authorize`, which stays first and outside the transaction). Order of
  refusals unchanged. The class docblock's "no transaction" note is rewritten with the reason (a second statement now exists).
- **D-3 (user D4, superseded by 0088 D8)** — Returnable units per line = `OrderItem::returnableStockUnits()` =
  `max(0, quantity - max(refunded_quantity, stock_returned_quantity))`.
  `Order::isManuallyCancellable()` refuses `PartiallyRefunded`, so a cancellable order normally has `refunded_quantity = 0`.
  The one exception is a fully refunded order (`Refunded`) in the short window before `AutoCancelFullyRefundedOrder` runs
  (it is dispatched after the refund commits): there every unit is refunded, so the helper returns 0 and nothing goes back.
  Lines with 0 returnable units or a `null` stock target (incl. 0088 D9's orphaned variant lines) are skipped.
- **D-4** — Lines are locked `orderBy('id')->lockForUpdate()` after the order row; the counter of each applied line grows by
  its returned units (`forceFill`), only for ids `AdjustOrderStock` reports as applied.
- **D-5 (user D5)** — `AutoCancelFullyRefundedOrder` is **not** changed; a regression test pins that it never moves stock.
- **D-6 (user D7)** — No authorization change.

## Gherkin
```gherkin
Feature: Cancelling an order returns stock only when the order administrator asks for it

  Scenario: Cancelling with stock return puts the units back
    Given an order administrator allowed to cancel, with a pending order holding 3 units of "Mug" and 2 units of the "Red / M" variant
    And "Mug" has 10 units and "Red / M" has 4 units in stock
    When the order administrator cancels the order and returns its units to stock
    Then the order is cancelled
    And "Mug" has 13 units and "Red / M" has 6 units in stock

  Scenario: Cancelling without stock return leaves stock unchanged
    Given an order administrator allowed to cancel, with a pending order holding 3 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator cancels the order without changing stock
    Then the order is cancelled
    And "Mug" still has 10 units in stock

  Scenario: A partially refunded order cannot be cancelled and moves no stock
    Given an order administrator allowed to cancel, with a processing order of 5 units of "Mug" of which 2 were refunded
    When the order administrator cancels the order and returns its units to stock
    Then the cancellation is refused because the order is partially refunded
    And "Mug" stock is unchanged

  Scenario: A fully refunded order not yet cancelled automatically returns no refunded units
    Given an order administrator allowed to cancel, with a processing order of 5 units of "Mug" fully refunded and returned to stock, not yet cancelled automatically
    When the order administrator cancels the order and returns its units to stock
    Then the order is cancelled
    And "Mug" stock is unchanged

  Scenario: A legacy order returns nothing
    Given an order administrator allowed to cancel, with a pending order recorded before stock tracking existed
    When the order administrator cancels the order and returns its units to stock
    Then the order is cancelled
    And no product or variant stock changes

  Scenario: A line whose product was deleted is skipped
    Given an order administrator allowed to cancel, with a pending order holding 3 units of "Mug" and a line of a product since deleted
    When the order administrator cancels the order and returns its units to stock
    Then "Mug" stock rises by 3
    And the deleted product's line moves no stock

  Scenario: Cancelling an already cancelled order moves no stock
    Given an order administrator allowed to cancel, with an order already cancelled with its units returned to stock
    When the order administrator cancels the order again and returns its units to stock
    Then the cancellation is refused because the order is already cancelled
    And no product or variant stock changes

  Scenario: A blocked cancellation moves no stock
    Given an order administrator allowed to cancel, with a shipped order holding 3 units of "Mug"
    When the order administrator cancels the order and returns its units to stock
    Then the cancellation is refused
    And "Mug" stock is unchanged

  Scenario: Removing a line after a cancellation with stock return returns nothing more
    Given an order administrator, with a cancelled order whose "Mug" units were returned to stock on cancellation
    When the order administrator removes the "Mug" line and adjusts stock
    Then "Mug" stock is unchanged

  Scenario: An administrator without the refund permission cannot cancel or move stock
    Given an administrator with the orders edit permission but not the orders refund permission, with a pending order
    When the administrator cancels the order and returns its units to stock
    Then the action is refused as not authorized
    And no product or variant stock changes

  Scenario: The automatic cancellation after a full refund never moves stock
    Given a paid order holding 2 units of "Mug", fully refunded by an order administrator without returning stock
    When the automatic cancellation of fully refunded orders runs for it
    Then the order is cancelled
    And "Mug" stock is unchanged
```

## Files to create/modify
- `app/Actions/Orders/CancelOrder.php` — `bool $adjustStock = false`; transaction + order lock + guards re-run on the locked
  row; lines locked by id; `AdjustOrderStock`; counter update; docblock rewritten (D-2).
- `tests/Feature/Orders/CancelOrderStockTest.php` — new.
- `tests/Feature/Orders/AutoCancelFullyRefundedOrderTest.php` — add the stock-free regression case.
- `tests/Feature/Orders/RemoveOrderItemStockTest.php` (created by 0090) — add the remove-after-cancel case **or** keep it in
  `CancelOrderStockTest.php`; decide in Phase 3 to avoid a write-set overlap if 0090 is in flight.

## Tests to perform
- [ ] Feature: every Gherkin scenario, product and variant lines.
- [ ] Feature: counter after cancel-with-return equals `quantity` minus nothing left returnable; unchanged after cancel-without-return.
- [ ] Negative: authorization refusal is still first (an unauthorized caller learns nothing, stock untouched); already-cancelled and blocked states refuse under the lock; stock untouched.
- [ ] Concurrency: two cancellations of the same order serialized by the order lock — the second is refused and stock is returned once (two-connection test or a lock-held assertion, per the project's existing concurrency test pattern, e.g. `MarkOrderAsPaidConcurrencyTest`).
- [ ] Regression: `CancelOrderTest`, `AutoCancelFullyRefundedOrderTest`, `ShowCancellationTest` green unchanged (default `false`).

## Expected outcome
Cancelling an order can put its outstanding units back in exactly the right stock rows, once, and never for units already
refunded-and-written-off or already returned.

## Acceptance criteria
- [ ] `CancelOrder` accepts `adjustStock` (default `false`).
- [ ] The cancellation and its stock return are one transaction under the order lock; refusals keep their current order and types.
- [ ] Returnable units come from `returnableStockUnits()` (0088 D8, never negative); no unit is ever returned twice across cancel, refund, quantity change and removal.
- [ ] `AutoCancelFullyRefundedOrder` never moves stock (test).
- [ ] No authorization change.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (CancelOrder contract, transaction now present), `docs/architecture/authorization/domain-invariants.md` only if it states CancelOrder has no transaction, `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088/0089 only. Negotiable: hook into AutoCancel explicitly excluded (D-5). Valuable: a cancelled order can give its goods back to inventory. Estimable/Small: one action gains a transaction, a lock and one stock call. Testable: stock asserted per scenario plus a concurrency test.

## Dependencies, risks, open technical questions
**Depends on:** [0088](0088-order-stock-adjustment-foundation-backend.md), [0089](0089-order-stock-auto-deduct-on-create-and-add-backend.md)
(hard). **Blocks:** [0095](0095-order-cancellation-stock-dialog-ui.md).
Soft: [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md) (the remove-after-cancel test exercises its parameter;
if 0090 is not merged yet, that one test moves to 0090).

- **Resolved:** 0088 OQ-1 (D8) and OQ-2 (D9), user decision 2026-11-08 — nothing blocks Phase 2.
- **R-1** — Adding a transaction to `CancelOrder` changes a documented design note from story 0050 (D-3 there); the reason
  (a second write now exists) must be recorded in the docblock and the digest so it is not "fixed" back.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the backend-expert design and decisions D3, D4, D5, D7.
  Two brief claims corrected against the code (CancelOrder has no lock today; lines are editable after cancel).

## Approval record
- Phase 1: complete 2026-11-08 (product-owner). Phase 2: pending.
