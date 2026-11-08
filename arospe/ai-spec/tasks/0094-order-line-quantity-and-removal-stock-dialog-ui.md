# [0094] Order detail — ask about stock when a line's quantity changes or a line is removed (frontend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 7 of the
> order-stock series 0088–0096. Fullstack pair: **related_task_id 0090** (backend `adjustStock` on
> `UpdateOrderItemQuantity`/`RemoveOrderItem`). Blocked on [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md)
> and [0093](0093-confirm-dialog-secondary-action-ui.md). OQ-3 (below) runs on an assumed default, not yet user-confirmed.

## Description
On the order detail screen, saving a new quantity or removing a line no longer writes straight away: a stock dialog opens and
asks, every time, whether to move stock — "Deduct N units from stock" (increase) or "Return N units to stock" (decrease /
removal), "Save without changing stock", or go back. The dialog previews, from a fresh read, each affected product/variant
with its current and resulting stock, and warns — without blocking — when an increase would leave stock below zero.
Lines whose catalog row was deleted skip the dialog (D1) and save as today without moving stock.

## Type
frontend (related_task_id: 0090) | includes database-expert: no

## Verified facts that shaped the story
- `App\Livewire\Orders\Show` today calls `updateLineItemQuantity(string $itemId, UpdateOrderItemQuantity)` and
  `removeLineItem(string $itemId, RemoveOrderItem)` directly from the `save-quantity-{id}` / `remove-line-item-{id}` buttons;
  each starts with `Gate::authorize('update', $this->order)` and `resetErrorBag()`, renders `OrderNotEditableException`
  inline, and calls `refreshOrderState()` (contract in
  [docs/api/orders.md#orders.show](../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities)).
- `#[Locked]` pending state with a stale guard already exists for the backward status transition (`pendingStatus`,
  `pendingFromStatus`) — this story copies that shape.
- Computeds must be read as properties and are `unset()` after every write; the error bag is reset at the top of every action;
  a number input is bound to a string (epic-3 digest, story 0055).

## Decisions (frontend-expert design, accepted)
- **D-1 (user D3)** — Always ask; never skipped for a line with a stock target. The only exception is D1: a line whose variant
  or product was deleted saves directly with `adjustStock: false` and no dialog.
- **D-2 — State.** `#[Locked] public string $pendingStockAction = ''` (`'quantity'|'remove'`), `#[Locked] public string $pendingItemId = ''`,
  `#[Locked] public int $pendingQuantity = 0`, `#[Locked] public int $pendingFromQuantity = 0` (stale guard),
  `public bool $showStockConfirm = false`. The cancel variant (`'cancel'`) is added by [0095](0095-order-cancellation-stock-dialog-ui.md).
- **D-3 — Methods.** `requestQuantityChange(string $itemId)` and `requestRemoveLineItem(string $itemId)` replace the direct
  `updateLineItemQuantity` / `removeLineItem` public methods (removed, so no public path skips the question). Each authorizes
  first, validates nothing the action does not own, records pending state, and opens the dialog — or, for an unchanged quantity,
  does nothing. `applyStockChoice(bool $adjust, UpdateOrderItemQuantity|RemoveOrderItem …)` re-authorizes, checks the stale
  guard (the item's current quantity still equals `pendingFromQuantity`; otherwise close, refresh and show
  `orders.stock.changed_meanwhile`), then calls the action with `adjustStock: $adjust`. `dismissStockConfirm()` clears all pending
  state; it is the dialog's dismiss action (Esc/backdrop/X).
- **D-4 — Preview.** `#[Computed] stockPreview(): list<array{name, variant, units, current, resulting, willGoNegative}>`, computed
  server-side from a **fresh** DB read of the pending line and its stock row (never from client state). `units` reads the same
  `OrderItem::returnableStockUnits()` helper the backend applies (0088 D8), so the dialog never promises more than the action
  returns; lines skipped per 0088 D9 (`ordered_as_variant` with a deleted variant) skip the dialog like any null-target line.
- **D-5 — Copy.** Confirm label "Deduct :count units from stock" (increase) / "Return :count units to stock" (decrease, removal);
  secondary "Save without changing stock" (quantity) / "Remove without changing stock" (removal); dismiss "Keep editing".
  Negative warning as a `flux:callout` with `role="alert"`: "This leaves :name at :stock units." — never disables a button.
  All keys under `orders.stock.*` in `lang/en/orders.php` and `lang/es/orders.php` (identical key sets).
- **D-6 — Hooks.** Dialog prefix `line-stock` → `confirm-dialog-line-stock`, `-confirm`, `-secondary`, `-dismiss`; preview rows
  `stock-preview-row-{itemId}`; warning `stock-negative-warning`. Existing hooks `save-quantity-{id}`, `remove-line-item-{id}`,
  `quantity-input-{id}` stay on the same buttons.
- **D-7 — Errors.** Every action refusal (`OrderNotEditableException`, `ValidationException` for `< refunded`, last line, refunded
  line) closes the dialog, refreshes, and renders inline as today — never a 500.
- **D-8 (user D7)** — No new permission; `orders.edit` gates both request and apply.

## Gherkin
```gherkin
Feature: The order administrator decides whether a line change moves stock

  Scenario: Raising a quantity asks whether to deduct the added units
    Given an order administrator, on a pending order with 2 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator saves the line with 5 units
    Then a dialog offers to deduct 3 units from stock, to save without changing stock, or to keep editing
    And it shows "Mug" going from 10 to 7 units

  Scenario: Deducting the added units updates the line and the stock
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units
    When the order administrator chooses to deduct 3 units from stock
    Then the line shows 5 units
    And "Mug" has 7 units in stock

  Scenario: Saving without changing stock updates only the line
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units
    When the order administrator chooses to save without changing stock
    Then the line shows 5 units
    And "Mug" still has 10 units in stock

  Scenario: Keeping editing changes nothing
    Given an order administrator, with the stock dialog open for raising "Mug" from 2 to 5 units
    When the order administrator chooses to keep editing
    Then the line still shows 2 units
    And "Mug" still has 10 units in stock

  Scenario: An increase that empties the stock is warned about but allowed
    Given an order administrator, on a pending order with 2 units of "Mug", and "Mug" has 1 unit in stock
    When the order administrator saves the line with 5 units
    Then the dialog warns that "Mug" would be left at -2 units
    And deducting is still offered

  Scenario: Lowering a quantity asks whether to return the removed units
    Given an order administrator, on a pending order with 5 units of "Mug"
    When the order administrator saves the line with 2 units
    Then a dialog offers to return 3 units to stock, to save without changing stock, or to keep editing

  Scenario: Removing a line asks whether to return its units
    Given an order administrator, on a pending order with lines of 3 units of "Mug" and 1 unit of "Plate"
    When the order administrator removes the "Mug" line
    Then a dialog offers to return 3 units to stock, to remove without changing stock, or to keep editing

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

  Scenario: An administrator without order editing rights cannot open the stock dialog
    Given an administrator without the orders edit permission, on a pending order
    When the administrator tries to save a line with a new quantity
    Then the action is refused as not authorized
    And no stock dialog opens
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-2 … D-4, D-7; remove the two direct methods.
- `resources/views/livewire/orders/show.blade.php` — buttons rewired, `<x-confirm-dialog test-prefix="line-stock" …>` with
  preview table slot and warning callout.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.stock.*`.
- `tests/Feature/Orders/ShowLineItemStockTest.php` — new (Livewire component tests).
- `tests/Feature/Orders/ShowLineItemsTest.php` — update the tests that called the removed methods.
- `tests/Browser/Orders/OrderLineItemStockDialogTest.php` — new; `tests/Browser/Orders/OrderDetailLineItemsTest.php` — update.

## Tests to perform
- [ ] Livewire: request opens the dialog with the right pending state and preview; unchanged quantity is a no-op; null-target line saves with `adjustStock: false` and no dialog.
- [ ] Livewire: `applyStockChoice(true|false)` calls the action with the matching `adjustStock` (assert on stock and counter, not a mock); dismiss clears state and changes nothing.
- [ ] Security: `#[Locked]` props cannot be set by the client (Livewire `set()` throws); `applyStockChoice` re-authorizes (revoke permission between request and apply → refused); forged item id of another order → not found, nothing written; stale guard fires.
- [ ] Preview: computed from a fresh read (stock changed after the dialog opened → preview after re-render shows the new value); `willGoNegative` true only when resulting < 0.
- [ ] Refusals render inline and close the dialog (shipped order, quantity below refunded, last line, refunded line).
- [ ] Lang parity (`OrdersLangParityTest`).
- [ ] Browser: three buttons with explicit labels, Esc / backdrop abort, warning has `role="alert"`, stacked at 375px, 44px targets, dark mode legible.

## Expected outcome
Every quantity change or removal on a line with a stock target asks the administrator, shows the stock effect, and applies
exactly the chosen option.

## Acceptance criteria
- [ ] No path on the screen changes a stock-tracked line's quantity or removes it without the stock question.
- [ ] The preview and the applied delta always agree (same server-side rule).
- [ ] Negative stock is warned, never blocked.
- [ ] Pending state is locked and re-validated; refusals never 500.
- [ ] en/es copy complete; existing hooks preserved.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (methods, locked props, hooks, stock dialog), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0090 and 0093. Negotiable: copy and OQ-3 (assumed default). Valuable: the administrator controls and sees the stock effect of every line edit. Estimable: one component, one view, lang keys. Small: two flows sharing one dialog and one pending-state shape; if Phase 2 finds it too big, split removal into its own story after quantity. Testable: Livewire and browser tests per scenario.

## Dependencies, risks, open technical questions
**Depends on:** [0090](0090-order-line-quantity-and-removal-stock-opt-in-backend.md),
[0093](0093-confirm-dialog-secondary-action-ui.md) (hard). **Blocks:** [0095](0095-order-cancellation-stock-dialog-ui.md)
(reuses this story's pending state, preview and dialog). Shares `Show.php`, `show.blade.php` and `lang/*/orders.php` with
[0095](0095-order-cancellation-stock-dialog-ui.md) and [0096](0096-order-refund-modal-stock-return-ui.md) — run sequentially.

- **OQ-3 — A line with zero returnable units (legacy line, or units already returned) — assumed default (a), not yet
  user-confirmed.** D3 says the admin is always asked, but "Return 0 units to stock" is a meaningless choice. Options:
  **(a) (recommended, assumed default)** still show the dialog (D3), replace the return button with a one-line note ("No units of this line are
  currently out of stock") and offer only "Save without changing stock" / "Keep editing";
  (b) skip the dialog for that line, like D1's deleted-catalog lines;
  (c) show the normal three buttons with "Return 0 units".
  Applies to decrease and removal here, and to the cancel dialog (0095) when **every** line has 0 returnable units.
- **R-1** — Removing the two public methods changes the component's public surface; any external caller (none found by grep in
  Phase 1) must be updated in the same PR.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design and decisions D1, D3, D7. frontend-qa
  scenarios folded into Gherkin and *Tests to perform*.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner). Phase 2: pending; OQ-3 on assumed default (a), not yet user-confirmed.
