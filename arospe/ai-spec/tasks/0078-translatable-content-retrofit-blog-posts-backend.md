# [0078] Translatable content retrofit — Blog Posts backend

## Description
Applies story [0070](done/0070-translatable-content-mechanism-product-categories-backend.md)'s per-store-language translatable-content mechanism to **Blog Posts** ([PRD Epic 5, Layer 2](../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization), whose translatable-content list names *"Blog post **title** and **body**"* and *"**Slug / SEO fields** … on products and posts"*). Story [0061](done/0061-blog-posts-core-crud-backend.md)'s `title`, `body` and `slug` columns move to a `blog_post_translations` child table, one row per `(post, store language)`, with slug uniqueness re-scoped from global to **per store language**. `status`, `published_at`, `blog_category_id`, `created_by` and the tag attachments stay on the parent — the PRD puts *"status, dates"* explicitly outside the language tabs.

**This story consumes a recipe; it does not write one.** `App\Concerns\HasTranslations`, `App\Actions\Translations\SetTranslation`, `StoreLanguage::defaultStoreLanguage()` and the `config('store-languages.translation_relations')` registry are 0070's and 0068's, shipped, and used **unmodified** (the registry gains one data row).

> **Rewrite note (2026-11-07).** The Phase 1 draft of 2026-08-29 was written when 0061, 0068 and 0070 were unimplemented files and said so throughout. All of them, plus the three sibling retrofits ([0072](done/0072-translatable-content-retrofit-blog-categories-backend.md) categories, [0074](done/0074-translatable-content-retrofit-blog-tags-backend.md) tags, [0076](done/0076-translatable-content-retrofit-products-backend.md) products), the post list/editor ([0063](done/0063-blog-posts-list-editor-ui.md)), the scheduler ([0064](done/0064-scheduled-post-auto-publish-backend.md)), the notifications ([0065](done/0065-blog-post-published-notification-backend.md)) and the dashboard ([0082](done/0082-dashboard-home-overview-backend.md)) are now in `done/`. This rewrite was verified against `HEAD` (`64f6499`) and replaces the draft's "Phase 3 must re-verify" hedges with facts. What changed in substance: **the readers now exist and must be migrated in this story** (**D-14**; the draft handed them off as R-1), the signatures in the draft were wrong (**D-12**), the multi-field fallback proof is 0076's (**D-10**), and the slug question is reopened because 0076 split slug off with a different shape (**Q-1**).

> **What genuinely does not port from the single-field siblings** (each is a decision below):
> 1. **Three translatable columns** (`title`, `body`, `slug`), one derived from another and one nullable. 0076 was the first with two.
> 2. **The parent soft-deletes** (`BlogPost` uses `SoftDeletes`); `product_categories`, `blog_categories`, `blog_tags` and `products` hard-delete. That changes the backfill (**D-6**) and is what the slug reservation rests on (**D-5**).
> 3. **The globally-unique column is `slug`, which is derived yet needs no stored fold** — a third shape, distinct from the pilot's raw `name` and 0072/0074's `normalized_name` (**D-2**).
> 4. **The parent keeps a non-empty `#[Fillable]`** (**D-9**).

## Type
backend | includes database-expert: **yes** (one new table with an idempotent data backfill, one column-drop migration with a guard, a model-hook relocation)

## 1. Refined user story

> **As** a blog editor publishing in more than one language,
> **I want** each post's title, body and slug stored and resolved per store language, falling back to the store default when a translation is missing,
> **so that** the blog reads correctly in every language the store authors in, and a partially translated post degrades gracefully instead of rendering blank.

> **As** the engineer applying 0070's recipe to a soft-deleting entity,
> **I want** the column drop, the slug reservation, the sanitizer and every reader of the moved columns to change together,
> **so that** dropping the columns breaks nothing that ships today and the language-tab story ([0079](0079-blog-post-editor-language-tabs-ui.md)) builds on a safe mechanism.

**Scope fence.** This story ships **no new screen, route, language tab, lang key or `config/modules.php` entry**. It **does** edit the existing readers of the dropped columns, minimally (**D-14**), so they keep their current behaviour while the default language is unchanged. Non-default-language authoring (the tabs and the per-entity `SetBlogPostTranslation` action that validates and authorizes it) is 0079's. Every write below goes through `CreateBlogPost`/`UpdateBlogPost`, which write the **default store language only** (0072 **D-7** / 0074 / 0076 precedent); French content in a `Given` is arranged fixture data, not a write path this story offers.

## Gherkin — 2. Detailed acceptance criteria (Given/When/Then)

