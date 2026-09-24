# Blog Routes

Part of [Routes](routes.md) — see [routes.md](routes.md#why-this-file-exists) for the full app-owned route table and the shared module-gate pattern. This file covers the Blog area's permission-gated routes; today that is the tags screen. Blog categories (story 0062) and the post list/editor (story 0063) append their own subsections here.

## Table of Contents

- [`blog-tags.index` — the thirteenth permission-gated route](#blog-tagsindex--the-thirteenth-permission-gated-route)

### `blog-tags.index` — the thirteenth permission-gated route

Story 0060 (route, component, view, sidebar entry). The first Blog screen, and the first story to touch `routes/`, `config/modules.php` or `lang/` for the Blog area at all. It consumes [story 0059](../../ai-spec/tasks/done/0059-blog-tags-backend.md)'s model, policy and three actions unchanged.

```php
// routes/blog-tags.php
use App\Livewire\BlogTags\Index as BlogTagsIndex;   // aliased: `Index` is ambiguous across areas

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:blog.view`, not Spatie's `permission:` — Livewire 4's PersistentMiddleware
    // allowlist carries `can:` but not `permission:`. See architecture/authorization.md.
    Route::livewire('blog/tags', BlogTagsIndex::class)
        ->middleware(['can:blog.view'])
        ->name('blog-tags.index');
});
```

The URI is nested (`/blog/tags`) and the name is flat (`blog-tags.index`), matching `sales-regions.index`: it leaves `blog-categories.index` and a bare `blog.index` (the post list) free.

- **Access.** The route gates on `blog.view`; `mount()` re-checks `viewAny` (unlogged, like the other admin screens, because a `can:` refusal is unreachable over HTTP). Create, rename and delete each require their own ability (`blog.create` / `blog.edit` / `blog.delete`), so a role holding `blog.edit` without `blog.view` gets a 403 and its grant is unreachable — the same fail-closed shape [architecture/authorization.md](../architecture/authorization/how-to-gate.md) documents for `users.index`.
- **Every public method authorizes, and every refusal is logged.** `openCreateModal()`, `openEditModal()`, `save()` (per branch), `confirmDelete()` and `deleteTag()` route through [`LogRefusedPrivilegedAttempt`](../architecture/authorization/step-up-and-refusal-logging.md#recording-a-refusal--what-every-gate-owes-the-audit-trail) with `target_type: 'blog_tag'` passed explicitly. This is [`BlogTagPolicy`](../../app/Policies/BlogTagPolicy.php)'s first *component* call site. The actions authorize again themselves — defence in depth, not duplication.
- **Validation lives in the actions, not the component.** `save()` calls `CreateBlogTag` / `RenameBlogTag` and lets their `ValidationException` (keyed `name`) propagate into the error bag; the component composes no validation trait and never calls `$this->validate()`. It never calls `FindOrCreateBlogTag` — that would turn every duplicate refusal into a silent success.
- **The `->ignore()` id is server-authoritative.** `$editingTagId` is `#[Locked]` and assigned only from a row just re-read in `openEditModal()`; `save()` re-reads with `findOrFail()` and hands the model to `RenameBlogTag`. `$tags`, `$deletingTagId` and `$deletingTagName` are `#[Locked]` too.
- **Deleting is unconditional, and that is the contract.** PRD Epic 4: a deleted tag is removed from every post that used it. The delete-confirmation modal names the tag and says so; it renders **no** usage count, blocked state, reassign step or error outlet, and its destructive button is never disabled. This is the deliberate inverse of the product-categories screen. The detach-from-posts half is honoured by the database (story 0061's `blog_post_tag` must `cascadeOnDelete()`), not by this screen.
- **Rows** are `{id, name, canEdit, canDelete}` ordered `name ASC, id ASC`; there is no post or usage-count key (`BlogTag` has no `posts()` relation yet). `canEdit`/`canDelete` are `Gate::allows()` UI hints from the same policy methods the mutating methods authorize against. The header's *New tag* button is likewise gated through `@can('create', BlogTag::class)`.
- **`data-test` hooks:** `create-blog-tag-button`, `edit-blog-tag-{id}`, `delete-blog-tag-{id}` (both present on the enabled *and* the disabled branch), `blog-tag-name-input`, `confirm-delete-blog-tag`; the sidebar renders `sidebar-group-content`, `sidebar-cluster-blog` and `sidebar-link-blog_tags`. Both row `wire:click` arguments are single `@js()` calls.
- **Sidebar.** [`config/modules.php`](../../config/modules.php) gained `groups.content`, a nested `clusters.blog` (`group: 'content'`) and `items.blog_tags` (`group: null, cluster: 'blog'`, `permissions: ['blog.view']` — exactly the route's ability). Stories 0062 and 0063 append one `items.*` entry each with `cluster: 'blog'` and declare no new group or cluster.
- **Topbar.** The view declares the `heading`/`subheading` slots (`blog-tags.index.title`, `topbar.blog_tags.subtitle`); `blog-tags.index` is in `TopbarTest`'s screen dataset, which fails for any authenticated screen missing from it.
- **Copy** lives in `lang/{en,es}/blog-tags.php`; there is deliberately no "blocked" or count copy.
