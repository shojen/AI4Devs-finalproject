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

## Story 0062 — Blog categories management screen

- `App\Livewire\BlogCategories\Index`, route `blog-categories.index` at `/blog/categories` gated `can:blog.view`, view `resources/views/livewire/blog-categories.blade.php` (the flat path), copy under `categories.index` in `lang/{en,es}/blog.php` beside 0061's `categories.delete_blocked` — story 0062.
- **Delete is hard-blocked with a count at every privilege level, and there is no confirm-and-proceed control.** The refusal is 0061's `ValidationException` on `blogCategoryId`, rendered inline with `@error`; the component catches nothing, so the throw keeps the modal open by construction. The delete target's property is named `$blogCategoryId` to match the error key, because Livewire drops an error whose key the component does not declare — story 0062.
- **A count rendered beside a guarded action is part of that guard's contract:** the row count uses `withCount(['posts' => fn ($q) => $q->withTrashed()])`, the same scope the guard counts with. A bare `withCount()` **excludes** soft-deleted rows by default (verified by execution), so it would undercount — story 0062.
- `closeModal()` resets the `name` key and `closeDeleteModal()` resets `blogCategoryId`; the two are distinct on purpose and a test proves each leaves the other alone — story 0062.
- The sidebar entry is `items.blog_categories` (`group: null, cluster: 'blog'`, icon `rectangle-stack`), appended to 0060's cluster with no group or cluster declared; the screen is in `TopbarTest::topbarScreens()` — story 0062.
- Story 0072 removes `blog_categories.name`; until it reaches Phase 3 this component reads `name` directly (`orderBy('name')`, `$category->name`), and 0073 rewrites it per language — story 0062.


## Story 0063 — Blog posts list + editor UI

- Routes `blog-posts.index` (`/blog/posts`), `blog-posts.create` (`/blog/posts/create`), `blog-posts.edit` (`/blog/posts/{blogPost}/edit`), all `can:blog.view`; `App\Livewire\BlogPosts\{Index,Editor}`; views `livewire/blog-posts.blade.php` (flat, the `Index` exception) and `livewire/blog-posts/editor.blade.php` (ordinary mirror); copy in `lang/{en,es}/blog-posts.php` (`index.*`, `editor.*`, `statuses.*`), **superseding the "0062 and 0063 extend `blog.php`" header sentence** of `lang/{en,es}/blog.php` — story 0063.
- **Route gate vs component gate differ on purpose:** `blog.view` alone → 200 on the list, 403 on create/edit, because `Editor::mount()` authorizes `create`/`update` through `LogRefusedPrivilegedAttempt` (`target_type: 'blog_post'`, so the refusal is reachable over HTTP and logged), whereas `Index::mount()` only re-checks `viewAny` (unlogged — the route already checks it) — story 0063.
- **The editor is a routed page, not a modal** (WYSIWYG seeded once, 0021 D9, so a save redirects and never resets the form in place); one component for create and edit; `$blogPostId` `#[Locked]`; `status` is a **plain string** (an enum-typed property turns a forged value into an uncaught `ValueError`, 0061 D-12) — story 0063.
- **Validation is the actions'.** The editor composes no validation trait and never calls `$this->validate()`; `save()` **re-keys** the action's `ValidationException` onto declared properties (`blog_category_id`→`blogCategoryId`, `published_at`→`publishedAt`, `tag_names`/`name`→`tagNames`) because Livewire persists only errors keyed on a declared property — story 0063.
- **`publishedAt` is sent to the action only when `status === 'scheduled'`** (the field is merely hidden by `x-show` otherwise; a stale hidden date must never schedule a post, 0061a); it is hydrated at **seconds** precision (`Y-m-d\TH:i:s`, `step="1"`) so retitling an overdue Scheduled post resubmits the stored instant (R-9); the app timezone is UTC and the field carries a translated UTC hint. Chromium drops `:00` seconds from a `datetime-local` value, so browser tests use non-zero seconds — story 0063.
- **Tag chip field:** bespoke, binds **names**, `$tagNames` is the complete set resubmitted whole and rendered whole (never filtered/truncated: `sync()` revokes what the editor cannot see); the add control for a NEW name is disabled without `blog.create`, **and `addTag()` itself refuses it** so Enter is covered; the server's `FindOrCreateBlogTag` refusal (403, whole-save rollback) is the real control. Editor reaches tags only through the post actions (arch test fence) — story 0063.
- **List:** `PER_PAGE = 25`, explicit columns (never `body`/`slug`), `category:id,name` + `tags:id,name` eager loads, `created_at DESC, id ASC`; row shape `{id, title, categoryName, status, date, tags, canEdit, canDelete}`; titles/names are read only in two private row mappers (Epic 5 retrofit seam); badge `zinc`/`amber`/`lime`; `BlogPostStatus::label()` now exists — story 0063.
- **Filters:** `category`/`tag`, `wire:model.live` + `#[Url]`, `''`-defaulted strings; sanitized in `mount()` **and** `updated…Filter()` against the **database** (`whereKey()->exists()`), not the capped dropdown; each update `resetPage()`; `posts()` clamps an empty past-the-end page to the last page and the empty state keys off `total()` — story 0063.
- **Deleted posts:** a collapsed section rendered only when trashed posts exist, bounded at `TRASHED_LIMIT = 200` with a "showing N of M" notice; `restoreBlogPost(string $blogPostId, RestoreBlogPost $restoreBlogPost, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt)` resolves `BlogPost::withTrashed()->findOrFail()`, authorizes `restore` (= `blog.edit`) and calls 0061's `RestoreBlogPost`; the row hint reads the same `Gate::allows('restore')`; **no force-delete anywhere** — story 0063.
- **Sidebar:** `items.blog_posts` (`group: null, cluster: 'blog'`, icon `pencil-square`, `current_when: 'blog-posts.*'`) **inserted before `blog_tags`** — `items` declaration order is render order, so the cluster renders **Posts / Tags / Categories** even though 0062's `blog_categories` was appended earlier; there is no `order` key. The story's own "join `groups.blog`" wording predates story 0080: it is `cluster: 'blog'` — story 0063.
- **Topbar:** both views declare `heading`/`subheading`; `topbar.blog_posts.subtitle`, `topbar.blog_post_editor.subtitle`; the three routes are covered by `TopbarTest` — story 0063.
- **Hand-offs:** for 0062 (already shipped), the delete-block count includes **trashed** posts and this screen's deleted-posts section is their exit; for 0064, the list already renders the `Scheduled` badge and date, so the sweep flipping a post needs no UI change; for 0072/0074/0078/0079, every read of `title`/`name` is in the row mappers, the category/tag option queries and the editor's hydrate/suggestion code, all written against the pre-retrofit schema — story 0063.
