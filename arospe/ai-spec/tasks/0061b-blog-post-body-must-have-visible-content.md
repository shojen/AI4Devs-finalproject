# [0061b] Blog posts — a body must actually show something

## Description
Story [0061](done/0061-blog-posts-core-crud-backend.md) refuses a `Published` or `Scheduled` post with **no**
body, but "no body" is judged on the **string**, so a body that *renders as nothing* passes. The WYSIWYG editors
in common use emit exactly such markup when an editor empties the field: `<p><br></p>`, `<br>`, `<p></p>`,
`&nbsp;`, a run of zero-width characters, an empty `<ul><li></li></ul>`, `<a href="…"></a>`, or an empty
`<div style="display:none"></div>`. Each is non-blank as text, survives the sanitizer, and passes `required`,
so a "published" post can show an empty page — the gap 0061 recorded as *"Published without a body is refusable
in the tests but not in practice"*.

**This story makes the rule about what a reader would actually see**, not about what the string contains: after
sanitizing, a body **that renders no visible text and no image is treated as no body at all.** That reuses
0061's existing behaviour with no new rule: for `Published`/`Scheduled` it is refused by the existing `required`
body rule, and for a `Draft` it is stored as `null` (see **OQ-1**), so "no body" has exactly one representation.

Backend only.

Raised by the human owner while closing 0061 (2026-09-24). Sibling of
[0061a](done/0061a-blog-post-publish-with-future-date-schedules.md).

## Type
backend | includes database-expert: **no**

## Three Amigos participants
**Not yet convened.** A Phase 1 *draft* from a direct product instruction; the debate and Phase 2 INVEST
validation have not run.

## Gherkin

```gherkin
Feature: A post body must show something

  Scenario Outline: Publishing a post whose body renders nothing is refused
    Given a blog editor
    When they save a post as published with the body "<body>"
    Then the save is refused with a validation message
    And no post is added

    Examples:
      | body                                          |
      | <p><br></p>                                   |
      | <br>                                          |
      | <p></p>                                       |
      | <p>&nbsp;</p>                                 |
      | <p>   </p>                                    |
      | <div style="display:none"></div>              |
      | <ul><li></li></ul>                            |
      | <h2></h2>                                     |
      | <a href="https://example.com"></a>            |
      | <p>&#8203;</p>                                |
      | <!-- just a comment -->                       |

  Scenario: Scheduling a post whose body renders nothing is refused
    Given a blog editor
    When they save a post as scheduled with the body "<p><br></p>"
    Then the save is refused with a validation message

  Scenario: A body with visible text is accepted
    Given a blog editor
    When they save a post as published with the body "<p>Botas de invierno</p>"
    Then the post is saved

  Scenario: A body that is only an image is accepted
    Given a blog editor
    When they save a post as published with a body containing only an image from the shared gallery
    Then the post is saved

  Scenario: Text hidden by markup the sanitizer removes is judged as what remains
    Given a blog editor
    When they save a post as published with the body '<div style="display:none">hola</div>'
    Then the post is saved with the visible text "hola"

  Scenario: Promoting a draft whose body renders nothing is refused
    Given a blog editor, with a draft post whose body is "<p><br></p>"
    When they change that post's status to published
    Then the save is refused with a validation message
    And the post is still a draft

  Scenario: A draft whose body renders nothing is stored without a body
    Given a blog editor
    When they save a draft with the body "<p><br></p>"
    Then the post is saved as a draft
    And it carries no body
```

## Files to create/modify

| Path | What & why |
| --- | --- |
| `app/Actions/Blog/BlogBodyHasVisibleContent.php` | **New.** `__invoke(?string $sanitizedHtml): bool`, pure and side-effect free. Parses the **already-sanitized** HTML and answers true when it holds at least one visible character or one `<img>` with a non-empty `src`. Visible text = the DOM's text content, entities decoded, with every Unicode whitespace, separator and format character removed (`\s`, `\p{Z}`, `\p{Cf}`, `\p{Cc}`, which covers `&nbsp;`, `&#8203;`, `&#65279;`). Invalid UTF-8 or unparseable input counts as no content. Uses PHP's `DOM` extension, already required by the sanitizer. |
| `app/Actions/Blog/CreateBlogPost.php` | **Modify.** `cleanBody()` returns `null` when the sanitized body has no visible content. The existing `bodyRules()` then does the rest. |
| `app/Actions/Blog/UpdateBlogPost.php` | **Modify.** Same. |
| `tests/Unit/Actions/Blog/BlogBodyHasVisibleContentTest.php` | **New.** The full table above as a dataset, plus the accepted cases, plus edge cases (nested empties, entity-encoded whitespace, mixed empty and text). |
| `tests/Feature/Blog/CreateBlogPostTest.php`, `UpdateBlogPostTest.php` | **Extend.** The published / scheduled / draft / promotion scenarios end to end. |
| `docs/security/html-sanitization.md`, `docs/database/schema-blog.md` | **Modify.** One paragraph each: the visibility rule and where it runs. |

**Explicitly not touched:** `config/html-sanitizer.php` (the allow-list is **not** widened or narrowed here),
`SanitizeProductDescription`, migrations, `routes/**`, `app/Livewire/**`, `resources/views/**`.

## Tests to perform
- [ ] **Unit, every "renders nothing" example above returns false**, one dataset row each; each accepted example
      returns true. A dataset row per case, so a partial implementation cannot pass on a subset.
