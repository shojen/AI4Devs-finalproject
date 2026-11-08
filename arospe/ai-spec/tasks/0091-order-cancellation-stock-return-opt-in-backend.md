# [0091] Order cancellation returns stock only on request (backend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.** Epic 3
> follow-up bug fix, order-stock series. Fullstack pair: **related_task_id 0095** (the cancel dialog). Blocked on
> [0088a](0088a-order-stock-auto-deduct-on-create-and-add-backend.md) (automatic deduction and `AdjustOrderStock`) and, through it, [0088](0088-order-stock-adjustment-foundation-backend.md) (counters).

## Description
`CancelOrder` gains `bool $adjustStock = false`. With `true`, every line returns its returnable units to its variant or product
stock and its undeducted-units counter (`stock_undeducted_quantity`) grows by the same amount, in one transaction under the order lock. With `false` stock is untouched, as
today. `AutoCancelFullyRefundedOrder` stays stock-free (pinned by a regression test).

## Type
backend (related_task_id: 0095) | includes database-expert: no

## Verified facts that shaped the story
- `CancelOrder` today: `Gate::authorize('cancel', $order)` → refuse already `Cancelled` (`ValidationException` on `status`) →
  refuse `! isManuallyCancellable()` (`OrderCancellationBlockedException`, 409) → `forceFill(['status' => Cancelled])->save()`.
  **No `DB::transaction()` and no row lock** today.
- **`OrderPolicy::cancel` (≈ lines 142–147) also checks `isManuallyCancellable()`** besides `orders.edit` and `orders.refund`.
  An ordinary, fully-permitted administrator is therefore refused for a partially refunded, shipped/delivered or already-cancelled
  order **at `Gate::authorize`, as an `AuthorizationException`**; the action's own `ValidationException` / 409 refusals are reached
  only by a super administrator (`Gate::before`). Both refusal paths are tested below.
- `tests/Feature/Orders/CancelOrderTest.php` (≈ line 218, "There is no confirmation bypass") asserts `__invoke()` has **exactly
  one** parameter; this story changes that test (see Files).
- `AutoCancelFullyRefundedOrder` locks the order in its own transaction and writes only `status`.
- Lines stay editable after cancellation (`isLineItemEditable()` true on `Cancelled`); 0090's rules plus this story's counter
  update (U + R) keep that path from returning units twice.

## Decisions
- **D-1 (user D3)** — `CancelOrder::__invoke(Order $order, bool $adjustStock = false): Order`; default `false`, server-side only.
  This is **not** a confirmation bypass: it decides only the stock side effect; every refusal still applies.
- **D-2** — The action opens `DB::transaction()`, re-reads the order under `lockForUpdate()`, and **re-runs both existing state
  guards against the locked row** (after `Gate::authorize`, which stays first and outside the transaction). Refusal order and
  types unchanged. The class docblock's "no transaction" note is rewritten with the reason (a second write now exists).
- **D-3 (user D4 superseded by 0088 D8, two counters user-confirmed 2026-11-08)** — Returnable units per line =
  `OrderItem::returnableStockUnits()` = `(quantity − refunded_quantity) − stock_undeducted_quantity` (never negative: 0088
  keeps `U <= Q − F`). A cancellable order is never `PartiallyRefunded`; a
  fully refunded order in the window before `AutoCancelFullyRefundedOrder` runs returns 0 per line.
- **D-4** — Lines locked `orderBy('id')->lockForUpdate()` after the order row; then stock rows (0088a FD-7). **`stock_undeducted_quantity`
  = `U + R` per applied line; unchanged for skipped or null-target lines; `stock_refunded_not_out_quantity` never touched** (only ids returned by `AdjustOrderStock` are updated).
- **D-5 (user D5)** — `AutoCancelFullyRefundedOrder` is **not** changed; a regression test pins that it never moves stock.
- **D-6 (user D7)** — No authorization change.

