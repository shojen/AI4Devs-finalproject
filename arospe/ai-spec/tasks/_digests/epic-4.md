# Epic 4 decision digest (Blog)

Append-only. See [workflow.md#decision-digest-per-epic](../../../docs/workflow/agents-and-epic-digests.md#decision-digest-per-epic)
for what belongs here and what doesn't — facts and decisions a later story in this epic must not
re-derive, never the full prose of a finalized story. Created by story 0059, the second Epic 4 story
to close; story 0058's entry below was written at that point from its shipped code and is the only
backfill.

## Story 0058 — Blog categories backend

- `App\Models\BlogCategory`: `name` 255, `normalized_name` 255 **unique**, no `unique('name')`,
  `NAME_MAX_LENGTH` constant, `saving` hook in `booted()`, no `SoftDeletes` — story 0058.
- `App\Concerns\BlogCategoryValidationRules` owns `nameRules()`, `trimName()` (Unicode-aware),
  `foldedNameFits()` and `uniqueNormalisedName()`. Story 0059 **duplicated** the last three into
  `BlogTagValidationRules` rather than extract a shared concern; extracting one is an open follow-up
  that touches both shipped traits — story 0059.
- `App\Actions\Blog\{Create,Rename,Delete}BlogCategory` constructor-inject `LogRefusedPrivilegedAttempt`
  (and `NormalizeForSearch` for the first two) and authorize as their first statement with
  `target_type: 'blog_category'` — story 0058.
- **`DeleteBlogCategory` is deliberately unguarded today.** Story 0061 extends *that file in place* with
  the hard-block-with-count guard once `blog_posts` exists — story 0058.

## Story 0059 — Blog tags backend

- `App\Models\BlogTag`: `name` **100**, `normalized_name` 255 unique, `NAME_MAX_LENGTH` and
  `NORMALIZED_NAME_MAX_LENGTH` constants, no `posts()` relation until story 0061 — story 0059.
- **Measured, not assumed:** `NormalizeForSearch` folds one code point to at most **5** characters
  (U+104C → `hnaik`), so no column width up to 255 guarantees a 100-character name fits. The guarantee
  is `foldedNameFits()` refusing a folded value over 255 (51 × U+104C is the exact accepted boundary) —
  story 0059.
- `BlogTagValidationRules` has **two** methods for one field: `nameFormatRules(NormalizeForSearch)` (no
  uniqueness) and `nameRules(NormalizeForSearch, ?string $blogTagId)` (format + uniqueness). Anything
  that resolves an existing name as a hit uses the first — story 0059.
- **`FindOrCreateBlogTag::__invoke(string $name): BlogTag`** is the resolver stories 0060/0061/0063 call.
  Returns a plain `BlogTag` (read `wasRecentlyCreated`, do not expect a tuple); validates format only;
  a `23000` on insert is caught and the winning row re-fetched, **not** refused — story 0059.
- Its authorization is branch-dependent: first gate is `create` **or** `viewAny`; only the insert branch
  then asks `create`. The reuse branch is open to a `blog.view`-only **or** a `blog.create`-only actor —
  story 0059 (a precision on the task file's D-11, recorded in its Phase 2 amendments).
- `DeleteBlogTag` is **complete as shipped** — no story extends it. Story 0061's `blog_post_tag` pivot
  **must** declare `foreignUuid('blog_tag_id')->constrained()->cascadeOnDelete()`; a `restrictOnDelete()`
  would turn every in-use tag deletion into a database error. The promise is in the action's docblock —
  story 0059.
- Story 0060 must authorize before opening each modal and keep the id passed to `RenameBlogTag`
  server-authoritative (`#[Locked]` / re-read); the action re-reads the row itself but the component
  is the first line of defence — story 0059.
- `BlogTagPolicy` and `BlogCategoryPolicy` are separate, near-identical policies on `blog.*`; a shared
  `BlogPolicy` would not be auto-discovered — stories 0058/0059.
- **Open follow-ups found by story 0059's audit, deliberately not fixed in 0058's files:** (a)
  `BlogCategoryValidationRules::trimName()` still has the quadratic trailing-whitespace regex that
  `BlogTagValidationRules::trimName()` replaced with a lookbehind-guarded one (29 s for 50,000 interior
  spaces with PCRE JIT off, run on the raw input before `max:`); (b) neither trait rejects interior
  zero-width, bidi-override or control characters (`run\u{200B}ning` creates a visually identical
  duplicate); (c) neither rejects invalid UTF-8 outright at the rule level — tags now trim it to an
  empty name — story 0059. Fix all three in the shared concern that extracts the duplication.
- **Story 0061's `tagNames` array needs an upper bound** (see `docs/security/array-validation-bounds.md`):
  every unmatched name mints a permanent taxonomy row for a `blog.create` holder. Any transaction
  around `FindOrCreateBlogTag` is safe: its race re-fetch is a locking read — story 0059.

## Story 0060 — Blog tags management screen

- `App\Livewire\BlogTags\Index`, route `blog-tags.index` at `/blog/tags` gated `can:blog.view`, view
  `resources/views/livewire/blog-tags.blade.php` (the flat path), copy in `lang/{en,es}/blog-tags.php`
  — story 0060.
- **Validation lives only in `CreateBlogTag`/`RenameBlogTag`.** The component composes no validation
  trait and never calls `$this->validate()`; the actions' `name`-keyed `ValidationException` lands in
  the error bag and keeps the modal open. It never calls `FindOrCreateBlogTag` — that would turn every
  duplicate refusal into a silent success — story 0060.
- **Deleting is unconditional and the screen shows nothing about usage**: no count, blocked state,
  reassign step or error outlet, and the destructive button is never disabled. A rendered-HTML test
  asserts those absences, because no "delete succeeds" test can see dead blocked-delete markup —
  story 0060.
- `$editingTagId` and every other id-carrying property are `#[Locked]`; `save()` re-reads the row with
  `findOrFail()` and hands the model to the action. Every public method except `mount()` and the two
  `close*()` resets authorizes through `LogRefusedPrivilegedAttempt` with `target_type: 'blog_tag'` —
  story 0060.
- **This story created the `content` sidebar group and its nested `blog` cluster** (and `items.blog_tags`,
  `cluster: 'blog'`, `permissions: ['blog.view']`). Stories 0062 and 0063 append one `items.*` entry each
  with `cluster: 'blog'` and declare no new group or cluster — story 0060.
- **Every new authenticated screen must be added to `TopbarTest::topbarScreens()`** and declare the
  `heading`/`subheading` slots; a guard test fails otherwise. Story 0062 and 0063's task files predate
  the topbar and do not say so — story 0060.
- The header's create button is gated with `@can('create', BlogTag::class)` (disabled branch plus
  tooltip); the row actions use per-row `canEdit`/`canDelete` from `Gate::allows()`. There is no
  `canCreate` property — story 0060.
- Story 0074 removes `blog_tags.name`; until it reaches Phase 3 this component reads `name` directly
  (`orderBy('name')`, `$tag->name`), and 0075 rewrites it per language — story 0060.
