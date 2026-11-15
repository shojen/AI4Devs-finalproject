# [0099] Blog category and tag translation write actions refuse a deleted parent cleanly (backend)

## Description

`App\Actions\Blog\SetBlogCategoryTranslation` (story
[0073](../done/0073-blog-categories-language-tabs-ui.md)) and `App\Actions\Blog\SetBlogTagTranslation`
(story [0075](../done/0075-blog-tags-language-tabs-ui.md)) write a translation row whose
`blog_category_id` / `blog_tag_id` foreign key points at the parent. Both parents are **hard deleted**
(neither `BlogCategory` nor `BlogTag` uses `SoftDeletes`), and both translation tables declare that FK
`constrained()->cascadeOnDelete()` with Laravel's default constraint name
(`database/migrations/2026_10_07_154600_create_blog_category_translations_table.php` line 25,
`database/migrations/2026_10_15_100000_create_blog_tag_translations_table.php` line 23; no later
migration renames or drops them).

When the parent row is gone at the moment of the translation `INSERT`, MySQL raises **1452**
(`ER_NO_REFERENCED_ROW_2`). Both actions catch `QueryException` only to hand it to
`TranslateBlogCategoryNameUniqueViolation` / `TranslateBlogTagNameUniqueViolation`, which map a 1062 on
the per-language `normalized_name` index and **rethrow everything else unchanged** — so a 1452 ends in
an unhandled `QueryException`: a **500**.

This is the blog-side sibling of story
[0098](../done/0098-translation-write-actions-deleted-entity-race-backend.md), split out by the user's answer
to 0098's **Q-3 (a)** (2026-11-14). The real state of the two actions, read from the code on
2026-11-14:

| Action | Parent re-read after authorize? | Parent deleted **before** the call | Parent deleted **between** read and write |
|---|---|---|---|
| `SetBlogCategoryTranslation` | **no** — it uses the passed `$blogCategory` for validation and the write | 1452 → 500 (a stale passed instance) | 1452 → 500 |
| `SetBlogTagTranslation` | yes, `BlogTag::query()->whereKey(...)->firstOrFail()` | clean `ModelNotFoundException` (404), already tested (`SetBlogTagTranslationTest`, "a tag deleted after it was loaded is a clean not-found…") | 1452 → 500 |

The calling Livewire components (`BlogCategories\Index::save()`, `BlogTags\Index::save()`) re-read their
target with `findOrFail()` before calling the action, so through the UI only the read-to-write window
is reachable; the "deleted before the call" window is reachable by any direct caller of
`SetBlogCategoryTranslation` (the action is the self-sufficient guard, per
[model-instance-trust.md](../../../docs/security/model-instance-trust.md)). Blog posts are soft-deleted,
so `SetBlogPostTranslation` is not exposed to 1452 and is out of scope.

## Type

backend | includes database-expert: no (no schema change; the FKs and their names stay as they are)

## PRD coverage

Epic 5 — Internationalization ([epic-5-internationalization.md](../../../docs/PRD/sections/epic-5-internationalization.md)).
Robustness hardening, sibling of 0098; no PRD sentence demands it directly.

## Three Amigos participants

- `product-owner` — facilitator, composed this file (2026-11-14).
- `backend-expert` — Amigo 1, to confirm the mechanism in the debate.
- `backend-qa` — Amigo 2, to confirm the failure-injection technique (R-1).

This file is the `product-owner` draft created at the user's request; the debate ran on 2026-11-15 (see the Phase 1 record). The functional decisions are **inherited from the user's answers on 0098** (§4); no
open question is left for the user.

## 1. Refined user story

**As** a content editor,
**I want** a translation save on a blog category or blog tag that another editor has just deleted to be
refused as "not found",
**so that** I see a clear outcome instead of a server error, and no orphan translation row is written.

## 2. Gherkin — detailed acceptance criteria

