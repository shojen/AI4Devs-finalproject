# [0095] Order detail — the cancel confirmation asks whether to return stock (frontend)

> **Status: New — Phase 1 output, rewritten after the Phase 2 INVEST review of 2026-11-08; pending re-review.** Epic 3
> follow-up bug fix, order-stock series. Fullstack pair: **related_task_id 0091**. Blocked on
> [0091](in-progress/0091-order-cancellation-stock-return-opt-in-backend.md), [0093](done/0093-confirm-dialog-secondary-action-ui.md) and
> [0094](0094-order-line-quantity-stock-dialog-ui.md) (reuses its `stockPreview` computed).
> **User-confirmed (2026-11-08):** OQ-3 — an order with nothing to return still gets the dialog, with a note and only "Cancel
> without changing stock" / "Keep order".

## Description
The existing "Cancel order" confirmation becomes a three-way question: "Cancel and return N units to stock", "Cancel without
changing stock", or "Keep order". It previews every line with returnable units with its current and resulting stock and leaves
out lines whose catalog row was deleted (D1). Cancellation authorization and refusal rendering are unchanged.

## Type
frontend (related_task_id: 0091) | includes database-expert: no

## Verified facts that shaped the story
- Today: `confirmCancel()` (authorizes `cancel`, opens `showCancelConfirm`), `dismissCancelConfirm()`, `cancelOrder(CancelOrder)`
  (authorizes, calls the action, renders `OrderCancellationBlockedException` / `ValidationException` as a `cancel` error); dialog
  prefix `cancel-order`; trigger hook `cancel-order`.
- Cancel is enabled only for `OrderPolicy::cancel` (`orders.edit` **and** `orders.refund` **and** `isManuallyCancellable()`); a
  super administrator sees it enabled on a shipped order (documented drift; the refusal is rendered) — epic-3 digest, story 0055.
- 0094 introduces `stockPreview` and the `line-stock` dialog bound to `showStockConfirm`.

## Decisions
- **D-1 (user D3)** — Always asked; the cancel confirmation is no longer two-way.
- **D-2 — A separate dialog, not the shared line dialog (chosen to avoid two dialogs on one `wire:model`).** The cancel dialog
  keeps its own flag `showCancelConfirm`, its own prefix `cancel-order` and its own methods; it only gains the 0093 secondary
  action and a preview slot. `confirmCancel()` authorizes `cancel`, records the stale guard and opens `showCancelConfirm`;
  `dismissCancelConfirm()` clears it; `cancelOrder(bool $adjust, CancelOrder $cancelOrder)` re-authorizes, checks the stale guard
  and calls `CancelOrder` with `adjustStock: $adjust` (the confirm button calls it with `true`, the secondary with `false`).
