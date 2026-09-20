# [0053a] Order totals — `tax_rate` is a percentage: fix `RecalculateOrderTotals` and share one tax computation

> **Follow-up to [0053](done/0053-order-tax-region-resolution-physical-backend.md)**, found while
> implementing it (its **D-13** warns about exactly this) and recorded in PR #20. It corrects a defect
> **shipped by [0048](done/0048-order-line-item-editing-backend.md)** and removes the second copy of the
> tax formula that 0053 was forced to introduce.

## Description

`orders.tax_rate` is a **percentage** (`21.000` means 21%), mirroring `sales_regions.rate`
([schema](../../docs/database/schema-products.md#sales_regions)). 0053's `ResolveOrderTaxRegion`
derives `tax_amount = subtotal × (tax_rate ÷ 100)`. `App\Actions\Orders\RecalculateOrderTotals` —
run by `AddOrderItem`, `RemoveOrderItem` and `UpdateOrderItemQuantity` — computes
`bcmul($subtotal, $tax_rate, 2)` with **no `÷ 100`**, following 0048's own **D-8** shorthand
(`subtotal × tax_rate`), which is dimensionally wrong read literally.

Consequence: resolve an order at 21% (`tax_amount 21.00` on a `100.00` subtotal), then add one line —
the recalculation multiplies by `21.000` instead of `0.21` and writes a tax **100× too large**, or
throws the column-ceiling `ValidationException` on a modest order. It surfaces the moment story
[0055](0055-orders-list-detail-editor-ui.md) wires resolution to the UI; **today nothing invokes
`ResolveOrderTaxRegion`, so no order carries a non-null `tax_rate` and no stored data is wrong.**

Why nobody saw it: the shipped tests use `tax_rate = '0.210'` and assert
`bcmul($subtotal, '0.210', 2)` — the same formula as the implementation, on a rate that is only
correct if read as a fraction. They restate the code instead of pinning a number.

This story is a bug fix plus one extraction: `RecalculateOrderTotals` gets the `÷ 100`, and the
formula lives in **one** collaborator both it and `ResolveOrderTaxRegion` compose — the extraction
0053's **D-13** and [0054](0054-order-tax-region-resolution-virtual-backend.md)'s step 5 named as
backlog item 1 and risk **R-7** ("the three copies of the tax arithmetic drift"). Already visible drift:
`ResolveOrderTaxRegion` rounds half-up, `RecalculateOrderTotals` truncates.

No route, component, view, migration, permission or lang key.

## Type
backend | includes database-expert: no

## Gherkin

```gherkin
Feature: Order tax stays correct when line items change after the region is resolved

  Scenario: Adding a line to a resolved order keeps tax at the region's percentage
    Given an order administrator, with an order resolved at 21.000 whose subtotal is 100.00
    When a line item worth 100.00 is added to the order
    Then the order records a tax amount of 42.00 and a total of 242.00

  Scenario: Removing a line from a resolved order keeps tax at the region's percentage
    Given an order administrator, with an order resolved at 21.000 holding two lines of 100.00
    When one of the lines is removed
    Then the order records a tax amount of 21.00 and a total of 121.00

  Scenario: Changing a quantity on a resolved order keeps tax at the region's percentage
    Given an order administrator, with an order resolved at 21.000 holding one line of 50.00
    When that line's quantity is changed to 2
    Then the order records a tax amount of 21.00 and a total of 121.00

  Scenario: Resolving and recalculating never disagree about the same subtotal
    Given an order administrator, with a subtotal of 10.79 and a rate of 7.000
    When the tax is computed by resolution and by recalculation
    Then both record the same tax amount

  Scenario: An order with no configured rate still invents no tax
    Given an order administrator, with an order whose tax rate has never been configured
    When a line item is added
    Then the order records a tax amount of 0.00

  Scenario: A rate of exactly zero is a real rate
    Given an order administrator, with an order resolved at 0.000
    When a line item is added
    Then the order records a tax amount of 0.00 and a total equal to its subtotal
```

## Files to create/modify

### `app/Actions/Orders/CalculateTaxAmount.php` — **create**

Invokable, container-resolved, no `Action`/`Service` suffix, no authorization (a pure computation
collaborator, like `ToNumericString`). Extracted **verbatim** from the private
`ResolveOrderTaxRegion::percentageOf()`:

```php
/** @param numeric-string $subtotal  @param numeric-string|null $taxRate  @return numeric-string */
public function __invoke(string $subtotal, ?string $taxRate): string
```

- `null` rate ⇒ `'0.00'` (**not configured** — no tax invented); `'0.000'` ⇒ `'0.00'` computed, not
  short-circuited (the two stay distinguishable by `tax_rate`, never by the amount).
- Otherwise `bcadd(bcdiv(bcmul($subtotal, $taxRate, 5), '100', 7), '0.005', 2)` — exact product, `÷ 100`,
  rounded **half-up** to two decimals.

### `app/Actions/Orders/RecalculateOrderTotals.php` — **modify**

Replace the inline `bcmul(...)` with the collaborator (constructor-injected). Correct the docblock's
`subtotal x tax_rate` wording to the percentage form, and its F-1 ceiling note (which reasoned from
`tax_rate <= 1`): `tax_amount` is still not checked in isolation because `total` (checked) is
`subtotal + tax_amount + shipping_amount` and every term is non-negative, so a `tax_amount` above the
ceiling implies a `total` above it.

### `app/Actions/Orders/ResolveOrderTaxRegion.php` — **modify**

Delete `percentageOf()`; call the collaborator. **Behaviour must not change** — 0053's 42 tests pass
untouched.

### Tests

- `tests/Feature/Orders/AddOrderItemTest.php`, `RemoveOrderItemTest.php`,
  `UpdateOrderItemQuantityTest.php` — the "recomputes `tax_amount` … when a rate is already resolved"
  test in each. Change the fixture rate `'0.210'` → `'21.000'` and **replace the re-derived expectation
  with a literal** computed by hand (an order the test builds with a known `subtotal`), so the assertion
  no longer restates the implementation. The two `'0.100'` "never writes `tax_rate`" fixtures become
  `'10.000'` (value irrelevant to the assertion, kept realistic).
- `tests/Feature/Orders/CalculateTaxAmountTest.php` — **create**, unit-style: `('100.00','21.000')→'21.00'`,
  `('10.79','7.000')→'0.76'` and `('10.75','7.000')→'0.75'` (half-up), `('100.00','0.000')→'0.00'`,
  `('100.00', null)→'0.00'`, a fractional rate `('100.00','7.500')→'7.50'`.
- **Parity test** (new, in the same file): for a table of `(subtotal, rate)` pairs, an order resolved
  through `ResolveOrderTaxRegion` and an order recalculated through `RecalculateOrderTotals` end with the
  identical `tax_amount` and `total`. This is the test R-7 asked for.
- **End-to-end regression:** resolve a `100.00` order at 21%, then `AddOrderItem` a `100.00` line —
  expect `tax_amount '42.00'`, `total '242.00'`. Fails against today's code.

All money and rates asserted as decimal **strings** (never floats).

### Documentation

- `docs/database/schema-orders.md` (`tax_amount` row and the "Totals are derived and re-derived"
  section): state the percentage formula once and that both actions share `CalculateTaxAmount`.
- `docs/conventions/directory-structure.md`: list `CalculateTaxAmount` beside `RecalculateOrderTotals`.
- 0048's done file states `subtotal × tax_rate` in three places (D-8, its AC, its outcome). Add a dated
  **correction note** under D-8 pointing here rather than rewriting history.
- `docs/errors-log.md`: an entry — a formula restated in its own tests cannot catch a units error.
- Grep for any other prose that says `subtotal × tax_rate` without `÷ 100`.

### Explicitly **not** touched

- `CreateOrder` (writes `tax_amount '0.00'` — no rate exists yet).
- `orders.tax_rate` semantics, the schema, any migration, any permission, any route or view.
- `subtotal` re-summing and `shipping_amount` handling in `RecalculateOrderTotals` (0048's, unchanged).
- Region resolution, the flag, and 0054's virtual resolver.

## Expected outcome

One tax formula exists, in `CalculateTaxAmount`. Resolving an order and later editing its lines produce
the same tax for the same subtotal and rate, at the same rounding. A resolved order edited at 21%
never shows a tax 100× too large. 0054, when built, composes the same collaborator instead of adding a
third copy.

## Acceptance criteria

- [ ] `App\Actions\Orders\CalculateTaxAmount` exists and is the **only** place `tax_rate ÷ 100`
      arithmetic lives; `RecalculateOrderTotals` and `ResolveOrderTaxRegion` both call it.
- [ ] Editing a resolved 21% order's lines recomputes `tax_amount = subtotal × 0.21`, half-up to two
      decimals; the end-to-end regression (`100.00` + `100.00` → `42.00` / `242.00`) is green.
- [ ] `null` rate ⇒ `tax_amount '0.00'`; `'0.000'` ⇒ `'0.00'` and stays distinguishable by `tax_rate`.
- [ ] Recalculation and resolution agree for every pair in the parity table.
- [ ] The three 0048 tests assert **literal** amounts, not a re-derived formula.
- [ ] 0053's `ResolveOrderTaxRegionTest` passes **unmodified**.
- [ ] `RecalculateOrderTotals`'s docblock no longer says `subtotal x tax_rate` and its ceiling note no
      longer reasons from `tax_rate <= 1`.
- [ ] No migration, route, permission, view or lang key added; no stored data needs correcting.

## Definition of Done

- [ ] Tests written and green, plus the full existing suite run **unscoped**.
- [ ] `vendor/bin/pint --format agent` clean (unscoped) and Larastan level 7 passing.
- [ ] Code reviewed (code-reviewer); appsec-auditor: no new write path, only the arithmetic changes.
- [ ] Documentation updated (docs-keeper) per the list above, plus the grep for the wrong formula.
- [ ] [0054](0054-order-tax-region-resolution-virtual-backend.md)'s step 5 is amended to compose
      `CalculateTaxAmount`; [0055](0055-orders-list-detail-editor-ui.md) lists this story as a hard
      dependency.

## Documented functional decisions

- **D-1 — `tax_rate` is a percentage everywhere.** Matches `sales_regions.rate` and 0053 **D-13**. The
  alternative (store a fraction on `orders`) would break the column-for-column snapshot and every
  displayed `21.000%`; rejected. Reversible only by a data migration, so it is fixed here.
- **D-2 — Extract now, not later.** 0053 named the extraction as a backlog item with the trigger "a
  fourth call site or the first divergence". The divergence already exists (truncate vs half-up) and
  the fourth call site is 0054, so both triggers have fired. Cost is one small class.
- **D-3 — Round half-up, for both.** Resolution already rounds half-up; adopting it in recalculation
  changes only sub-cent cases (`10.79 @ 7%`: `0.75` → `0.76`). Reversible: change one line in one class.
- **D-4 — No data correction.** Only `ResolveOrderTaxRegion` writes a non-null `tax_rate`, and nothing
  calls it yet, so no stored `tax_amount` was computed by the wrong formula. **Verify this before
  Phase 3** (query for `orders.tax_rate IS NOT NULL`); if any exist, that is a new decision, not a
  silent backfill.
- **D-5 — Not folded into 0053.** 0053's own scope fence forbids editing other stories' shipped
  actions, and its PR had already merged; a separate reviewable fix is cleaner.

## Scope fences: what this story must NOT do

- Must **not** change the formula's meaning (percentage, half-up) beyond what D-1/D-3 state.
- Must **not** resolve a Sales Region, write `tax_rate`, or set `flagged_for_review`.
- Must **not** touch `CreateOrder`, 0054's action (it does not exist yet — amend its *spec* only), or
  any UI.

## Dependencies, risks and open questions

| Depends on | State |
| --- | --- |
| `RecalculateOrderTotals`, `AddOrderItem`, `RemoveOrderItem`, `UpdateOrderItemQuantity` | 0048 — done |
| `ResolveOrderTaxRegion` | 0053 — done |

**Blocks:** 0055 (must not ship a UI that resolves and then edits before this lands) and 0054's
implementation (should compose the collaborator from the start).

- **R-1 — A half-up change to recalculation alters existing 0048 expectations.** Only sub-cent cases;
  the 0048 tests use whole-number subtotals at fractional rates today. Confirm in Phase 3.
- **R-2 — Tests restating the formula reappear.** Mitigated by the literal-value acceptance criterion.
- **OQ-1 (non-blocking):** should `tax_amount` be checked against the column ceiling in isolation once
  rates above 100% are representable (`decimal(6,3)` allows 999.999)? Argued unnecessary above (the
  checked `total` bounds it); recorded so a reviewer can overturn it.

## Provenance

- **Source:** [0053](done/0053-order-tax-region-resolution-physical-backend.md) **D-13**'s warning and
  **R-7**, PR #20's "Qué impacto tiene" note; the defect in `app/Actions/Orders/RecalculateOrderTotals.php`
  and its three test files, read on 2026-09-20.
- **Stage:** `new`. Moves to `ai-spec/tasks/in-progress/` at Phase 3 and `done/` at Phase 7; re-resolve
  every relative link on each move ([workflow.md](../../docs/workflow.md#link-integrity-check-on-every-stage-move)).
- **Gherkin:** named business-role actor and exactly one `When` per scenario, per
  [gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md).
