# [0096] Order detail — the refund modal asks whether to return stock (frontend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 9 of the
> order-stock series 0088–0096. Fullstack pair: **related_task_id 0092** (backend `RecordRefund` `stockReturns`). Blocked on
> [0092](0092-order-refund-stock-return-backend.md).

## Description
The refund modal gains a "Return to stock" choice: **None** (default), **All refunded units**, or **Some units** — the last one
shows, for each line being refunded, a number input bounded by the units refunded on that line in this refund. The server
turns the choice into explicit per-line units, clamps and validates them, and passes them to `RecordRefund`.

## Type
frontend (related_task_id: 0092) | includes database-expert: no

## Verified facts that shaped the story
- Today: `openRefundModal()` (authorizes `orders.refund`, no-op when not `isRefundable()`), `closeRefundModal()`,
  `recordRefund(RecordRefund)` which builds `$items` from `refundQuantities` (positive ints only), refuses an empty selection with
  `orders.refunds.nothing_selected`, and renders a `ValidationException` as the `refund` error. Hooks `record-refund`,
  `refund-modal`, `refund-quantity-{id}`, `refund-confirm`.
- The refund control is absent when the payment state refuses and disabled without `orders.refund` (story 0055).

## Decisions (frontend-expert design, accepted)
- **D-1 (user D5)** — `public string $refundStockReturn = 'none'` (`none|all|partial`) as a `flux:radio.group`; default `none`
  every time the modal opens (reset in `openRefundModal()` / `closeRefundModal()`).
- **D-2** — `public array $refundStockQuantities = []` (item id → string, bound per line, shown only in `partial` mode and only for
  lines with a refund quantity > 0 and a stock target — D1). Strings, not ints (epic-3 digest: a cleared number box unsets an int).
- **D-3 — Server mapping.** `none` → `[]`; `all` → every refunded line with a stock target → its refund quantity; `partial` →
  each entry cast to int and clamped to `0 … refund quantity for that line`; ids not in the current refund are dropped. The
  backend still validates everything (0092 D-2); the UI never relies on the clamp for safety. An unknown mode value is treated as
  a validation error, never as `none`.
