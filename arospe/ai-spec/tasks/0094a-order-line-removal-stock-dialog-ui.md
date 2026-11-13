# [0094a] Order detail — ask about stock when a line is removed (frontend)

> **Status: New — created 2026-11-08 by splitting former 0094 after the Phase 2 INVEST review (user decision); pending review.**
> Epic 3 follow-up bug fix, order-stock series. Suffix numbering follows the `0076a` precedent so the story sorts after
> [0094](0094-order-line-quantity-stock-dialog-ui.md) (whose state and dialog it extends) and before
> [0095](0095-order-cancellation-stock-dialog-ui.md). Fullstack pair: **related_task_id 0090** (`RemoveOrderItem` `adjustStock`).
> **User-confirmed (2026-11-08):** OQ-3 — a line with zero returnable units still gets the dialog, with a note and only
> "Remove without changing stock" / "Keep editing".

## Description
Removing a line from an order no longer deletes it straight away: the same stock dialog as 0094 asks "Return N units to stock",
"Remove without changing stock", or "Keep editing", with a preview of the stock effect. A line whose catalog row was deleted is
removed without the question (D1).

## Type
frontend (related_task_id: 0090) | includes database-expert: no

## Verified facts that shaped the story
- `App\Livewire\Orders\Show::removeLineItem(string $itemId, RemoveOrderItem)` is called directly from `remove-line-item-{id}`;
  Remove is disabled on a line with refunded units, and `RemoveOrderItem` refuses that case and the order's last line.
- 0094 introduces the pending state (`pendingStockAction`, `pendingItemId`, `pendingFromQuantity`, `showStockConfirm`),
  `applyStockChoice()`, `dismissStockConfirm()`, `stockPreview` and the `line-stock` dialog.

## Decisions
- **D-1 (user D3)** — Always ask for a line with a stock target; a null-target line (0088 D1/D9) is removed directly with
  `adjustStock: false`.
- **D-2** — `requestRemoveLineItem(string $itemId)` replaces the public `removeLineItem` (removed). It authorizes `update`, resets
  the error bag, resolves the item of this order, sets `pendingStockAction = 'remove'`, `pendingItemId`, `pendingFromQuantity`
  (current quantity, stale guard), and opens the dialog — or removes directly for a null-target line. No validation of its own:
  the last-line and refunded-line refusals stay the action's (rendered inline on apply).
- **D-3** — `applyStockChoice()` gains the `remove` branch. `RemoveOrderItem` is added as a **second concretely typed injected
  parameter** (`applyStockChoice(bool $adjust, UpdateOrderItemQuantity $update, RemoveOrderItem $remove)`) — no union type; it
  re-authorizes, runs the stale guard (line still exists with `pendingFromQuantity` units; otherwise `orders.stock.changed_meanwhile`),
  and calls `RemoveOrderItem` with `adjustStock: $adjust`.
- **D-4** — `stockPreview` gains the removal shape: `units = returnableStockUnits()` (= `quantity − stock_undeducted_quantity`, refunded lines cannot
  be removed).
- **D-5 (OQ-3, user-confirmed)** — When `units` is 0 the dialog shows `orders.stock.nothing_to_return` instead of the confirm
  button and offers only "Remove without changing stock" and "Keep editing".
- **D-6 — Copy.** Confirm "Return :count units to stock"; secondary "Remove without changing stock"; dismiss "Keep editing"
  (`orders.stock.*`, en + es).
- **D-7 — Hooks.** Same `line-stock` dialog hooks as 0094; `remove-line-item-{id}` stays on the same button.
- **D-8 (user D7)** — `orders.edit` gates request and apply.
- **D-9 — Errors and pending state.** Every rendered action refusal closes the dialog, refreshes and renders inline — never
  a 500. **Pending state (`pendingStockAction`, `pendingItemId`, `pendingFromQuantity`, `showStockConfirm`) is
  cleared on every exit path of `applyStockChoice()`**: success, every rendered refusal (`OrderNotEditableException`,
  `ValidationException`, not-found item) and the stale-guard path, plus `dismissStockConfirm()`. **A failed re-authorization is
  a 403** (the `Gate::authorize` exception propagates; tests assert `assertForbidden()`); it does not clear pending state, which
  is harmless because the next apply re-authorizes first. An apply call with empty pending state (no prior request) is refused
  like a stale one.
  (Same rule as 0094 D-9, applied to the `remove` branch.)

