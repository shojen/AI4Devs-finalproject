# [0087] Translation write actions refuse an inactive store language

## Description

There are five per-language translation **write** actions, one per translatable entity:

| Action | Story | Refuses an inactive language today? |
|---|---|---|
| `App\Actions\Blog\SetBlogTagTranslation` | [0075](0075-blog-tags-language-tabs-ui.md) D-5 | yes, inline `StoreLanguage::query()->active()` re-read, key `names.{id}` |
| `App\Actions\Products\SetProductTranslation` | [0076b](0076b-product-translation-write-action-backend.md) D-3 | yes, inline copy of the same re-read, key `names.{id}` |
| `App\Actions\Blog\SetBlogCategoryTranslation` | [0073](0073-blog-categories-language-tabs-ui.md) | **no** — accepts it; `tests/Feature/Blog/SetBlogCategoryTranslationTest.php` ~line 381 pins that |
| `App\Actions\ProductCategories\SetProductCategoryTranslation` | [0071](0071-product-categories-language-tabs-ui.md) | **no** — accepts it; `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` ~line 340 pins that |
| `App\Actions\Blog\SetBlogPostTranslation` | [0079](0079-blog-post-editor-language-tabs-ui.md) | **no** — accepts it; `tests/Feature/Blog/SetBlogPostTranslationTest.php` ~line 249 pins that; 0079's Phase 4 finding **L-1** deferred the check to this story |

A removed store language is deactivated (`is_active = false`) and keeps its rows (story 0068 D5).
Hiding its tab is only a UI hint: a forged Livewire call, or a race with a deactivation, can still
write to it through the three non-compliant actions. This story:

1. extracts one shared collaborator, `App\Actions\Translations\ResolveActiveStoreLanguage`, that
   re-reads the passed language as **active** and throws a `ValidationException` otherwise;
2. makes **all five** `Set*Translation` actions use it (the two compliant ones replace their inline
   copy, with no behaviour change);
3. makes `SetBlogPostTranslation` refuse with a **post-level** error key, shown by
   `BlogPosts\Editor` as a danger `<flux:callout>` at the top of the form;
4. adds a structural guard so a future `Set*Translation` action cannot skip the helper.

## Type

full-stack (backend-heavy) | includes database-expert: no

> **No split.** The workflow's full-stack rule would normally split this into a backend and a
> frontend task. The user's binding decision **Q-2 (a)** (2026-11-13) places the editor callout in
> this story: the frontend part is one `@error` callout, one catch-block check and their tests, and it
> only exists to display the refusal this story introduces — a separate frontend task would ship a
> refusal with no visible message in between. Precedent: 0075 shipped `SetBlogTagTranslation` inside
> its UI story, and 0079 recorded the same no-split note. No schema change, so no `database-expert`.

## PRD coverage

Epic 5 — Internationalization ([epic-5-internationalization.md](../../../docs/PRD/sections/epic-5-internationalization.md)).
No PRD sentence demands this directly: it is a **security hardening** of the consumers of story
0068's decision D5 ("removing a language deactivates it and keeps its rows"). The only visible change
is the blog post editor's callout, reachable only through a forged request or a race with a
deactivation (the editor re-reads the active languages at click time).

## Three Amigos participants

- `product-owner` — facilitator, composed this file.
- `backend-expert` — Amigo 1 (helper and action design).
- `backend-qa` — Amigo 2 (action and structural-guard test design).
- `frontend-expert` — Amigo 3 (editor catch block and callout; added on the Phase 2 rewrite).
- `frontend-qa` — Amigo 4 (editor component test; added on the Phase 2 rewrite).

## 1. Refined user story

**As** a store owner,
**I want** a translation to be writable only into a language my store currently offers,
**so that** a removed language keeps exactly the content it had when it was removed, and nobody
can silently grow content in it by bypassing the admin screens.

## 2. Gherkin — detailed acceptance criteria

Actors: **a catalog administrator** holds `products.edit` (product and product category records);
**a blog editor** holds `blog.edit` (blog category, blog tag and blog post records) — the actor 0061,
0063 and 0079 use. `<field>` is the field the refusal lands on: `names.<de id>` for the four name-based
actions, the post-level key `translation` for `SetBlogPostTranslation` (D-2, D-8).

