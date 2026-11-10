# [0093] Confirmation dialog — optional second choice (frontend)

> **Status: Done — closed 2026-11-10 (Phases 1–7 complete).** Epic 3
> follow-up, order-stock series. No backend pair and no dependency: a backwards-compatible extension of the shared
> `<x-confirm-dialog>` that the stock dialogs ([0094](../0094-order-line-quantity-stock-dialog-ui.md),
> [0094a](../0094a-order-line-removal-stock-dialog-ui.md), [0095](../0095-order-cancellation-stock-dialog-ui.md)) need. This story is
> limited to what a render test can prove; the interactive checks (Esc / backdrop / X abort, loading-disable, 375px layout) are
> owned by its first consumer, [0094](../0094-order-line-quantity-stock-dialog-ui.md).

## Description
`<x-confirm-dialog>` today offers exactly two controls: confirm and dismiss. The stock dialogs need three explicit outcomes. This
story adds optional `secondary-action` / `secondary-label` props that render a second, non-default choice; when they are omitted
the dialog renders **exactly** as today.

## Type
frontend | includes database-expert: no

## Verified facts that shaped the story
- `resources/views/components/confirm-dialog.blade.php` is anonymous (story 0055 D-2): the parent owns the `show` flag and the
  method names; `@close` runs the **dismiss** method; inner content is wrapped in `@if ($show)`; hooks `confirm-dialog-{prefix}`,
  `-dismiss`, `-confirm` live on the inner content.
- Current consumers, all of which must render unchanged: the backward status transition and the cancellation on
  `resources/views/livewire/orders/show.blade.php` (≈ lines 421, 433), and the **mark-as-paid** dialog on
  `orders/show.blade.php` (≈ line 447) and `resources/views/livewire/orders.blade.php` (≈ line 142).

## Decisions
- **D-1** — New props `secondaryAction` (`secondary-action`) and `secondaryLabel` (`secondary-label`), both default `null`. The
  secondary button renders only when **both** are non-null.
- **D-2** — Hook `data-test="confirm-dialog-{prefix}-secondary"`; same `wire:loading.attr="disabled"` + `wire:target` pattern as
  confirm, targeting both actions so neither can be double-submitted.
- **D-3** — `@close` (Esc, backdrop, X) keeps running the dismiss method — never the secondary action. No change to the
  `@close`/`wire:model` contract and no state in the component.
- **D-4** — **The stacked layout applies only when the secondary action renders.** Three-button dialogs: left → right on `sm`+
  dismiss (outline), secondary (outline), confirm (`$variant`); below `sm` stacked full width, confirm first, dismiss last, each
  at least 44px tall. **Two-button dialogs render as before: same hooks, same container classes, same `wire:target`, no secondary or stacked-layout classes.**
- **D-5** — Labels are always supplied by the parent (escaped with `{{ }}`); the component ships no default copy.

## Gherkin
```gherkin
Feature: A confirmation dialog can offer a second choice

  Scenario: A dialog without a second choice is unchanged
    Given an order administrator, on an order whose status change needs confirmation
    When the order administrator opens the confirmation
    Then the dialog offers only to confirm or to go back, laid out as before

  Scenario: A dialog with a second choice shows three explicitly labelled options
    Given an order administrator is asked to confirm an action that offers a second choice
    When the confirmation dialog is shown
    Then it offers the main choice, the second choice and going back, each with its own label
```

## Files to create/modify
- `resources/views/components/confirm-dialog.blade.php` — two optional props, secondary button, conditional stacked layout (D-4),
  header comment updated (hooks list).
- `tests/Feature/Components/ConfirmDialogTest.php` — new (Blade render tests).

## Tests to perform
- [ ] Render without the new props: same hooks, same container classes (`flex gap-3 justify-end`), no `-secondary` hook, no stacked-layout classes; the unchanged consumers are covered by the regression list below.
- [ ] Render with both props: `-secondary` hook and label render; stacked-layout classes present.
- [ ] Render with only one of the two props: no secondary button, two-button markup.
- [ ] A label containing HTML is escaped.
- [ ] `wire:loading.attr="disabled"` / `wire:target` attributes present on both action buttons when the secondary renders.
- [ ] Regression (unchanged and green): `ShowStatusTest`, `ShowCancellationTest`, `ShowMarkAsPaidTest`, `IndexMarkAsPaidTest`, `tests/Browser/Orders/OrderStatusTransitionTest.php`, `tests/Browser/Orders/OrderCancellationTest.php`, `tests/Browser/Orders/MarkAsPaidTest.php`.