## Gherkin
```gherkin
Feature: The order administrator decides whether removing a line returns its units to stock

  Scenario: Removing a line asks whether to return its units
    Given an order administrator, on a pending order with lines of 3 units of "Mug" and 1 unit of "Plate"
    When the order administrator removes the "Mug" line
    Then a dialog offers to return 3 units to stock, to remove without changing stock, or to keep editing

  Scenario: Removing and returning the units
    Given an order administrator, with the stock dialog open for removing a 3-unit "Mug" line, and "Mug" has 10 units in stock
    When the order administrator chooses to return 3 units to stock
    Then the "Mug" line is gone
    And "Mug" has 13 units in stock

  Scenario: Removing without changing stock
    Given an order administrator, with the stock dialog open for removing a 3-unit "Mug" line, and "Mug" has 10 units in stock
    When the order administrator chooses to remove without changing stock
    Then the "Mug" line is gone
    And "Mug" still has 10 units in stock

  Scenario: Keeping editing keeps the line
    Given an order administrator, with the stock dialog open for removing a 3-unit "Mug" line
    When the order administrator chooses to keep editing
    Then the "Mug" line is still on the order

  Scenario: Removing a line with nothing to return still asks, without a return option
    Given an order administrator, on an order recorded before stock tracking existed, with lines of 3 units of "Mug" and 1 unit of "Plate"
    When the order administrator removes the "Mug" line
    Then a dialog explains that no units of this line are currently out of stock
    And it offers only to remove without changing stock or to keep editing

  Scenario: A line whose product was deleted is removed without a stock question
    Given an order administrator, on a pending order with a line of a product since deleted and a line of "Mug"
    When the order administrator removes the deleted product's line
    Then the line is removed without any stock dialog

  Scenario: Removing the last line is refused and explained
    Given an order administrator, with the stock dialog open for removing the only line of an order
    When the order administrator chooses to return its units to stock
    Then the dialog closes
    And the order administrator is told an order needs at least one line

  Scenario: An administrator who may not edit orders cannot remove a line
    Given an administrator who may not edit orders, on a pending order
    When the administrator tries to remove a line
    Then the action is refused as not authorized
    And no stock dialog opens
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-2 … D-5; remove the public `removeLineItem`.
- `resources/views/livewire/orders/show.blade.php` — `remove-line-item-{id}` calls `requestRemoveLineItem`; dialog labels for the
  removal variant.
- `lang/en/orders.php`, `lang/es/orders.php` — removal copy under `orders.stock.*`.
- `tests/Feature/Orders/ShowLineItemRemovalStockTest.php` — new (Livewire).
- `tests/Feature/Orders/ShowLineItemsTest.php` — update tests that called the removed method.
- `tests/Browser/Orders/OrderLineItemStockDialogTest.php` (from 0094) — add removal cases.

## Tests to perform
- [ ] Livewire: request opens the dialog with the removal preview; null-target line removed with `adjustStock: false`, no dialog.
- [ ] Livewire: both choices call `RemoveOrderItem` with the matching `adjustStock` (assert stock and line deletion); dismiss changes nothing.
- [ ] Livewire: zero-returnable removal shows the note and only two options (OQ-3).
- [ ] Refusals (last line, refunded line, shipped order) close the dialog and render inline.
- [ ] Pending state is empty after success, after each rendered refusal type (authorization failure asserted as forbidden instead, `assertForbidden()`) and after the stale-guard path; an apply with empty pending state is refused and changes nothing.
- [ ] Security: locked pending state; re-authorization on apply; item of another order → not found; stale guard (line already removed or quantity changed).
- [ ] Lang parity; browser: removal dialog labels and abort paths.

## Expected outcome
Removing a stock-tracked line always asks whether its units go back to stock and applies exactly the choice.

## Acceptance criteria
- [ ] No path on the screen removes a stock-tracked line without the stock question.
- [ ] Preview and applied delta agree; zero-returnable lines follow OQ-3.
- [ ] No union-typed injection; refusals never 500; en/es copy complete.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (removal methods and hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0090, 0093 and 0094's shared dialog state. Negotiable: copy. Valuable: removals can no longer silently lose or
keep inventory. Estimable/Small: one request method and one branch on an existing dialog. Testable: Livewire and browser cases.

## Dependencies, risks, open technical questions
**Depends on:** [0090](in-progress/0090-order-line-quantity-and-removal-stock-opt-in-backend.md),
[0093](done/0093-confirm-dialog-secondary-action-ui.md), [0094](0094-order-line-quantity-stock-dialog-ui.md) (hard). Shares
`Show.php`, `show.blade.php` and `lang/*/orders.php` with 0094, 0095, 0096 and 0092b (lang) — run sequentially.
- **R-1** — Removing the public `removeLineItem` changes the component's public surface.

## Debate record
- 2026-11-08 — Split out of former 0094 per the Phase 2 review and the user's decision.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner, split from 0094).
- Phase 2: INVEST review 2026-11-08 (of former 0094): findings received → split and rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: helper wording aligned with the two-counter model (0088 D8) → rewritten, pending re-review.
- Phase 2: INVEST re-review #3 2026-11-08 → rewritten, pending re-review (same D-9 pending-state wording as 0094).
