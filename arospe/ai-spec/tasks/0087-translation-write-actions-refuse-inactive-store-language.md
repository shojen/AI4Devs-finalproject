# [0087] Translation write actions refuse an inactive store language

## Description

The per-language translation **write** actions `SetBlogCategoryTranslation` (story
[0073](done/0073-blog-categories-language-tabs-ui.md)) and `SetProductCategoryTranslation`
(story 0071) currently accept a store language that has been removed (`is_active = false`; rows
are kept, story 0068 D5) and each pins that behaviour with a test. Hiding the tab of a removed
language is only a UI hint: a forged Livewire call, or a race with a deactivation, can still write
to it. This story makes both actions re-read the language as **active** and refuse otherwise —
the same defence in depth `SetBlogTagTranslation` already has (story
[0075](done/0075-blog-tags-language-tabs-ui.md), **D-5**) — and adds a structural guard so
a future `Set*Translation` action cannot forget it.

## Type

backend | includes database-expert: no

## PRD coverage

Epic 5 — Internationalization ([epic-5-internationalization.md](../../docs/PRD/sections/epic-5-internationalization.md)).
No PRD sentence demands this directly: it is a **security hardening** of the consumers of story
0068's decision D5 ("removing a language deactivates it and keeps its rows"). It changes no
visible behaviour on any screen (the UI already never offers an inactive language).

## Three Amigos participants

- `product-owner` — facilitator, composed this file.
- `backend-expert` — Amigo 1 (implementation design).
- `backend-qa` — Amigo 2 (test design).

No frontend amigo: no component, view or browser behaviour changes. No `database-expert`: no
schema change.

## 1. Refined user story

**As** a store owner,
**I want** a translation to be writable only into a language my store currently offers,
**so that** a removed language keeps exactly the content it had when it was removed, and nobody
can silently grow content in it by bypassing the admin screens.

## 2. Gherkin — detailed acceptance criteria

```gherkin
Feature: Translation write actions refuse an inactive store language

  Background:
    Given the store has a default store language "en"

  Scenario Outline: An inactive store language is refused and nothing is written to it
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And a store language "de" that has been removed from the store
    When the catalog administrator saves the name "Kategorie" for that <record> in "de" through <action>
    Then the write is refused with an error on the field "names.<de id>"
    And the error message reads "This language is no longer active for the store."
    And no translation row exists for that <record> in "de"

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |
      | SetBlogTagTranslation          | blog tag         |

  Scenario Outline: A language instance loaded while active is not trusted after it is deactivated
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And the store language "de" was loaded while it was still active
    And "de" has since been removed from the store without that loaded copy being refreshed
    When the catalog administrator saves a name for that <record> in "de" through <action> using the stale copy
    Then the write is refused with an error on the field "names.<de id>"
    And no translation row exists for that <record> in "de"

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |
      | SetBlogTagTranslation          | blog tag         |

  Scenario Outline: Authorization is checked before the language
    Given a staff member without permission to edit <record>s
    And a <record> exists
    And a store language "de" that has been removed from the store
    When the staff member tries to save a name for that <record> in "de" through <action>
    Then the attempt is refused as unauthorized, not as an inactive language
    And the refusal is logged exactly once
    And no translation row exists for that <record> in "de"

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: The language check runs before the name is validated
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And a store language "de" that has been removed from the store
    When the catalog administrator saves a blank name for that <record> in "de" through <action>
    Then the only error on "names.<de id>" is the inactive-language message

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: Active languages remain writable
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And an active non-default store language "es"
    When the catalog administrator saves the name "Categoría" for that <record> in <language> through <action>
    Then the <record>'s name in <language> is stored

    Examples:
      | action                         | record           | language |
      | SetBlogCategoryTranslation     | blog category    | "es"     |
      | SetBlogCategoryTranslation     | blog category    | "en"     |
      | SetProductCategoryTranslation  | product category | "es"     |
      | SetProductCategoryTranslation  | product category | "en"     |

  Scenario Outline: A refused write leaves an existing translation of the removed language intact
    Given a catalog administrator allowed to edit <record>s
    And a <record> whose name in "de" is "Alt" was stored while "de" was active
    And "de" has since been removed from the store
    When the catalog administrator saves the name "Neu" for that <record> in "de" through <action>
    Then the write is refused
    And the <record>'s stored name in "de" is still "Alt"

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |

  Scenario: Reading a removed language's stored translation is unchanged
    Given a blog category whose name in "de" is "Alt" was stored while "de" was active
    And "de" has since been removed from the store
    When the store reads that blog category's translated name in "de"
    Then the name returned is "Alt"

  Scenario Outline: A reactivated language becomes writable again
    Given a catalog administrator allowed to edit <record>s
    And a <record> exists
    And the store language "de" was removed and has since been re-added to the store
    When the catalog administrator saves the name "Kategorie" for that <record> in "de" through <action>
    Then the <record>'s name in "de" is stored

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |

  Scenario Outline: The refusal message is localized and leaks no internal identifiers
    Given a catalog administrator allowed to edit <record>s whose interface locale is <locale>
    And a store language "de" that has been removed from the store
    When the catalog administrator saves a name for a <record> in "de" through <action>
    Then the error message reads <message>
    And the message contains neither "names." nor the language id

    Examples:
      | action                         | record           | locale | message                                              |
      | SetBlogCategoryTranslation     | blog category    | en     | "This language is no longer active for the store."   |
      | SetBlogCategoryTranslation     | blog category    | es     | "Este idioma ya no está activo en la tienda."        |
      | SetProductCategoryTranslation  | product category | en     | "This language is no longer active for the store."   |
      | SetProductCategoryTranslation  | product category | es     | "Este idioma ya no está activo en la tienda."        |

  Scenario: A new translation write action that forgets the guard is caught
    Given a developer adds a new "Set...Translation" action under app/Actions
    And that action does not re-read the store language as active
    When the test suite runs
    Then the structural guard test fails naming that action
```

