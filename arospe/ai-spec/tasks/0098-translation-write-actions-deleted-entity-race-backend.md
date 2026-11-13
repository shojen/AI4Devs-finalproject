# [0098] Product translation write actions refuse a deleted product or category cleanly (backend)

## Description

`App\Actions\Products\SetProductTranslation` (story
[0076b](done/0076b-product-translation-write-action-backend.md)) and
`App\Actions\ProductCategories\SetProductCategoryTranslation` (story
[0071](done/0071-product-categories-language-tabs-ui.md)) write a translation row whose
`product_id` / `product_category_id` foreign key points at the parent. Both parents are **hard
deleted** (`Product` has no `SoftDeletes`, 0024 D-12; `ProductCategory` likewise), and both translation
tables declare that FK `cascadeOnDelete()`.

When the parent row is deleted after the action last saw it and before the translation `INSERT`, MySQL
raises **1452** (`ER_NO_REFERENCED_ROW_2`) and the request ends in an unhandled `QueryException`: a
**500**. This is 0076b's Phase 4 accepted residual **L-2** ("product deleted between re-read and save
gives an FK 1452 500, same as `SetProductCategoryTranslation`"). Story
[0087](done/0087-translation-write-actions-refuse-inactive-store-language.md) (decision
**D-13**, user decision Q-4 (a), 2026-11-13) kept it out of its scope and the user asked for this
separate task.

The two actions differ today:

| Action | Parent re-read after authorize? | Parent deleted **before** the call | Parent deleted **between** read and write |
|---|---|---|---|
| `SetProductTranslation` | yes, `Product::query()->whereKey(...)->firstOrFail()` | clean `ModelNotFoundException` (404) | 1452 → 500 |
| `SetProductCategoryTranslation` | **no** (0087 D-4 adds none) | 1452 → 500 (a stale passed instance) | 1452 → 500 |

The sibling blog actions already treat "the record is gone" as a clean 404 (`SetBlogPostTranslation`:
"a post deleted meanwhile is a clean 404"; `SetBlogTagTranslation` re-reads the tag with
`firstOrFail()`). This task brings the two product-side actions to a clean refusal on both windows.

## Type

backend | includes database-expert: no (no schema change; the FKs and their names stay as they are)

## PRD coverage

Epic 5 — Internationalization ([epic-5-internationalization.md](../../docs/PRD/sections/epic-5-internationalization.md)).
Robustness hardening of a residual accepted at 0076b's Phase 4; no PRD sentence demands it directly.

## Three Amigos participants

- `product-owner` — facilitator, composed this file (2026-11-13).
- `backend-expert` — Amigo 1, to confirm the mechanism (**Q-2**) in the debate.
- `backend-qa` — Amigo 2, to confirm the failure-injection technique (R-1).

Phase 1 debate not yet run with the two amigos: this file is the `product-owner` draft created at the
user's request. **Q-1–Q-3 must be answered before Phase 2.**

## 1. Refined user story

**As** a catalog administrator,
**I want** a translation save on a product or product category that another administrator has just
deleted to be refused as "not found",
**so that** I see a clear outcome instead of a server error, and no orphan translation row is written.

## 2. Gherkin — detailed acceptance criteria

The refusal outcome below is the **recommended** option of **Q-1** (a `ModelNotFoundException`, a 404,
like the blog siblings). If the user picks another option the "Then" lines change accordingly.

