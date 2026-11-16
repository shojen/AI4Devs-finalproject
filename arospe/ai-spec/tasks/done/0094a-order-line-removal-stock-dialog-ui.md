# [0094a] Order detail — ask about stock when a line is removed (frontend)

> **Status: New — created 2026-11-08 by splitting former 0094 after the Phase 2 INVEST review (user decision); pending review.**
> Epic 3 follow-up bug fix, order-stock series. Suffix numbering follows the `0076a` precedent so the story sorts after
> [0094](../done/0094-order-line-quantity-stock-dialog-ui.md) (whose state and dialog it extends) and before
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
- 0094 is merged: the pending state (`pendingStockAction` = `'quantity'`, `pendingItemId`, `pendingQuantity`, `pendingFromQuantity`,
  `showStockConfirm`), `applyStockChoice(bool, UpdateOrderItemQuantity)`, `dismissStockConfirm()`, `stockPreview`, the private
  `saveQuantity()` / `clearPendingStock()` and the `line-stock` dialog exist. Today's `removeLineItem` calls the action with no
  `adjustStock` argument (default `false`) and catches only `OrderNotEditableException`. The dialog's heading, body and confirm label
  are quantity-specific today (`orders.stock.dialog_heading` / `dialog_body`; deduct vs return chosen from `pendingQuantity >
  pendingFromQuantity`), and `orders.stock.changed_meanwhile` says "Review the new quantity".

## Decisions
- **D-1 (user D3)** — Always ask for a line with a stock target; a null-target line (0088 D1/D9) is removed directly with
  `adjustStock: false`.
- **D-2** — `requestRemoveLineItem(string $itemId, RemoveOrderItem $removeOrderItem)` replaces the public `removeLineItem` (removed);
  the action is injected, like `requestQuantityChange` injects its own, because a null-target line is removed directly (no `app()`).
  It authorizes `update`, resets the error bag, resolves the item of this order, sets `pendingStockAction = 'remove'`, `pendingItemId`, `pendingFromQuantity`
  (current quantity, stale guard), and opens the dialog — or removes directly (`adjustStock: false`) for a null-target line. No validation of its own:
  the last-line and refunded-line refusals stay the action's (rendered inline on apply).
- **D-3** — `applyStockChoice()` gains the `remove` branch: its `$isPending` check (today `pendingStockAction === 'quantity'`)
  accepts `'remove'` too and branches on it. `RemoveOrderItem` is added as a **second concretely typed injected
  parameter** (`applyStockChoice(bool $adjust, UpdateOrderItemQuantity $update, RemoveOrderItem $remove)`) — no union type; it
  re-authorizes, runs the stale guard (line still exists with `pendingFromQuantity` units; otherwise `orders.stock.changed_meanwhile`),
  and calls `RemoveOrderItem` with `adjustStock: $adjust`.
- **D-4** — `stockPreview` gains the removal shape: `units = max(0, returnableStockUnits())` — `returnableStockUnits()` is
  `(Q − F) − U`, which equals the action's `Q − U` because a line with refunded units (F > 0) cannot be removed. Same
  `max(0, …)` clamp as 0094; `resulting = current + units`, `willGoNegative` is always false for a return.
