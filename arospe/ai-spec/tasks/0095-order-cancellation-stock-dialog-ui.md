# [0095] Order detail — the cancel confirmation asks whether to return stock (frontend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up bug fix, part 8 of the
> order-stock series 0088–0096. Fullstack pair: **related_task_id 0091** (backend `CancelOrder` `adjustStock`). Blocked on
> [0091](0091-order-cancellation-stock-return-opt-in-backend.md), [0093](0093-confirm-dialog-secondary-action-ui.md) and
> [0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md) (reuses its pending state, preview and dialog).

## Description
The existing "Cancel order" confirmation becomes the shared stock dialog: "Cancel and return N units to stock", "Cancel without
changing stock", or "Keep order". It previews every line that has returnable units with its current and resulting stock, and
leaves out lines whose catalog row was deleted (D1). Cancellation authorization and refusals are unchanged.

## Type
frontend (related_task_id: 0091) | includes database-expert: no

## Verified facts that shaped the story
- Today: `confirmCancel()` (authorizes `cancel`, opens `showCancelConfirm`), `dismissCancelConfirm()`, `cancelOrder(CancelOrder)`
  (authorizes, calls the action, renders `OrderCancellationBlockedException` / `ValidationException` as a `cancel` error);
  dialog hook prefix `cancel-order` (`confirm-dialog-cancel-order`, `-confirm`, `-dismiss`); trigger hook `cancel-order`.
- Cancel is enabled only for `OrderPolicy::cancel` (`orders.edit` **and** `orders.refund` **and** `isManuallyCancellable()`), and
  Super Admin sees it enabled on a Shipped order (documented drift; the 409 is rendered) — epic-3 digest, story 0055.

## Decisions
- **D-1 (user D3)** — Always asked; the cancel confirmation is no longer a two-way dialog.
- **D-2** — `confirmCancel()` sets `pendingStockAction = 'cancel'` and opens `showStockConfirm` (0094 state) instead of
  `showCancelConfirm`; `showCancelConfirm`, `dismissCancelConfirm()` and the old `cancelOrder()` are removed;
  `applyStockChoice(bool $adjust)` gains the `cancel` branch: re-authorize `cancel`, stale guard on the order status
  (`pendingFromStatus`-style: the status at request time must still be current), call `CancelOrder` with `adjustStock: $adjust`.
- **D-3** — `stockPreview` gains the cancel shape: one row per line with returnable units > 0 and a stock target; two lines of the
  same target are shown per line but the resulting stock accounts for both (same aggregation as the backend).
- **D-4 — Copy.** Confirm "Cancel and return :count units to stock"; secondary "Cancel without changing stock"; dismiss "Keep order".
  Keys under `orders.stock.cancel_*` (en + es).
- **D-5 — Hooks (assumed default, not yet user-confirmed).** The dialog prefix changes from `cancel-order` to `order-stock` **or** stays `cancel-order` — **recommended and assumed: keep
  `cancel-order`** so existing browser tests change only for the new button (`confirm-dialog-cancel-order-secondary`). Trigger hook
  `cancel-order` unchanged.
- **D-6** — If no line has a returnable unit (legacy order, or every line deleted from the catalog), the dialog follows 0094's
  OQ-3 assumed default (a), not yet user-confirmed (note "No units of this order are currently out of stock", only "Cancel without changing stock" and
  "Keep order").
- **D-7 (user D7)** — No authorization change.