## Gherkin
```gherkin
Feature: Cancelling an order returns stock only when the order administrator asks for it

  Scenario: Cancelling with stock return puts the units back
    Given an order administrator who may refund, with a pending order holding 3 units of "Mug" and 2 units of the "Red / M" variant
    And "Mug" has 10 units and "Red / M" has 4 units in stock
    When the order administrator cancels the order and returns its units to stock
    Then the order is cancelled
    And "Mug" has 13 units and "Red / M" has 6 units in stock

  Scenario: Cancelling without stock return leaves stock unchanged
    Given an order administrator who may refund, with a pending order holding 3 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator cancels the order without changing stock
    Then the order is cancelled
    And "Mug" still has 10 units in stock

  Scenario: A legacy order returns nothing
    Given an order administrator who may refund, with a pending order recorded before stock tracking existed
    When the order administrator cancels the order and returns its units to stock
    Then the order is cancelled
    And no product or variant stock changes

  Scenario: A line whose product was deleted is skipped
    Given an order administrator who may refund, with a pending order holding 3 units of "Mug" and a line of a product since deleted
    When the order administrator cancels the order and returns its units to stock
    Then "Mug" stock rises by 3
    And the deleted product's line moves no stock

  Scenario: A partially refunded order cannot be cancelled and moves no stock
    Given an order administrator who may refund, with a processing order of 5 units of "Mug" of which 2 were refunded
    When the order administrator cancels the order and returns its units to stock
    Then the cancellation is refused
    And "Mug" stock is unchanged

  Scenario: A fully refunded order not yet cancelled automatically returns no refunded units
    Given a super administrator, with a processing order of 5 units of "Mug" fully refunded and returned to stock, not yet cancelled automatically
    When the super administrator cancels the order and returns its units to stock
    Then the order is cancelled
    And "Mug" stock is unchanged

  Scenario: Cancelling an already cancelled order moves no stock
    Given a super administrator, with an order already cancelled with its units returned to stock
    When the super administrator cancels the order again and returns its units to stock
    Then the cancellation is refused because the order is already cancelled
    And no product or variant stock changes

  Scenario: A shipped order cannot be cancelled and moves no stock
    Given a super administrator, with a shipped order holding 3 units of "Mug"
    When the super administrator cancels the order and returns its units to stock
    Then the cancellation is refused because the order is no longer cancellable
    And "Mug" stock is unchanged

  Scenario: Removing a line after a cancellation with stock return returns nothing more
    Given an order administrator, with a cancelled order whose "Mug" units were returned to stock on cancellation
    When the order administrator removes the "Mug" line and adjusts stock
    Then "Mug" stock is unchanged

  Scenario: An administrator who may not refund cannot cancel or move stock
    Given an order administrator who may edit orders but may not refund, with a pending order
    When the administrator cancels the order and returns its units to stock
    Then the action is refused as not authorized
    And no product or variant stock changes

  Scenario: The automatic cancellation after a full refund never moves stock
    Given an order administrator who may refund, with a paid order holding 2 units of "Mug", fully refunded without returning stock
    When the automatic cancellation of fully refunded orders runs for it
    Then the order is cancelled
    And "Mug" stock is unchanged
```

## Files to create/modify
- `app/Actions/Orders/CancelOrder.php` — `bool $adjustStock = false`; transaction + order lock + both guards re-run on the locked
  row; lines locked by id; `AdjustOrderStock`; `stock_undeducted_quantity` update (D-4); docblock rewritten (D-1, D-2).
- `tests/Feature/Orders/CancelOrderTest.php` — rewrite the "accepts exactly one parameter" assertion (≈ line 218): `__invoke()`
  has two parameters, the first typed `Order`, the second `bool $adjustStock` with default `false`; keep the assertion that no
  confirmation-bypass parameter exists (no other parameter, a blocked cancellation still throws with `adjustStock: true`).
- `tests/Feature/Orders/CancelOrderStockTest.php` — new.
- `tests/Feature/Orders/AutoCancelFullyRefundedOrderTest.php` — add the stock-free regression case.
- `tests/Feature/Orders/RemoveOrderItemStockTest.php` (created by 0090) — add the remove-after-cancel case (if 0090 is not yet
  merged, place it in `CancelOrderStockTest.php`).