## Expected outcome
Any screen can ask a three-way question with the shared dialog; every existing two-way dialog is unaffected.

## Acceptance criteria
- [ ] Optional props, defaults `null`; all four existing consumers render unchanged.
- [ ] The secondary button and stacked layout appear only when both props are set.
- [ ] Hooks documented; labels escaped.

## Definition of Done
- [ ] Tests written and green (full suite, unscoped)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper): `docs/api/orders.md` "Shared pieces this story introduced" (component props/hooks), `ai-spec/tasks/_digests/epic-3.md`
- [ ] Acceptance criteria met

## INVEST note
Independent: no dependency. Negotiable: button order and variants. Valuable: unblocks every three-way question with zero regression
for existing dialogs. Estimable/Small: one Blade component. Testable: render tests only; interactive behaviour is tested through
0094.

## Dependencies, risks, open technical questions
**Depends on:** nothing pending. **Blocks:** [0094](../0094-order-line-quantity-stock-dialog-ui.md),
[0094a](../0094a-order-line-removal-stock-dialog-ui.md), [0095](../0095-order-cancellation-stock-dialog-ui.md).
- **R-1** — Flux modal + Blaze markup rules (hooks on inner content, single dismiss control in the DOM) must hold for the new button
  ([Livewire and Flux conventions](../../../docs/conventions/base-standards/livewire-and-flux-conventions.md)).

## Debate record
- 2026-11-08 — Three Amigos facilitated by product-owner from the frontend-expert design.
- 2026-11-08 — Phase 2 review applied: mark-as-paid consumers added to regression, stacked layout only with the secondary action,
  scope limited to render-testable behaviour (interactive checks moved to 0094), ghost-precondition scenario and "decide in Phase
  3" line removed.

## Approval record
- Phase 1: complete 2026-11-08 (product-owner).
- Phase 2: INVEST review 2026-11-08: findings received → rewritten, pending re-review.
- Phase 2: INVEST re-review 2026-11-08: scenario 2 rewritten without reference to the quantity screen → rewritten, pending re-review.
- Phase 2: INVEST review 2026-11-10 (code-reviewer): **APPROVED**; two minor findings applied (the "byte-for-byte" wording replaced by "renders as before" in D-4 and the first test line; this approval record added). Process note: the review ran after the Phase 3 code and tests were committed (`eba0bbc`, `6464bcf`) because the story was started before its Phase 2 approval; the review checked the committed work against the story and found no scope drift.
- Phase 3: complete 2026-11-10 — `ConfirmDialogTest` (6 cases) green; regression `ShowStatusTest`, `ShowCancellationTest`, `ShowMarkAsPaidTest`, `IndexMarkAsPaidTest` green (168 tests).
- Phase 4: security audit 2026-11-10 (appsec-auditor): **APPROVED, no findings**. Advice for the consumers 0094, 0094a and 0095: `secondary-action` must be a literal method name in the parent view (never bound from a variable), and each secondary method must re-authorize, re-check the dialog-open flag, re-load its target from server state and be idempotent, per `docs/security/livewire-authorization.md`.
- Phase 5: final code review 2026-11-10 (code-reviewer): **APPROVED**; acceptance criteria and DoD checked, two non-blocking notes (inline `//` header comment in the test follows the sibling pattern; no test asserts that two-button buttons carry no stray `class` attribute, accepted under "renders as before"). Full suite unscoped, run in chunks on two test databases: Unit + Feature/Orders…Console 2804 passed; Feature/Customers…ProductCategories 1768 passed (5 skipped); Feature/Products…Users 1832 passed; Browser 276 passed (3 skipped, run alone). Pint unscoped passes; Larastan passes (needs `--memory-limit`: the default 128M crashes the parallel workers).
- Phase 6: documentation 2026-11-10 — `docs/api/orders.md` (props, hooks, last-updated line) and `ai-spec/tasks/_digests/epic-3.md`.
- Phase 7: closed 2026-11-10 — moved to `done/`, link-integrity check in both directions, `tasks-map.md` and `tasks-status.json` regenerated; PR against `main`.