```gherkin
Feature: Translation write actions refuse an inactive store language

  Background:
    Given the store has a default store language "en"

  Scenario Outline: An inactive store language is refused and nothing is written to it
    Given <actor> allowed to edit <record>s
    And a <record> exists
    And a store language "de" that has been removed from the store
    When <actor> saves a translation of that <record> in "de" through <action>
    Then the write is refused with an error on the field "<field>"
    And no translation row exists for that <record> in "de"

    Examples:
      | actor                     | action                         | record           | field         |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    | names.<de id> |
      | a catalog administrator   | SetProductCategoryTranslation  | product category | names.<de id> |
      | a blog editor             | SetBlogTagTranslation          | blog tag         | names.<de id> |
      | a catalog administrator   | SetProductTranslation          | product          | names.<de id> |
      | a blog editor             | SetBlogPostTranslation         | blog post        | translation   |

  Scenario Outline: A language instance loaded while active is not trusted after it is deactivated
    Given <actor> allowed to edit <record>s
    And a <record> exists
    And the store language "de" was loaded while it was still active
    And "de" has since been removed from the store without that loaded copy being refreshed
    When <actor> saves a translation of that <record> in "de" through <action> using the stale copy
    Then the write is refused with an error on the field "<field>"
    And no translation row exists for that <record> in "de"

    Examples:
      | actor                     | action                         | record           | field         |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    | names.<de id> |
      | a catalog administrator   | SetProductCategoryTranslation  | product category | names.<de id> |
      | a blog editor             | SetBlogTagTranslation          | blog tag         | names.<de id> |
      | a catalog administrator   | SetProductTranslation          | product          | names.<de id> |
      | a blog editor             | SetBlogPostTranslation         | blog post        | translation   |

  Scenario Outline: Authorization is checked before the language
    Given a staff member without permission to edit <record>s
    And a <record> exists
    And a store language "de" that has been removed from the store
    When the staff member tries to save a translation of that <record> in "de" through <action>
    Then the attempt is refused as unauthorized, not as an inactive language
    And the refusal is logged exactly once
    And no translation row exists for that <record> in "de"

    Examples:
      | action                         | record           |
      | SetBlogCategoryTranslation     | blog category    |
      | SetProductCategoryTranslation  | product category |
      | SetBlogPostTranslation         | blog post        |

  Scenario Outline: The language check runs before the content is validated
    Given <actor> allowed to edit <record>s
    And a <record> exists
    And a store language "de" that has been removed from the store
    When <actor> saves a blank <content> for that <record> in "de" through <action>
    Then the only error is the inactive-language message on "<field>"

    Examples:
      | actor                     | action                         | record           | content | field         |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    | name    | names.<de id> |
      | a catalog administrator   | SetProductCategoryTranslation  | product category | name    | names.<de id> |
      | a blog editor             | SetBlogPostTranslation         | blog post        | title   | translation   |

  Scenario Outline: Active languages remain writable
    Given <actor> allowed to edit <record>s
    And a <record> exists
    And an active non-default store language "es"
    When <actor> saves a translation of that <record> in <language> through <action>
    Then the <record>'s translation in <language> is stored

    Examples:
      | actor                     | action                         | record           | language |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    | "es"     |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    | "en"     |
      | a catalog administrator   | SetProductCategoryTranslation  | product category | "es"     |
      | a catalog administrator   | SetProductCategoryTranslation  | product category | "en"     |
      | a blog editor             | SetBlogPostTranslation         | blog post        | "es"     |

  Scenario Outline: A refused write leaves an existing translation of the removed language intact
    Given <actor> allowed to edit <record>s
    And a <record> whose translation in "de" was stored while "de" was active
    And "de" has since been removed from the store
    When <actor> saves a different translation of that <record> in "de" through <action>
    Then the write is refused
    And the <record>'s stored translation in "de" is unchanged

    Examples:
      | actor                     | action                         | record           |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    |
      | a catalog administrator   | SetProductCategoryTranslation  | product category |
      | a blog editor             | SetBlogPostTranslation         | blog post        |

  Scenario: Reading a removed language's stored translation is unchanged
    Given a blog editor allowed to edit blog categories
    And a blog category whose name in "de" is "Alt" was stored while "de" was active
    And "de" has since been removed from the store
    When the blog editor's store reads that blog category's translated name in "de"
    Then the name returned is "Alt"

  Scenario Outline: A reactivated language becomes writable again
    Given <actor> allowed to edit <record>s
    And a <record> exists
    And the store language "de" was removed and has since been re-added to the store
    When <actor> saves a translation of that <record> in "de" through <action>
    Then the <record>'s translation in "de" is stored

    Examples:
      | actor                     | action                         | record           |
      | a blog editor             | SetBlogCategoryTranslation     | blog category    |
      | a catalog administrator   | SetProductCategoryTranslation  | product category |
      | a blog editor             | SetBlogPostTranslation         | blog post        |

  Scenario Outline: The refusal message is localized and leaks no internal identifiers
    Given <actor> allowed to edit <record>s whose interface locale is <locale>
    And a store language "de" that has been removed from the store
    When <actor> saves a translation of a <record> in "de" through <action>
    Then the error message reads <message>
    And the message contains neither the literal error key ("names.<de id>" or "translation") nor the language id

    Examples:
      | actor                   | action                         | record           | locale | message                                         |
      | a blog editor           | SetBlogCategoryTranslation     | blog category    | en     | "This language is no longer active for the store." |
      | a blog editor           | SetBlogCategoryTranslation     | blog category    | es     | "Este idioma ya no está activo en la tienda."   |
      | a catalog administrator | SetProductCategoryTranslation  | product category | en     | "This language is no longer active for the store." |
      | a catalog administrator | SetProductCategoryTranslation  | product category | es     | "Este idioma ya no está activo en la tienda."   |
      | a blog editor           | SetBlogPostTranslation         | blog post        | en     | the en text of blog-posts.editor.tabs.language_inactive |
      | a blog editor           | SetBlogPostTranslation         | blog post        | es     | the es text of blog-posts.editor.tabs.language_inactive |

  Scenario: The blog post editor shows a refused translation as a general error at the top of the form
    Given a blog editor editing a blog post in the editor with an engaged "de" tab
    And "de" is deactivated after the editor read the active languages but before the "de" translation is written
    When the blog editor saves the post
    Then a danger callout at the top of the form shows the blog-posts.editor.tabs.language_inactive message
    And no language tab is marked with an error
    And the post's stored content is unchanged, the default language included
    And the editor stays on the form

  Scenario: A new translation write action that skips the shared helper is caught
    Given a developer adds a new "Set...Translation" action under app/Actions
    And that action does not use ResolveActiveStoreLanguage
    When the test suite runs
    Then the structural guard test fails naming that action
```