## Tests to perform
- [ ] Every Gherkin scenario, product and variant lines.
- [ ] `stock_undeducted_quantity` = `U + R` per applied line after cancel-with-return; unchanged for skipped or null-target lines and after cancel-without-return; `stock_refunded_not_out_quantity` unchanged.
- [ ] 0088 sequences 1 (round trip, returns 2) and 10 (raise with deduction, returns 5) end at stock 10 through the real actions; after a cancel with return, `returnableStockUnits()` is 0 for every line and `refundedUnitsOutOfStock()` (`F − K`) is unchanged.
- [ ] **Both refusal paths:** an ordinary administrator holding `orders.edit` + `orders.refund` → `AuthorizationException` for partially refunded, shipped and already-cancelled orders (policy state clause); a super administrator → `ValidationException` (already cancelled) / `OrderCancellationBlockedException` (shipped) from the action's guards re-run under the lock. Stock untouched in every case.
- [ ] Authorization refusal is still first (an unauthorized caller learns nothing).
- [ ] Concurrency: two cancellations of the same order serialized by the order lock — the second is refused and stock is returned once (pattern of `MarkOrderAsPaidConcurrencyTest`).
- [ ] Signature test rewritten (see Files); `adjustStock: true` never bypasses a refusal.
- [ ] Regression: `CancelOrderTest` (except the rewritten signature assertion), `AutoCancelFullyRefundedOrderTest`, `ShowCancellationTest` green.

## Expected outcome
Cancelling an order can put its outstanding units back in exactly the right stock rows, once, and never for units already
refunded-and-written-off or already returned.

## Acceptance criteria
- [ ] `CancelOrder` accepts `adjustStock` (default `false`).
- [ ] Cancellation and stock return are one transaction under the order lock; both guards re-run on the locked row; refusals keep their order and types.
- [ ] Returnable units come from `returnableStockUnits()`; counters per D-4; no unit is ever returned twice across cancel, refund, quantity change and removal.
- [ ] `AutoCancelFullyRefundedOrder` never moves stock (test).
- [ ] No authorization change.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` (CancelOrder contract, transaction now present), `docs/architecture/authorization/domain-invariants.md` only if it states CancelOrder has no transaction, `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0088a (and 0088) only. Negotiable: AutoCancel explicitly excluded (D-5). Valuable: a cancelled order can give its goods
back to inventory. Estimable/Small: one action gains a transaction, a lock and one stock call, plus one rewritten test.
Testable: stock asserted per scenario, both refusal paths, a concurrency test.

## Dependencies, risks, open technical questions
**Depends on:** [0088a](0088a-order-stock-auto-deduct-on-create-and-add-backend.md) (hard) and [0088](0088-order-stock-adjustment-foundation-backend.md) (transitively). **Blocks:**
[0095](0095-order-cancellation-stock-dialog-ui.md). Soft: [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md)
(shared test file; the remove-after-cancel case uses its parameter).

- **R-1** — Adding a transaction to `CancelOrder` changes a documented design note from story 0050; the reason (a second write
  now exists) must be recorded in the docblock and the digest so it is not "fixed" back.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the backend-expert design and decisions D3, D4, D5, D7.
- 2026-11-08 — Phase 2 review applied: signature test rewrite, policy-level refusal for ordinary admins (super administrator
  actor for the action-level refusals), counter bullet made precise, actor wording.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner).
- Phase 2: INVEST review 2026-11-08: findings received → rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: helper and counter names aligned with the two-counter model (0088 D8) → rewritten, pending re-review.
- Phase 2: INVEST re-review #3 2026-11-08 → rewritten, pending re-review (K and helper names aligned with the corrected two-counter model, sequence numbers updated, last scenario opens with a named actor).
- Phase 2: INVEST re-review #4 2026-11-08 → rewritten, pending re-review (dependency moved to 0088a after the 0088 split, FD reference renumbered).