- **D-4** — `partial` with a total of 0 units blocks submit with `orders.refunds.stock_partial_empty` ("Choose at least one unit to
  return, or pick None").
- **D-5 — Copy.** Group label "Return to stock"; options "None", "All refunded units", "Some units"; per-line helper "Up to :max".
  Keys under `orders.refunds.stock_*` (en + es, identical sets). Backend refusals (e.g. units already back in stock, 0088 D8 cap enforced by 0092)
  render as the existing `refund` error.
- **D-6 — Hooks.** `refund-stock-return` (group), `refund-stock-return-{none|all|partial}`, `refund-stock-quantity-{id}`.
- **D-7 (user D7)** — `orders.refund` only; no new permission.

## Gherkin
```gherkin
Feature: The refund modal lets the order administrator return refunded units to stock

  Scenario: The refund modal returns nothing to stock by default
    Given an order administrator with refund rights, on a paid order with 4 units of "Mug"
    When the order administrator opens the refund modal
    Then "Return to stock" is set to none

  Scenario: Refunding without returning stock
    Given an order administrator with refund rights, with the refund modal open for 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator confirms the refund leaving "Return to stock" at none
    Then the refund is recorded
    And "Mug" still has 10 units in stock

  Scenario: Returning all refunded units
    Given an order administrator with refund rights, with the refund modal open for 2 units of "Mug" and "All refunded units" chosen
    When the order administrator confirms the refund
    Then "Mug" stock rises by 2

  Scenario: Choosing some units shows one input per refunded line
    Given an order administrator with refund rights, with the refund modal open for 2 units of "Mug" and 1 unit of "Plate"
    When the order administrator chooses to return some units
    Then an input up to 2 is shown for "Mug" and an input up to 1 is shown for "Plate"

  Scenario: Returning some units
    Given an order administrator with refund rights, with the refund modal open for 3 units of "Mug" and "Some units" chosen with 1 unit for "Mug"
    When the order administrator confirms the refund
    Then "Mug" stock rises by 1

  Scenario: Choosing some units but none in total is blocked
    Given an order administrator with refund rights, with the refund modal open and "Some units" chosen with 0 units on every line
    When the order administrator confirms the refund
    Then the order administrator is asked to choose at least one unit or pick none
    And no refund is recorded

  Scenario: A typed amount above the refunded units is limited to the refunded units
    Given an order administrator with refund rights, with the refund modal open for 2 units of "Mug" and "Some units" chosen with 5 units for "Mug"
    When the order administrator confirms the refund
    Then "Mug" stock rises by 2

  Scenario: A line whose variant was deleted is not offered for stock return
    Given an order administrator with refund rights, with the refund modal open for a line of a variant since deleted
    When the order administrator chooses to return some units
    Then no input is shown for that line

  Scenario: A backend refusal is shown in the modal
    Given an order administrator with refund rights, with the refund modal open for units that were already returned to stock on cancellation and "All refunded units" chosen
    When the order administrator confirms the refund
    Then the modal explains that those units are already back in stock
    And no refund is recorded
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-1 … D-4; `recordRefund()` passes `stockReturns:`.
- `resources/views/livewire/orders/show.blade.php` — radio group and per-line inputs inside `refund-modal`.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.refunds.stock_*`.
- `tests/Feature/Orders/ShowRefundStockTest.php` — new; `tests/Feature/Orders/ShowRefundTest.php` — regression only.
- `tests/Browser/Orders/OrderRefundStockReturnTest.php` — new.

## Tests to perform
- [ ] Livewire: default `none` on every open; `all` maps to every refunded line's units; `partial` clamps, drops foreign ids, ignores lines not refunded now; `partial` total 0 blocked; unknown mode refused.
- [ ] Livewire: stock and counter asserted after each mode (real action, not a mock).
- [ ] Security: forged `refundStockQuantities` keys (another order's line) never reach a stock write (dropped by mapping, refused by backend).
- [ ] Backend refusal rendered as the `refund` error; modal stays open; nothing written.
- [ ] Lang parity.
- [ ] Browser: radio group keyboard-operable, inputs appear only in `partial`, explicit labels, 44px targets, mobile stacking, dark mode.

## Expected outcome
Recording a refund lets the administrator put none, all or some of the refunded units back in stock, with no way to return more
than is being refunded.

## Acceptance criteria
- [ ] Default is none; stock moves only on an explicit choice.
- [ ] Partial inputs are bounded per line and a zero total is blocked.
- [ ] Server-side mapping plus backend validation; no client value trusted.
- [ ] en/es copy complete; existing refund hooks preserved.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (refund modal fields and hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0092 only (shares files with 0094/0095, no logical dependency). Negotiable: clamp vs error (R-1, assumed default). Valuable: refunded goods can be put back with the refund itself. Estimable/Small: one modal section and one mapping method. Testable: Livewire mapping tests per mode plus browser checks.

## Dependencies, risks, open technical questions
**Depends on:** [0092](0092-order-refund-stock-return-backend.md) (hard). Shares `Show.php`, `show.blade.php` and `lang/*/orders.php`
with [0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md) and [0095](0095-order-cancellation-stock-dialog-ui.md) — no
logical dependency, but run sequentially to avoid merge conflicts.

- **Resolved (0088 D8, user decision 2026-11-08)** — the backend caps every stock return at the units still held out of
  inventory; "All refunded units" on a line already back in stock is refused by 0092 and shown as the `refund` error. The UI
  does not pre-clamp to that cap (the backend is the only judge).
- **R-1 — Clamp vs error: assumed default (clamp), not yet user-confirmed.** The clamp in D-3 silently lowers an over-typed
  value to the line's refund quantity (scenario "limited to the refunded units"). If the owner prefers an explicit error
  instead, change D-3 before Phase 3.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design and decisions D1, D5, D7.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner). Phase 2: pending.
