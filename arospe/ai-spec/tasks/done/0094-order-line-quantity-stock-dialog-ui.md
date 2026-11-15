# [0094] Order detail — ask about stock when a line's quantity changes (frontend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.** Epic 3
> follow-up bug fix, order-stock series. **Split (user decision 2026-11-08):** this story covers the **quantity change only**;
> line removal moved to [0094a](../0094a-order-line-removal-stock-dialog-ui.md). Fullstack pair: **related_task_id 0090**. Blocked
> on [0090](../done/0090-order-line-quantity-and-removal-stock-opt-in-backend.md) and [0093](../done/0093-confirm-dialog-secondary-action-ui.md).
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
  ([docs/api/orders.md#orders.show](../../../docs/api/orders.md#ordersshow--the-twelfth-permission-gated-route-and-the-first-screen-governed-by-three-abilities)).
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
  action applies. For input the action will refuse (negative, or below the refunded quantity) `units` is **clamped to
  `[0, returnableStockUnits()]`**, so the preview never shows more units than the line holds; the apply step still refuses the
  input inline.
- **D-6 (OQ-3, user-confirmed) — Zero units to return.** When a decrease would return 0 units (legacy line, units never deducted
  or already returned), the dialog still opens, shows the note `orders.stock.nothing_to_return` ("No units of this line are
  currently out of stock") and offers only "Save without changing stock" and "Keep editing". **Implementation (Phase 2 re-review #4,
  option a):** `x-confirm-dialog` always renders its `-confirm` button and has no prop to hide it, and it is not modified by this
  story. So this variant is the **two-button form** (no `secondary-*` props): `confirm-action="applyStockChoice(false)"` with
  `confirm-label` "Save without changing stock", and the dismiss button "Keep editing". There is therefore **no `-secondary` hook
  and no "Deduct"/"Return" label** in this variant; the `-confirm` hook carries "Save without changing stock".
- **D-7 — Copy.** Confirm "Deduct :count units from stock" / "Return :count units to stock"; secondary "Save without changing
  stock"; dismiss "Keep editing"; negative warning `flux:callout` with `role="alert"`: "This leaves :name at :stock units." —
  never disables a button. Keys under `orders.stock.*` (en + es, identical sets).
- **D-8 — Hooks.** Dialog prefix `line-stock` → `confirm-dialog-line-stock`, `-confirm`, `-secondary`, `-dismiss` (`-secondary` only
  in the stock-moving variants; in the zero-units variant `-confirm` is "Save without changing stock", see D-6); preview row
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
- `resources/views/components/confirm-dialog.blade.php` — added during Phase 3 (not planned): the three-button form now always stacks full width, because the dismiss button overflowed the `max-w-md` panel from `sm` up; `tests/Feature/Components/ConfirmDialogTest.php` still matches.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.stock.*` (incl. `changed_meanwhile`, `nothing_to_return`).
- `tests/Feature/Orders/ShowLineItemStockTest.php` — new (Livewire).
- `tests/Feature/Orders/ShowLineItemsTest.php` and `tests/Feature/Orders/ShowTest.php` (authorization dataset, line 142) — update tests that called the removed method.
- `tests/Browser/Orders/OrderLineItemStockDialogTest.php` — new; `tests/Browser/Orders/OrderDetailLineItemsTest.php` — update.

## Tests to perform
- [x] Livewire: request records the cast `pendingQuantity` / `pendingFromQuantity` and opens the dialog with the right preview; unchanged quantity is a no-op; a null-target line saves with `adjustStock: false` and no dialog.
- [x] Livewire: `applyStockChoice(true|false)` calls the action with the matching `adjustStock` (assert stock and counters, not a mock); dismiss clears state and changes nothing.
- [x] Livewire: zero-returnable decrease shows the note, no `-secondary` hook and no Deduct/Return label, `-confirm` reads "Save without changing stock" and runs `applyStockChoice(false)`, plus "Keep editing" (OQ-3).
- [x] Livewire: the preview `units` is clamped to `[0, returnableStockUnits()]` for negative / below-refunded input.
- [x] Livewire: invalid input (`0`, below refunded, empty string) reaches the action on apply and is rendered inline; the request step adds no validation of its own.
- [x] Pending state is empty after success, after each rendered refusal type (authorization failure asserted as forbidden instead, `assertForbidden()`) and after the stale-guard path; `applyStockChoice()` with empty pending state is refused and changes nothing.
- [x] Security: `#[Locked]` props cannot be set by the client; `applyStockChoice` re-authorizes (revoke permission between request and apply → refused); an item id of another order → not found, nothing written; stale guard fires.
- [x] Preview from a fresh read (stock changed after opening → re-render shows the new value); `willGoNegative` only when resulting < 0.
- [x] Lang parity.
- [x] Browser (also covering 0093's interactive behaviour): three explicitly labelled buttons; secondary runs only the secondary method; Esc, backdrop and X abort; both action buttons disabled while one is loading (confirm that Livewire 4 matches the composite `wire:target="applyStockChoice(true),applyStockChoice(false)"`; a bare `applyStockChoice` target is the fallback); warning has `role="alert"`; stacked at 375px with ≥ 44px targets; dark mode legible.

## Expected outcome
Every quantity change on a stock-tracked line asks the administrator, shows the stock effect, and applies exactly the chosen
option.

## Acceptance criteria
- [x] No path on the screen changes a stock-tracked line's quantity without the stock question.
- [x] Preview and applied delta always agree (same formula as 0090 D-3).
- [x] Zero-returnable decreases follow the confirmed OQ-3 behaviour.
- [x] Negative stock is warned, never blocked; pending state is locked and re-validated; refusals never 500.
- [x] en/es copy complete; existing hooks preserved.

## Definition of Done
- [x] Tests written and green (full suite, unscoped)
- [x] Code reviewed (code-reviewer)
- [x] No security findings (appsec-auditor)
- [x] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (methods, locked props, hooks, stock dialog), `ai-spec/tasks/_digests/epic-3.md`
- [x] Acceptance criteria met

## INVEST note
Independent: needs 0090 and 0093. Negotiable: copy. Valuable: the administrator controls and sees the stock effect of every quantity
edit. Estimable/Small: one flow on one component after the removal split. Testable: Livewire and browser tests per scenario.

## Dependencies, risks, open technical questions
**Depends on:** [0090](../done/0090-order-line-quantity-and-removal-stock-opt-in-backend.md), [0093](../done/0093-confirm-dialog-secondary-action-ui.md)
(hard). **Blocks:** [0094a](../0094a-order-line-removal-stock-dialog-ui.md), [0095](../0095-order-cancellation-stock-dialog-ui.md)
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
- Phase 2: INVEST re-review #4 2026-11-15 (code-reviewer): REJECTED — D-6/D-8 not implementable as written: `x-confirm-dialog` always renders its confirm button (no prop to hide it) and the component is not in Files to create/modify; state which hook carries "Save without changing stock" in the zero-units dialog (two-button form with confirm-action `applyStockChoice(false)`, or a new optional component prop) and align the "no confirm button" test.
- Phase 2: story rewritten 2026-11-15 after re-review #4: D-6/D-8 fixed with option (a) (two-button form, `-confirm` = "Save without changing stock", shared component untouched), D-5 clamp added, `ShowTest.php` added to the file list, 0094a pending-state list aligned; pending re-review.
- Phase 2: INVEST re-review #5 2026-11-15 (code-reviewer): APPROVED — zero-units variant is the two-button form (`-confirm` = "Save without changing stock" → `applyStockChoice(false)`, no `-secondary`), consistent across D-6, D-8, Gherkin, Tests to perform and Files; D-5 clamp to `[0, returnableStockUnits()]` matches `UpdateOrderItemQuantity` (k = max(0, d − U), upper bound reached at newQty = refunded_quantity); no new contradiction.
- Phase 4: security audit 2026-11-15 (appsec-auditor): APPROVED — orders.edit re-authorized on request and apply (403), pending props #[Locked], item resolved through the order (no IDOR), Blade output escaped, no remaining path skips the stock question; two Low notes (stale guard checks only `quantity` outside the transaction; action LogicException under a concurrent race surfaces as a 500, pre-existing from 0090) left as non-blocking follow-ups.
- Phase 5: code review 2026-11-15 (code-reviewer): APPROVED — all 12 Gherkin scenarios and the acceptance criteria implemented and covered (ShowLineItemStockTest 39/39 green, browser file covers Esc/backdrop/X, loading-disable, 375px, dark mode); preview formula equals the action (d − U); en/es parity; hooks preserved; confirm-dialog always-stacked change sound and ConfirmDialogTest still matches. Non-blocking: stale test title ShowLineItemsTest.php:89, stale view docblock show.blade.php:22-25, redundant returnableStockUnits() call Show.php:577; docs/api/orders.md:60 and :188 for Phase 6.
- Phase 5 (full suite, 2026-11-15, unscoped, run in directory chunks on separate databases): `tests/Feature/Orders` 1047/1047; Unit + Actions/Auth/Authorization/Blog/Components/Console/Customers 1814/1814; Dashboard…Notifications 1077 passed + 5 skipped; PaymentMethods…Settings 1494/1494; Shipping…Users 653/653; `tests/Browser` 305 tests, 301 passed + 3 skipped + 1 timeout (`WysiwygEditorOutputHtmlTest`, unrelated, load flake: passes 11/11 alone). Pint unscoped clean; Larastan clean on `Show.php`. Review follow-ups applied: concurrent-change `LogicException` rendered as `changed_meanwhile`, preview formula tidy, docblock, test title.
- Phase 6: documentation updated 2026-11-15 (`docs/api/orders.md#orders.show`, `ai-spec/tasks/_digests/epic-3.md`).
- Phase 7: closed 2026-11-15, moved to `done/`. Known low follow-up (appsec): pass the expected counters into `UpdateOrderItemQuantity` so the stale guard runs inside the action's transaction.