```gherkin
Feature: Product translation write actions refuse a deleted parent cleanly

  Background:
    Given the store has a default store language "en"
    And an active non-default store language "es"

  Scenario Outline: A parent deleted before the call is refused as not found
    Given a catalog administrator allowed to edit <record>s
    And a <record> the catalog administrator loaded earlier
    And that <record> has since been deleted
    When the catalog administrator saves a translation of that <record> in "es" through <action>
    Then the write is refused as not found
    And it is not refused with a server error
    And no translation row exists for that <record>

    Examples:
      | action                         | record           |
      | SetProductTranslation          | product          |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: A parent deleted between the action's read and its write is refused as not found
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And another administrator deletes that <record> after the action has read it and before the translation is written
    When the catalog administrator saves a translation of that <record> in "es" through <action>
    Then the write is refused as not found
    And it is not refused with a server error
    And no translation row exists for that <record>

    Examples:
      | action                         | record           |
      | SetProductTranslation          | product          |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: Authorization is still checked first
    Given a staff member without permission to edit <record>s
    And a <record> that has been deleted
    When the staff member tries to save a translation of that <record> in "es" through <action>
    Then the attempt is refused as unauthorized, not as not found
    And the refusal is logged exactly once

    Examples:
      | action                         | record           |
      | SetProductTranslation          | product          |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: Other database errors are not disguised as not found
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And the translation write fails with a database error other than a missing <record>
    When the catalog administrator saves a translation of that <record> in "es" through <action>
    Then the original database error is raised unchanged

    Examples:
      | action                         | record           |
      | SetProductTranslation          | product          |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: A save on an existing parent is unchanged
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    When the catalog administrator saves a translation of that <record> in "es" through <action>
    Then the <record>'s translation in "es" is stored

    Examples:
      | action                         | record           |
      | SetProductTranslation          | product          |
      | SetProductCategoryTranslation  | product category |
```

## Files to create/modify

### Modify