Every scenario opens with a named business-role actor and carries exactly one `When`, per [gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3. **"Blog editor"** is 0061's actor term.

```gherkin
Feature: Per-store-language blog post content

  # --- Resolution and per-field fallback ---

  Scenario: A blog editor reads a post translated into the requested language
    Given a blog editor, with a post titled "Botas de invierno" in Spanish and "Bottes d'hiver" in French
    When the post's French title is requested
    Then "Bottes d'hiver" is returned

  Scenario: A missing translation falls back to the default store language
    Given a blog editor, with a post titled "Botas de invierno" in the default store language and no French translation
    When the post's French title is requested
    Then "Botas de invierno" is returned, because the store default supplies the fallback

  Scenario: A post translated in neither the requested nor the default language resolves to nothing
    Given a blog editor, with a post holding no translation in any store language
    When the post's French title is requested
    Then no title is returned and no error is raised

  Scenario: A half-translated post falls back on the body only
    Given a blog editor, with a post whose French title is present and whose French body is absent
    When the post's French body is requested
    Then the store default's body is returned

  Scenario: A half-translated post keeps its translated title
    Given a blog editor, with a post whose French title is present and whose French body is absent
    When the post's French title is requested
    Then the French title is returned

  Scenario: A body stored as an empty value still falls back
    Given a blog editor, with a post whose French body is stored as an empty value
    When the post's French body is requested
    Then the store default's body is returned

  Scenario: A translation authored in a removed store language is still readable
    Given a blog editor, with a post translated into French and French since removed as a store language
    When the post's French title is requested
    Then "Bottes d'hiver" is returned, because removal preserves stored content

  Scenario: A post written only in a language that is no longer the default still has a title
    Given a blog editor, with a post that holds only a French translation and French not the default language
    When they open the posts list
    Then the post is listed under its French title rather than nameless

  # --- Writing the default language through the actions ---

  Scenario: Creating a post stores its content in the default store language
    Given a blog editor holding the blog create permission
    When they create a post titled "Botas de invierno"
    Then the post holds exactly one translation, in the default store language, with the derived slug "botas-de-invierno"

  Scenario: Updating a post replaces its default-language translation rather than adding one
    Given a blog editor holding the blog edit permission, with a post titled "Botas de invierno"
    When they retitle it "Botas de nieve"
    Then the post still holds exactly one default-language translation and it reads "Botas de nieve"

  Scenario: Updating a post leaves its other languages untouched
    Given a blog editor, with a post titled in Spanish and French, and another post with a French translation
    When they retitle the first post in the default language
    Then the first post's French title, body and slug are unchanged
    And the other post's French translation is unchanged

  Scenario: Updating a post that has no default-language translation adds one
    Given a blog editor holding the blog edit permission, with a post that holds only a French translation
    When they update the post with a title and body
    Then the post gains a default-language translation and its French translation is unchanged

  Scenario: Saving a post with no default store language configured fails clearly
    Given a blog editor holding the blog edit permission, and a store with no default language
    When they create a post
    Then the save fails with a clear error and nothing is written

  Scenario: A failure while saving rolls back the whole post
    Given a blog editor holding the blog create permission
    When they create a post whose tag set cannot be saved
    Then no post and no translation are stored

  Scenario: A body that sanitizes to nothing counts as no body
    Given a blog editor holding the blog create permission
    When they publish a post whose body is only an empty paragraph
    Then the post is refused with a validation message on the body

  Scenario: A blog editor without the create permission cannot create a post
    Given a signed-in user who does not hold the blog create permission
    When they attempt to create a post
    Then the attempt is refused and nothing is written

  Scenario: A translation saved with its own slug still gets the derived one
    Given a blog editor, with a post
    When a translation of that post is saved carrying title "Botas" and the slug "evil"
    Then the stored slug reads "botas"

  # --- The slug, derived per language and unique per language ---

  Scenario: Retitling a post re-derives that language's slug only
    Given a blog editor, with a post titled in both Spanish and French
    When they retitle it in the default language
    Then the default-language slug is re-derived and the French slug is unchanged

  Scenario: Two posts cannot share a slug within one store language
    Given a blog editor, with a post whose default-language slug is "resumen-semanal"
    When they title another post "Resumen semanal"
    Then the change is refused with a validation message on the title

  Scenario: The same slug in two different store languages is permitted
    Given a blog editor, with a post whose French slug is "resumen-semanal"
    When they title another post "Resumen semanal" in the default language
    Then the change is accepted, because uniqueness is scoped to one store language

  Scenario: A post keeps its own slug when re-saved
    Given a blog editor, with a post titled "Resumen semanal"
    When they save that post again with the same title
    Then the change is accepted rather than refused as a duplicate

  Scenario: A title with no usable slug is still refused
    Given a blog editor holding the blog create permission
    When they create a post titled "!!!"
    Then the change is refused with a validation message on the title

  # --- Soft delete: the reservation, now per language ---

  Scenario: A deleted post keeps its slug reserved in the language it was written in
    Given a blog editor, with a deleted post whose default-language slug is "resumen-semanal"
    When they title a new post "Resumen semanal"
    Then the change is refused with a validation message on the title

  Scenario: A deleted post's slug is still free in a language it was never translated into
    Given a blog editor, with a deleted post translated only into the default store language
    When they add a French translation of another post whose slug equals the deleted post's
    Then the change is accepted, because the reservation is scoped to one store language

  Scenario: A restored post carries every language's content unchanged
    Given a blog editor, with a deleted post translated into Spanish and French
    When they restore that post
    Then it carries its Spanish and French titles, bodies and slugs unchanged

  Scenario: Deleting a post removes no translation
    Given a blog editor, with a post translated into three store languages
    When they delete that post
    Then all three of its translations are retained for a later restore

  Scenario: A deleted post still blocks its category's deletion
    Given a blog editor, with a blog category assigned to 1 post that has been deleted
    When they try to delete that category
    Then deletion is blocked with a message stating that 1 post uses it

  # --- Publication state is not translatable ---

  Scenario: Publishing a post announces it once however many languages it carries
    Given a blog editor, with a draft post translated into three store languages
    When they change that post's status to published
    Then a single published-post notification is raised for that post, titled with the default-language title

  Scenario: Retitling and publishing in one save notifies with the new title
    Given a blog editor, with a draft post titled "Borrador"
    When they retitle it "Titular final" and publish it in the same save
    Then the stored notification carries the title "Titular final"

  Scenario: A later retitle never changes a notification already sent
    Given a blog editor, with a published post whose notification was already sent
    When they retitle the post
    Then the stored notification still carries the original title

  # --- Readers ---

  Scenario: Deleting a post asks for confirmation under its default-language title
    Given a blog editor, with a post titled "Botas de invierno"
    When they open the delete confirmation for that post
    Then the confirmation names "Botas de invierno"

  Scenario: The deleted-posts list shows the default-language title
    Given a blog editor, with a deleted post titled "Botas de invierno"
    When they open the posts list
    Then the deleted-posts section lists "Botas de invierno"

  Scenario: Editing a post shows only content written in the default language
    Given a blog editor, with a post that holds only a French translation and French not the default language
    When they open the post in the editor
    Then the title and body fields are empty, so a fallback is never saved as a translation

  Scenario: The posts list shows the default-language title
    Given a blog editor, with a post titled "Botas de invierno" in the default language and "Bottes d'hiver" in French
    When they open the posts list
    Then the post is listed as "Botas de invierno"

  Scenario: The dashboard shows the default-language title and description
    Given an administrator holding the blog view permission, with a published post
    When they open the dashboard
    Then the latest-posts block shows the post's default-language title and a description derived from its default-language body

  # --- Authorization and the removal warning ---

  Scenario: A user without the blog edit permission cannot update a post
    Given a signed-in user who does not hold the blog edit permission
    When they attempt to update a post
    Then the attempt is refused and nothing is written

  Scenario: A blog editor needs no store-language permission to save a post
    Given a blog editor holding the blog edit permission and no store-languages permission
    When they update a post's title
    Then the change is saved

  Scenario: Removing a language in use reports the blog post content it affects
    Given a store administrator, with French active and holding blog post translations
    When they open the removal warning for French
    Then the warning counts the French blog post translations
```

## Files to create/modify

### Create

- **`database/migrations/2026_11_07_100000_create_blog_post_translations_table.php`** — calls `BackfillBlogPostTranslations::assertCanBackfill()` **before any DDL** (MySQL auto-commits `CREATE TABLE`), then creates the table, then runs the backfill:
  `uuid id` PK; `foreignUuid blog_post_id` → `blog_posts` **cascadeOnDelete** (never fires through a soft delete, **D-5**); `foreignUuid store_language_id` → `store_languages` **restrictOnDelete**; `string title` (255) NOT NULL; `string slug` (255) NOT NULL; `mediumText body` NULL; timestamps; `unique(['blog_post_id','store_language_id'])` named `blog_post_translations_post_language_unique`; `unique(['store_language_id','slug'])` named `blog_post_translations_language_slug_unique`. **No `deleted_at`, no `normalized_slug`** (**D-2**, **D-5**). `down()` drops the table.
- **`database/migrations/2026_11_07_100001_drop_translatable_columns_from_blog_posts_table.php`** — a guard aborts when any `blog_posts` row (trashed included) lacks a default-language translation; (the `whereNotExists` shape of 0076's drop migration, over `DB::table('blog_posts')`, which sees trashed rows); then, in one `Schema::table`, `dropUnique('blog_posts_slug_unique')` **before** `dropColumn(['title','slug','body'])`. `down()` re-adds all three **nullable**, no unique index (**D-7**).
- **`app/Actions/Blog/BackfillBlogPostTranslations.php`** — query builder only (**D-6**); `assertCanBackfill()` refuses only when posts exist **and** no default store language does; `translationRowsFor()` pure and unit-testable; `__invoke()` idempotent (skips a post that already has a default-language row), `chunkById(500)`.
- **`app/Models/BlogPostTranslation.php`** — UUID PK; `#[Fillable(['title','body'])]` (`blog_post_id` and `store_language_id` only via `SetTranslation`'s `forceFill`; `slug` derived, never fillable); `booted()` `saving` hook: derives `slug = Str::slug($title)` when `title` is dirty (**D-4**) and sanitizes `body` when dirty (**D-8**); `blogPost()` (`withTrashed()`) and `storeLanguage()` relations.
- **`database/factories/BlogPostTranslationFactory.php`**, **`database/factories/WritesDefaultBlogPostTranslation.php`** — the identifiable `afterCreating` step, copied from `WritesDefaultProductTranslation` (**D-15**).
- **Tests** — see *Tests to perform*.

### Modify

- **`app/Models/BlogPost.php`** — `use HasTranslations; implements Translatable`; `translationModel()`; **delete the `saving` slug hook**; `#[Fillable(['blog_category_id','status'])]` (**D-9**); `@property` docblock loses `title`, `slug`, `body` and gains `translations`; `displayTitle(): ?string` (default language, else any stored translation, as `BlogTag::displayName()`); `scopeWithListTranslations()` loading `translations` with explicit columns **excluding `body`** (**D-13**). `TITLE_MAX_LENGTH` stays (it now names the `title` and `slug` column length on the child).
- **`app/Concerns/BlogPostValidationRules.php`** — `titleRules()` unchanged (still **no** uniqueness rule); `titleSlugRules(string $storeLanguageId, ?string $ignoreBlogPostId = null)` queries `blog_post_translations` for the language, own-row exclusion `where('blog_post_id', '!=', $id)`, never `->ignore()` (**D-11**). The empty-slug and over-long-slug refusals are unchanged.
- **`app/Actions/Blog/CreateBlogPost.php`**, **`UpdateBlogPost.php`** — signatures unchanged (**D-12**); the `forceCreate`/`forceFill` whitelists lose `title`/`body`; the default-language translation is written through `SetTranslation` **inside the existing `DB::transaction`** (**D-16**); `$defaultLanguage` resolved once (a missing default language is a clear failure, not a null deref); the slug-collision catch matches the **index name** instead of the substring `'slug'`. Sanitization, `BlogBodyHasVisibleContent`, `refresh()`-first, authorization order and the after-commit notification are untouched.
- **Readers (D-14):** `app/Livewire/BlogPosts/Index.php`, `app/Livewire/BlogPosts/Editor.php`, `app/Actions/Dashboard/GetLatestBlogPosts.php`, `app/Actions/Blog/NotifyScheduledBlogPostPublishFailed.php`, `app/Notifications/BlogPostPublished.php`, `database/factories/BlogPostFactory.php`, plus the stale docblocks named in **D-14**.
- **`config/store-languages.php`** — append `['table' => 'blog_post_translations', 'column' => 'store_language_id']`.
- **`BlogPostTranslation::LANGUAGE_SLUG_UNIQUE_INDEX`** — a constant holding `'blog_post_translations_language_slug_unique'`, used by the migration and by both actions' 1062 catch, matched with `str_contains($e->getMessage(), …)` (works for MySQL's `table.index` form and MariaDB's bare name). The pair-unique 1062 is reachable only under concurrent first-translation writes, is rethrown, and that is accepted.
- **Existing tests (Phase 3 task).** ✅ **Owner approval, 2026-11-08:** the project owner approved porting these tests to the new storage **and deleting any test that is no longer necessary** once its behaviour has moved (CLAUDE.md otherwise forbids deleting tests without approval). The approval is scoped to tests made obsolete by this story's column move; each deletion must be named in the Phase 3 commit message with the test that now covers the behaviour, and no test is deleted merely because it is inconvenient. Not mechanical, and wider than the draft said:
  - **~31 files**, including **3 Browser files** (`tests/Browser/BlogPosts/EditorJourneyTest.php`, `IndexTest.php`, `tests/Browser/Dashboard/DashboardJourneyTest.php`), `tests/Feature/Seeders/DemoDataSeederTest.php` and `tests/Unit/Concerns/BlogPostValidationRulesTest.php` (`titleSlugRules()` gains a required first parameter). About 92 `BlogPost::factory()->create(['title' => …])` call sites move to `titled()`; reads change to `translated('title')`/`displayTitle()`.
  - **Rewrite, not migrate** — port to `BlogPostTranslation`/`blog_post_translations`: in `tests/Feature/Models/BlogPostTest.php` the slug-hook tests, the "does not rewrite slug" test, the `Rule::unique('blog_posts','slug')` test and the duplicate-slug `QueryException` test; `tests/Feature/Blog/BlogPostCreatorTest.php:68-77` pins five `blog_posts` index names including `blog_posts_slug_unique` and must become four.
  - **False-positive traps:** the raw `DB::table('blog_posts')->insert([... 'title','slug' ...])` calls in `BlogPostTest.php` (~88, ~164) must drop the moved columns, or the NOT NULL `blog_category_id` test passes on error 1054 instead of its intended error (epic-5 digest, 0076 lesson).
  - **Column-matching asserts:** `DeleteBlogPostTest.php:32`, `UpdateBlogPostTest.php:306`, `DeleteBlogTagTest.php:148`.
  - **Stale comments:** `BlogPostsIndexTest.php:13`, `BlogPostsEditorTest.php:20`.
- **Docs (Phase 6):** `docs/database/schema-blog.md` (new table, ER diagram, `blog_posts` section), `docs/api/routes.md` only if a Livewire contract line cites the moved columns, the epic-5 digest.

### Deliberately not touched

`lang/{en,es}/*` (no new copy — keeps this story clear of 0087), `routes/*`, `config/modules.php`, `BlogPostPolicy`, `RolePermissionSeeder` (catalog stays at its current count), `SetTranslation`, `HasTranslations`, `Translatable`, `DeleteBlogCategory` (its `withTrashed()` count fronts a `restrict` FK on `blog_posts`, and no `blog_posts` row moved), `PublishScheduledBlogPost`/the sweep (parent-only), `SyncBlogPostTags`, the blog tag/category translation tables.

## Tests to perform — 3. QA test cases / validation scenarios

Pest, Feature unless marked. `RefreshDatabase`; use factories (`BlogPost::factory()->titled(...)`) and **never** a raw `DB::table('blog_posts')->insert()` that still sends `title` (it fails 1054 before any other error, 0076's lesson).

- **Schema** (`BlogPostTranslationsSchemaTest`): columns, nullability, FK actions, **exactly three indexes** (PK + the two uniques; verify with `Schema::getIndexes`, not by assumption), the two unique names, no `deleted_at`, the dropped parent columns, and `blog_posts`' `(deleted_at, status, published_at)` index still present.
- **Placement and running.** New tests go under `tests/Feature/Blog/` and `tests/Unit/` (class-style titles as the sibling blog tests, `test('…')`). Host-native runs set `DB_DATABASE=testing_<worktree>` explicitly ([worktree-databases.md](../../docs/testing/worktree-databases.md)); the Browser directory is part of the full suite; never run `migrate:*` against the app database. The "index check recorded" DoD item is satisfied by the schema test, not by a separate artifact.
- **Backfill** (`BackfillBlogPostTranslationsTest`, mirroring `BackfillProductTranslationsTest`'s old-schema helper on a test-created table, with at least one trashed row, one live row and one `NULL` body): copies `title`, `body` (including `NULL`) and `slug` byte-for-byte; **includes trashed posts** (the test that guards the silent-loss failure, **D-6**); idempotent on a second run; `assertCanBackfill()` refuses with posts and no default language and passes with none; the fresh-install case (`store_languages` empty, no posts). Arrange the pre-backfill state with the query builder on a table created by the test, as 0074/0076 do.
- **Per-field fallback** (**D-10**): mirror 0076's `title`/`description` block with real `title`/`body`: French title present + body absent → body falls back, title stays; the reverse; `''` body falls back; removed-language content still readable. Locate 0076's file under `tests/Feature/Products/` and mirror it rather than re-deriving.
- **Slug**: derived on create; re-derived only when `title` is dirty; unchanged on a body-only save; a forced `slug` assignment is overwritten; per-language uniqueness (same slug, two languages accepted; same language refused) written as **three** assertions so a rule that rejects everything cannot pass; own-row re-save accepted; trashed post still reserves its slug in its language and not in another; the 1062 race catch re-keyed to `title` **by index name** and a non-slug 23000 (category FK) **not** swallowed.
- **Soft delete × translations**: delete keeps all translation rows; restore returns them intact; no `deleting` hook exists on `BlogPost`; `BlogPostTranslation` has no `SoftDeletes`; a `forceDelete()` does cascade (**D-5** point 4 — pins the FK, not a feature).
- **Sanitizer**: `<script>` in a body is stripped by the action (layer 1) **and** by a bare `SetTranslation` call (layer 2); a body that sanitizes to no visible content still collapses to `NULL` in the actions and, for a Published post, is refused.
- **Actions**: existing `CreateBlogPostTest`/`UpdateBlogPostTest`/status-and-date/tag/creator/notification suites stay green after the mechanical `titled()` migration; one translation row per call; second-language rows untouched by an update; publish transition still announces exactly once with three translations; a failure inside the transaction leaves **no** post and **no** translation.
- **One behaviour test per D-14 reader**, so a missed reader fails (a `grep` criterion is not testable in Pest): list, trashed list, `$deletingBlogPostTitle` confirmation, Editor `mount()`, `GetLatestBlogPosts`, `NotifyScheduledBlogPostPublishFailed`, `BlogPostPublished`, and a `DemoDataSeeder` run — each using a post whose default row is absent or differs from the requested language. The list query's eager load is asserted by its SQL column list: neither `body` nor `slug` on `blog_post_translations`; plus the footgun case (`translated('body')` on that partial relation returns `null`).
- **Dashboard query budget** (`DashboardActionsQueryCountTest`): `DomainQueryLog::TABLES` names `blog_posts` but **not** `blog_post_translations`, so the existing `'posts' => 1` budget is blind to an N+1 on translations. Add `blog_post_translations` to the log and assert exactly **one** statement naming it at scale 3 and 30; do not claim the old budget proves the eager load.
- **Notification title**: retitle-and-publish in one save stores the **new** title (**D-17**); a later retitle leaves the stored `data['title']` unchanged (read it back from the database); a post with only a French row uses `displayTitle()`.
- **Editor** (`BlogPostsEditorTest`): `mount()` reads the **raw default-language row** via a fresh query, never a fallback; for a post with only a French row the title and body are empty and saving does not copy a fallback into a default row (0073 **D-6**).
- **Factory** (`BlogPostFactoryTranslationStatesTest`, mirroring `ProductFactoryTranslationStatesTest`): `create()` yields one default-language row; `titled($t, $b)` swaps only that step; `withoutTranslations()` yields zero rows; `published()->titled()` and any tag-attaching state keep their effect; with no default `StoreLanguage` the step creates exactly one and never duplicates it.
- **Model guards**: `BlogPost::make([...])` with `title`, `slug`, `published_at`, `created_by` fills only `blog_category_id` and `status` (**D-9**); a `ReflectionMethod` test pins both actions' parameter names and types (**D-12**); schema negative assertions: no `meta_*` and no `normalized_slug` column (**D-1**, **D-2**); index **names and column lists**, not only the count.
- **Child hook limit** (**D-8**): a bare `SetTranslation` with title `'!!!'` stores `slug = ''`; a forced `slug` with a non-dirty `title` is not overwritten. These pin the limit rather than endorse it.
- **Missing default store language**: both actions throw `RuntimeException` after authorization and write nothing (mirror `ProductTranslationActionsTest`).
- **`down()` (D-7) is deliberately untested**: the project forbids `migrate:rollback` against a real database, and the mirror siblings do the same.
- **Registry**: `StoreLanguage::translationUsageCount()` includes `blog_post_translations`.
- **Authorization**: unchanged abilities; `CreateBlogPost`/`UpdateBlogPost` still authorize before any write; no `store-languages.*` permission is needed.
- **Architecture** (`tests/Unit/ArchitectureTest.php`): confirm no existing rule forbids `App\Actions\Blog` importing `SetTranslation` (0071 restricts `app/Livewire/` only). Add nothing unless a rule is genuinely missing.
- **Deliberately NOT tested here:** non-default-language authoring (0079), meta fields (**Q-2**), public slug routing (no storefront exists).

## Expected outcome

`blog_posts` is a thin parent. Every post written through the actions has a default-language `blog_post_translations` row holding `title`, derived `slug` and sanitized `body`; reads resolve per field with fallback to the store default and never throw; slug uniqueness is per language and a trashed post keeps reserving its slugs; every screen, notification and dashboard block that read the dropped columns behaves exactly as before while the default language is unchanged; the removal warning counts blog post translations.

## Acceptance criteria

- [ ] `blog_post_translations` exists with the shape in *Files*, **three** indexes, and a per-language unique slug.
- [ ] `blog_posts.title`, `.slug` and `.body` are gone; every pre-existing post, **trashed ones included**, kept its content in the default language.
- [ ] `BlogPost` uses `HasTranslations`/`Translatable`, has no slug hook and no `deleting` hook, and `#[Fillable(['blog_category_id','status'])]`.
- [ ] `CreateBlogPost`/`UpdateBlogPost` keep their public signatures and one-transaction atomicity, and write the default-language translation.
- [ ] The slug stays derived per language, never mass-assignable, unique per language, reserved by trashed posts.
- [ ] Per-field fallback is proven on real fields; `translated()` never throws.
- [ ] Every reader in **D-14** is migrated and has its own behaviour test; a review `grep` for `->title`/`->body`/`->slug` on `BlogPost` instances (not the bare words) finds nothing outside the translation layer.
- [ ] The body sanitizer binds every writer (two layers).
- [ ] `config/store-languages.php` registers the table.
- [ ] No new permission, policy, route, lang key or Composer package; `lang/*` untouched.

## Definition of Done

- [ ] All acceptance criteria met; tests written first (red → green) and passing.
- [ ] Full suite green, unscoped, in directory chunks if run from a worktree.
- [ ] `vendor/bin/pint --format agent` clean; Larastan level 7 clean.
- [ ] `db:table`-equivalent index check recorded (**D-3**).
- [ ] Docs pass (Phase 6): `schema-blog.md` including **the ER diagram entry for the new table**, epic-5 digest entry, task-coordination files refreshed.
- [ ] Phase 4 audit covers the sanitizer layers and the unauthenticated-write surface of `BlogPostTranslation`.

## 4. Documented functional decisions

**D-1 — `title`, `body` and `slug` move; nothing else does.** PRD assumption 14 and Epic 5's list name post title/body and slug; the same PRD puts status and dates outside the tabs. A post's tag *attachments* do not vary by language (tag *names* are 0074's, done). **Meta title/description stay out of scope** (Q-2 of the draft, resolved 2026-08-30 as option (a)): 0061 shipped no such columns, and this is a translation retrofit, not a new-field story. That is deliberately *different* from 0076a, which adds them for products by that story's own debate; each entity's SEO scope is decided per story.

**D-2 — The per-language `UNIQUE` binds `slug`, which needs no separate fold column.** `title` carries no uniqueness (0061 **D-10**: a series, a "Part 2"), so it is never the candidate. `slug` is the only column that carried a `unique()`. `Str::slug()` is itself a lowercasing, accent-stripping transform, so a `normalized_slug` would index a fold of a fold — 0061 named that a review finding. Therefore this story, unlike 0072/0074, never touches `NormalizeForSearch` and adds no re-seed obligation.

**D-3 — Three indexes, not four.** `blog_post_id` is leftmost in the pair unique and `store_language_id` is leftmost in the slug unique, so InnoDB creates no extra FK index (the fifth independent derivation: 0070, 0072, 0074, 0076, here). No third `UNIQUE(blog_post_id, slug)`. This is predicted from InnoDB behaviour; the schema test is what confirms it, and any fourth index is investigated rather than accepted.

**D-4 — The slug hook moves to `BlogPostTranslation` and becomes per-language.** `SetTranslation` calls `save()` on the child, so the child's `saving` event derives the slug without the shared primitive knowing the column exists (the 0072 D-3 / 0074 D-4 relocation). The derivation stays within one row. The parent's hook is **deleted**: its guarded column no longer exists there.

**D-5 — Slug reservation on delete survives with a changed mechanism.** A soft delete is an `UPDATE`, so `cascadeOnDelete()` never fires and translation rows survive a trashed post; `UNIQUE(store_language_id, slug)` binds physically present rows; the child has no `deleted_at`, so no `SoftDeletingScope` can hide a trashed post's slug from the pre-flight check. Ways to break it, each a plausible reflex: `SoftDeletes` on the child; a `deleting` hook on `BlogPost` removing translations (destroys lossless restore); an independent cleanup job. A genuine `forceDelete()` cascading is not a break. A per-language slug presupposes language-scoped URLs; a future flat `/blog/{slug}` storefront would need its own reconciliation (no storefront this phase).

**D-6 — The backfill must include trashed posts.** `DB::table()` applies no global scope, which is both the 0070 D-11 mandate and the only way to see every row. A `whereNull('deleted_at')` would permanently empty every trashed post, invisibly, because `translated()` never throws. It copies `title`, `body` (including `NULL`) and `slug` byte-for-byte and recomputes nothing. It cannot violate the new composite unique by construction: the old `UNIQUE(slug)` makes values pairwise distinct and every row lands in one language partition. Idempotent, with `assertCanBackfill()` before DDL, as `BackfillProductTranslations` (0076 **D-13**).

**D-7 — `down()` is knowingly not an inverse.** All three columns return nullable; the unique index is not re-added because it would protect nothing over all-`NULL` values (and `slug` is derived from `title`, so no principled single value exists once languages diverge). Rollback is data-lossy, as in 0072/0074/0076.

**D-8 — A body-sanitizer layer on the translation model (0076 D-8 applied).** Layer 1 stays in the actions (sanitize *before* validating so `required` judges what is stored, 0061 **D-14**). Layer 2 is the child's `saving` hook, sanitizing `body` when dirty, so a bare `SetTranslation` call or 0079's future writer cannot store raw HTML. Idempotent on the action path. `BlogBodyHasVisibleContent` (0061b) stays an action-level rule — 0079's per-language writer must call it; this is recorded for that story. This closes the draft's R-8 residual. **Explicit limit:** the child hook only *derives* the slug; it has **no empty-slug guard**, so a bare `SetTranslation` with title `'!!!'` stores `slug = ''`. The empty-slug refusal is action-level (`titleSlugRules()`), and 0079's writer must honour it.

**D-9 — The parent keeps a non-empty `#[Fillable]`.** `['blog_category_id','status']`: both genuinely form-supplied and non-translatable. A reviewer arriving from the single-field siblings will look for `#[Fillable([])]`; its absence is correct. `published_at` and `created_by` stay unfillable for their 0061/0064b reasons.

**D-10 — The multi-field per-field-fallback proof is 0076's, shipped; this story runs the real-field regression.** The draft expected to be first; it is not. Phase 3 locates the 0076 test (`tests/Feature/Products/`) and mirrors it with `title`/`body`, adding the one thing products lacked: a nullable *content* field (`body`) beside a required one.

**D-11 — Validation: a slug rule scoped to a language; the self-exclusion is an explicit FK clause.** `titleSlugRules()` gains a required `$storeLanguageId`; it still keys its refusal on `title` (the field the editor can change). `titleRules()` carries no uniqueness and a test pins that. The own-row exclusion is `where('blog_post_id', '!=', $id)`, **not** `->ignore()`: `ignore()` compares the *translation* row's UUID, a disjoint key space, so every same-title re-save would be refused forever. Re-pointing at the translation id is no better, since a retitle in a not-yet-translated language is an insert with no id. Adopts 0074 **D-6**.

**D-12 — Signatures are unchanged and the draft's were wrong.** At `HEAD`: `CreateBlogPost::__invoke(string $title, ?string $body, ?string $blogCategoryId, ?string $status, ?string $publishedAt, array $tagNames)` and `UpdateBlogPost::__invoke(BlogPost $blogPost, string $title, ?string $body, ?string $blogCategoryId, string $status, ?string $publishedAt, array $tagNames)`; status is a **string** (task 0015's F8). Their meaning narrows to "the default store language's". Widening to a per-language array is rejected for the reasons in 0070 **D-12**. Ordering inside each action is preserved: `refresh()` first (update, 0061 **D-19a**) → authorize → trashed check (update) → resolve the default store language (**after** authorization, **before** the validator, because `titleSlugRules()` needs its id; a missing default is a clear `RuntimeException`, never a null deref) → sanitize → validate → `DB::transaction(parent + translation + tag sync)` → after-commit notification. **Accepted consequence:** a title- or body-only edit no longer changes `blog_posts.updated_at` (the parent has nothing dirty, so no UPDATE runs); nothing reads it today (lists and the dashboard order by `created_at`). A `touch()` inside the transaction is the fix if that ever matters.

**D-13 — `blog_posts` is thin, so the `SELECT *` discipline relocates.** 0061's R-7 kept `body` (a `mediumText` held inline) out of list queries. The parent now has no large text, but `blog_post_translations` carries `title`, `slug` and `body` together, and `scopeWithTranslationsFor()` loads **whole rows**. Lists therefore use `BlogPost::scopeWithListTranslations()`: only the requested and default languages, columns `blog_post_id, store_language_id, title` (no `body`, no `slug`). Footgun, documented on the scope: `translated('body')` on that partial relation returns `null`. The `(deleted_at, status, published_at)` index and the sweep are parent-only and unaffected.

**D-14 — Every live reader of the dropped columns is migrated here** (verified by grep, 2026-11-07; precedent 0074 `d8a6be2`, 0076 **D-20**):
- `Livewire/BlogPosts/Index.php`: `select([... 'title' ...])` in `posts()` (~215) and `trashedPosts()` (~257) drops `title` and adds `->withListTranslations()` (`id` is already selected, which the eager load needs); `$post->title` (~234, ~265); `confirmDelete` (~136) does `findOrFail()->title`, so it loads `translations` and sets `$deletingBlogPostTitle` (a `string`) from `displayTitle() ?? ''`. The row array's `title` stays a `string` because the view prints `{{ $post['title'] }}` unguarded (`blog-posts.blade.php` ~78, ~114, ~210): `translated('title') ?? displayTitle() ?? '—'`, and the trashed list's `title: string` shape gets the same fallback so Larastan level 7 stays clean. The stale "pre-Epic-5 schema" docblock at ~42.
- `Livewire/BlogPosts/Editor.php`: `mount()` title (~129) and body (~137) read the raw default-language row through a **fresh query** (`$blogPost->translations()->where('store_language_id', $default->id)->first()`), never through `scopeWithListTranslations()`; the stale docblock line ~54. **Accepted consequence** (as 0073 **D-6** for tags): after the store default changes, a post written in the old default opens blank and Save creates a new default-language row; the `title` `required` rule limits the damage.
- `Actions/Dashboard/GetLatestBlogPosts.php`: the two D-9 seam methods become `translated('title')`/`translated('body')`, **and** the query gains `->withTranslationsFor()` — the seam docblock's "swaps those two methods and nothing else" is wrong, and without the eager load every post lazy-loads (an N+1 the query-count budget cannot see, see *Tests*). Update that docblock. The row shape is already `title: ?string`.
- `Actions/Blog/NotifyScheduledBlogPostPublishFailed.php` (~78): it reads `$post->title` inside a `catch (Throwable) { report }`, so a failing lazy query would silently drop the alert. Re-read the post with `with('translations')` at ~52 and compute the title there, before the dedupe `Cache::add`. `Notifications/BlogPostPublished.php` (~61) and its docblock at ~30-34 ("0078 has not shipped"): a frozen title snapshot, see **D-17**. `Actions/Blog/RestoreBlogPost.php` ~39 names "body, slug" as parent attributes in a comment — reword.
- `database/factories/BlogPostFactory.php` (**D-15**) and `database/seeders/DemoDataSeeder.php`: the class docblock (~26-29) justifies not using `WithoutModelEvents` by the parent hook; reword it to name the child hook (the run itself still fires it). The factory's default step mints a fake `StoreLanguage` when none exists; in the demo command that is a fake row in a real database — the seeder should assert a default exists first (0076 behaves the same; recorded, not fixed here).

Read rules. **Admin lists** show the default-language title, else `displayTitle()` (a post is never rendered nameless or dropped). **The editor** reads the raw default-language row. **Dashboard** resolves the store default language. A post with no translation at all is reachable only through `withoutTranslations()` or raw writes.

**D-15 — The factory follows `ProductFactory`, not `BlogTagFactory`.** `definition()` holds parent columns only. `configure()` registers `WritesDefaultBlogPostTranslation` as an identifiable `afterCreating` step (creating a default `StoreLanguage` if none exists), so `BlogPost::factory()->create()` keeps producing a titled post. `titled(string $title, ?string $body = null)` and `withoutTranslations()` swap **only** that step, because `BlogPostFactory` states may add their own `afterCreating` callbacks and `BlogTagFactory`'s whole-collection replace would silently drop them (0076 N-1). `titled()` rather than `named()`: a post has a title, not a name. **The default step does not copy `WritesDefaultProductTranslation`'s content generation:** that one uses non-unique `fake()->words(3)` and writes `description` only when a name is passed. For posts, slug is unique per language, so the step keeps `fake()->unique()->sentence(4)` for the title (what `BlogPostFactory` uses today) and `'<p>'.fake()->paragraph().'</p>'` for the body, otherwise duplicate-slug flakes and `NULL` bodies on `published()` posts (dashboard description, `DemoDataSeeder`) follow.

**D-16 — The translation write sits inside the existing transactions.** Both actions already wrap parent write + tag sync in `DB::transaction()`; the translation write joins it, so a refusal while resolving a tag leaves neither a post nor a translation. The notification dispatch stays **outside** (errors-log transaction-wrapper rule). `SetTranslation` neither authorizes nor validates, so each action's authorization stays above it — the only checkpoint in the path.

**D-17 — Notification titles are a frozen default-language snapshot.** `BlogPostPublished::toArray()['title']` already means "a literal taken at publication, not a relation read at render". It becomes `displayTitle()` (default language, else any stored translation); `ScheduledBlogPostPublishFailed` already accepts a null title and renders its `untitled` copy. **Settled (Phase 2):** nothing in `app/` or `resources/` reads the stored `data['title']`, so the payload type stays `string` and the unreachable no-translation case stores `displayTitle() ?? ''`. **The title must be fresh, not merely loaded:** `UpdateBlogPost` calls `refresh()` first, which reloads an already-loaded `translations`, then writes the new translation; `DB::afterCommit` then builds the notification. `loadMissing('translations')` does nothing on an already-loaded relation and would notify with the **old** title when a post is retitled and published in one save. So both actions call `$post->unsetRelation('translations')` (or `->load('translations')`) after the write and before the notification is built, and a test pins it. The sweep path passes a post with no translations loaded, which lazy-loads once — acceptable. `BlogPostPublished` is not queued and its listener is synchronous, so there is no serialization concern. A later rename never changes a stored notification.

**D-18 — Translated content adds no permission, ability or policy.** `blog.view/create/edit/delete` already exist; authoring a translation is using a configured language, not managing the catalog (0068 **D18**, 0070 **D-13**), so no `store-languages.*` permission is needed. No `TranslationPolicy`; no step-up.

## 5. Dependencies, risks, open technical questions

### Dependencies

All hard dependencies are **shipped** (`done/`): [0061](done/0061-blog-posts-core-crud-backend.md) (table, model, actions, validation trait, **D-3/D-7b/D-10/D-14/D-19a**), [0061a](done/0061a-blog-post-publish-with-future-date-schedules.md) and [0061b](done/0061b-blog-post-body-must-have-visible-content.md) (action behaviour preserved), [0063](done/0063-blog-posts-list-editor-ui.md) (readers), [0064](done/0064-scheduled-post-auto-publish-backend.md)/[0064b](done/0064b-scheduled-post-publish-failure-notification-backend.md) (sweep and failure notification), [0065](done/0065-blog-post-published-notification-backend.md), [0068](done/0068-store-languages-catalog-backend.md), [0070](done/0070-translatable-content-mechanism-product-categories-backend.md), [0072](done/0072-translatable-content-retrofit-blog-categories-backend.md), [0074](done/0074-translatable-content-retrofit-blog-tags-backend.md), [0076](done/0076-translatable-content-retrofit-products-backend.md) (backfill, guard and factory shapes), [0082](done/0082-dashboard-home-overview-backend.md) (the D-9 seam), and [0024a](done/0024a-product-description-html-sanitization.md) (`SanitizeProductDescription`, consumed unforked).

**Dependents:** [0079](0079-blog-post-editor-language-tabs-ui.md) (hard). **Parallel work:** [0076a](0076a-product-slug-and-seo-fields-backend.md) (products only; shares no file) and [0087](0087-translation-write-actions-refuse-inactive-store-language.md) (shares `lang/*` and possibly `tests/Unit/ArchitectureTest.php` with nothing here unless the architecture test is edited — if it is, add the file to `touches` and `0087` to `conflict_risk_with`). No new Composer package.

### Risks

- **R-1 — The reader migration is wide.** Nine production files and a long tail of tests. Mitigation: the factory default keeps most tests unchanged; the mechanical `titled()` edits are one commit; the full suite is the gate. A reader missed here fails at runtime with an unknown-attribute `null`, not an exception, so the *grep acceptance criterion* is load-bearing, not decorative.
- **R-2 — A list that eager-loads whole translation rows reintroduces the `mediumText` problem (D-13).** Pinned by a test on the generated column list.
- **R-3 — The backfill is the only copy of the data once the drop migration runs.** Guards: `assertCanBackfill()` before DDL, the drop migration's per-row guard, and the trashed-row test. A production-sized `blog_posts` table is copied in 500-row chunks.
- **R-4 — Slug race.** The pre-flight is advisory; the unique index has the last word. The catch must match the **index name**; the draft's `str_contains($message, 'slug')` would also match any future column or index containing that word and would miss a renamed index.
- **R-5 — `refresh()` and loaded relations.** `UpdateBlogPost::refresh()` reloads loaded relations too, which is the correct direction; `Editor::mount()` must not cache a stale `translations` collection across saves.
- **R-6 — Notification payload drift.** A stored notification keeps its snapshot; only new ones change source. No data migration of existing notification rows.
- **R-7 — `down()` loses data (D-7).** Accepted, documented, and the same as every sibling.
- **R-8 — Tests that hard-code `title`.** `titled()` removes the need; a test that still passes `'title' =>` to `create()` fails loudly (1054 or unknown column), which is the desired behaviour.

### Open questions for the product owner

Each carries a recommendation per [contracts.md](../../docs/contracts.md)'s Uncertainty Handling Rule. **Both are now closed (Q-1 on 2026-11-08, Q-2 on 2026-08-30).**

**Q-1 — ✅ RESOLVED 2026-11-08 (project owner) — option (a), derived and required.** The slug stays exactly as 0061 shipped it; **D-2**, **D-4** and **D-11** stand as written. Question as asked: stays the post slug *derived and required* (0061's shipped behaviour), or does it become an *editable, nullable* per-language field as 0076a made it for products? 0076 split the product slug off into 0076a with a different shape (canonicalised on write, nullable, user-editable). The blog editor has no slug input today.
- **(a) Derived and required — _(recommended)_.** Preserves shipped behaviour exactly, makes this a pure retrofit, keeps every post addressable, and leaves slug editing to a story that adds the input (0079 or later). Cost: blog and product slugs behave differently until then.
- **(b) Editable and nullable, matching 0076a.** Consistent across entities, but it changes behaviour 0061 shipped and adds an input no screen has, so it is a new-feature story, not a retrofit.

**Q-2 — Do meta title/description join `blog_post_translations` now?** *Resolved 2026-08-30: option (a), out of scope here* (see **D-1**). Restated only because 0076a has since added them for products; re-open only if the owner wants the two entities aligned. Adding them later is purely additive.

## 6. Technical tasks for later backlog creation

None in scope for 0078.

1. **0079** inherits: the per-entity `SetBlogPostTranslation` action (authorize `update`, re-read the post and language via `StoreLanguage::query()->active()` and **refuse an inactive language** per the 0075 shape, own `Validator`, sanitizer **and** `BlogBodyHasVisibleContent`, 1062 re-keyed by index name), and `bodyRules()` scoped so publishing requires a body in the **default** language only (Q-1(a) of the draft, resolved 2026-08-30) — otherwise adding a store language would retroactively unpublish every post.
2. **A flat public `/blog/{slug}` route** would need slug uniqueness across languages (**D-5**).
3. **Surface "this language was removed and still holds content"** in 0069's picker (its backlog item 3, still unaddressed after four translation tables).

## Provenance

**Phase 1 Three Amigos debate, 2026-08-29.** Facilitator `product-owner`; `database-expert` contributed the child-table shape, the three-index derivation and the trashed-row backfill finding; `backend-expert` and `backend-qa` were dispatched but the draft recorded their input as incomplete (its R-13).

**Rewrite, 2026-11-07.** The draft predated every dependency it cites. This revision was re-derived from the code at `HEAD` `64f6499` (models, actions, validation trait, the nine reader files, factories, `config/store-languages.php`, `tasks-status.json`) and from the epic-5 digest and the shipped 0072/0074/0076 files, not from the draft's text. Decisions D-1 to D-9 and D-11 are the draft's, kept where still correct; D-10, D-12, D-13 to D-17 are new or substantially corrected. **Phase 2 — INVEST validation and documentation check, 2026-11-08, against `HEAD` `a8d571d`.** Three read-only reviewers were dispatched in parallel (no write sets, so no ownership conflict): `code-reviewer`, `backend-expert` and `backend-qa` (the latter two re-dispatched as the draft's backlog item asked, its specialist input having been stale).
- **`code-reviewer` — ✅ APPROVED, non-blocking findings only.** Verified D-12 signatures, the D-14 reader list (complete), D-3, D-5, D-6, D-11 and D-15 against the code. INVEST: Independent, Negotiable, Valuable, Estimable, Testable pass; **Small borderline pass** — no 0076/0076a-style split is warranted, because the slug already exists, stays derived (Q-1(a)) and its column is dropped by this story, and the readers cannot ship apart from the column drop. Findings folded in: D-17's `loadMissing` staleness and its unsettled fallback, `tasks-status.json` out of date, the test-migration scope, the factory content generation, two stale docblocks, the unactionable slug scenario, and the `updated_at` consequence.
- **`backend-expert` — feasible with listed changes, no design blockers.** Folded in: the slug scenario rewrite, the `GetLatestBlogPosts` eager load and the query-count blind spot, the notification-title freshness and the failure-notifier silent-drop risk, action ordering and the index-name constant, `Index`/`Editor` details, the migration drop shape.
- **`backend-qa` — not ready for TDD as first written.** Folded in: the real test-migration scope (31 files including 3 Browser, rewrites not migrations), missing tests for D-9/D-12/D-15/D-17/D-18 and the missing-default-language path, un-drivable and vacuous Gherkin scenarios, and the placement/running conventions. Not taken: nothing was declined.

**Re-confirmation by `code-reviewer`, 2026-11-08, on `19ed416`: REJECTED on one point only, since fixed.** All eight earlier findings were confirmed resolved in the text, the amendments were found free of contradiction, and the code claims spot-checked held. The single blocker was in the coordination file, not the story: `tasks-status.json` `touches` omitted `tests/Support/Dashboard/DomainQueryLog.php`, `tests/Feature/StoreLanguages/**` and `tests/Unit/**`, which the story says it writes. Those three paths were added in the same pass as the map note; `conflict_risk_with` stays empty (0076a and 0087 write none of them). The reviewer was not re-run for this coordination-file fix; the rest of its verdict stands as **APPROVED to proceed to Phase 3**. **Not run:** TDD (Phase 3), security audit (Phase 4), code review (Phase 5), docs pass (Phase 6).