- **D-5 (OQ-3, user-confirmed)** — When `units` is 0 the dialog shows `orders.stock.nothing_to_return` and offers only
  "Remove without changing stock" and "Keep editing". As in 0094 D-6 (Phase 2 re-review #4, option a), this is the **two-button
  form**: `x-confirm-dialog` always renders `-confirm` and is not modified, so `-confirm` carries "Remove without changing stock"
  (`confirm-action="applyStockChoice(false)"`), with no `-secondary` hook and no "Return" label.
- **D-6 — Copy (removal mode, `pendingStockAction === 'remove'`).** The view selects the removal wording from the mode instead of
  from `pendingQuantity > pendingFromQuantity`: heading `orders.stock.remove_heading` ("Remove this line?"), body
  `orders.stock.remove_body` ("Removing this line can also return its units to the stock of the product."), confirm
  `orders.stock.return` ("Return :count units to stock", never deduct), secondary — and the zero-units `-confirm` —
  `orders.stock.remove_without_stock` ("Remove without changing stock", **not** `save_without_stock`), dismiss
  `orders.stock.keep_editing`. All three new keys exist in en + es with identical key sets. `orders.stock.changed_meanwhile` is
  reworded in both locales to be mode-neutral ("This line changed while the dialog was open. Review the line and try again.").
- **D-7 — Hooks.** Same `line-stock` dialog hooks as 0094; `remove-line-item-{id}` stays on the same button.
- **D-8 (user D7)** — `orders.edit` gates request and apply.
- **D-9 — Errors and pending state.** Every rendered action refusal closes the dialog, refreshes and renders inline — never
  a 500. **Pending state (`pendingStockAction`, `pendingItemId`, `pendingQuantity`, `pendingFromQuantity`, `showStockConfirm`) is
  cleared on every exit path of `applyStockChoice()`**: success, every rendered refusal (`OrderNotEditableException`,
  `ValidationException`, not-found item) and the stale-guard path, plus `dismissStockConfirm()`. **A failed re-authorization is
  a 403** (the `Gate::authorize` exception propagates; tests assert `assertForbidden()`); it does not clear pending state, which
  is harmless because the next apply re-authorizes first. An apply call with empty pending state (no prior request) is refused
  like a stale one.
  (Same rule as 0094 D-9, applied to the `remove` branch.) **Race exceptions:** `RemoveOrderItem` throws a `LogicException` when
  its locked re-check fails and a `ModelNotFoundException` if the line disappears inside the transaction; both are rendered as
  `orders.stock.changed_meanwhile` (as `saveQuantity()` does for the quantity branch), never as a 500. The action's
  `ValidationException` refusals (last line, refunded line) keep the `order_item_id` key already rendered by the view's error loop.

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
- `app/Livewire/Orders/Show.php` — D-2 … D-6, D-9; remove the public `removeLineItem`.
- `resources/views/livewire/orders/show.blade.php` — `remove-line-item-{id}` calls `requestRemoveLineItem`; the `line-stock` dialog selects
  heading, body and labels from the pending mode (D-6).
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.stock.remove_heading`, `remove_body`, `remove_without_stock`; reworded `changed_meanwhile`.
- `tests/Feature/Orders/ShowLineItemRemovalStockTest.php` — new (Livewire).
- `tests/Feature/Orders/ShowLineItemsTest.php` — update tests that called the removed method.
- `tests/Feature/Orders/ShowTest.php` — authorization dataset row `removeLineItem` (line ~141) and the calls at ~204 and ~216.
- `tests/Feature/Orders/ShowRenderingTest.php` — the query-count test that calls `removeLineItem` (~203): it becomes request + apply, so its query limit needs a fresh look.
- `tests/Browser/Orders/OrderDetailLineItemsTest.php` — the plain-removal (~84) and last-line-refusal (~99) tests now go through the dialog; the `assertDisabled` check (~116) is unchanged.
- `tests/Browser/Orders/OrderLineItemStockDialogTest.php` (from 0094) — add removal cases.

## Tests to perform
- [x] Livewire: request opens the dialog with the removal preview; null-target line removed with `adjustStock: false`, no dialog.
- [x] Livewire: both choices call `RemoveOrderItem` with the matching `adjustStock` (assert stock and line deletion); dismiss changes nothing.
- [x] Livewire: zero-returnable removal shows the note, no `-secondary` hook and no Return label; `-confirm` reads "Remove without changing stock" and runs `applyStockChoice(false)`, plus "Keep editing" (OQ-3).
- [x] Refusals (last line, refunded line, shipped order) close the dialog and render inline; a `LogicException` / `ModelNotFoundException` from the action renders `changed_meanwhile` and clears the pending state (mocked action, as in 0094).
- [x] Removal mode uses its own heading, body and labels (`remove_heading`, `remove_body`, `return`, `remove_without_stock`), never the quantity-change wording; lang parity covers the three new keys.
- [x] Pending state is empty after success, after each rendered refusal type (authorization failure asserted as forbidden instead, `assertForbidden()`) and after the stale-guard path; an apply with empty pending state is refused and changes nothing.
- [x] Security: locked pending state; re-authorization on apply; item of another order → not found; stale guard (line already removed or quantity changed).
- [x] Lang parity; browser: removal dialog labels and abort paths.

## Expected outcome
Removing a stock-tracked line always asks whether its units go back to stock and applies exactly the choice.

## Acceptance criteria
- [x] No path on the screen removes a stock-tracked line without the stock question.
- [x] Preview and applied delta agree; zero-returnable lines follow OQ-3.
- [x] No union-typed injection; refusals never 500; en/es copy complete.

## Definition of Done
- [x] Tests written and green (full suite, unscoped)
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor)
- [x] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (removal methods and hooks), `ai-spec/tasks/_digests/epic-3.md`
- [x] Acceptance criteria met

## INVEST note
Independent: needs 0090, 0093 and 0094's shared dialog state. Negotiable: copy. Valuable: removals can no longer silently lose or
keep inventory. Estimable/Small: one request method and one branch on an existing dialog. Testable: Livewire and browser cases.

## Dependencies, risks, open technical questions
**Depends on:** [0090](../done/0090-order-line-quantity-and-removal-stock-opt-in-backend.md),
[0093](../done/0093-confirm-dialog-secondary-action-ui.md), [0094](../done/0094-order-line-quantity-stock-dialog-ui.md) (hard). Shares
`Show.php`, `show.blade.php` and `lang/*/orders.php` with 0094, 0095, 0096 and 0092b (lang) — run sequentially.
- **R-1** — Removing the public `removeLineItem` changes the component's public surface.

## Debate record
- 2026-11-08 — Split out of former 0094 per the Phase 2 review and the user's decision.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner, split from 0094).
- Phase 2: INVEST review 2026-11-08 (of former 0094): findings received → split and rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: helper wording aligned with the two-counter model (0088 D8) → rewritten, pending re-review.
- Phase 2: INVEST re-review #3 2026-11-08 → rewritten, pending re-review (same D-9 pending-state wording as 0094).
- Phase 2: INVEST re-review #4 2026-11-15 (code-reviewer, against the merged 0094 code): REJECTED — (1) Files to create/modify incomplete: `tests/Feature/Orders/ShowTest.php` (authorization dataset `removeLineItem` line 141, calls at 204 and 216), `tests/Feature/Orders/ShowRenderingTest.php:203` (query-budget test calls `removeLineItem`; now a two-step flow) and `tests/Browser/Orders/OrderDetailLineItemsTest.php:84,99` (the click now opens the dialog first) all break; (2) the real `line-stock` dialog body `orders.stock.dialog_body` ("Changing this quantity…") and the `$stockIncrease`-driven confirm label are quantity-specific — D-6 must name the removal heading/body keys and state the remove mode always uses `orders.stock.return`; (3) D-2's `requestRemoveLineItem(string $itemId)` cannot remove a null-target line directly without injecting `RemoveOrderItem` (as `requestQuantityChange` injects `UpdateOrderItemQuantity`; `app()` was ruled out in 0094). Non-blocking: name `LogicException`/`ModelNotFoundException` → `changed_meanwhile` in D-9 (mirrors `saveQuantity`); fix D-4's formula parenthetical (`returnableStockUnits()` is `(Q − F) − U`, equal to the action's `Q − U` only because refunded lines are refused) and keep the `max(0, …)` clamp; refresh "Verified facts" to the merged state.
- Phase 2: story rewritten 2026-11-15 after re-review #4: Files list completed (`ShowTest.php`, `ShowRenderingTest.php`, `OrderDetailLineItemsTest.php`), D-6 names the removal heading/body/label keys and the mode-neutral `changed_meanwhile`, D-2 injects `RemoveOrderItem`, D-9 maps `LogicException`/`ModelNotFoundException`, D-4 formula and the stale fact about 0094 corrected; pending re-review.
- Phase 2: INVEST re-review #5 2026-11-15 (code-reviewer): APPROVED — the three #4 blockers are resolved and consistent across D-2/D-3/D-6/D-9, Files and Tests; grep of `tests/` and `docs/` for `removeLineItem` / `remove-line-item` finds no file outside the list; 0094's tests assert `changed_meanwhile` via `__()` only; no contradiction with 0095 (own `cancel_changed_meanwhile`, mode chosen by `pendingStockAction`). Non-blocking for Phase 3: in `ShowLineItemsTest.php` the blocked-section `->not->toContain('removeLineItem')` (~84) becomes vacuous after the rename (assert `requestRemoveLineItem` instead), and the "ENABLED add or remove control … goes straight to the action" test title (~88) is no longer true for remove.
- Phase 4: security audit 2026-11-15 (appsec-auditor): APPROVED, no Critical/High/Medium. `orders.edit` re-authorized on request, apply and inside `RemoveOrderItem`; pending props `#[Locked]` and cleared on every exit; a forged `showStockConfirm` with empty pending only yields `changed_meanwhile`; item lookups scoped to the order (no IDOR); no remaining direct removal method; preview and translations escaped. Low (non-blocking): the stale guard compares only `quantity` and sits outside the action transaction, so a concurrent refund or `stock_undeducted_quantity` change between open and apply makes the shown units differ from the units moved (the action stays internally consistent under its locks; a refund is refused); Info: the broad `LogicException` catch also swallows unrelated programming errors without `report()`.
- Phase 5: final code review 2026-11-15 (code-reviewer): APPROVED — D-1..D-9 and all 8 Gherkin scenarios implemented in `Show.php`/`show.blade.php` and covered by Livewire (`ShowLineItemRemovalStockTest`, 30 passed) and browser tests; concrete two-parameter injection, no union type; en/es `orders.stock` key sets identical; `remove-line-item-{id}` hook kept; no leftover `removeLineItem` in app/resources/tests/docs; `#[Renderless]` `dismissStockConfirm` accepted (mirrors `dismissMarkAsPaid`, guarded by the browser last-line and quantity-refusal tests). Non-blocking nits only; full suite, Pint and Larastan left to the orchestrator; `docs/api/orders.md#orders.show` removal methods pending Phase 6.
- Phase 5 (full suite, 2026-11-15, unscoped, run in directory chunks on separate databases): `tests/Feature/Orders` 1077/1077; Unit + Actions/Auth/Authorization/Blog/Components/Console/Customers 1814/1814; Dashboard...Notifications 1077 passed + 5 skipped; PaymentMethods...Settings 1493/1494 (the one error, `GenerateProductVariantCombinationsTest` "refused ... logged against the parent product", a Log-spy test that also failed once in 0094's CI, passes 22/22 alone: unrelated, load flake); Shipping...Users 653/653; `tests/Browser` 315 tests, 312 passed + 3 skipped. Pint unscoped clean; Larastan clean on the whole project.
- Phase 6: documentation updated 2026-11-15 (`docs/api/orders.md#orders.show`, `ai-spec/tasks/_digests/epic-3.md`).
- Phase 7: closed 2026-11-15, moved to `done/`. Low follow-ups (appsec, recorded in the epic 3 digest): the stale guard compares only `quantity` outside the action's transaction; the `LogicException` catch is broad.
