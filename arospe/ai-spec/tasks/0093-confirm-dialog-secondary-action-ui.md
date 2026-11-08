# [0093] Confirmation dialog — optional second choice (frontend)

> **Status: New — Phase 1 (Three Amigos) output, Phase 2 (INVEST) not run.** Epic 3 follow-up, part 6 of the order-stock
> series 0088–0096. No backend pair and no dependency: a generic, backwards-compatible extension of the shared
> `<x-confirm-dialog>` that the stock dialogs ([0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md),
> [0095](0095-order-cancellation-stock-dialog-ui.md)) need.

## Description
`<x-confirm-dialog>` today offers exactly two controls: confirm and dismiss. The stock dialogs need three explicit outcomes —
"Return N units to stock", "Save without changing stock", and abort. This story adds optional `secondary-action` /
`secondary-label` props that render a second, non-default choice; when they are omitted the dialog renders exactly as today.

## Type
frontend | includes database-expert: no

## Verified facts that shaped the story
- `resources/views/components/confirm-dialog.blade.php` is an anonymous component (story 0055 D-2): the parent owns the
  `show` flag and the method names; `@close` on `<flux:modal>` runs the **dismiss** method so Esc / backdrop / X reach the
  server; inner content is wrapped in `@if ($show)`; hooks `confirm-dialog-{prefix}`, `-dismiss`, `-confirm` live on the inner content.
- Current consumers: the backward status transition and the order cancellation on `Orders\Show`, and anywhere else a
  `grep '<x-confirm-dialog'` finds in Phase 3 — all must render byte-for-byte as today.

## Decisions
- **D-1** — New props `secondaryAction` (`secondary-action`) and `secondaryLabel` (`secondary-label`), both default `null`.
  The secondary button renders only when **both** are non-null.
- **D-2** — Hook `data-test="confirm-dialog-{prefix}-secondary"`; same `wire:loading.attr="disabled"` + `wire:target` pattern
  as confirm. While either action is loading, both action buttons are disabled (no double submit of two different choices).
- **D-3** — Esc, backdrop and the X **always** run the dismiss method (abort) — never the secondary action.
- **D-4** — Button order (left → right on `sm` and up): dismiss (outline), secondary (outline / `filled`), confirm (`$variant`).
  Below `sm` the three buttons stack full width, confirm first, dismiss last; each target is at least 44px tall.
- **D-5** — Labels are always explicit verbs supplied by the parent (e.g. "Return 3 units to stock", "Save without changing
  stock", "Keep editing"); the component ships no default copy.
- **D-6** — No change to the existing `@close`/`wire:model` contract and no state in the component.

## Gherkin
```gherkin
Feature: A confirmation dialog can offer a second choice

  Scenario: A dialog without a second choice is unchanged
    Given an order administrator, on an order whose status change needs confirmation
    When the order administrator opens the confirmation
    Then the dialog offers only to confirm or to go back

  Scenario: A dialog with a second choice shows three explicit options
    Given an order administrator, on a screen that offers a confirmation with a second choice
    When the order administrator opens the confirmation
    Then the dialog offers the main choice, the second choice and going back, each with its own label

  Scenario: Choosing the second option runs only that option
    Given an order administrator, with a confirmation with a second choice open
    When the order administrator picks the second choice
    Then only the second choice is carried out
    And the dialog closes

  Scenario: Closing the dialog with the keyboard aborts
    Given an order administrator, with a confirmation with a second choice open
    When the order administrator presses Escape
    Then neither choice is carried out
    And the dialog closes

  Scenario: Clicking outside the dialog aborts
    Given an order administrator, with a confirmation with a second choice open
    When the order administrator clicks outside the dialog
    Then neither choice is carried out

  Scenario: On a phone the options are stacked and easy to tap
    Given an order administrator using a phone-sized screen, with a confirmation with a second choice open
    When the order administrator looks at the options
    Then the options are stacked full width with the main choice first
```

## Files to create/modify
- `resources/views/components/confirm-dialog.blade.php` — two optional props, secondary button, responsive stacking, docblock
  comment updated (hooks list).
- `tests/Feature/Components/ConfirmDialogTest.php` — new (Blade render test: with/without secondary props; hooks; labels escaped).
- `tests/Browser/Components/ConfirmDialogSecondaryActionTest.php` — new, or folded into 0094's browser test if no standalone
  harness page exists (decide in Phase 3; a test-only route is **not** added).

## Tests to perform
- [ ] Render: without the new props the markup equals today's (no `-secondary` hook); with both, the hook and label render; with only one, nothing renders.
- [ ] Render: a label containing HTML is escaped.
- [ ] Regression: `ShowStatusTest`, `ShowCancellationTest`, `OrderStatusTransitionTest`, `OrderCancellationTest` unchanged and green.
- [ ] Browser (through 0094's first consumer if needed): secondary click calls only the secondary method; Esc / backdrop / X call dismiss; both action buttons disabled while one is loading; stacked layout at 375px with ≥ 44px targets.

## Expected outcome
Any screen can ask a three-way question with the shared dialog; existing two-way dialogs are unaffected.

## Acceptance criteria
- [ ] Optional props, defaults `null`, existing consumers unchanged.
- [ ] Abort paths never run the secondary action.
- [ ] Hooks documented; responsive and 44px targets.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` "Shared pieces this story introduced" (component props/hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: no dependency. Negotiable: button order and variants. Valuable: unblocks every three-way question in the admin, with zero regression for existing dialogs. Estimable/Small: one Blade component. Testable: render tests plus browser checks through its first consumer.

## Dependencies, risks, open technical questions
**Depends on:** nothing pending. **Blocks:** [0094](0094-order-line-quantity-and-removal-stock-dialog-ui.md),
[0095](0095-order-cancellation-stock-dialog-ui.md).

- **R-1** — Flux modal + Blaze markup rules (hooks on inner content, single dismiss control in the DOM) must hold for the new
  button too ([base-standards Livewire and Flux conventions](../../docs/conventions/base-standards/livewire-and-flux-conventions.md)).
- **Open (non-blocking)** — none.

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner). Phase 2: pending.