- [ ] Visible text mixed with empty wrappers (`<p><br></p><p>hola</p>`) is true — the rule is *any* visible content,
      not *only* visible content.
- [ ] An `<img>` with a `src` is true even with no text; an `<img>` with an empty `src` is false.
- [ ] `<div style="display:none">hola</div>` is judged **after sanitizing**: the sanitizer drops `style` and unwraps
      `<div>`, so the text survives and the body is accepted (pinned, so a future allow-list change that starts
      keeping `style` fails here and forces the visibility rule to be revisited).
- [ ] Feature: `Published` and `Scheduled` + each empty-rendering body → `ValidationException` on `body`, no row.
- [ ] Feature: promoting a draft whose stored body renders nothing → refused, and the post is still a draft.
- [ ] Feature: a `Draft` with `<p><br></p>` stores `null`, not the markup.
- [ ] Sanitize-then-judge ordering: a body whose only content is a `<script>` (dropped by the sanitizer) is refused.
- [ ] Performance guard: a body at the sanitizer's `max_input_length` is judged without error.

## Expected outcome
A post cannot be published or scheduled with a body that shows nothing, however that emptiness is spelled. A
draft cannot carry an invisible body either: it is stored as no body.

## Acceptance criteria
- [ ] `BlogBodyHasVisibleContent` exists, is pure, judges the **sanitized** value, and counts visible text or an
      `<img src>` as content.
- [ ] `Published` and `Scheduled` posts whose sanitized body has no visible content are refused on `body`, on the
      create path **and** the update path (including a draft promotion).
- [ ] A body with no visible content is persisted as `null`, never as its markup.
- [ ] The sanitizer, its config and its allow-list are unchanged.
- [ ] No migration, route, component, view or new Composer dependency.

## Definition of Done
- [ ] Tests written and green, plus the **full** suite in one isolated run.
- [ ] Pint and Larastan level 7, **unscoped**, results recorded.
- [ ] appsec-auditor points at: the check runs on the sanitized value, never on raw input; the DOM parse cannot be
      driven into external entity loading (`LIBXML_NONET`, no `LIBXML_NOENT`) or quadratic behaviour.
- [ ] Docs updated.
- [ ] **Hand-off recorded for 0063** (editor): the server is the authority. A client-side "looks empty" hint is fine
      but must not be relied on, and the editor should surface the `body` error like any other.

## Documented functional decisions

### D-1 — Judge visibility, not string emptiness *(from the product owner's instruction)*
"A body that shows nothing" is defined by what a reader would see, so `<p><br></p>`, an empty
`<div style="display:none"></div>` and their kin are all the same case as `''`.

### D-2 — Reuse the existing `required` rule by normalizing to `null`
Rather than a second rule, `cleanBody()` returns `null` for an invisible body and 0061's `bodyRules()` decides:
`required` refuses it for `Published`/`Scheduled`, `nullable` accepts it for `Draft`. One representation of "no
body", no new error message, no change to the trait's public shape. `cleanBody()` is duplicated in the two actions
today; this story is the natural moment to extract the shared part into the new action.

### D-3 — Run after the sanitizer, never before
The sanitizer is what removes `style`, `hidden`, `<script>` and comments, so judging the raw string would have to
re-implement every way of hiding content. Judging what is **left** needs only "is there text or an image".

## Dependencies, risks and open questions

### Dependencies
- **[0061](done/0061-blog-posts-core-crud-backend.md) — done.** Owns the actions and `bodyRules()`.
- **[0024a](done/0024a-product-description-html-sanitization.md) — done.** Owns the allow-list this story relies on
  *not* keeping `style`/`hidden`/`class`.
- **Conflicts with [0061a](done/0061a-blog-post-publish-with-future-date-schedules.md), [0063](0063-blog-posts-list-editor-ui.md)
  and [0065](0065-blog-post-published-notification-backend.md)** on `CreateBlogPost` / `UpdateBlogPost`; independent in
  behaviour, run them one at a time.

### Risks
- **R-1 — A hiding technique the sanitizer keeps.** Today the allow-list has no `style`, `hidden` or non-code `class`,
  so CSS-hidden text cannot survive; that is a property of the allow-list, not of this rule. The pinning test above
  is what makes a future widening loud.
- **R-2 — Visible characters that look invisible.** An image-only or symbol-only body (`•`, `—`) has visible content
  and is accepted; only whitespace, separators and format characters are discounted.
- **R-3 — No backfill.** No screen writes posts yet, so there is no existing data to correct. If a seeder or import
  ever creates one, it bypasses the actions and this rule.

### Open questions
- **OQ-1 — May a `Draft` be *saved* with a body that renders nothing?** 0061's **D-4** (human-confirmed 2026-08-27)
  lets a `Draft` be bodiless, and an editor that was typed in and then cleared emits `<p><br></p>`. The instruction
  "there must be no body that shows nothing, not even empty" can be read two ways. **Recommendation: accept the
  save but store `null` (recommended)** — the draft is still creatable, the invisible markup is never persisted, and
  an autosave of a cleared editor does not fail. *Alternative:* refuse an invisible body for a `Draft` as well, which
  reverses 0061's D-4 for the empty case and would make a draft impossible to save before anything is written. Needs the
  product owner's answer before Phase 3.
- **OQ-2 — Is an image-only body content?** Recommended **yes**, as 0061's own scenario "a post body keeps the images
  inserted from the shared gallery" implies. *Alternative:* require text as well.