```gherkin
Feature: Blog category and tag translation write actions refuse a deleted parent cleanly

  Background:
    Given the store has a default store language "en"
    And an active non-default store language "es"

  Scenario Outline: A parent deleted before the call is refused as not found
    Given a content editor allowed to edit <record>s
    And a <record> the content editor loaded earlier
    And that <record> has since been deleted
    When the content editor saves a translation of that <record> in "es" through <action>
    Then the write is refused as not found
    And it is not refused with a server error
    And no translation row exists for that <record>

    Examples:
      | action                      | record        |
      | SetBlogCategoryTranslation  | blog category |
      | SetBlogTagTranslation       | blog tag      |

  Scenario Outline: A parent deleted between the action's read and its write is refused as not found
    Given a content editor allowed to edit <record>s
    And a <record> exists
    And another editor deletes that <record> after the action has read it and before the translation is written
    When the content editor saves a translation of that <record> in "es" through <action>
    Then the write is refused as not found
    And it is not refused with a server error
    And no translation row exists for that <record>

    Examples:
      | action                      | record        |
      | SetBlogCategoryTranslation  | blog category |
      | SetBlogTagTranslation       | blog tag      |

  Scenario Outline: Authorization is still checked first
    Given a staff member without permission to edit <record>s
    And a <record> that has been deleted
    When the staff member tries to save a translation of that <record> in "es" through <action>
    Then the attempt is refused as unauthorized, not as not found
    And the refusal is logged exactly once

    Examples:
      | action                      | record        |
      | SetBlogCategoryTranslation  | blog category |
      | SetBlogTagTranslation       | blog tag      |

  Scenario Outline: Other database errors are not disguised as not found
    Given a content editor allowed to edit <record>s
    And a <record> exists
    And the translation write fails with a database error other than a missing <record>
    When the content editor saves a translation of that <record> in "es" through <action>
    Then the original database error is raised unchanged

    Examples:
      | action                      | record        |
      | SetBlogCategoryTranslation  | blog category |
      | SetBlogTagTranslation       | blog tag      |

  Scenario Outline: A save on an existing parent is unchanged
    Given a content editor allowed to edit <record>s
    And a <record> exists
    When the content editor saves a translation of that <record> in "es" through <action>
    Then the <record>'s translation in "es" is stored

    Examples:
      | action                      | record        |
      | SetBlogCategoryTranslation  | blog category |
      | SetBlogTagTranslation       | blog tag      |
```

## Files to create/modify

### Modify

