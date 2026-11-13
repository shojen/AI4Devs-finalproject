# [0092a] `RecordRefund` locks the order before its lines — no behaviour change (backend)

> **Status: New — created 2026-11-08 by splitting former story 0092 after the Phase 2 INVEST review (user decision); pending
> review.** Epic 3 follow-up, order-stock series. Pure concurrency fix with **no behaviour change**; no UI pair. Its sibling
> [0092b](../in-progress/0092b-order-refund-stock-return-backend.md) (refund stock return) depends on it.

## Description
`RecordRefund` re-reads and locks the `orders` row **before** locking the order's line rows, matching every other order write
(`orders → order_items`). This closes the documented deadlock between a refund and a concurrent line-item edit, and removes the
"Known concurrency gap" note from the docs. Results, refusals and events are unchanged.

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- Inside its transaction `RecordRefund` does `$order->refresh()` (no lock), then `$order->items()->lockForUpdate()->get()`; the
  `orders` row is only locked implicitly by the final `UPDATE orders`. `AddOrderItem`, `UpdateOrderItemQuantity` and
  `RemoveOrderItem` lock `orders` first, so the two orders of acquisition can deadlock
  ([docs/api/orders.md, "Known concurrency gap"](../../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities);
  epic-3 digest, story 0055 open item).
- `Gate::authorize('orders.refund')` and the shape validation run before the transaction; `OrderFullyRefunded` is dispatched
  after commit.

## Decisions
- **D-1** — Inside the transaction: `Order::query()->whereKey($order->id)->lockForUpdate()->first()` (or `findOrFail` semantics as
  the line-item actions use) **first**, then the items `orderBy('id')->lockForUpdate()` (the added `orderBy('id')` is intentional — deterministic line-lock order — and changes no persisted value). All guards (payment state, ownership,
  outstanding units) run on the locked rows in their current order.
- **D-2** — No signature change, no new refusal, no new event, no lang change. The plain `$order->refresh()` at the top of the
  transaction is replaced by the locked re-read into a local `$lockedOrder`, which every guard and write uses. **The caller's
  `$order` instance is still refreshed**: the action keeps returning `$order->refresh()` after the writes, exactly as today, so
  a caller that reuses its instance (e.g. `Orders\Show`) sees the same state as before. Every persisted value and the returned
  `Order` are identical to today.
- **D-3** — This lock order is the one 0088a FD-7 documents (`orders → order_items → products → product_variants`); 0092b's stock
  call relies on it (0088a FD-2). 0088 (done) already added the counter lines to `RecordRefund.php`; no live conflict (`conflict_risk_with: []`).

## Gherkin
```gherkin
Feature: A refund and a line edit on the same order never block each other forever

  Scenario: A refund records the same result as before
    Given an order administrator who may refund, with a paid order of 4 units of "Mug"
    When the order administrator refunds 2 units of "Mug"
    Then the refund is recorded and the order shows as partially refunded

  Scenario: A refund takes the same locks, in the same order, as a line edit
    Given an order administrator who may refund, with a paid order of 4 units of "Mug" and 1 unit of "Plate"
    When the order administrator refunds 2 units of "Mug"
    Then the refund locks the order before its lines, in the same order a line edit does
```

## Files to create/modify
- `app/Actions/Orders/RecordRefund.php` — lock the order row first (D-1); docblock step list updated.
- `tests/Feature/Orders/RecordRefundLockOrderTest.php` — new.

## Tests to perform
- [ ] Query log (`RecordRefund`): `SELECT … FROM orders … FOR UPDATE` precedes `SELECT … FROM order_items … FOR UPDATE`.
- [ ] Query log (`UpdateOrderItemQuantity`, same assertion helper): `orders … FOR UPDATE` precedes `order_items … FOR UPDATE` — the two actions acquire locks in the same order. (No real-parallelism test: `MarkOrderAsPaidConcurrencyTest` is deterministic, and this project has no two-connection harness.)
- [ ] Deterministic hook test (required): the guards read the locked order instance (e.g. the payment state is changed between the caller's load and the call; the refund is refused/accepted according to the row as re-read under the lock, not the caller's stale instance).
- [ ] The caller's `$order` instance reflects the refund after the call (D-2).
- [ ] Regression: `RecordRefundTest`, `ShowRefundTest`, `MarkOrderAsPaidRefundInteractionTest`, `AutoCancelFullyRefundedOrderTest` green unchanged.

