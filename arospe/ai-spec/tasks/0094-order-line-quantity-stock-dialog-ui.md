# [0094] Order detail — ask about stock when a line's quantity changes (frontend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.** Epic 3
> follow-up bug fix, order-stock series. **Split (user decision 2026-11-08):** this story covers the **quantity change only**;
> line removal moved to [0094a](0094a-order-line-removal-stock-dialog-ui.md). Fullstack pair: **related_task_id 0090**. Blocked
> on [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md) and [0093](done/0093-confirm-dialog-secondary-action-ui.md).
> It is also the first consumer of 0093's secondary action and owns its interactive checks (Esc / backdrop / X abort,
> loading-disable, 375px layout).
> **User-confirmed (2026-11-08):** OQ-3 — a line with zero returnable units still gets the dialog, with a note and only
> "Save without changing stock" / "Keep editing".

## Description
On the order detail screen, saving a new quantity no longer writes straight away: a stock dialog asks, every time, whether to
move stock — "Deduct N units from stock" (increase) or "Return N units to stock" (decrease), "Save without changing stock", or
"Keep editing". The dialog previews, from a fresh read, the affected product/variant with its current and resulting stock, and
warns — without blocking — when an increase would leave stock below zero. Lines whose catalog row was deleted skip the dialog
(D1) and save as today without moving stock.

## Type
frontend (related_task_id: 0090) | includes database-expert: no