The `SetBlogTagTranslation` rows are the already-compliant reference (0075); they are included
only in the first two outlines so all three siblings are proven identical on the two
highest-value cases. They must not require any change to 0075's files.

## Files to create/modify

### Modify

- `app/Actions/Blog/SetBlogCategoryTranslation.php` and
  `app/Actions/ProductCategories/SetProductCategoryTranslation.php` — after the (logged)
  authorize step, re-read the language and refuse when it is not active; every downstream step
  (trim/validate/`setTranslation`) uses the **re-read** instance, never the passed one
  ([model-instance-trust.md](../../docs/security/model-instance-trust.md)). Order:
  **authorize → active re-read → trim/validate → write** (0058 D-13). Shape (mirrors
  `SetBlogTagTranslation`):

  ```php
  $activeLanguage = StoreLanguage::query()->active()->find($language->id);

  if ($activeLanguage === null) {
      throw ValidationException::withMessages([
          "names.{$language->id}" => __('blog.categories.index.tabs.language_inactive'),
      ]);
  }
  ```

- `lang/en/blog.php`, `lang/es/blog.php` — add `language_inactive` inside the existing
  `categories.index.tabs` array.
- `lang/en/products.php`, `lang/es/products.php` — add `language_inactive` inside the existing
  `categories.index.tabs` array. Wording identical to 0075's `blog-tags.index.tabs.language_inactive`.
- `tests/Feature/Blog/SetBlogCategoryTranslationTest.php` (~line 406) and
  `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` (~line 358) — reverse
  and rename `an inactive store language is still writable through the action` to
  `an inactive store language is refused and nothing is written to it`; add the new cases in §3.
  Grep both files for any other test using an inactive fixture as a convenient language.
- `tests/Unit/ArchitectureTest.php` — add the structural guard (§3).
- Any fixture/test elsewhere that writes through either action into an inactive language
  (risk R3) — fix here.

### Deliberately not touched

- Livewire components `BlogCategories\Index` / `ProductCategories\Index` and their views: both
  `save()` iterate active languages only, so the refusal is unreachable from the UI. The
  implementer **verifies** their `catch` blocks catch only specific exceptions and let
  `ValidationException` propagate; a change is needed only if that verification fails.
- `App\Actions\Translations\SetTranslation` (the primitive, story 0070) stays unguarded; seeders
  use it.
- The read side (`translated()` and fallbacks).
- All files of story 0075 (reference implementation, in progress on another branch line).
- Stories 0077 and 0079 — amended by note only (§6).

## 3. QA test cases / validation scenarios

Feature tests, direct action calls, `RefreshDatabase`, a seeded default language, actor acting
with the permission and `forgetCachedPermissions()` before negative cases.

| # | Case | Where | Notes |
|---|------|-------|-------|
| 1 | Inactive language refused, `errors()` has key `names.{id}` with the `language_inactive` message, translations row count 0 | both action test files | the reversed test |
| 2 | Stale instance not trusted | both files (+ assert tag action as reference if cheap) | `StoreLanguage::whereKey($id)->update(['is_active' => false])` without refreshing, then `StoreLanguage::flushDefaultStoreLanguage()` explicitly (a raw update fires no model events). **Highest value.** |
| 3 | Unauthorized + inactive → `AuthorizationException`, logged exactly once, nothing written | both files | proves order |
| 4 | Blank name + inactive → only the language error | both files | proves order vs validation |
| 5 | Active non-default and active default still writable | both files | likely already covered — reuse |
| 6 | Existing translation of the now-inactive language unchanged after refusal | both files | |
| 7 | Reactivated language writable again | both files | |
| 8 | Message localized en/es, contains neither `names.` nor the id | one dataset over locales × actions | not duplicated per file |
| 9 | `translated()` of an inactive language's stored row unchanged | one test, one model | cheap regression |
| 10 | Structural guard: every `app/Actions/**/Set*Translation.php` except `app/Actions/Translations/SetTranslation.php` contains `->active()` | `tests/Unit/ArchitectureTest.php` | source-text check (can be gamed; paired with behavioural tests). Pest `arch()` cannot express it. |

