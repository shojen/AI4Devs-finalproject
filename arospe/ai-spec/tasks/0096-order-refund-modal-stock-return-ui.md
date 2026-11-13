# [0096] Order detail — the refund modal asks whether to return stock (frontend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.** Epic 3
> follow-up bug fix, order-stock series. Fullstack pair: **related_task_id 0092b** (backend `RecordRefund` `stockReturns`).
> Blocked on [0092b](in-progress/0092b-order-refund-stock-return-backend.md).
> **User-confirmed (2026-11-08):** an over-typed stock-return amount is **clamped silently** to the units refunded on that line.

## Description
The refund modal gains a "Return to stock" choice: **None** (default), **All refunded units**, or **Some units**. "Some units"
shows a number input for every refundable line with a stock target; the server clamps each value to the units refunded on that
line in this refund, validates, and passes explicit per-line units to `RecordRefund`.

## Type
frontend (related_task_id: 0092b) | includes database-expert: no

## Verified facts that shaped the story
- Today: `openRefundModal()` (authorizes `orders.refund`, no-op when not `isRefundable()`), `closeRefundModal()` (calls
  `syncRefundInputs()`), `recordRefund(RecordRefund)` which builds `$items` from `refundQuantities` (positive ints only), refuses an
  empty selection with `orders.refunds.nothing_selected`, and renders a `ValidationException` as the `refund` error. Hooks
  `record-refund`, `refund-modal`, `refund-quantity-{id}`, `refund-confirm`.
- `refundQuantities` inputs use **deferred** `wire:model`: the server does not know the refund quantities until submit, so it cannot
  decide per keystroke which lines get a stock input.

## Decisions
- **D-1 (user D5)** — `public string $refundStockReturn = 'none'` (`none|all|partial`) as a `flux:radio.group`. `none` every time
  the modal opens: `openRefundModal()` and `syncRefundInputs()` (hence `closeRefundModal()`) reset **both** `refundStockReturn`
  and `refundStockQuantities`.
- **D-2 — Inputs without live round-trips (chosen over `wire:model.live`).** In `partial` mode, a stock input is rendered for
  **every refundable line with a stock target** (lines with outstanding units and `stockTarget()` not null), regardless of what is
  typed in the refund column; helper text "Up to the units you refund on this line". `public array $refundStockQuantities = []`
  (item id → string; strings, not ints — epic-3 digest). The refund quantities stay deferred; no `.live` binding is added.
- **D-3 — Server mapping on submit.** `none` → `[]`; `all` → every refunded line with a stock target → its refund quantity;
  `partial` → each entry cast to int and **clamped silently** to `0 … refund quantity for that line in this submit` (user-confirmed);
  entries for lines not refunded in this submit or not on this order are dropped. An unknown mode value is a validation error,
  never treated as `none`. The backend still validates everything (0092b D-2).