## Verified facts that shaped the story
- `App\Livewire\Orders\Show` today calls `updateLineItemQuantity(string $itemId, UpdateOrderItemQuantity)` directly from the
  `save-quantity-{id}` button; it starts with `Gate::authorize('update', $this->order)` and `resetErrorBag()`, renders
  `OrderNotEditableException` inline and calls `refreshOrderState()`
  ([docs/api/orders.md#orders.show](../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities)).
- `#[Locked]` pending state with a stale guard exists for the backward status transition (`pendingStatus`, `pendingFromStatus`).
- Computeds are read as properties and `unset()` after writes; the error bag is reset at the top of every action; a number input
  is bound to a string (epic-3 digest, story 0055).

## Decisions (frontend-expert design, accepted)
- **D-1 (user D3)** — Always ask for a line with a stock target. Exception (D1): a line whose variant or product was deleted
  (`stockTarget()` null) saves directly with `adjustStock: false` and no dialog.
- **D-2 — State** (shared with 0094a and reused by 0095's preview): `#[Locked] public string $pendingStockAction = ''`
  (`'quantity'` here; `'remove'` added by 0094a), `#[Locked] public string $pendingItemId = ''`, `#[Locked] public int
  $pendingQuantity = 0`, `#[Locked] public int $pendingFromQuantity = 0` (stale guard), `public bool $showStockConfirm = false`.
- **D-3 — Request step.** `requestQuantityChange(string $itemId)` replaces the public `updateLineItemQuantity` (removed, so no
  public path skips the question). It: authorizes `update`; resets the error bag; resolves the item **of this order** (not found →
  inline error); **casts** `$this->editingQuantities[$itemId]` to `int` and records it in `pendingQuantity` and the item's current
  quantity in `pendingFromQuantity`; returns silently when the two are equal; saves directly with `adjustStock: false` for a
  null-target line; otherwise opens the dialog. It performs **no validation of its own** — `< 1`, `< refunded` and the hard block
  stay the action's to refuse (rendered inline on apply).
- **D-4 — Apply step.** `applyStockChoice(bool $adjust, UpdateOrderItemQuantity $updateOrderItemQuantity)` — **one concrete
  type injected, no union-typed parameter** (0094a adds `RemoveOrderItem` as a second concretely typed injected parameter). It
  re-authorizes, checks the stale guard (current quantity still equals
  `pendingFromQuantity`; otherwise close, refresh and show `orders.stock.changed_meanwhile`), calls the action with
  `adjustStock: $adjust`, clears pending state, closes the dialog and refreshes. `dismissStockConfirm()` clears all pending state
  and is the dialog's dismiss action (Esc / backdrop / X / "Keep editing").
- **D-5 — Preview.** `#[Computed] stockPreview(): list<array{name, variant, units, current, resulting, willGoNegative}>`, computed
  from a **fresh** DB read of the pending line and its stock row. `units` for a decrease uses the same formula as 0090 D-3
  (`max(0, returnableStockUnits() − (newQty − refunded_quantity))`), for an increase `d`; the dialog never promises more than the
  action applies.
- **D-6 (OQ-3, user-confirmed) — Zero units to return.** When a decrease would return 0 units (legacy line, units never deducted
  or already returned), the dialog still opens, shows the note `orders.stock.nothing_to_return` ("No units of this line are
  currently out of stock") instead of the confirm button, and offers only "Save without changing stock" and "Keep editing".
- **D-7 — Copy.** Confirm "Deduct :count units from stock" / "Return :count units to stock"; secondary "Save without changing
  stock"; dismiss "Keep editing"; negative warning `flux:callout` with `role="alert"`: "This leaves :name at :stock units." —
  never disables a button. Keys under `orders.stock.*` (en + es, identical sets).
- **D-8 — Hooks.** Dialog prefix `line-stock` → `confirm-dialog-line-stock`, `-confirm`, `-secondary`, `-dismiss`; preview row
  `stock-preview-row-{itemId}`; warning `stock-negative-warning`; note `stock-nothing-to-return`. Existing hooks
  `save-quantity-{id}` and `quantity-input-{id}` stay.
- **D-9 — Errors and pending state.** Every rendered action refusal closes the dialog, refreshes and renders inline — never
  a 500. **Pending state (`pendingStockAction`, `pendingItemId`, `pendingQuantity`, `pendingFromQuantity`, `showStockConfirm`) is
  cleared on every exit path of `applyStockChoice()`**: success, every rendered refusal (`OrderNotEditableException`,
  `ValidationException`, not-found item) and the stale-guard path, plus `dismissStockConfirm()`. **A failed re-authorization is
  a 403** (the `Gate::authorize` exception propagates; tests assert `assertForbidden()`); it does not clear pending state, which
  is harmless because the next apply re-authorizes first. An apply call with empty pending state (no prior request) is refused
  like a stale one.
- **D-10 (user D7)** — `orders.edit` gates request and apply; no new permission.

## Gherkin
```gherkin
Feature: The order administrator decides whether a quantity change moves stock

  Scenario: Raising a quantity asks whether to deduct the added units
    Given an order administrator, on a pending order with 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator saves the line with 5 units
    Then a dialog offers to deduct 3 units from stock, to save without changing stock, or to keep editing
    And it shows "Mug" going from 10 to 7 units

  Scenario: Deducting the added units updates the line and the stock
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units, and "Mug" has 10 units in stock
    When the order administrator chooses to deduct 3 units from stock
    Then the line shows 5 units
    And "Mug" has 7 units in stock

  Scenario: Saving without changing stock updates only the line
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units, and "Mug" has 10 units in stock
    When the order administrator chooses to save without changing stock
    Then the line shows 5 units
    And "Mug" still has 10 units in stock

  Scenario: Keeping editing changes nothing
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units
    When the order administrator chooses to keep editing
    Then the line still shows 2 units
    And no stock changes

  Scenario: Pressing Escape aborts
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units
    When the order administrator presses Escape
    Then the line still shows 2 units
    And no stock changes

  Scenario: An increase that empties the stock is warned about but allowed
    Given an order administrator, on a pending order with 2 units of "Mug", and "Mug" has 1 unit in stock
    When the order administrator saves the line with 5 units
    Then the dialog warns that "Mug" would be left at -2 units
    And deducting is still offered

  Scenario: Lowering a quantity asks whether to return the removed units
    Given an order administrator, on a pending order with 5 units of "Mug"
    When the order administrator saves the line with 2 units
    Then a dialog offers to return 3 units to stock, to save without changing stock, or to keep editing

  Scenario: Lowering a line with nothing to return still asks, without a return option
    Given an order administrator, on an order recorded before stock tracking existed, with 5 units of "Mug"
    When the order administrator saves the line with 2 units
    Then a dialog explains that no units of this line are currently out of stock
    And it offers only to save without changing stock or to keep editing

  Scenario: A line whose product was deleted is saved without a stock question
    Given an order administrator, on a pending order with a line of a product since deleted
    When the order administrator saves that line with a new quantity
    Then the line is updated without any stock dialog

  Scenario: A change made by someone else meanwhile is not applied blindly
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units, while another administrator has since changed the line to 4 units
    When the order administrator chooses to deduct 3 units from stock
    Then nothing is applied
    And the order administrator is told the line changed meanwhile and sees its current quantity

  Scenario: A refused change closes the dialog and explains why
    Given an order administrator, with the stock dialog open for a line of an order that has since been shipped
    When the order administrator chooses to deduct the units from stock
    Then the dialog closes
    And the order administrator is told the order can no longer be edited

  Scenario: An administrator who may not edit orders cannot open the stock dialog
    Given an administrator who may not edit orders, on a pending order
    When the administrator tries to save a line with a new quantity
    Then the action is refused as not authorized
    And no stock dialog opens
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-2 … D-6, D-9; remove the public `updateLineItemQuantity`.
- `resources/views/livewire/orders/show.blade.php` — `save-quantity-{id}` calls `requestQuantityChange`; `<x-confirm-dialog
  test-prefix="line-stock" …>` with preview slot, warning callout and the zero-units note.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.stock.*` (incl. `changed_meanwhile`, `nothing_to_return`).
- `tests/Feature/Orders/ShowLineItemStockTest.php` — new (Livewire).
- `tests/Feature/Orders/ShowLineItemsTest.php` — update tests that called the removed method.
- `tests/Browser/Orders/OrderLineItemStockDialogTest.php` — new; `tests/Browser/Orders/OrderDetailLineItemsTest.php` — update.

## Tests to perform
- [ ] Livewire: request records the cast `pendingQuantity` / `pendingFromQuantity` and opens the dialog with the right preview; unchanged quantity is a no-op; a null-target line saves with `adjustStock: false` and no dialog.
- [ ] Livewire: `applyStockChoice(true|false)` calls the action with the matching `adjustStock` (assert stock and counters, not a mock); dismiss clears state and changes nothing.
- [ ] Livewire: zero-returnable decrease shows the note, no confirm button, and the two remaining options (OQ-3).
- [ ] Livewire: invalid input (`0`, below refunded, empty string) reaches the action on apply and is rendered inline; the request step adds no validation of its own.
- [ ] Pending state is empty after success, after each rendered refusal type (authorization failure asserted as forbidden instead, `assertForbidden()`) and after the stale-guard path; `applyStockChoice()` with empty pending state is refused and changes nothing.
- [ ] Security: `#[Locked]` props cannot be set by the client; `applyStockChoice` re-authorizes (revoke permission between request and apply → refused); an item id of another order → not found, nothing written; stale guard fires.
- [ ] Preview from a fresh read (stock changed after opening → re-render shows the new value); `willGoNegative` only when resulting < 0.
- [ ] Lang parity.
- [ ] Browser (also covering 0093's interactive behaviour): three explicitly labelled buttons; secondary runs only the secondary method; Esc, backdrop and X abort; both action buttons disabled while one is loading; warning has `role="alert"`; stacked at 375px with ≥ 44px targets; dark mode legible.

## Expected outcome
Every quantity change on a stock-tracked line asks the administrator, shows the stock effect, and applies exactly the chosen
option.

## Acceptance criteria
- [ ] No path on the screen changes a stock-tracked line's quantity without the stock question.
- [ ] Preview and applied delta always agree (same formula as 0090 D-3).
- [ ] Zero-returnable decreases follow the confirmed OQ-3 behaviour.
- [ ] Negative stock is warned, never blocked; pending state is locked and re-validated; refusals never 500.
- [ ] en/es copy complete; existing hooks preserved.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (methods, locked props, hooks, stock dialog), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0090 and 0093. Negotiable: copy. Valuable: the administrator controls and sees the stock effect of every quantity
edit. Estimable/Small: one flow on one component after the removal split. Testable: Livewire and browser tests per scenario.

## Dependencies, risks, open technical questions
**Depends on:** [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md), [0093](done/0093-confirm-dialog-secondary-action-ui.md)
(hard). **Blocks:** [0094a](0094a-order-line-removal-stock-dialog-ui.md), [0095](0095-order-cancellation-stock-dialog-ui.md)
(reuse of the pending state and `stockPreview`). Shares `Show.php`, `show.blade.php` and `lang/*/orders.php` with 0094a, 0095,
0096 and 0092b (lang) — run sequentially.
- **R-1** — Removing the public `updateLineItemQuantity` changes the component's public surface; any caller must be updated in the
  same PR.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design and decisions D1, D3, D7.
- 2026-11-08 — Phase 2 review applied: split (removal → 0094a), no union-typed injection, request-step cast vs action validation,
  OQ-3 user-confirmed with its scenario, 0093's interactive checks moved here, actor wording.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner).
- Phase 2: INVEST review 2026-11-08: findings received → split and rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: pending state cleared on every refusal and stale-guard path, leftover app() alternative removed → rewritten, pending re-review.
- Phase 2: INVEST re-review #3 2026-11-08 → rewritten, pending re-review (D-9 pending-state wording: rendered refusals clear it, failed re-authorization is a 403 asserted with assertForbidden(), next apply re-authorizes).