- `app/Actions/Blog/SetBlogCategoryTranslation.php` — after the (logged) authorize step, re-read the
  category with `BlogCategory::query()->whereKey($blogCategory->getKey())->firstOrFail()` and use the
  re-read instance downstream (validation's ignore id and the write), mirroring `SetBlogTagTranslation`.
  Order: authorize → **category re-read** → active language (0087's `ResolveActiveStoreLanguage`) →
  validate → write. Plus the 1452 handling below.
- `app/Actions/Blog/SetBlogTagTranslation.php` — the 1452 handling below (its re-read already exists).
- 1452 handling (both actions): in the existing `QueryException` catch, a MySQL 1452 whose native driver
  message (`errorInfo[2]`) names the parent FK constraint — `blog_category_translations_blog_category_id_foreign`
  / `blog_tag_translations_blog_tag_id_foreign` — becomes a `ModelNotFoundException` for the parent
  model; every other `QueryException` (including a 1452 on `store_language_id`) is rethrown unchanged
  through the existing 1062 translator path.
- The two translators (`TranslateBlogCategoryNameUniqueViolation` / `TranslateBlogTagNameUniqueViolation`)
  are **not touched**: Phase 1 settled on the inline placement of story 0098
  (`SetProductCategoryTranslation`): the guard is `errorInfo[1] === 1452` plus
  ``preg_match('/CONSTRAINT `<fk name>` FOREIGN KEY/', (string) ($e->errorInfo[2] ?? ''))``, placed in the
  action's `catch` before the translator call. Add `@throws ModelNotFoundException` to both docblocks.
- `tests/Feature/Blog/SetBlogCategoryTranslationTest.php`, `tests/Feature/Blog/SetBlogTagTranslationTest.php`
  — §3's cases.

### Deliberately not touched

- The schema and the FK names.
- The Livewire components (`BlogCategories\Index`, `BlogTags\Index`): a `ModelNotFoundException`
  propagates as Livewire's standard 404 handling, the outcome a deleted blog post or a stale tag already
  gives. Their own `findOrFail()` re-reads stay.
- `SetBlogPostTranslation` (soft-deleted parent, no 1452 exposure) and the two product-side actions
  (story 0098).

## 3. QA test cases / validation scenarios

Feature tests, direct action calls resolved from the container, `RefreshDatabase`.

| # | Case | Where | Notes |
|---|------|-------|-------|
| 1 | Parent deleted before the call (stale passed instance) → `ModelNotFoundException`; no translation row | both files | already covered for the tag — reuse; new for the category |
| 2 | Parent deleted between read and write → `ModelNotFoundException`, not `QueryException`; no translation row | both files | **highest value**; inject the delete with a translation-model `creating`/`saving` listener that deletes the parent through a raw query (the 0078 errors-log technique, as 0076b R-1 and 0098 case 2) |
| 3 | Unauthorized + deleted → `AuthorizationException`, logged once | both files | proves order |
| 4 | A 1452 on `store_language_id`, a 1062 on a non-handled index and a non-integrity error are rethrown unchanged | both files | discrimination on the **constraint name in `errorInfo[2]`**, never on `getMessage()` (the translators' own docblocks and the `CreateShippingRate` F-5 precedent) |
| 3b | A deleted parent with an inactive language is refused as not found (proves the re-read precedes `ResolveActiveStoreLanguage`) | both files | added in Phase 1 (backend-qa) |
| 4b | Case 4 dataset has a decoy row: `errorInfo[2]` names the language FK while the PDO message names the parent FK; plus a 1213 deadlock | both files | reuse `bindThrowingBlogSetTranslation` / `bindThrowingBlogTagSetTranslation`; fold the existing tag 1452-on-`store_language_id` test into it |
| 5 | Existing parent still writable; existing 1062 mapping on `…_language_normalized_name_unique` unchanged | both files | regression, likely already covered |

## Expected outcome

Saving a translation of a blog category or blog tag that no longer exists — whether it was deleted
before the call or during it — ends in a clean "not found" refusal (a 404) and never in a 500 or an
orphan row; every other database error behaves exactly as before.

## Acceptance criteria

- [ ] `SetBlogCategoryTranslation` re-reads the category after authorize and uses the re-read instance.
- [ ] Both actions refuse a parent deleted before the call, and a parent deleted between read and write, with a `ModelNotFoundException`; no translation row is written.
- [ ] The race handling matches only the parent FK's 1452 by constraint name in `errorInfo[2]`.
- [ ] Every other `QueryException` is rethrown unchanged (case 4).
- [ ] Authorization still runs first (case 3).
- [ ] 0087's order (authorize → re-read → active language → validate → write) is preserved and its structural guard (`tests/Unit/ArchitectureTest.php`) still passes.

## Definition of Done

- [x] Phase 1 debate completed with `backend-expert` and `backend-qa`.
- [ ] Tests written and green (iteration filter, then the **full** suite unscoped).
- [ ] Pint unscoped and Larastan level 7 clean.
- [ ] Code reviewed (`code-reviewer`).
- [ ] No security findings (`appsec-auditor`).
- [ ] Documentation updated (`docs-keeper`): the blog category/tag translation write action docs and
  `docs/security/livewire-authorization/action-level-authorization.md` record the clean refusal; the
  Epic 5 digest notes the blog-side deleted-parent race as resolved by this task.
- [ ] Acceptance criteria met.

## 4. Documented functional decisions

All three are **inherited from the user's answers on 0098** (2026-11-14) and apply here unchanged:

- **D-1 (0098 Q-1 (a)) The refusal is a `ModelNotFoundException`** (Livewire's standard 404), the
  outcome `SetBlogTagTranslation` and `SetBlogPostTranslation` already give for a missing record.
- **D-2 (0098 Q-2 (a)) Catch, don't lock.** Catch the `QueryException`, match **only** 1452 on the
  parent FK constraint name in `errorInfo[2]`, rethrow everything else. No transaction or
  `lockForUpdate()`.
- **D-3 (0098 Q-3 (a)) Scope split.** 0098 keeps the two product-side actions; this task covers the two
  blog actions exposed to 1452.
- **D-4 Authorize first** (0058 D-13): the logged authorize step stays the first statement; the
  re-read comes after it.
- **D-5 Not a schema change.** The FKs keep `cascadeOnDelete()` and their default names.

## 5. Dependencies, risks, open questions

### Dependencies

- **None pending.** It builds on shipped stories only: 0073, 0075 and
  [0087](../done/0087-translation-write-actions-refuse-inactive-store-language.md) (the order and the
  `ResolveActiveStoreLanguage` helper), all in `done/`. Status `ready`.
- **Independent of [0098](../done/0098-translation-write-actions-deleted-entity-race-backend.md).** Their code
  and test write sets are disjoint (0098: `app/Actions/Products/*`, `app/Actions/ProductCategories/*`
  and their two test files; this task: `app/Actions/Blog/*` and two `tests/Feature/Blog/*` files), so
  `conflict_risk_with` is empty. The only shared surface is Phase 6 documentation
  (`action-level-authorization.md`, the Epic 5 digest), a small merge-time overlap, not a dependency.
  0098 has landed; this task follows its placement of the 1452 discrimination (action vs translator) for
  consistency; it is not a prerequisite.

### Risks

- **R-1** The race test needs failure injection; follow the 0078 errors-log technique (a model hook),
  not a mock of `SetTranslation`.
- **R-2** A translation row loaded before the parent's cascade delete is then `UPDATE`d: zero rows
  affected, no error, and the action returns a model that no longer exists. Under D-2 this window stays
  open, as accepted for 0098.
- **R-4** `FindOrCreateBlogTag` inserts `BlogTagTranslation` rows directly and has the same 1452 exposure; out
  of scope here (recorded by `backend-expert` in Phase 1) — candidate follow-up.
- **R-5** Model listeners used for the failure injection are static: flush them in `afterEach`
  (`BlogCategoryTranslation::flushEventListeners()` / `BlogTagTranslation::...`) unless the file already does.
- **R-3** Matching on the error message instead of the constraint name misclassifies errors (the
  message embeds the user's text). Mitigated by case 4.

### Open questions for the user

None: Q-1–Q-3 are inherited from 0098's user decisions (D-1–D-3).

## Phase 1 record (2026-11-15)

- `backend-expert`: **approved**. FK names confirmed as Laravel defaults (52 / 44 chars); `store_language_id` FK is
  `restrictOnDelete`, so constraint-name discrimination is mandatory; re-read goes right after authorize and before
  `ResolveActiveStoreLanguage`; `ArchitectureTest` guard unaffected; translators stay untouched.
- `backend-qa`: **approved with adjustments** (cases 3b and 4b above). No existing test breaks from the category re-read.

## Provenance

Split from 0098 by the user's Q-3 (a) answer (2026-11-14). Created by `product-owner` on 2026-11-14 from
a direct read of both actions, their translators and their migrations; Phase 1 debate and Phase 2 ran on 2026-11-15 (records above).

## Phase 2 — INVEST validation (`code-reviewer`, 2026-11-15)

**Approved.**

- INVEST: Independent (0073, 0075, 0087, 0098 all in `done/`; disjoint write set), Negotiable (D-1–D-5 inherited from 0098's user answers, no open question), Valuable (500 → clean 404, no orphan row), Estimable (two actions, two test files, pattern already shipped in `SetProductCategoryTranslation`), Small, Testable (§3 cases 1–5 map to every acceptance criterion).
- Gherkin: every scenario opens with a named role (content editor / staff member) and has a single `When`.
- Consistent with 0098: same inline placement (`errorInfo[1] === 1452` + constraint name in `errorInfo[2]`, before the translator call), same `ModelNotFoundException` outcome, same decoy test (4b ≈ 0098's 4c).