## Gherkin
```gherkin
Feature: Cancelling an order asks whether to return its units to stock

  Scenario: The cancel confirmation offers to return the units
    Given an order administrator allowed to cancel, on a pending order with 3 units of "Mug" and 2 units of the "Red / M" variant
    When the order administrator asks to cancel the order
    Then a dialog offers to cancel and return 5 units to stock, to cancel without changing stock, or to keep the order
    And it shows the current and resulting stock of "Mug" and "Red / M"

  Scenario: Cancelling and returning the units
    Given an order administrator, with the cancel dialog open for an order with 3 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator chooses to cancel and return the units to stock
    Then the order shows as cancelled
    And "Mug" has 13 units in stock

  Scenario: Cancelling without changing stock
    Given an order administrator, with the cancel dialog open for an order with 3 units of "Mug", and "Mug" has 10 units in stock
    When the order administrator chooses to cancel without changing stock
    Then the order shows as cancelled
    And "Mug" still has 10 units in stock

  Scenario: Keeping the order changes nothing
    Given an order administrator, with the cancel dialog open
    When the order administrator chooses to keep the order
    Then the order is not cancelled
    And no stock changes

  Scenario: A line whose product was deleted is not listed
    Given an order administrator allowed to cancel, on a pending order with 3 units of "Mug" and a line of a product since deleted
    When the order administrator asks to cancel the order
    Then the dialog lists only "Mug"

  Scenario: An order changed by someone else meanwhile is not cancelled blindly
    Given an order administrator, with the cancel dialog open, while another administrator has since moved the order to shipped
    When the order administrator chooses to cancel and return the units to stock
    Then nothing is applied
    And the order administrator is told the order changed meanwhile

  Scenario: A Super Admin cancelling a shipped order is told it is blocked
    Given a Super Admin, with the cancel dialog open on a shipped order
    When the Super Admin chooses to cancel and return the units to stock
    Then the dialog closes
    And the Super Admin is told the order cannot be cancelled in its current state
    And no stock changes

  Scenario: An administrator without the refund permission cannot open the cancel dialog
    Given an administrator with the orders edit permission but not the orders refund permission, on a pending order
    When the administrator tries to cancel the order
    Then the action is refused as not authorized
```

## Files to create/modify
- `app/Livewire/Orders/Show.php` — D-2, D-3; remove `showCancelConfirm`, `dismissCancelConfirm()`, `cancelOrder()`.
- `resources/views/livewire/orders/show.blade.php` — the cancel `<x-confirm-dialog>` uses the stock dialog props and preview slot.
- `lang/en/orders.php`, `lang/es/orders.php` — `orders.stock.cancel_*`.
- `tests/Feature/Orders/ShowCancellationTest.php` — updated for the new flow (existing hooks/method names change).
- `tests/Browser/Orders/OrderCancellationTest.php` — updated; new secondary-button cases.

## Tests to perform
- [ ] Livewire: request opens the stock dialog with the cancel preview; both choices call `CancelOrder` with the matching `adjustStock` (asserted on stock); keep-order changes nothing.
- [ ] Security: locked pending state; re-authorization on apply (revoke `orders.refund` between request and apply → refused); stale-status guard.
- [ ] Refusals: Super Admin on Shipped → rendered 409 message, dialog closed, no stock change; already cancelled (race) → rendered validation message.
- [ ] Preview excludes null-target lines and lines with 0 returnable units; zero-returnable order follows OQ-3's answer.
- [ ] Browser: explicit labels, Esc/backdrop abort, mobile stacking, dark mode.

## Expected outcome
Cancelling an order always states its stock effect and applies exactly the administrator's choice.

## Acceptance criteria
- [ ] The cancel confirmation is the three-way stock dialog with a preview.
- [ ] The old two-way cancel path no longer exists on the component.
- [ ] Authorization and refusal rendering unchanged.
- [ ] en/es copy complete.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md#orders.show` (cancel methods and hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: needs 0091 and 0094's shared pieces. Negotiable: hook prefix (D-5) and zero-returnable variant. Valuable: cancelling states and applies its stock effect. Estimable/Small: one more branch on an existing dialog plus test updates. Testable: Livewire and browser tests per scenario.

## Dependencies, risks, open technical questions
**Depends on:** [0091](0091-order-cancellation-stock-return-opt-in-backend.md), [0093](0093-confirm-dialog-secondary-action-ui.md),
[0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md) (hard). Shares files with
[0096](0096-order-refund-modal-stock-return-ui.md) — run sequentially.

- **Assumed defaults, not yet user-confirmed** — D-5 hook prefix (keep `cancel-order`); D-6 follows 0094's OQ-3 default (a).
- Returnable units in the preview come from `OrderItem::returnableStockUnits()` (0088 D8); orphaned variant lines (0088 D9) are not listed.
- **R-1** — Existing cancellation tests change by design; Phase 5 must check they were adapted, not deleted.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design and decisions D1, D3, D4, D7.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner). Phase 2: pending.