## Expected outcome
Refunds and line edits on the same order serialize on the order row instead of deadlocking; nothing else changes.

## Acceptance criteria
- [ ] The `orders` row is locked before the line rows in `RecordRefund`.
- [ ] No behaviour, signature, refusal or event change (regression suites green unchanged).
- [ ] Lock acquisition order matches the line-item actions (`orders` before `order_items`), proven by the query-log assertions.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): remove the "Known concurrency gap" note from `docs/api/orders.md#ordersshow…`, add `RecordRefund` (`orders → order_items`) to the "Lock-order convention (project-wide)" section of `docs/api/orders.md`, and close only the lock-order part of the story 0055 open item in `ai-spec/tasks/_digests/epic-3.md` (the "interim picker disclosure" and "unbounded list" items stay open)
- [ ] Acceptance criteria met

## INVEST note
Independent: no pending dependency. Negotiable: the test harness choice. Valuable: removes a reachable 500 (deadlock) on the order
screen. Estimable/Small: one method's lock sequence. Testable: query-log assertions on both actions (plus an optional deterministic hook test).

## Dependencies, risks, open technical questions
**Depends on:** nothing pending. **Blocks:** [0092b](../in-progress/0092b-order-refund-stock-return-backend.md).
- **R-1** — A lock-order change on a hot path; the query-log assertions are the guard (no real-parallelism test exists in this project).
- **Open** — none.

## Debate record
- 2026-11-08 — Split out of former 0092 per the Phase 2 review and the user's decision.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner, split from 0092).
- Phase 2: INVEST review 2026-11-08 (of former 0092): findings received → split and rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: two-connection test replaced by query-log assertions on both actions (optional deterministic hook test), acceptance criterion and scenario 2 rephrased, D-2 states the caller's instance is still refreshed → rewritten, pending re-review.
- Phase 2: INVEST re-review #4 2026-11-08 → rewritten, pending re-review (FD references point at 0088a after the split; file overlap with 0088 noted).
- Phase 2: INVEST review 2026-11-12 (code-reviewer) → **APPROVED**, nothing blocking. Verified facts, doc references, dependencies and file overlap checked against HEAD. Four non-blocking notes applied (D-3 made historical, D-1 `orderBy('id')` declared intentional, DoD docs scope widened, hook test made required).
- Phase 3: implementation + tests green 2026-11-12 (RecordRefund locks `orders` before `order_items`; full suite 6,055 tests, 0 failures, 8 skipped pre-existing; Pint and Larastan clean).
- Phase 4: security audit 2026-11-12 (appsec-auditor) → **APPROVED**, no blocking findings. Non-blocking, pre-existing and out of scope: `OrderFullyRefunded::dispatch()` is only "after commit" while `RecordRefund` is the outermost transaction (a future caller wrapping it in its own transaction would fire the auto-cancel early; the listener has its own lock); candidate for a separate story (`DB::afterCommit()` / `ShouldDispatchAfterCommit`).
- Phase 5: final code review 2026-11-12 (code-reviewer) → **APPROVED**, no blocking findings. Three non-blocking notes applied to `RecordRefundLockOrderTest` (`toBeInt()` guard on the edit-side assertion, typed `QueryExecuted` listener, hook test also asserts the `items` error key and zero `refunds` rows); story test file 3 tests / 9 assertions green, Pint and Larastan clean. Phase 6 (docs-keeper) pending.
- Phase 6: documentation 2026-11-12 (docs-keeper): `docs/api/orders.md` (gap note removed, lock-order convention gains `RecordRefund`) and the epic-3 digest (lock-order part of the 0055 open item closed) synced.
- Phase 7: closed 2026-11-12 — moved to `done/`, two-direction link check clean, `tasks-map.md` and `tasks-status.json` regenerated (`0092b` now `ready`), PR opened against `main`.
