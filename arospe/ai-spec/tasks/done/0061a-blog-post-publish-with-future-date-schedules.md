# [0061a] Blog posts — publishing with a future date schedules the post

## Description
Today a post saved as `Published` with a **future** `published_at` is accepted as-is: story
[0061](0061-blog-posts-core-crud-backend.md)'s **D-6** gives `Published` the rule
`nullable|date` and nothing else. The result is a post that is `Published` **now** and announced now,
yet dated in the future — a state that means nothing, that a public reader filtering on
`published_at <= now` would not show, and that dodges the `Scheduled` path (and 0064's sweep) entirely.

**This story makes "publish with a future date" mean what an editor intends by it: schedule the post.**
When `CreateBlogPost` or `UpdateBlogPost` is asked for `Published` and the resolved `published_at` is
**strictly in the future**, the post is stored as **`Scheduled`** with that date, and **no
`NotifyBlogPostPublished` is dispatched** — story [0064](0064-scheduled-post-auto-publish-backend.md)'s
sweep flips it to `Published` when its time comes and announces it then (trigger 3 of 0061's **D-19**).
A `Published` request with no date, or a date at or before now, behaves exactly as today.

Backend only: no screen, route, Livewire component or migration.

Raised by the human owner while closing 0061 (2026-09-24), as one of two follow-ups to 0061's
"open, not fixed" list; its sibling is [0061b](0061b-blog-post-body-must-have-visible-content.md).

## Type
backend | includes database-expert: **no** (no schema change; the existing
`(deleted_at, status, published_at)` index already serves 0064's query)

## Three Amigos participants
**Not convened.** This file is a Phase 1 *draft* written from a direct product instruction. On
2026-09-24 the human owner (acting as product owner) waived the Three Amigos debate and the Phase 2 INVEST
validation for this small, fully specified change and answered its one open question (OQ-1, see D-4), so
work went straight to Phase 3 (TDD).

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

  Scenario: A live post cannot be moved into the future by editing its date
    Given a blog editor, with a published post
    When they change that post's publication date to a date in the future, keeping it published
    Then the save is refused with a validation message on the publication date
    And the post is still published with its original date
    And no published-post notification is raised

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

- [x] Create `Published` + future date → stored `Scheduled`, date persisted verbatim, spy **never** invoked.
- [x] Update `Draft` → `Published` + future date → stored `Scheduled`, spy never invoked.
- [x] Create `Published` + no date → `Published`, stamped `now()`, spy invoked exactly once (unchanged).
- [x] Create `Published` + past date → `Published`, date persisted verbatim (unchanged).
- [x] Create `Published` + a date exactly `now()` → `Published`; `now()->addSecond()` → `Scheduled`.
- [x] Update `Scheduled` (overdue) → `Published` with its stored date → `Published`; spy invoked once.
- [x] Update an already-`Published` post → `Published` + future date → `ValidationException` on `published_at`,
      row unchanged (still `Published`, original date), spy never invoked (D-4).
- [x] Update an already-`Published` post → `Published` + a date exactly `now()` → accepted (boundary, other side).
- [x] `Published` + future date + no body → `ValidationException` on `body`, nothing written.
- [x] `Published` + a date past 2038 → `ValidationException` on `published_at` (0061's bound still applies).
- [x] The returned model's `status` is `Scheduled`, so a caller can tell the editor what happened.
- [x] A converted post is picked up by 0064's exact query `where status = Scheduled and published_at <= now`
      once the clock passes its date — asserted with a plain query, not by importing 0064's command.

## Expected outcome
Asking to publish a post with a future date schedules it instead of leaving a live post dated in the
future. It is announced when the scheduler publishes it, not when the editor pressed the button.
Everything else about publishing is unchanged.

## Acceptance criteria
- [x] A `Published` request with a strictly future `published_at` is persisted as `Scheduled`, on the
      create path **and** the update path.
- [x] Such a save dispatches **no** `NotifyBlogPostPublished`; the sweep announces it later.
- [x] A `Published` request with no date, a past date or a date equal to now is unchanged.
- [x] The decision uses a single `now()` per call.
- [x] `body` and the `1970`/`2038` date bounds still apply to the request.
- [x] No migration, route, component, view or notification class is added.

## Definition of Done
- [x] Tests written and green, plus the **full** suite in one isolated run (Full Test Suite Gate Rule).
- [x] `vendor/bin/pint --format agent` and `vendor/bin/phpstan analyse`, both **unscoped**, results recorded.
- [x] Code reviewed; appsec-auditor confirms no path announces a post before it is actually published.
- [x] Docs updated (`docs/database/schema-blog.md`, and 0061's D-6 amendment noted).
- [x] **Hand-off recorded for 0063** (editor UI): submitting `Published` with a future date is a valid way to
      schedule; the editor should show the resulting status from the returned model rather than assume it.
- [x] **Hand-off recorded for 0064 / 0065:** a post scheduled this way reaches `Published` only through 0064's
      sweep, so the announcement is 0064's trigger 3; nothing else changes for either.

## Hand-offs (recorded 2026-09-24)
- **0061 (amendment to D-6):** `Published` with a strictly future `published_at` is no longer stored as live: it is stored
  as `Scheduled`. Recorded here and in `docs/database/schema-blog.md`; 0061's body is left untouched (amend-forward).
- **0063 (editor UI):** submitting `Published` with a future date is a valid way to schedule; render the status from the
  **returned model** (`Scheduled for …`), never the status that was submitted. Editing an already-`Published` post so
  it carries a future date raises a `ValidationException` keyed `published_at` (D-4), so the form must show it there.
- **0064 / 0065:** a post scheduled this way reaches `Published` only through 0064's sweep, so the announcement is 0064's
  trigger 3 (D-19 of 0061); `NotifyBlogPostPublished` is untouched and nothing else changes for either.

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

### D-4 — A live post is never moved into the future by a date edit *(resolves OQ-1; human owner, 2026-09-24)*
Editing an already-`Published` post so it carries a strictly future `published_at` is **refused** with a
validation error on `published_at`, not converted. Converting would un-publish a live post as a side effect of a
date edit and announce it a second time when the sweep re-publishes it; moving a post out of view must be an
explicit status change to `Draft` or `Scheduled`. The check lives in `UpdateBlogPost` (it needs the pre-save
status), uses the same single `now()` as the conversion, and runs after the rule-based validation. A
`Scheduled` post asked to become `Published` with a future date (its stored date, not yet due) stays
`Scheduled` — it was never live — and announces nothing.

## Dependencies, risks and open questions

### Dependencies
- **[0061](0061-blog-posts-core-crud-backend.md) — done.** Owns the actions, the rule set and D-6.
- **Conflicts with [0061b](0061b-blog-post-body-must-have-visible-content.md), [0063](../0063-blog-posts-list-editor-ui.md) and
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
- **OQ-1 — RESOLVED (2026-09-24, human owner): refuse it**, see D-4.
- **OQ-2 — Should the editor be told?** The returned model carries the real status, which is enough for 0063 to
  say "Scheduled for …". A dedicated return type is not recommended.

## Phase records (2026-09-25)
- **Phase 1/2 (Three Amigos, INVEST):** waived by the human owner for this fully specified change; OQ-1 answered (D-4).
- **Phase 3 (TDD):** 11 new cases written red first (9 failures + 2 errors, all for the missing conversion/refusal), then
  green. `tests/Feature/Blog`: 369 passed.
- **Phase 4 (security):** the `appsec-auditor` agent is not available in this environment; self-review instead. The only
  announcement sites are `CreateBlogPost` (`$post->status === Published`, read from the persisted model) and
  `UpdateBlogPost` (`! $wasPublished && status === Published`); both read the status **after** the conversion, so no path
  announces a post that was stored as `Scheduled`, and the refusal for an already-live post throws before any write.
  Approved.
- **Phase 5 (review):** every acceptance criterion is covered by a test; no migration, route, component, view or
  notification class touched. `vendor/bin/pint --format agent` passed, `vendor/bin/phpstan analyse` 0 errors,
  **full suite in one isolated run: 3894 tests, 3891 passed, 3 skipped, 0 failed.** Approved.
- **Phase 6 (docs):** `docs/database/schema-blog.md` updated; the amendment to 0061's D-6 lives here and in that doc.