- `app/Actions/ProductCategories/SetProductCategoryTranslation.php` — after the (logged) authorize
  step, re-read the category with `ProductCategory::query()->whereKey($productCategory->getKey())->firstOrFail()`
  and use the re-read instance downstream ([model-instance-trust.md](../../docs/security/model-instance-trust.md)).
  Order after 0087: authorize → **category re-read** → active language (0087's helper) → validate →
  write. Plus the race handling chosen in **Q-2**.
- `app/Actions/Products/SetProductTranslation.php` — the race handling chosen in **Q-2** (its re-read
  already exists).
- `tests/Feature/Products/SetProductTranslationTest.php`,
  `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` — §3's cases.
- Under **Q-2 (a)**, possibly `app/Actions/Products/TranslateProductUniqueViolation.php` /
  `app/Actions/ProductCategories/TranslateProductCategoryNameUniqueViolation.php` if the implementer
  places the 1452 discrimination there instead of in the action; both currently rethrow every
  non-1062 error unchanged, and that must stay true for every error other than the parent FK 1452.

### Deliberately not touched

- The schema and the FK names. The Livewire components (`ProductCategories\Index`,
  `Products\Editor`): a `ModelNotFoundException` propagates as Livewire's standard 404 handling,
  the same outcome a deleted blog post or tag already gives.
- The blog actions (scope, **Q-3**).

## 3. QA test cases / validation scenarios

Feature tests, direct action calls resolved from the container, `RefreshDatabase`.

| # | Case | Where | Notes |
|---|------|-------|-------|
| 1 | Parent deleted before the call (stale passed instance) → `ModelNotFoundException`; no translation row | both files | for the product this is likely covered already — reuse |
| 2 | Parent deleted between read and write → `ModelNotFoundException`, not `QueryException`; no translation row | both files | **highest value**; inject the delete with a translation-model `creating`/`saving` listener that deletes the parent through a raw query (the 0078 errors-log technique, as 0076b R-1) |
| 3 | Unauthorized + deleted → `AuthorizationException`, logged once | both files | proves order |
| 4 | A 1452 on `store_language_id`, a 1062 on a non-handled index and a non-integrity error are rethrown unchanged | both files | under Q-2 (a) the discrimination is on the **constraint name in `errorInfo[2]`**, never on `getMessage()` (the `TranslateProductCategoryNameUniqueViolation` docblock and the `CreateShippingRate` F-5 precedent) |
| 5 | Existing parent still writable; existing 1062 mappings unchanged | both files | regression, likely already covered |

## Expected outcome

Saving a translation of a product or product category that no longer exists — whether it was deleted
before the call or during it — ends in a clean "not found" refusal (per Q-1) and never in a 500 or an
orphan row; every other database error behaves exactly as before.

## Acceptance criteria

- [ ] `SetProductCategoryTranslation` re-reads the category after authorize and uses the re-read instance.
- [ ] Both actions refuse a parent deleted before the call, and a parent deleted between read and write, with the Q-1 outcome; no translation row is written.
- [ ] The race handling follows the Q-2 choice; under (a) it matches only the parent FK's 1452 by constraint name in `errorInfo[2]`.
- [ ] Every other `QueryException` is rethrown unchanged (case 4).
- [ ] Authorization still runs first (case 3).
- [ ] 0087's order (authorize → re-read → active language → validate → write) is preserved and its structural guard still passes.

## Definition of Done

- [ ] Q-1–Q-3 answered and the Phase 1 debate completed with `backend-expert` and `backend-qa`.
- [ ] Tests written and green (iteration filter, then the **full** suite unscoped).
- [ ] Pint unscoped and Larastan level 7 clean.
- [ ] Code reviewed (`code-reviewer`).
- [ ] No security findings (`appsec-auditor`).
- [ ] Documentation updated (`docs-keeper`): the product/product-category translation write action
  docs and `docs/security/livewire-authorization/action-level-authorization.md` record the clean
  refusal; the Epic 5 digest's L-2 line (~118) is marked resolved by this task.
- [ ] Acceptance criteria met.

## 4. Documented functional decisions

- **D-1 Scope is the two product-side actions** named by the user (pending confirmation in Q-3).
- **D-2 Not a schema change.** The FKs keep `cascadeOnDelete()`; the fix is in the actions.

## 5. Dependencies, risks, open questions

### Dependencies

- **Hard: [0087](done/0087-translation-write-actions-refuse-inactive-store-language.md).** Both
  tasks edit `SetProductTranslation.php`, `SetProductCategoryTranslation.php` and their two test files;
  this one is numbered after it and builds on its order (authorize → re-read → active language).
  **Satisfied:** 0087 closed to `done/` on 2026-11-13, so this task has no pending dependency and is
  `ready` (pending its own Q-1–Q-3 and Phase 1 debate); the `conflict_risk_with` pairing was dropped
  with it.

### Risks

- **R-1** The race test needs failure injection; follow the 0078 errors-log technique (a model hook),
  not a mock of `SetTranslation`.
- **R-2** A translation row loaded before the parent's cascade delete is then `UPDATE`d: zero rows
  affected, no error, and the action returns a model that no longer exists. Under Q-2 (a) this window
  stays open; Q-2 (b) closes it. To be weighed in the debate.
- **R-3** Matching on the error message instead of the constraint name misclassifies errors (the
  message embeds the user's text). Mitigated by case 4.

### Open questions for the user

- **Q-1 — What should the user get when the parent is gone?**
  - **(a) (recommended)** A `ModelNotFoundException` (Livewire's standard 404), exactly what the blog
    post and blog tag actions already give: one consistent "this record no longer exists" outcome.
  - (b) A `ValidationException` on `names.{id}` with a new "this product was deleted" message, which
    keeps the user on the form (but on a record that no longer exists).
- **Q-2 — How to close the read-to-write window?**
  - **(a) (recommended)** Catch the `QueryException`, match **only** 1452 on the parent FK constraint
    (`product_translations_product_id_foreign` / `product_category_translations_product_category_id_foreign`)
    in `errorInfo[2]`, and throw the Q-1 outcome; everything else is rethrown. Small, no locking, and
    it follows the shipped `CreateShippingRate` 1452 precedent. Leaves R-2 open.
  - (b) Wrap read and write in a transaction with `lockForUpdate()` on the parent row, so a delete
    waits. Closes R-2 too, but adds a lock to every translation save and a deadlock surface with the
    delete actions' own locks.
- **Q-3 — Scope.** `SetBlogCategoryTranslation` has the same missing re-read, and `SetBlogTagTranslation`
  the same read-to-write window (blog posts are soft-deleted, so `SetBlogPostTranslation` is not exposed
  to 1452).
  - **(a) (recommended)** Keep the two product-side actions, as the user asked; open a sibling task for
    the blog ones if wanted.
  - (b) Include `SetBlogCategoryTranslation` and `SetBlogTagTranslation` here.

## Provenance

0076b Phase 4 (2026-11-10) accepted L-2 and pointed it at "the shared helper backlogged by 0087 D-5".
0087's Phase 2 re-review (2026-11-13) kept it out of that helper's scope; the user chose Q-4 (a) and
asked for this task. Created by `product-owner` on 2026-11-13; Phase 1 debate and Phase 2 not run.