- **D-3 — Stale guard.** New `#[Locked] public string $pendingCancelFromStatus = ''`, set by `confirmCancel()` to the order's
  current status. It is **reset to `''` by `dismissCancelConfirm()` and on every exit path of `cancelOrder()`**: success,
  `OrderCancellationBlockedException`, `ValidationException` and the stale-guard path. If the status differs at apply time —
  **or the guard is empty at apply time (a direct `cancelOrder()` call without `confirmCancel()`), which is refused exactly like a
  stale guard** — nothing is applied, the dialog closes, the order refreshes and `orders.stock.cancel_changed_meanwhile` ("This
  order changed meanwhile. Review it and try again.") is shown.
- **D-4 — Preview.** `stockPreview` (0094) chooses its mode from `pendingStockAction`: `'quantity'` / `'remove'` are the line
  modes (0094/0094a); the **cancel mode applies only when `pendingStockAction === ''`** (and returns rows only while
  `pendingCancelFromStatus` is set), so the line dialog's preview can never be replaced by the cancel one. Cancel mode: one row per line with `returnableStockUnits() > 0` and a stock target; two lines of the same
  target show the combined resulting stock (same aggregation as the backend).
- **D-5 — Copy.** Confirm "Cancel and return :count units to stock"; secondary "Cancel without changing stock"; dismiss "Keep
  order"; keys under `orders.stock.cancel_*` (en + es).
- **D-6 (OQ-3, user-confirmed) — Nothing to return.** If no line has a returnable unit (legacy order, every unit already returned,
  or every line deleted from the catalog), the dialog shows `orders.stock.cancel_nothing_to_return` ("No units of this order are
  currently out of stock") instead of the confirm button and offers only "Cancel without changing stock" and "Keep order".
- **D-7 — Hooks.** Prefix `cancel-order` kept → existing hooks unchanged; new `confirm-dialog-cancel-order-secondary`,
  `cancel-stock-preview-row-{itemId}`, `cancel-stock-nothing-to-return`.
- **D-8 (user D7)** — No authorization change.

## Gherkin
```gherkin
Feature: Cancelling an order asks whether to return its units to stock

  Scenario: The cancel confirmation offers to return the units
    Given an order administrator who may refund, on a pending order with 3 units of "Mug" and 2 units of the "Red / M" variant
    When the order administrator asks to cancel the order
    Then a dialog offers to cancel and return 5 units to stock, to cancel without changing stock, or to keep the order
    And it shows the current and resulting stock of "Mug" and "Red / M"

  Scenario: Cancelling and returning the units
    Given an order administrator who may refund, with the cancel dialog open for an order with 3 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator chooses to cancel and return the units to stock
    Then the order shows as cancelled
    And "Mug" has 13 units in stock

  Scenario: Cancelling without changing stock
    Given an order administrator who may refund, with the cancel dialog open for an order with 3 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator chooses to cancel without changing stock
    Then the order shows as cancelled
    And "Mug" still has 10 units in stock

  Scenario: Keeping the order changes nothing
    Given an order administrator who may refund, with the cancel dialog open
    When the order administrator chooses to keep the order
    Then the order is not cancelled
    And no stock changes

  Scenario: An order with nothing to return still asks, without a return option
    Given an order administrator who may refund, on a pending order recorded before stock tracking existed
    When the order administrator asks to cancel the order
    Then a dialog explains that no units of this order are currently out of stock
    And it offers only to cancel without changing stock or to keep the order

  Scenario: A line whose product was deleted is not listed
    Given an order administrator who may refund, on a pending order with 3 units of "Mug" and a line of a product since deleted
    When the order administrator asks to cancel the order
    Then the dialog lists only "Mug"

  Scenario: An order changed by someone else meanwhile is not cancelled blindly
    Given an order administrator who may refund, with the cancel dialog open, while another administrator has since moved the order to processing
    When the order administrator chooses to cancel and return the units to stock
    Then nothing is applied
    And the order administrator is told the order changed meanwhile

  Scenario: A super administrator cancelling a shipped order is told it is blocked
    Given a super administrator, with the cancel dialog open on a shipped order
    When the super administrator chooses to cancel and return the units to stock
    Then the dialog closes
    And the super administrator is told the order cannot be cancelled in its current state
    And no stock changes

  Scenario: An administrator who may not refund cannot open the cancel dialog
    Given an order administrator who may edit orders but may not refund, on a pending order
    When the administrator tries to cancel the order
    Then the action is refused as not authorized
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-2 … D-4, D-6; `cancelOrder(bool $adjust, CancelOrder $cancelOrder)`; new locked property.
- `resources/views/livewire/orders/show.blade.php` — the cancel `<x-confirm-dialog>` gets the secondary action, preview slot and
  nothing-to-return note.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.stock.cancel_*` (incl. `cancel_changed_meanwhile`, `cancel_nothing_to_return`).
- `tests/Feature/Orders/ShowCancellationTest.php` — updated for the three-way flow.
- `tests/Browser/Orders/OrderCancellationTest.php` — updated; new secondary-button cases.

## Tests to perform
- [ ] Livewire: `confirmCancel()` opens the dialog with the cancel preview and sets `pendingCancelFromStatus`; both choices call `CancelOrder` with the matching `adjustStock` (asserted on stock); keep-order changes nothing.
- [ ] Only one modal is bound to `showCancelConfirm`; the `line-stock` dialog's flag is untouched by the cancel flow.
- [ ] `pendingCancelFromStatus` is `''` after `dismissCancelConfirm()` and after every exit path of `cancelOrder()` (success, blocked, validation, stale guard); a direct `cancelOrder(true)` call without `confirmCancel()` is refused like a stale guard and moves no stock.
- [ ] `stockPreview` returns the line rows while `pendingStockAction` is `'quantity'`/`'remove'` and the cancel rows only when `pendingStockAction === ''`.
- [ ] Security: `pendingCancelFromStatus` cannot be set by the client; re-authorization on apply (revoke `orders.refund` → refused); stale guard fires on a status change.
- [ ] Refusals: super administrator on shipped → rendered blocked message, dialog closed, no stock change; already cancelled (race) → rendered validation message.
- [ ] Preview excludes null-target lines and lines with 0 returnable units; a fully zero order shows the note and two options (OQ-3).
- [ ] Browser: explicit labels, Esc/backdrop abort, mobile stacking, dark mode.

## Expected outcome
Cancelling an order always states its stock effect and applies exactly the administrator's choice.

## Acceptance criteria
- [ ] The cancel confirmation is a separate three-way dialog with a preview, on its own flag and prefix.
- [ ] Stale guard and re-authorization on apply; refusals render inline.
- [ ] Zero-returnable orders follow OQ-3; en/es copy complete; existing hooks preserved.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (cancel methods, locked prop, hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0091, 0093 and 0094's preview. Negotiable: copy. Valuable: cancelling states and applies its stock effect.
Estimable/Small: one existing dialog gains a second action, a preview and a guard. Testable: Livewire and browser cases per scenario.

## Dependencies, risks, open technical questions
**Depends on:** [0091](in-progress/0091-order-cancellation-stock-return-opt-in-backend.md), [0093](done/0093-confirm-dialog-secondary-action-ui.md),
[0094](0094-order-line-quantity-stock-dialog-ui.md) (hard). Shares files with [0094a](0094a-order-line-removal-stock-dialog-ui.md),
[0096](0096-order-refund-modal-stock-return-ui.md) and [0092b](0092b-order-refund-stock-return-backend.md) (lang) — run sequentially.
- **R-1** — Existing cancellation tests change by design; Phase 5 must check they were adapted, not deleted.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design and decisions D1, D3, D4, D7.
- 2026-11-08 — Phase 2 review applied: separate dialog (own flag and prefix), named stale-guard property and lang key, OQ-3
  scenario (user-confirmed), actor wording.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner).
- Phase 2: INVEST review 2026-11-08: findings received → rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: D-3 reset and empty-guard rule, preview mode selection by pendingStockAction, parameter order cancelOrder(bool $adjust, CancelOrder $cancelOrder), matching tests → rewritten, pending re-review.