**Not tested here:** layer-1 component tests (already prove forged keys never reach the action),
the `SetTranslation` primitive, 0075's tag tests, a default+inactive language
(`RemoveStoreLanguage` forbids it). Check that no browser/journey test removes a language then
saves relying on the old behaviour.

## Expected outcome

Calling either action with an inactive store language — fresh or stale instance — throws a
`ValidationException` keyed `names.{id}` with a localized message and writes nothing; active
languages behave exactly as before; a fourth `Set*Translation` action without the guard fails
the suite.

## Acceptance criteria

- [ ] Both actions refuse an inactive language with `ValidationException` on `names.{id}` and write nothing.
- [ ] The refusal uses a fresh `active()` re-read, never the passed instance (stale-instance test green).
- [ ] Order is authorize → active re-read → validate → write (cases 3 and 4 green).
- [ ] `language_inactive` exists in `lang/en` and `lang/es` for `blog.categories.index.tabs` and `products.categories.index.tabs`, wording identical to 0075.
- [ ] Active default and non-default languages remain writable; reads are unchanged.
- [ ] The two "still writable" tests are reversed and renamed; no other test relies on writing an inactive language.
- [ ] The structural guard exists and passes for the three current actions.
- [ ] No Livewire component, view, migration or 0075 file is modified (unless the catch-block verification forces a component fix, recorded in this file).

## Definition of Done

- [ ] Tests written and green (iteration filter, then the **full** suite unscoped).
- [ ] Pint unscoped and Larastan level 7 clean.
- [ ] Code reviewed (`code-reviewer`).
- [ ] No security findings (`appsec-auditor`).
- [ ] Documentation updated (`docs-keeper`): grep `docs/` for statements that inactive languages are writable through these actions and correct them; record the rule (all `Set*Translation` write actions refuse inactive languages) where translation write actions are documented.
- [ ] Amendment notes for 0077 and 0079 applied (§6).
- [ ] Acceptance criteria met.

## 4. Documented functional decisions

- **D-1 Refuse everywhere.** The actions refuse an inactive language for every caller; seeders and
  bulk tooling that legitimately need it use the `SetTranslation` primitive.
- **D-2 `ValidationException`, not `AuthorizationException` / `ModelNotFoundException`.** The actor
  is authorized; the input is invalid. Keyed `names.{id}` to match the component's field keys
  and 0075.
- **D-3 Re-read, never trust the instance** (`StoreLanguage::query()->active()->find($language->id)`).
- **D-4 Order:** authorize → language check → name validation → write. A blank name on an inactive
  language reports the language error.
- **D-5 Inline guard per action**, no shared helper yet. Revisit extracting a
  `ResolveActiveStoreLanguage` when 0079 adds the fourth copy.
- **D-6 No component change.** The refusal is unreachable from the UI; its message may be
  invisible when the tab is gone — acceptable, it only exists for forged or racing requests.
- **D-7 Per-domain lang keys** (`blog.categories…`, `products.categories…`) rather than a shared
  key, following the existing per-screen `tabs` arrays.

## 5. Dependencies, risks, open technical questions

### Dependencies

- **Soft relation to [0075](done/0075-blog-tags-language-tabs-ui.md)** — reference
  implementation and wording source. Neither blocks the other for merge; this story must not
  edit 0075's files.
- Builds on 0071, [0073](done/0073-blog-categories-language-tabs-ui.md) (the actions changed) and
  0068 D5 (removal = deactivation).
- Pending [0077](0077-product-editor-language-tabs-ui.md) and
  [0079](0079-blog-post-editor-language-tabs-ui.md) are affected by note only (§6).

### Risks

- **R1** A legitimate flow writes an inactive language through these actions. Mitigation: grep all
  callers (seeders, tests, commands); move any to the primitive (D-1).
- **R2** An administrator deactivates a language while another is editing: the save is refused on
  a tab no longer rendered. Accepted (matches 0075).
- **R3** Fixtures using an inactive language as a convenient second language break. Fix in this story.
- **R4** The error is invisible when the tab is hidden. Acceptable: forged/race path only.

### Open technical questions

- None blocking. The implementer verifies the components' `catch` blocks (see "Deliberately not
  touched") and records the result here.

## 6. Technical backlog / amendment notes

- **Amend [0079](0079-blog-post-editor-language-tabs-ui.md):** `SetBlogPostTranslation` must refuse
  an inactive store language per this story; its currently specified "still writable" cases
  (~lines 614 and 1009) are reversed. Consider extracting the shared resolver (D-5) there.
- **Amend [0077](0077-product-editor-language-tabs-ui.md):** any new product `Set*Translation`
  action must refuse an inactive language in the action itself; component-only filtering is
  insufficient. The structural guard will enforce it.
- **docs-keeper:** sweep `docs/` for "inactive languages remain writable" statements.

## Provenance

The divergence (tag action refuses inactive languages, category actions accept them) was found
while implementing 0075; on 2026-10-27 the project owner chose to fix it in this separate story
rather than inside 0075. Phase 1 (Three Amigos: product-owner, backend-expert, backend-qa) only;
Phase 2 onwards not run.