The `SetBlogTagTranslation` and `SetProductTranslation` rows appear only in the first two outlines:
they already refuse and are already tested in their own stories; here they only prove that the
refactor onto the helper keeps them identical on the two highest-value cases. Their existing tests
must stay green **without edits**.

The editor scenario's race window is narrow (`save()` re-reads the active languages at click time,
D-14 of 0079); the component test provokes it with a test double (§3 case 11), not a real race.

## Files to create/modify

### Create

- `app/Actions/Translations/ResolveActiveStoreLanguage.php` — the shared helper (D-5). Shape:

  ```php
  /**
   * @throws ValidationException keyed $errorKey with $message when the language is not active
   */
  public function __invoke(StoreLanguage $language, string $errorKey, string $message): StoreLanguage
  {
      $activeLanguage = StoreLanguage::query()->active()->find($language->id);

      if ($activeLanguage === null) {
          throw ValidationException::withMessages([$errorKey => $message]);
      }

      return $activeLanguage;
  }
  ```

  It authorizes nothing: it is a collaborator invoked only by an already-authorized action (the
  `SyncProductGallery` / `GenerateImageConversions` collaborator pattern in
  [directory-structure/layout-conventions.md](../../../docs/conventions/directory-structure/layout-conventions.md)).
  Constructor-injected into each caller, resolved from the container. **Not `final`** (unlike its
  folder-mates): §3 case 11 binds a subclass test double of it in the container. Its docblock says
  so, so a later "tidy-up" does not add `final` and break that test.
- `tests/Feature/Translations/ResolveActiveStoreLanguageTest.php` — the helper's own contract (§3
  case 12).

### Modify

- `app/Actions/Blog/SetBlogCategoryTranslation.php`,
  `app/Actions/ProductCategories/SetProductCategoryTranslation.php` — inject the helper and call it
  **directly after** the (logged) authorize step, with `"names.{$language->id}"` and the domain
  message. These two actions have no entity re-read today and this story adds **none** (a deleted
  category is task [0098](../0098-translation-write-actions-deleted-entity-race-backend.md)'s concern
  for the product category); every downstream step (trim/validate/`setTranslation`) uses the **returned**
  instance, never the passed one ([model-instance-trust.md](../../../docs/security/model-instance-trust.md)).
- `app/Actions/Blog/SetBlogPostTranslation.php` — same, with the post-level key `translation` and
  `blog-posts.editor.tabs.language_inactive`. Order: authorize → post re-read → **active language
  re-read** → trim/sanitize/size-check/validate → write. The `titles.{id}` / `bodies.{id}` keys are
  then derived from the **returned** instance. Its docblock line "Like its siblings it does NOT filter
  on `is_active`" is replaced.
- `app/Actions/Blog/SetBlogTagTranslation.php`, `app/Actions/Products/SetProductTranslation.php` —
  replace the inline `StoreLanguage::query()->active()->find()` + throw with the helper; same key,
  same message, same order, no behaviour change.
- `app/Livewire/BlogPosts/Editor.php` (catch block, ~line 387) — verify that the `translation` key
  passes through `rekeyErrors()` unchanged (it is not in `RE_KEYED_ERRORS` and is not `title`/`body`)
  and that `firstErroringLanguageId()` leaves the current tab when no `titles.*`/`bodies.*` key is
  present. Expected: no code change; change it only if verification fails, and record the result here.