- **D-4** — `partial` with a mapped total of 0 blocks submit with `orders.refunds.stock_partial_empty` ("Choose at least one unit
  to return, or pick None").
- **D-5 — Copy.** Group label "Return to stock"; options "None", "All refunded units", "Some units"; keys under
  `orders.refunds.stock_*` (en + es). Backend refusals (e.g. `orders.refunds.stock_return_not_out_of_stock`, 0088 D8 cap enforced by 0092b) render as the `refund` error.
- **D-6 — Hooks.** `refund-stock-return` (group), `refund-stock-return-{none|all|partial}`, `refund-stock-quantity-{id}`.
- **D-7 (user D7)** — `orders.refund` only; no new permission.

## Gherkin
```gherkin
Feature: The refund modal lets the order administrator return refunded units to stock

  Scenario: The refund modal returns nothing to stock by default
    Given an order administrator who may refund, on a paid order with 4 units of "Mug"
    When the order administrator opens the refund modal
    Then "Return to stock" is set to none

  Scenario: Reopening the modal forgets a previous stock choice
    Given an order administrator who may refund, who chose "Some units" with 1 unit for "Mug" and then closed the refund modal
    When the order administrator opens the refund modal again
    Then "Return to stock" is set to none and no unit is entered

  Scenario: Refunding without returning stock
    Given an order administrator who may refund, with the refund modal open for 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator confirms the refund leaving "Return to stock" at none
    Then the refund is recorded
    And "Mug" still has 10 units in stock

  Scenario: Returning all refunded units
    Given an order administrator who may refund, with the refund modal open for 2 units of "Mug" and "All refunded units" chosen
    When the order administrator confirms the refund
    Then "Mug" stock rises by 2

  Scenario: Choosing some units shows one input per refundable stock-tracked line
    Given an order administrator who may refund, with the refund modal open on an order with "Mug" and "Plate" lines
    When the order administrator chooses to return some units
    Then an input is shown for "Mug" and an input is shown for "Plate"

  Scenario: Returning some units
    Given an order administrator who may refund, with the refund modal open for 3 units of "Mug" and "Some units" chosen with 1 unit for "Mug"
    When the order administrator confirms the refund
    Then "Mug" stock rises by 1

  Scenario: A typed amount above the refunded units is limited to the refunded units
    Given an order administrator who may refund, with the refund modal open for 2 units of "Mug" and "Some units" chosen with 5 units for "Mug"
    When the order administrator confirms the refund
    Then "Mug" stock rises by 2

  Scenario: Units entered for a line that is not being refunded are ignored
    Given an order administrator who may refund, with the refund modal open for 2 units of "Mug" only, and "Some units" chosen with 1 unit for "Mug" and 1 unit for "Plate"
    When the order administrator confirms the refund
    Then "Mug" stock rises by 1
    And "Plate" stock is unchanged

  Scenario: Choosing some units but none in total is blocked
    Given an order administrator who may refund, with the refund modal open and "Some units" chosen with 0 units on every line
    When the order administrator confirms the refund
    Then the order administrator is asked to choose at least one unit or pick none
    And no refund is recorded

  Scenario: A line whose variant was deleted is not offered for stock return
    Given an order administrator who may refund, with the refund modal open on an order holding a line of a variant since deleted
    When the order administrator chooses to return some units
    Then no input is shown for that line

  Scenario: A backend refusal is shown in the modal
    Given an order administrator who may refund, with the refund modal open for units that were already returned to stock on cancellation and "All refunded units" chosen
    When the order administrator confirms the refund
    Then the modal explains that those units are not out of stock
    And no refund is recorded
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-1 … D-4; `syncRefundInputs()` resets the stock state; `recordRefund()` passes `stockReturns:`.
- `resources/views/livewire/orders/show.blade.php` — radio group and per-line inputs inside `refund-modal`.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.refunds.stock_*`.
- `tests/Feature/Orders/ShowRefundStockTest.php` — new; `tests/Feature/Orders/ShowRefundTest.php` — regression only.
- `tests/Browser/Orders/OrderRefundStockReturnTest.php` — new.

## Tests to perform
- [ ] Livewire: default `none` on every open; reopen after close resets both properties (via `syncRefundInputs()`).
- [ ] Livewire mapping: `all` → every refunded stock-target line's units; `partial` clamps silently, drops lines not refunded now and foreign ids; `partial` total 0 blocked; unknown mode refused.
- [ ] Livewire: stock and both counters asserted after each mode (real action, not a mock); the backend cap is `min(refunded units, returnableStockUnits())` (0088 D8, two counters, corrected after review #3) and the UI does not pre-compute it.
- [ ] Inputs rendered for every refundable stock-target line in `partial`, none for null-target lines; no `wire:model.live` on refund quantities.
- [ ] Security: forged `refundStockQuantities` keys never reach a stock write.
- [ ] Backend refusal rendered as the `refund` error; modal stays open; nothing written. Lang parity.
- [ ] Browser: radio group keyboard-operable, inputs appear only in `partial`, explicit labels, 44px targets, mobile stacking, dark mode.

## Expected outcome
Recording a refund lets the administrator put none, all or some of the refunded units back in stock, never more than is refunded.

## Acceptance criteria
- [ ] Default is none, reset on every open and close; stock moves only on an explicit choice.
- [ ] Partial inputs are clamped silently per line (user-confirmed) and a zero total is blocked.
- [ ] Server-side mapping plus backend validation; no client value trusted.
- [ ] en/es copy complete; existing refund hooks preserved.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (refund modal fields and hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0092b only (shares files with 0094/0094a/0095, no logical dependency). Negotiable: copy. Valuable: refunded
goods can be put back with the refund itself. Estimable/Small: one modal section and one mapping method. Testable: Livewire mapping
tests per mode plus browser checks.

## Dependencies, risks, open technical questions
**Depends on:** [0092b](in-progress/0092b-order-refund-stock-return-backend.md) (hard). Shares `Show.php`, `show.blade.php` and
`lang/*/orders.php` with [0094](0094-order-line-quantity-stock-dialog-ui.md), [0094a](0094a-order-line-removal-stock-dialog-ui.md)
and [0095](0095-order-cancellation-stock-dialog-ui.md) — run sequentially.
- **Resolved (0088 D8)** — the backend caps every stock return at the units still held out of inventory; the UI shows its refusal
  and does not pre-clamp to that cap.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design and decisions D1, D5, D7.
- 2026-11-08 — Phase 2 review applied: inputs for every refundable stock-target line with server-side clamp (no `.live`), stock
  state reset through `syncRefundInputs()`, silent clamp user-confirmed, actor wording.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner).
- Phase 2: INVEST review 2026-11-08: findings received → rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: counter references aligned with the two-counter model (0088 D8) → rewritten, pending re-review.
- Phase 2: INVEST re-review #3 2026-11-08 → rewritten, pending re-review (backend cap name, refusal wording and copy reference aligned with 0092b's `stock_return_not_out_of_stock`).
