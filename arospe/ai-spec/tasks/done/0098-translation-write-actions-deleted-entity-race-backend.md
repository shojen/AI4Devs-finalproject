# [0098] Product translation write actions refuse a deleted product or category cleanly (backend)

## Description

`App\Actions\Products\SetProductTranslation` (story
[0076b](0076b-product-translation-write-action-backend.md)) and
`App\Actions\ProductCategories\SetProductCategoryTranslation` (story
[0071](0071-product-categories-language-tabs-ui.md)) write a translation row whose
`product_id` / `product_category_id` foreign key points at the parent. Both parents are **hard
deleted** (`Product` has no `SoftDeletes`, 0024 D-12; `ProductCategory` likewise), and both translation
tables declare that FK `cascadeOnDelete()`.

When the parent row is deleted after the action last saw it and before the translation `INSERT`, MySQL
raises **1452** (`ER_NO_REFERENCED_ROW_2`) and the request ends in an unhandled `QueryException`: a
**500**. This is 0076b's Phase 4 accepted residual **L-2** ("product deleted between re-read and save
gives an FK 1452 500, same as `SetProductCategoryTranslation`"). Story
[0087](0087-translation-write-actions-refuse-inactive-store-language.md) (decision
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

Epic 5 — Internationalization ([epic-5-internationalization.md](../../../docs/PRD/sections/epic-5-internationalization.md)).
Robustness hardening of a residual accepted at 0076b's Phase 4; no PRD sentence demands it directly.

## Three Amigos participants

- `product-owner` — facilitator, composed this file (2026-11-13) and folded in the debate (2026-11-14).
- `backend-expert` — Amigo 1, confirmed the mechanism (**Q-2 (a)**) and its placement.
- `backend-qa` — Amigo 2, confirmed the failure-injection technique (R-1) and the test matrix.

Q-1–Q-3 were answered by the user on 2026-11-14 (Q-1 (a), Q-2 (a), Q-3 (a); see §5). The Phase 1
debate ran on 2026-11-14; its outcome is below and the body is amended accordingly.

## Phase 1 debate outcome

Debate with `backend-expert` and `backend-qa`, 2026-11-14. Agreed points (binding for Phase 3):

- **P-1 Placement.** The 1452 handling lives in **each action**, inside the existing
  `catch (QueryException $exception)` around the `SetTranslation` call, and is checked **before**
  delegating to the `Translate*UniqueViolation` helper. 1452 and 1062 are disjoint on `errorInfo[1]`,
  so the order cannot shadow the existing 1062 mapping. **Rejected:** placing it in
  `TranslateProductUniqueViolation` / `TranslateProductCategoryNameUniqueViolation` —
  `TranslateProductUniqueViolation` is shared, and both helpers' contract is "throw a
  `ValidationException` or rethrow unchanged"; a `ModelNotFoundException` does not belong there.
- **P-2 Matching.** Match only when `errorInfo[1] === 1452` **and**
  `preg_match('/CONSTRAINT `<name>` FOREIGN KEY/', errorInfo[2])` for the parent FK. Never match on
  `getMessage()` (it embeds user text, R-3) and never on the table name (the `store_language_id` FK
  error names the same translation table). Constraint names verified against the migrations
  (Laravel defaults, no later rename): `product_translations_product_id_foreign` and
  `product_category_translations_product_category_id_foreign`. On a match throw
  `(new ModelNotFoundException)->setModel(Product::class, [$key])` /
  `(new ModelNotFoundException)->setModel(ProductCategory::class, [$key])`. Both `handle()` methods
  gain a `@throws ModelNotFoundException` docblock line.
- **P-3 Category action order.** authorize (logged) → `ProductCategory::query()->whereKey(...)->firstOrFail()`
  (**no** `->active()` scope or call: `tests/Unit/ArchitectureTest.php` ~238–260 forbids an inline
  `active()` re-read and requires `ResolveActiveStoreLanguage` in the constructor) → 0087's
  active-language helper → validate → write. The re-read instance is used for `nameRules` and for
  `setTranslation`.
- **P-4 Tests.** See §3 (revised). Failure injection uses a `ProductTranslation::creating` /
  `ProductCategoryTranslation::creating` listener that raw-deletes the parent via
  `DB::table(...)->where('id', ...)->delete()`, on the non-default language so `creating` fires once;
  `FOREIGN_KEY_CHECKS` is never disabled.
- **P-5 R-2 stays open and untested** (user chose Q-2 (a)); see §5.
- **P-6 Callers.** The implementer verifies that `Products\Editor` and `ProductCategories\Index` do not
  wrap the action call in a `try`/`catch` that would swallow or remap a `ModelNotFoundException`
  (they are not modified; this is a read-only check recorded in Phase 3).

## 1. Refined user story

**As** a catalog administrator,
**I want** a translation save on a product or product category that another administrator has just
deleted to be refused as "not found",
**so that** I see a clear outcome instead of a server error, and no orphan translation row is written.

## 2. Gherkin — detailed acceptance criteria

The refusal outcome below is **Q-1 (a)**, chosen by the user on 2026-11-14 (a `ModelNotFoundException`,
a 404, like the blog siblings).

R-2 (an existing translation row `UPDATE`d after the parent's cascade delete) is **deliberately not
covered** by any scenario: under Q-2 (a) that window stays open by the user's choice (§5).

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
    Then the write is refused as not found for that <record>
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
    Then the write is refused as not found for that <record>
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

  Scenario Outline: A deleted parent is refused as not found before the language is checked
    Given a catalog administrator allowed to edit <record>s
    And a <record> that has been deleted
    And an inactive store language "fr"
    When the catalog administrator saves a translation of that <record> in "fr" through <action>
    Then the write is refused as not found for that <record>
    And it is not refused as a validation error

    Examples:
      | action                         | record           |
      | SetProductTranslation          | product          |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: Other database errors are not disguised as not found
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And the translation write fails with <error>
    When the catalog administrator saves a translation of that <record> in "es" through <action>
    Then the original database error is raised unchanged

    Examples:
      | action                         | record           | error                                                                        |
      | SetProductTranslation          | product          | a missing-reference error on the store language foreign key                  |
      | SetProductTranslation          | product          | a duplicate-entry error on an index the action does not handle               |
      | SetProductTranslation          | product          | an error whose message names the product foreign key but whose constraint does not |
      | SetProductCategoryTranslation  | product category | a missing-reference error on the store language foreign key                  |
      | SetProductCategoryTranslation  | product category | a duplicate-entry error on an index the action does not handle               |
      | SetProductCategoryTranslation  | product category | an error whose message names the category foreign key but whose constraint does not |

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
  (no `active()` call, P-3) and use the re-read instance for `nameRules` and `setTranslation`
  ([model-instance-trust.md](../../../docs/security/model-instance-trust.md)). Order: authorize →
  **category re-read** → active language (0087's helper) → validate → write. Inside the existing
  `catch (QueryException)`, before the `TranslateProductCategoryNameUniqueViolation` delegation, map the
  parent-FK 1452 to `ModelNotFoundException` (P-1, P-2); add the `@throws` line.
- `app/Actions/Products/SetProductTranslation.php` — the same parent-FK 1452 mapping inside its
  existing `catch (QueryException)`, before the `TranslateProductUniqueViolation` delegation (its
  re-read already exists); add the `@throws` line.
- `tests/Feature/Products/SetProductTranslationTest.php`,
  `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` — §3's cases.

### Deliberately not touched

- `app/Actions/Products/TranslateProductUniqueViolation.php` /
  `app/Actions/ProductCategories/TranslateProductCategoryNameUniqueViolation.php` (**rejected** as the
  placement, P-1): they keep rethrowing every non-1062 error unchanged.
- The schema and the FK names. The Livewire components (`ProductCategories\Index`,
  `Products\Editor`): a `ModelNotFoundException` propagates as Livewire's standard 404 handling,
  the same outcome a deleted blog post or tag already gives (verified read-only, P-6).
- The blog actions (scope, **Q-3 (a)**): covered by sibling task
  [0099](../in-progress/0099-blog-translation-write-actions-deleted-entity-race-backend.md).

## 3. QA test cases / validation scenarios

Feature tests, direct action calls resolved from the container, `RefreshDatabase`. Every
`ModelNotFoundException` assertion also checks `getModel()` (`Product::class` / `ProductCategory::class`).

| # | Case | Where | Notes |
|---|------|-------|-------|
| 1 | Parent deleted before the call (stale passed instance) → `ModelNotFoundException`; zero rows in the translation table | both files | product: reuse the existing test (~line 449), add the zero-row and `getModel()` assertions; category: **new** |
| 2 | Parent deleted between read and write → `ModelNotFoundException`, not `QueryException`; zero rows in the translation table | both files | **new, highest value**; `ProductTranslation::creating` / `ProductCategoryTranslation::creating` listener raw-deletes the parent with `DB::table(...)->where('id', ...)->delete()`; write on the non-default language so `creating` fires once; never disable `FOREIGN_KEY_CHECKS` |
| 3 | Unauthorized + deleted → `AuthorizationException`, logged once | both files | **new**; proves order |
| 3b | Deleted parent + inactive language → `ModelNotFoundException`, not `ValidationException` | both files | **new**; proves re-read precedes the active-language check |
| 4a | 1452 on the `store_language_id` FK → same `QueryException` rethrown | both files | **new**; separate injection |
| 4b | 1062 on an index the action does not handle → same instance rethrown | both files | **new**; fake `SetTranslation` throwing a crafted `QueryException`, assert the same instance |
| 4c | Decoy: `getMessage()` contains the parent FK name, `errorInfo[2]` does not → rethrown unchanged | both files | **new**; R-3 guard |
| 5 | Existing parent still writable; existing 1062 mappings and 1213 handling unchanged | both files | regression; reuse the 1213 tests (~418 product, ~303 category) and the 1062 race tests |

R-2 is deliberately untested (§5).

## Expected outcome

Saving a translation of a product or product category that no longer exists — whether it was deleted
before the call or during it — ends in a clean "not found" refusal (per Q-1) and never in a 500 or an
orphan row; every other database error behaves exactly as before.

## Acceptance criteria

- [ ] `SetProductCategoryTranslation` re-reads the category after authorize (no inline `active()`) and uses the re-read instance for `nameRules` and `setTranslation`.
- [ ] Both actions refuse a parent deleted before the call, and a parent deleted between read and write, with a `ModelNotFoundException` whose `getModel()` is the parent class; no translation row is written.
- [ ] The 1452 mapping sits in each action's existing `catch (QueryException)`, before the `Translate*UniqueViolation` delegation, and matches only `errorInfo[1] === 1452` plus the parent FK constraint name in `errorInfo[2]`.
- [ ] Every other `QueryException` is rethrown unchanged (cases 4a–4c).
- [ ] Authorization still runs first (case 3); a deleted parent is refused before the active-language check (case 3b).
- [ ] 0087's order (authorize → re-read → active language → validate → write) is preserved and its structural guard in `tests/Unit/ArchitectureTest.php` still passes.

## Definition of Done

- [x] Q-1–Q-3 answered (user, 2026-11-14).
- [x] Phase 1 debate completed with `backend-expert` and `backend-qa` (2026-11-14).
- [ ] Tests written and green (iteration filter, then the **full** suite unscoped).
- [ ] Pint unscoped and Larastan level 7 clean.
- [ ] Code reviewed (`code-reviewer`).
- [ ] No security findings (`appsec-auditor`).
- [x] Documentation updated (`docs-keeper`): the product/product-category translation write action
  docs and `docs/security/livewire-authorization/action-level-authorization.md` record the clean
  refusal; the Epic 5 digest's L-2 line (~118) is marked resolved by this task.
- [ ] Acceptance criteria met.

## 4. Documented functional decisions

- **D-1 Scope is the two product-side actions** named by the user (confirmed by Q-3 (a), 2026-11-14).
- **D-2 Not a schema change.** The FKs keep `cascadeOnDelete()`; the fix is in the actions.
- **D-3 Placement and matching** per the Phase 1 debate (P-1, P-2): in each action, before the 1062
  helper, on `errorInfo[1]` plus the constraint name in `errorInfo[2]`.

## 5. Dependencies, risks, open questions

### Dependencies

- **Hard: [0087](0087-translation-write-actions-refuse-inactive-store-language.md).** Both
  tasks edit `SetProductTranslation.php`, `SetProductCategoryTranslation.php` and their two test files;
  this one is numbered after it and builds on its order (authorize → re-read → active language).
  **Satisfied:** 0087 closed to `done/` on 2026-11-13, so this task has no pending dependency and is
  `ready` (Phase 1 done 2026-11-14); the `conflict_risk_with` pairing was dropped with it.

### Risks

- **R-1** The race test needs failure injection; use the translation-model `creating` listener with a
  raw parent delete (P-4, the 0078 errors-log technique), not a mock of `SetTranslation`.
- **R-2** A translation row loaded before the parent's cascade delete is then `UPDATE`d: zero rows
  affected, no error, and the action returns a model that no longer exists. The user chose Q-2 (a)
  (2026-11-14), which leaves this window open; it is accepted and deliberately untested.
- **R-3** Matching on the error message instead of the constraint name misclassifies errors (the
  message embeds the user's text). Mitigated by P-2 and the decoy case 4c.

### Open questions for the user

All three **answered by the user on 2026-11-14**: Q-1 (a), Q-2 (a), Q-3 (a).

- **Q-1 — What should the user get when the parent is gone?** **Answered (a)** (user, 2026-11-14).
  - **(a) (recommended)** A `ModelNotFoundException` (Livewire's standard 404), exactly what the blog
    post and blog tag actions already give: one consistent "this record no longer exists" outcome.
  - (b) A `ValidationException` on `names.{id}` with a new "this product was deleted" message, which
    keeps the user on the form (but on a record that no longer exists).
- **Q-2 — How to close the read-to-write window?** **Answered (a)** (user, 2026-11-14).
  - **(a) (recommended)** Catch the `QueryException`, match **only** 1452 on the parent FK constraint
    (`product_translations_product_id_foreign` / `product_category_translations_product_category_id_foreign`)
    in `errorInfo[2]`, and throw the Q-1 outcome; everything else is rethrown. Small, no locking, and
    it follows the shipped `CreateShippingRate` 1452 precedent. Leaves R-2 open.
  - (b) Wrap read and write in a transaction with `lockForUpdate()` on the parent row, so a delete
    waits. Closes R-2 too, but adds a lock to every translation save and a deadlock surface with the
    delete actions' own locks.
- **Q-3 — Scope.** **Answered (a)** (user, 2026-11-14). `SetBlogCategoryTranslation` has the same missing re-read, and `SetBlogTagTranslation`
  the same read-to-write window (blog posts are soft-deleted, so `SetBlogPostTranslation` is not exposed
  to 1452).
  - **(a) (recommended)** Keep the two product-side actions, as the user asked; the blog ones go to
    sibling task [0099](../in-progress/0099-blog-translation-write-actions-deleted-entity-race-backend.md).
  - (b) Include `SetBlogCategoryTranslation` and `SetBlogTagTranslation` here.

## Provenance

0076b Phase 4 (2026-11-10) accepted L-2 and pointed it at "the shared helper backlogged by 0087 D-5".
0087's Phase 2 re-review (2026-11-13) kept it out of that helper's scope; the user chose Q-4 (a) and
asked for this task. Created by `product-owner` on 2026-11-13; Phase 1 debate run and folded in on
2026-11-14 (`backend-expert`, `backend-qa`); Phase 2 not run.

## Phase 2 — INVEST validation (`code-reviewer`, 2026-11-14)

**Verdict: APPROVED.**

- INVEST: Independent (0087 is in `done/`; 0099 split out), Negotiable (Q-1–Q-3 answered), Valuable
  (500 → clean 404, no orphan row), Estimable/Small (two actions, two test files, no schema change),
  Testable (§3 matrix maps every scenario).
- Gherkin: every scenario opens with a named business-role actor and has a single `When`.
- Template: all sections present (§3 stands in for "Tests to perform").
- Code claims verified against source: `SetProductTranslation` re-reads with `firstOrFail()` after
  authorize and catches `QueryException` into `TranslateProductUniqueViolation`;
  `SetProductCategoryTranslation` has no parent re-read (authorize → language → validate → write);
  `ProductCategory` and `Product` have no `SoftDeletes`; both FKs are default-named
  `constrained()->cascadeOnDelete()` with no later rename (the 2026-11-07 migration only touches the
  `store_language_id` index); `ArchitectureTest` forbids `StoreLanguage::query()->active()` and
  requires `ResolveActiveStoreLanguage` in the constructor (a `ProductCategory::query()` re-read does
  not trip it); `CreateShippingRate` 1452 precedent exists; both Livewire callers catch only
  `ValidationException`.
- Non-blocking note for Phase 3: P-2 says "both `handle()` methods"; the actions use `__invoke()`.
  Add the `@throws ModelNotFoundException` line to `__invoke()`'s docblock.

## Phase 3 — Implementation (TDD)

- 2026-11-14: Phase 3 started; task file moved from `ai-spec/tasks/` to `ai-spec/tasks/in-progress/` (claimed by `worktree-0098-translation-write-actions-deleted-entity-race-backend`).

_Last updated: 2026-11-14, Phase 3 started (task moved to in-progress); closed in Phase 7._

## Phase 4 — Security audit (`appsec-auditor`, 2026-11-14)

**Verdict: APPROVED.** Reviewed `git diff 13dde8e..HEAD -- app tests`.

1. Authorization order: both actions call `logRefusedPrivilegedAttempt->authorize()` (refusal logged) before the `firstOrFail()` re-read; both policies' `update()` are permission-only, so authorizing on the caller-supplied instance decides nothing from its attributes. An unauthorized actor gets 403, never a 404 existence oracle.
2. Model-instance trust: the re-read instance (not the passed one) feeds `nameRules`/slug rules and `setTranslation`, consistent with `docs/security/model-instance-trust.md`.
3. Leakage: the mapped `ModelNotFoundException` carries only the model class and the server-side UUID the admin already holds; production renders a generic 404. No SQL text reaches the client.
4. Constraint matching: `errorInfo[2]` is the MySQL server message; 1452 text carries schema/constraint identifiers, never column values, so no user-controlled text can forge a match. Pattern is a fixed literal, no ReDoS.
5. No masking: the branch requires both code 1452 and the parent FK name; the `store_language_id` 1452 and every other `QueryException` still reach the existing `Translate*UniqueViolation` delegation (which rethrows non-1062), covered by test 4a.

Residual (accepted, Low): the read-to-write window is closed by refusal, not by lock (Q-2 (a)).

## Phase 5 — Final code review (`code-reviewer`, 2026-11-14)

**Verdict: APPROVED.** Reviewed `git diff 13dde8e..HEAD -- app tests` (6b89bdb, 958a7e3).

- Acceptance criteria: all six met. The category action re-reads with `firstOrFail()` after the logged authorize and before `ResolveActiveStoreLanguage`, and the re-read instance feeds `nameRules` and `setTranslation`. Both actions map only `errorInfo[1] === 1452` plus the parent FK constraint name in `errorInfo[2]` to `ModelNotFoundException::setModel(<Parent>::class, [$key])`, before the `Translate*UniqueViolation` delegation. §3 cases 1, 2, 3, 3b and 4a–4c are covered in both test files; the regression tests (1062, 1213) still pass. Callers catch only `ValidationException` (P-6 confirmed).
- Gates: Pint unscoped passed, no files changed. Larastan passes on both changed actions. A full run in this worktree reports one error in the untouched `app/Actions/Products/SanitizeProductDescription.php:98`; the same file passes in the main checkout, so this diff did not introduce it. `composer types:check` hits the default 128M memory limit, so it was run with `--memory-limit=-1`.
- Full suite on `testing_0098_…`, run sequentially in chunks: Unit 289; Feature 5721 (5 skipped); Browser 290 (3 skipped). Total 6300, 0 failures.
- Non-blocking: `SetProductTranslation`'s `@throws ModelNotFoundException` line says only "before the write", but the code also throws it for a delete during the write (the category action's wording, "before or during", is correct). `bindProductThrowingSetTranslation` duplicates the category file's `bindThrowingSetTranslation`.

## Phase 6 — Documentation (`docs-keeper`, 2026-11-14)

**Done.** Updated `docs/api/products/products-index-and-editor.md` (`SetProductTranslation` 1452 mapping), `docs/api/products/product-categories.md` (category re-read and 1452 mapping), `docs/security/livewire-authorization/action-level-authorization.md` (new clean-refusal subsection, single `_Last updated:_` line), and `ai-spec/tasks/_digests/epic-5.md` (L-2 marked resolved by 0098; 0098 decision bullets appended). No schema, route or lang change, so no other docs, README or `../readme.md` change.

## Phase 7 — Closure (2026-11-14)

**Status: done.** Phases 3–6 approved (full unscoped suite green: 6300 tests, 0 failures). Task file moved from `ai-spec/tasks/in-progress/` to `ai-spec/tasks/done/`; claim `worktree-0098-translation-write-actions-deleted-entity-race-backend` released and its `tasks-status.json` entry removed. Link integrity checked in both directions (inbound links repointed in `0099`, `done/0087` and `docs/security/model-instance-trust.md`).