- `resources/views/livewire/blog-posts/editor.blade.php` — add a `<flux:callout variant="danger">`
  (icon per the existing `no_categories` callout's style) rendered only `@error('translation')`, at the
  top of the form, above the language tabs, with a `data-test` hook. No hand-rolled Tailwind alert.
- `lang/en/blog.php`, `lang/es/blog.php` — `language_inactive` inside the existing
  `categories.index.tabs` array.
- `lang/en/products.php`, `lang/es/products.php` — `language_inactive` inside the existing
  `categories.index.tabs` array. Wording identical to `blog-tags.index.tabs.language_inactive`.
- `lang/en/blog-posts.php`, `lang/es/blog-posts.php` — `language_inactive` inside the existing
  `editor.tabs` array, post-level wording (**D-11**): en "One of this post's languages is no longer
  active for the store. Reload the page and save again." / es "Uno de los idiomas de esta entrada ya
  no está activo en la tienda. Recarga la página y vuelve a guardar."
- `tests/Feature/Blog/SetBlogCategoryTranslationTest.php` (~line 381),
  `tests/Feature/ProductCategories/SetProductCategoryTranslationTest.php` (~line 340) — reverse and
  rename `an inactive store language is still writable through the action` to
  `an inactive store language is refused and nothing is written to it`; add §3's cases.
- `tests/Feature/Blog/SetBlogPostTranslationTest.php` (~line 249) — reverse and rename
  `an inactive store language is still writable, since only tab rendering filters on is_active`;
  add §3's cases.
- A Livewire component test for the editor callout — in
  `tests/Feature/Blog/BlogPostEditorTranslationValidationTest.php` (the file that already owns the
  editor's translation refusals).
- `tests/Unit/ArchitectureTest.php` — the structural guard (§3 case 10).
- Any fixture/test elsewhere that writes through one of these actions into an inactive language
  (risk R3) — fix here. Grep each action's test file and the editor/index tests for `inactive()`.

### Deliberately not touched

- `BlogCategories\Index` / `ProductCategories\Index` and their views: both `save()` iterate active
  languages only. The implementer **verifies** that in each catch block a `ValidationException` from
  the action is re-keyed and rethrown, never swallowed; a change is needed only if that verification
  fails, recorded here.
- `BlogTags\Index`, `Products\Editor` and their views: their actions' behaviour is unchanged.
- The tests of 0075 and 0076b (`SetBlogTagTranslationTest`, `SetProductTranslationTest`): they must
  stay green unedited.
- `App\Actions\Translations\SetTranslation` (the primitive, story 0070) stays unguarded; seeders use it.
- The read side (`translated()` and fallbacks).

## 3. QA test cases / validation scenarios

Feature tests, direct action calls resolved from the container, `RefreshDatabase`, a seeded default
language, actor acting with the permission and `forgetCachedPermissions()` before negative cases.
"Category files" = the two category action test files; "post file" = `SetBlogPostTranslationTest`.

| # | Case | Where | Notes |
|---|------|-------|-------|
| 1 | Inactive language refused: `errors()` has the expected key (`names.{id}`, or `translation` for the post) with the domain message; translation row count 0 | category files, post file | the reversed tests; the post asserts no `titles.*`/`bodies.*` key |
| 2 | Stale instance not trusted | category files, post file | `StoreLanguage::whereKey($id)->update(['is_active' => false])` without refreshing, then `StoreLanguage::flushDefaultStoreLanguage()` explicitly (a raw update fires no model events). **Highest value.** The tag/product actions are covered by their own suites. |
| 3 | Unauthorized + inactive → `AuthorizationException`, logged exactly once, nothing written | category files, post file | proves order |
| 4 | Blank content + inactive → only the language error | category files (blank name), post file (blank title) | proves order vs validation |
| 5 | Active non-default and active default still writable | category files, post file | likely already covered — reuse |
| 6 | Existing translation of the now-inactive language unchanged after refusal | category files, post file | |
| 7 | Reactivated language writable again | category files, post file | |
| 8 | Message localized en/es; it contains neither the literal error key (`names.{id}` for the categories, `translation` for the post) nor the language id; it is not the untranslated lang key | one dataset over locales × the three changed actions | not duplicated per file |
| 9 | `translated()` of an inactive language's stored row unchanged | one test, one model | cheap regression |
| 10 | Structural guard: every `app/Actions/**/Set*Translation.php` except `app/Actions/Translations/SetTranslation.php` takes `ResolveActiveStoreLanguage` (constructor parameter, checked by reflection) **and** contains no inline `StoreLanguage::query()->active()` | `tests/Unit/ArchitectureTest.php` | the guard's failure message names the offending class; discovery by glob so a new file is caught. Paired with the behavioural tests (D-6). |
| 11 | Editor callout, end to end: bind a subclass double of `ResolveActiveStoreLanguage` whose `__invoke` first deactivates the passed language (raw `update(['is_active' => false])` + `StoreLanguage::flushDefaultStoreLanguage()`) and then **delegates to the real `parent::__invoke`**, so the editor's click-time read saw the language active and the real helper refuses it. The key and message therefore come from production code, not the double (D-12). Saving shows the `data-test` callout with the message, `assertHasErrors(['translation'])`, no `titles.*`/`bodies.*` error, the active tab unchanged, no redirect, the post's stored default-language content unchanged (outer transaction rolled back) | `BlogPostEditorTranslationValidationTest.php` | plus a negative control: a normal save renders no callout |
| 12 | Helper contract: active → returns a fresh instance (not the passed object); inactive → `ValidationException` keyed with the passed key and message; unknown id → same refusal | `ResolveActiveStoreLanguageTest.php` | |

**Not tested here:** layer-1 component tests of the category/tag/product screens (they already prove
forged keys never reach the action), the `SetTranslation` primitive, a default+inactive language
(`RemoveStoreLanguage` forbids it). **No browser test** (D-9). Check that no browser/journey test
removes a language then saves relying on the old behaviour.

## Expected outcome

Calling any of the five `Set*Translation` actions with an inactive store language — fresh or stale
instance — throws a `ValidationException` and writes nothing: on `names.{id}` for the four name-based
actions, on the post-level `translation` key for `SetBlogPostTranslation`, which the blog post editor
shows as a danger callout at the top of the form. All five go through one shared
`ResolveActiveStoreLanguage`; active languages behave exactly as before; a sixth `Set*Translation`
action that skips the helper fails the suite.

## Acceptance criteria

- [ ] `ResolveActiveStoreLanguage` exists in `app/Actions/Translations/`, authorizes nothing, and is used by all five `Set*Translation` actions; none keeps an inline active re-read.
- [ ] All five actions refuse an inactive language with `ValidationException` and write nothing: `names.{id}` for blog category, product category, blog tag and product; `translation` for blog post.
- [ ] The refusal uses a fresh `active()` re-read, never the passed instance, and every downstream step uses the returned instance (stale-instance tests green).
- [ ] Order is authorize → entity re-read (only in the tag, product and post actions, which already have one) → active language re-read → validate → write; the two category actions call the helper directly after authorize and gain no entity re-read (cases 3 and 4 green).
- [ ] `language_inactive` exists in `lang/en` and `lang/es` under `blog.categories.index.tabs`, `products.categories.index.tabs` (wording identical to 0075) and `blog-posts.editor.tabs` (post-level wording, D-11).
- [ ] `ResolveActiveStoreLanguage` is not `final`.
- [ ] The blog post editor shows the refusal as a danger `<flux:callout>` at the top of the form, marks no tab with an error, and saves nothing (case 11 green).
- [ ] `SetBlogTagTranslationTest` and `SetProductTranslationTest` pass unedited.
- [ ] Active default and non-default languages remain writable; reads are unchanged.
- [ ] The three "still writable" tests are reversed and renamed; no other test relies on writing an inactive language.
- [ ] The structural guard exists and passes for the five current actions.
- [ ] The `BlogCategories\Index`, `ProductCategories\Index` and `BlogPosts\Editor` catch-block verifications are recorded in this file.

## Definition of Done

- [ ] Tests written and green (iteration filter, then the **full** suite unscoped).
- [ ] Pint unscoped and Larastan level 7 clean.
- [ ] Code reviewed (`code-reviewer`).
- [ ] No security findings (`appsec-auditor`).
- [ ] Documentation updated (`docs-keeper`):
  - grep `docs/` for statements that an inactive language is writable through these actions, at least
    `docs/api/blog.md` line ~49 (the `SetBlogTagTranslation` bullet saying the category siblings accept
    an inactive language and that 0087 "proposes aligning them") and line ~120 (the editor save path:
    "It does **not** refuse an inactive language itself ... deferred to story 0087"), and correct them;
  - the footer of `docs/architecture/authorization.md` (it says the category siblings accept an
    inactive language and that 0087 proposes aligning them);
  - `docs/security/livewire-authorization/action-level-authorization.md` ~line 58 (the
    `SetBlogTagTranslation` paragraph contrasting it with the non-refusing siblings) and its footer
    (~line 74, "records residual L-1 (no inactive-language check, story 0087)");
  - `docs/conventions/directory-structure/actions.md` ~line 158 ("siblings do not; story 0087" and
    "it does not yet refuse an inactive l[anguage]"), and add `ResolveActiveStoreLanguage` to its
    `Actions/Translations/` entry;
  - record the rule (every `Set*Translation` write action refuses an inactive language through the
    shared helper) where translation write actions are documented, and the editor callout in the blog
    posts editor docs;
  - update the Epic 5 digest lines `_digests/epic-5.md` ~70 and ~139 (siblings non-compliant / L-1
    deferred here) and ~114 (the guard covers `SetProductTranslation` through the helper). Line ~118's
    L-2 reference was already re-pointed to task 0098 by `product-owner` on 2026-11-13.
- [ ] Acceptance criteria met.

## 4. Documented functional decisions

- **D-1 Refuse everywhere.** The actions refuse an inactive language for every caller; seeders and
  bulk tooling that legitimately need it use the `SetTranslation` primitive.
- **D-2 `ValidationException`, not `AuthorizationException` / `ModelNotFoundException`.** The actor
  is authorized; the input is invalid. The four name-based actions key it `names.{id}` to match their
  components' field keys and 0075/0076b. `SetBlogPostTranslation` uses a post-level key (D-8).
- **D-3 Re-read, never trust the instance** (`StoreLanguage::query()->active()->find($language->id)`,
  inside the helper only); callers use the returned instance afterwards.
- **D-4 Order:** authorize → entity re-read → language check → content validation → write. The
  entity re-read exists only where the action already has one (tag, product, post); the two category
  actions go straight from authorize to the language check and gain no re-read here (a deleted
  entity is out of scope, D-13). Blank content on an inactive language reports only the language
  error.
- **D-5 Shared helper `App\Actions\Translations\ResolveActiveStoreLanguage`** (user decision **Q-1**,
  2026-11-13; replaces the earlier "inline guard per action"). Name and place, per
  [directory-structure/layout-conventions.md](../../../docs/conventions/directory-structure/layout-conventions.md)
  and [directory-structure/actions.md](../../../docs/conventions/directory-structure/actions.md):
  - **`Actions/Translations/`, not `Actions/StoreLanguages/`.** The rule is "a class serving two
    areas belongs to the concern, not to whichever area called it first"; its five callers span
    `Blog/`, `ProductCategories/` and `Products/`. `Translations/` is already the named cross-cutting
    folder for "the per-store-language content mechanism every translatable entity's actions reuse"
    (story 0070), beside `SetTranslation`. `StoreLanguages/` holds the catalog module's own
    self-authorizing writers of `store_languages`; this helper writes nothing there and authorizes
    nothing. No `Shared/`/`Common/` folder.
  - **`Resolve…`** follows the existing `ResolveSalesBuckets` verb for a class that returns a
    resolved value; it is an invokable action like its folder-mates.
  - **Signature takes `$errorKey` and the already-translated `$message`** because the five callers
    use different keys and per-domain messages (D-7, D-8).
  - **Scope:** language resolution only (D-13).
- **D-6 Structural guard checks helper usage, not a source string.** Reflection on each
  `Set*Translation` constructor for a `ResolveActiveStoreLanguage` parameter, plus a negative text
  check that no inline `StoreLanguage::query()->active()` copy remains. A constructor parameter can
  still be injected and not called; the behavioural tests (cases 1–2 for the three changed actions,
  the existing suites for the other two) close that gap. Pest `arch()` cannot select classes by
  filename pattern, so this is a plain test in `ArchitectureTest.php`.
- **D-7 Per-domain lang keys** (`blog.categories…`, `products.categories…`, `blog-posts.editor…`)
  rather than one shared key, following the existing per-screen `tabs` arrays.
- **D-8 `SetBlogPostTranslation` refuses on a post-level key; the other four keep `names.{id}`**
  (user decision **Q-2 (a)**, 2026-11-13). The editor never renders an inactive language's tab, so a
  `titles.{id}`/`bodies.{id}` error would mark nothing visible; a general `translation` error shown as
  a danger `<flux:callout>` at the top of the form is visible. **Conscious inconsistency:** the four
  name-based actions keep `names.{id}`, and their screens keep today's behaviour, where the refusal
  message may be invisible when the tab is gone (R4). Aligning them is not in scope.
- **D-9 Component test, no browser test.** The refusal is only reachable through a race between the
  editor's click-time active re-read and the action's own re-read; a browser test can only provoke it
  with a container double, which a Livewire component test does more cheaply and deterministically.
  The callout is a stock Flux component with no JavaScript.
- **D-10 The editor's outer transaction is relied on, not changed.** A refused translation rolls back
  the default-language write too (0079 D-14), so the whole save is refused, not partially applied.
- **D-11 Post-level callout wording** (user decision **Q-3 (a)**, 2026-11-13): en "One of this
  post's languages is no longer active for the store. Reload the page and save again." / es "Uno de
  los idiomas de esta entrada ya no está activo en la tienda. Recarga la página y vuelve a guardar."
  It says what happened and what to do without claiming what was written. The four name-based
  actions keep the sibling wording "This language is no longer active for the store."
- **D-12 Case 11 is end to end.** Its double deactivates the language and then delegates to the real
  helper, so the editor test proves both the display and the real key/message, not a key the double
  invented. Case 1 in the post file still proves the key on a direct action call.
- **D-13 0076b residual L-2 is out of scope** (user decision **Q-4 (a)**, 2026-11-13). A product or
  product category deleted between the action's read and the write raising an FK 1452 500 is an
  entity race on a different table, not language resolution. The user asked for a separate task:
  [0098](../0098-translation-write-actions-deleted-entity-race-backend.md), which depends on this story
  (same action files).

## 5. Dependencies, risks, open technical questions

### Dependencies

- None pending. Builds on 0068 D5 (removal = deactivation), 0070 (primitive), 0071 and
  [0073](0073-blog-categories-language-tabs-ui.md) (category actions),
  [0075](0075-blog-tags-language-tabs-ui.md) (tag action, wording source),
  [0076b](0076b-product-translation-write-action-backend.md) (product action) and
  [0079](0079-blog-post-editor-language-tabs-ui.md) (post action and editor) — **all done**.
  [0077](0077-product-editor-language-tabs-ui.md) is unaffected (its action's behaviour is unchanged).

### Supersedes

This story deliberately reverses criteria that earlier, closed stories accepted:

- 0071 (~line 567): "An **inactive** store language is still writable through the action — no defensive `is_active` guard (0070 **D-6**)."
- 0073 (~lines 563–565): the same criterion for `SetBlogCategoryTranslation`.
- 0079 (~line 675 and ~line 1095): the same criterion and the D-5 warning that "the write path must
  not inherit this filter" for `SetBlogPostTranslation`.

Neither cited decision forbids the refusal: 0070 **D-6** only constrains the **read** side (fallbacks
and `translated()`), which this story leaves unchanged; 0068 **D5** only requires the rows to be kept,
and refusing a write keeps them. The closed files are not edited; this section is the record.

### Risks

- **R1** A legitimate flow writes an inactive language through these actions. Mitigation: grep all
  callers (seeders, tests, commands); move any to the primitive (D-1).
- **R2** An administrator deactivates a language while another is editing: the save is refused.
  Accepted (matches 0075); on the post editor the whole save is refused with the callout (D-10).
- **R3** Fixtures using an inactive language as a convenient second language break. Fix in this story.
- **R4** On the category, tag and product screens the error is invisible when the tab is hidden.
  Accepted: forged/race path only (D-8).
- **R5** The refactor of the tag and product actions changes their behaviour. Mitigation: their
  suites must pass unedited.

### Open technical questions

- Implementer: record the three catch-block verifications here.

## 6. Questions for the user (all closed)

- **Q-3 — Wording of `blog-posts.editor.tabs.language_inactive`.** Closed 2026-11-13: user chose
  **(a)**, the post-level wording now recorded in **D-11**. (Option (b), reusing "This language is no
  longer active for the store.", was rejected as unclear outside a tab.)
- **Q-4 — 0076b residual L-2 (product deleted between re-read and save → FK 1452 500).** Closed
  2026-11-13: user chose **(a)**, out of scope here (**D-13**), and asked for a separate task, created
  as [0098](../0098-translation-write-actions-deleted-entity-race-backend.md). The Epic 5 digest line
  (~118) that assigned L-2 to "the shared helper backlogged by 0087 D-5" now points to 0098.

## Phase 2 record

**Status: ✅ APPROVED by `code-reviewer` on the re-review (2026-11-13, no blockers).** The first
INVEST review rejected the story; `product-owner` rewrote it the same day (record below), and the
re-review approved it with six non-blocking findings, folded in afterwards (last list below). The user
then closed **Q-3 (a)** and **Q-4 (a)**. The file moved to `in-progress/` for Phase 3 (step 0), claimed
by `worktree-0087-translation-write-actions-refuse-inactive-language`.

**First review: ❌ REJECTED by `code-reviewer`.**

Findings and resolution:

- **B-1 (blocking) — `SetBlogPostTranslation` missing.** 0079 deferred its check here (0079 Phase 4
  L-1; `docs/api/blog.md` ~49, ~120) and `SetBlogPostTranslationTest.php:249` pins the opposite.
  Resolved: action added to *Modify*, test reversal listed, `blog-posts.editor.tabs.language_inactive`
  added, blog post rows in the Gherkin outlines and QA cases 1–4 and 6–8, editor catch block added to
  the verifications; the stale "Amend 0079/0076b" backlog section is removed.
- **B-2 (blocking) — five actions, not three.** Resolved: description table, acceptance criteria,
  expected outcome and the structural guard name all five.
- **N-1 — no supersedes note.** Resolved: *Supersedes* section (0071, 0073, 0079; 0070 D-6 and 0068
  D5 explained).
- **N-2/N-3 — stale statements and line hints.** Resolved: 0075, 0076b and 0079 marked done;
  "in progress on another branch" removed; hints set to ~381, ~340 and ~249.
- **N-4 — catch-block wording.** Resolved: "a `ValidationException` from the action is re-keyed and
  rethrown, never swallowed".
- **N-5 — actor in the read scenario.** Resolved: every scenario names an actor; blog records use
  "a blog editor" (`blog.edit`), product records "a catalog administrator" (`products.edit`).
- **N-6 — over-broad guard.** Resolved: the guard checks use of the shared helper (D-6).
- **N-7 — docs sweep.** Resolved: `docs/api/blog.md` ~49 and ~120 and the `authorization.md` footer
  added to the DoD.

User decisions taken on the rejection (2026-11-13, binding): **Q-1** extract the shared helper now,
used by all five actions, guard checks its use (D-5, D-6; replaces the former inline-copy D-5).
**Q-2 (a)** only `SetBlogPostTranslation` uses a post-level key shown as a danger callout; the other
four keep `names.{id}` (D-8). Consequences: the type widened to full-stack without a split (see
*Type*), `frontend-expert`/`frontend-qa` joined, and the tag and product action files are now touched
(behaviour unchanged). New open questions **Q-3** and **Q-4** (§6) were non-blocking for re-review.

**Re-review: ✅ APPROVED by `code-reviewer`.** Non-blocking findings, folded in on 2026-11-13:

1. The category actions call the helper directly after authorize and add **no** entity re-read —
   stated in *Modify*, **D-4** and the order acceptance criterion.
2. The helper must not be `final` (case 11 doubles it) — stated in its *Create* entry and an
   acceptance criterion.
3. Case 11 alone would only prove the display — resolved by making its double deactivate the language
   and then delegate to the real helper (**D-12**); case 1 still proves the key on a direct call.
4. Docs sweep extended: `docs/security/livewire-authorization/action-level-authorization.md` ~58 and
   footer ~74, `docs/conventions/directory-structure/actions.md` ~158, epic-5 digest ~70, ~114, ~139
   (`docs-keeper`); digest ~118 (L-2) re-pointed to 0098 by `product-owner`.
5. Localization assertion made precise: the message contains neither the literal error key
   (`names.{id}` / `translation`) nor the language id (Gherkin outline and case 8).
6. Accepted as is.

User decisions after approval (2026-11-13): **Q-3 (a)** → **D-11**; **Q-4 (a)** → **D-13** and new
task 0098.

## Phase 3 record

TDD completed on 2026-11-13: `backend-qa` wrote the tests red (180 tests, 20 failing for the missing helper / missing refusal), `backend-expert` and `frontend-expert` implemented the green step. The eight story test files pass (180 of 180, 456 assertions).

## Phase 4 record

**Phase 4 security audit: APPROVED by `appsec-auditor` (2026-11-13).**

- **Scope:** `ResolveActiveStoreLanguage` (new), the five `Set*Translation` actions, the `BlogPosts\Editor` callout and its error-key passthrough, the en/es lang keys and the `ArchitectureTest` structural guard.
- **Verified:** authorization precedes the language check in all five actions, so a forbidden actor learns nothing about language state; an inactive and a missing language get the same refusal; only the freshly re-read instance is used after the check; the refusal message is escaped and constant; the editor save rolls back completely on a refusal; no other caller of the `SetTranslation` primitive accepts a caller-supplied language.
- **Blocking findings:** none.
- **L-1 (Low, accepted residual):** an unlocked check-then-write race with `RemoveStoreLanguage` can still let one write through (CWE-367); the result is a row in an inactive language, which the read side already hides (0068 D5). To be recorded by `docs-keeper`.
- **L-2 (Low, follow-up):** the structural guard finds actions by filename only; a name-independent rule (an action injecting `SetTranslation` and taking a `StoreLanguage` parameter must inject the helper) would catch a renamed action. Not implemented in this story.
- **I-3 to I-7 (informational):** no action required; `docs-keeper` should add one short section on the rule to `docs/security/model-instance-trust.md`.

## Phase 5 record

**Phase 5 final code review, first pass: REJECTED by `code-reviewer` (2026-11-13), two small items.**

- Gates: Pint `--test` passed; Larastan level 7 passed (0 errors); full suite unscoped in directory chunks green: 6162 tests, 6154 passed, 8 skipped, 0 failed (Feature/Blog+Products 1928; Unit+Orders+Dashboard 1736; Models/Users/Policies/Customers/StoreLanguages/Shipping/Auth/Seeders 1166; remaining Feature folders 1042; Browser 290).
- Verified: helper placement, not `final`, authorizes nothing; all five actions use it with the right keys and messages; order authorize, entity re-read, language, validate, write; the returned instance is used downstream; editor callout end to end (case 11); the 0075/0076b suites unedited and green; structural guard green.
- B-1 (fixed): the `SetBlogPostTranslation` class docblock still said it "does NOT filter on `is_active`"; replaced.
- B-2 (fixed by recording): the catch-block verifications, all re-key-and-rethrow or passthrough with no code change needed:
  - `app/Livewire/BlogCategories/Index.php:331-336`: the action's `ValidationException` is re-keyed (`rekeyDefaultLanguageErrors`) and rethrown, never swallowed.
  - `app/Livewire/ProductCategories/Index.php:345-350`: same.
  - `app/Livewire/BlogPosts/Editor.php:387-392`: `rekeyErrors()` passes `translation` through unchanged (not in `RE_KEYED_ERRORS`, not `title`/`body`); `firstErroringLanguageId()` (line 441) keeps the current tab when no `titles.*`/`bodies.*` key is present.

**Phase 5 final code review, re-review: APPROVED by `code-reviewer` (2026-11-13).** B-1 is fixed: the `SetBlogPostTranslation` class docblock (lines 25-27) now says it refuses an inactive store language through `ResolveActiveStoreLanguage` on the post-level `translation` key (D-8), matching the injected dependency and the `@throws` contract. B-2 is confirmed: the recorded catch-block ranges are accurate and none needed a code change. Pint `--test` passed; the full suite and Larastan were not re-run because the only change since the first pass is a docblock edit, and the first-pass results stand. The story may proceed to Phase 6.

## Provenance

The divergence (tag action refuses inactive languages, category actions accept them) was found
while implementing 0075; on 2026-10-27 the project owner chose to fix it in this separate story.
Phase 1 (Three Amigos: product-owner, backend-expert, backend-qa) on 2026-10-27; rewritten after the
Phase 2 rejection on 2026-11-13 with frontend-expert and frontend-qa added; Phase 2 approved on the
re-review the same day.
