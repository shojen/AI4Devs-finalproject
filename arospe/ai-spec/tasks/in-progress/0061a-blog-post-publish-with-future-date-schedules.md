# [0061a] Blog posts — publishing with a future date schedules the post

## Description
Today a post saved as `Published` with a **future** `published_at` is accepted as-is: story
[0061](../done/0061-blog-posts-core-crud-backend.md)'s **D-6** gives `Published` the rule
`nullable|date` and nothing else. The result is a post that is `Published` **now** and announced now,
yet dated in the future — a state that means nothing, that a public reader filtering on
`published_at <= now` would not show, and that dodges the `Scheduled` path (and 0064's sweep) entirely.

**This story makes "publish with a future date" mean what an editor intends by it: schedule the post.**
When `CreateBlogPost` or `UpdateBlogPost` is asked for `Published` and the resolved `published_at` is
**strictly in the future**, the post is stored as **`Scheduled`** with that date, and **no
`NotifyBlogPostPublished` is dispatched** — story [0064](../0064-scheduled-post-auto-publish-backend.md)'s
sweep flips it to `Published` when its time comes and announces it then (trigger 3 of 0061's **D-19**).
A `Published` request with no date, or a date at or before now, behaves exactly as today.

Backend only: no screen, route, Livewire component or migration.

Raised by the human owner while closing 0061 (2026-09-24), as one of two follow-ups to 0061's
"open, not fixed" list; its sibling is [0061b](../0061b-blog-post-body-must-have-visible-content.md).

## Type
backend | includes database-expert: **no** (no schema change; the existing
`(deleted_at, status, published_at)` index already serves 0064's query)

## Three Amigos participants
**Not yet convened.** This file is a Phase 1 *draft* written from a direct product instruction; the
debate (`product-owner` + `backend-expert` + `backend-qa`) and Phase 2 INVEST validation have not run.
Open questions are recorded below rather than guessed.

## Gherkin

```gherkin
Feature: Publishing a post with a future date schedules it

  Scenario: Publishing with a future date saves the post as scheduled
    Given a blog editor
    When they create a post with the status published and a publication date in the future
    Then the post is saved as scheduled, carrying that date

  Scenario: A post scheduled this way announces nothing yet
    Given a blog editor
    When they create a post with the status published and a publication date in the future
    Then no published-post notification is raised

  Scenario: Publishing an existing draft with a future date schedules it
    Given a blog editor, with a draft post
    When they change that post's status to published with a publication date in the future
    Then the post is saved as scheduled, carrying that date
    And no published-post notification is raised

  Scenario: Publishing without a date still publishes now
    Given a blog editor
    When they create a post with the status published and no publication date
    Then the post is saved as published, carrying the current moment as its publication date
    And a published-post notification is raised for that post

  Scenario: Publishing with a past date still backdates
    Given a blog editor
    When they create a post with the status published and a publication date in the past
    Then the post is saved as published, carrying that date

  Scenario: A date equal to the current moment is published, not scheduled
    Given a blog editor
    When they create a post with the status published and a publication date equal to the current moment
    Then the post is saved as published

  Scenario: A scheduled post whose date has passed is published with its own date
    Given a blog editor, with a scheduled post whose publication date has already passed
    When they change that post's status to published, keeping its date
    Then the post is saved as published, carrying that date

  Scenario: Publishing with a future date still requires a body
    Given a blog editor
    When they create a post with the status published, a future date and no body
    Then the save is refused with a validation message

  Scenario: Publishing with a future date is still bounded by what the database can store
    Given a blog editor
    When they create a post with the status published and a publication date beyond the year 2038
    Then the save is refused with a validation message
```

## Files to create/modify

| Path | What & why |
| --- | --- |
| `app/Actions/Blog/CreateBlogPost.php` | **Modify.** After validation, resolve the status once: a `Published` request whose resolved `published_at` is strictly `> now()` is persisted as `Scheduled`. Use **one** `now()` value for the whole call, so the decision and the stamping cannot disagree across a second boundary. The notification condition stays `$post->status === Published`, so it is already false for the converted post. |
| `app/Actions/Blog/UpdateBlogPost.php` | **Modify.** Same conversion. The pre-save transition guard (`$wasPublished`) is untouched, so a post that ends up `Scheduled` never announces. |
| `app/Concerns/BlogPostValidationRules.php` | **Modify only its docblocks** (`publishedAtRules()`): `Published`'s rule set is unchanged; the future-date case is resolved by the action, not refused by a rule. |
| `tests/Feature/Blog/BlogPostStatusAndPublicationDateTest.php` | **Extend.** Every case below, clock frozen, both sides of the `>` boundary. |
| `tests/Feature/Blog/BlogPostPublishedNotificationTest.php` | **Extend.** The two "announces nothing" cases. |
| `docs/database/schema-blog.md` | **Modify** the `published_at` row: `Published` + future date is stored as `Scheduled`. |
| `ai-spec/tasks/done/0061-...` | **Do not edit the body.** Record the amendment to **D-6** here and in the docs, per this project's amend-forward convention. |

**Explicitly not touched:** migrations, `routes/**`, `app/Livewire/**`, `resources/views/**`,
`NotifyBlogPostPublished` (0065), the scheduler (0064), the policy, `config/**`.

## Tests to perform
Backend only, no browser tests. Every case freezes the clock (`Carbon::setTestNow()`), and the boundary is
asserted from **both** sides: `now()` → `Published`, `now()->addSecond()` → `Scheduled`.

- [ ] Create `Published` + future date → stored `Scheduled`, date persisted verbatim, spy **never** invoked.
- [ ] Update `Draft` → `Published` + future date → stored `Scheduled`, spy never invoked.
- [ ] Create `Published` + no date → `Published`, stamped `now()`, spy invoked exactly once (unchanged).
- [ ] Create `Published` + past date → `Published`, date persisted verbatim (unchanged).
- [ ] Create `Published` + a date exactly `now()` → `Published`; `now()->addSecond()` → `Scheduled`.
- [ ] Update `Scheduled` (overdue) → `Published` with its stored date → `Published`; spy invoked once.
- [ ] `Published` + future date + no body → `ValidationException` on `body`, nothing written.
- [ ] `Published` + a date past 2038 → `ValidationException` on `published_at` (0061's bound still applies).
- [ ] The returned model's `status` is `Scheduled`, so a caller can tell the editor what happened.
- [ ] A converted post is picked up by 0064's exact query `where status = Scheduled and published_at <= now`
      once the clock passes its date — asserted with a plain query, not by importing 0064's command.

## Expected outcome
Asking to publish a post with a future date schedules it instead of leaving a live post dated in the
future. It is announced when the scheduler publishes it, not when the editor pressed the button.
Everything else about publishing is unchanged.

## Acceptance criteria
- [ ] A `Published` request with a strictly future `published_at` is persisted as `Scheduled`, on the
      create path **and** the update path.
- [ ] Such a save dispatches **no** `NotifyBlogPostPublished`; the sweep announces it later.
- [ ] A `Published` request with no date, a past date or a date equal to now is unchanged.
- [ ] The decision uses a single `now()` per call.
- [ ] `body` and the `1970`/`2038` date bounds still apply to the request.
- [ ] No migration, route, component, view or notification class is added.

## Definition of Done
- [ ] Tests written and green, plus the **full** suite in one isolated run (Full Test Suite Gate Rule).
- [ ] `vendor/bin/pint --format agent` and `vendor/bin/phpstan analyse`, both **unscoped**, results recorded.
- [ ] Code reviewed; appsec-auditor confirms no path announces a post before it is actually published.
- [ ] Docs updated (`docs/database/schema-blog.md`, and 0061's D-6 amendment noted).
- [ ] **Hand-off recorded for 0063** (editor UI): submitting `Published` with a future date is a valid way to
      schedule; the editor should show the resulting status from the returned model rather than assume it.
- [ ] **Hand-off recorded for 0064 / 0065:** a post scheduled this way reaches `Published` only through 0064's
      sweep, so the announcement is 0064's trigger 3; nothing else changes for either.

## Documented functional decisions

### D-1 — Convert, do not refuse *(from the product owner's instruction)*
"Publishing with a future date is scheduling it." The action converts the status rather than raising a
validation error. *Rejected:* refusing with "use Scheduled", which pushes a mode switch onto the editor for
an intent that is already unambiguous.

### D-2 — The conversion lives in the actions, not in a validation rule or a model event
A rule cannot change the value it validates, and a `saving` hook would run on **every** write, including
seeders and 0064's own sweep, where `Scheduled` → `Published` legitimately carries a date. The two actions are
the only writers, so the decision goes where 0061's D-6 already resolves the other status-dependent
behaviour (draft nulls the date, published stamps `now()`).

### D-3 — Strictly greater than now
A date equal to the current instant is already publishable, the same boundary 0061's `after:now` uses for
`Scheduled` (asserted from both sides).

## Dependencies, risks and open questions

### Dependencies
- **[0061](../done/0061-blog-posts-core-crud-backend.md) — done.** Owns the actions, the rule set and D-6.
- **Conflicts with [0061b](../0061b-blog-post-body-must-have-visible-content.md), [0063](../0063-blog-posts-list-editor-ui.md) and
  [0065](../0065-blog-post-published-notification-backend.md)**, which also touch `CreateBlogPost` / `UpdateBlogPost`
  (0065's file list names both). They are independent in behaviour, so any order works; run them one at a time or
  reconcile the two action files at merge.

### Risks
- **R-1 — A silent status change.** The caller asked for `Published` and got `Scheduled`. Mitigated by returning
  the model with its real status; 0063 must render that rather than the status it submitted.
- **R-2 — Double announcement.** A post scheduled this way and later flipped by 0064 is announced exactly once,
  by the sweep. If this conversion were ever missed, the same post would be announced now **and** never again;
  the two "announces nothing" tests are what pin it.

### Open questions
- **OQ-1 — What about an already-`Published` post edited to a future date?** That would un-publish a live post
  and, when the sweep re-publishes it, announce it a second time. **Recommendation: refuse it with a validation
  error on `published_at` (recommended)** — moving a live post out of view should be an explicit status change to
  `Draft` or `Scheduled`, never a side effect of a date edit. *Alternative:* convert it like a new publish,
  consistent with D-1 but with the two consequences above. Needs the product owner's answer before Phase 3.
- **OQ-2 — Should the editor be told?** The returned model carries the real status, which is enough for 0063 to
  say "Scheduled for …". A dedicated return type is not recommended.
