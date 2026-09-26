<?php

namespace App\Livewire\BlogPosts;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Blog\DeleteBlogPost;
use App\Actions\Blog\RestoreBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Blog post list screen: title, category, status badge, date, per-row edit/delete actions, two
 * URL-bound taxonomy filters, a "New post" action and a collapsed section listing deleted posts
 * with a restore action -- story 0063 (layer 1; the routed editor is App\Livewire\BlogPosts\Editor).
 *
 * Writes nothing of its own: delete and restore go through story 0061's DeleteBlogPost and
 * RestoreBlogPost, and there is no force-delete anywhere (0061 D-20) -- restore is the only exit for
 * a deleted post, which is also what lets an editor free a category that a trashed post still blocks
 * from deletion (0061 D-7d).
 *
 * Gated on `blog.view` at the route (`can:blog.view`, routes/blog-posts.php) and again in mount() --
 * Livewire's `/livewire/update` endpoint never runs route middleware. Every mutating method
 * re-reads its target (`findOrFail()`, `withTrashed()` for a restore) and re-authorizes through
 * LogRefusedPrivilegedAttempt with `target_type: 'blog_post'` passed explicitly (it auto-resolves
 * only User and Role targets); the actions authorize again themselves, so the component's own check
 * is defence in depth. The per-row `canEdit`/`canDelete`/`canRestore` hints come from the SAME policy
 * abilities those methods authorize against, so a disabled control can never drift from what a click
 * would actually do -- and they are hints, never the control.
 *
 * Written against the pre-Epic-5 schema (`blog_posts.title`, `blog_categories.name`,
 * `blog_tags.name`). Whatever reads those columns does so in the two private row mappers below, so
 * the translatable-content retrofit has one place to change.
 */
#[Title('Blog posts')]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    /**
     * Upper bound on each filter dropdown's option set. The taxonomies are small lookup tables, but
     * an unbounded query on a select would be one runaway import away from a slow page.
     */
    private const FILTER_OPTIONS_LIMIT = 500;

    /**
     * The id of the category the list is narrowed to; '' means every category. A plain,
     * '' -defaulted string, never null: it is bound to a native select whose "All categories"
     * option is a legitimate, selectable value, and a null wire:model property desyncs its control.
     * Anything that is not the id of a known category is reset to '' -- see sanitizeFilters().
     */
    #[Url(as: 'category')]
    public string $categoryFilter = '';

    /** The id of the tag the list is narrowed to; '' means every tag. Same rules as above. */
    #[Url(as: 'tag')]
    public string $tagFilter = '';

    public bool $showDeleteModal = false;

    /**
     * Written only from a freshly re-read row's id, never from the raw method argument -- the
     * server-authoritative id deleteBlogPost() re-reads with findOrFail() before authorizing against
     * it. See docs/security/livewire-authorization.md.
     */
    #[Locked]
    public ?string $deletingBlogPostId = null;

    /** Never null: the modal renders it. */
    #[Locked]
    public string $deletingBlogPostTitle = '';

    /**
     * Deliberately left unlogged, like BlogTags\Index::mount(): the route's own `can:blog.view` gate
     * checks the identical ability, and `can:` IS on Livewire's PersistentMiddleware allow-list, so a
     * refusal here is unreachable over HTTP -- reachable only through a direct Livewire::test() call.
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', BlogPost::class);

        $this->sanitizeFilters();
    }

    /**
     * Changing a filter returns to page 1: WithPagination does not do it for an arbitrary property
     * change, and an editor filtering from page 3 would otherwise land on an empty page and read it
     * as "no results".
     */
    public function updatedCategoryFilter(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function updatedTagFilter(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    /**
     * Open the delete-confirmation modal, naming the target from a freshly re-read row and
     * authorizing BEFORE anything is disclosed or opened.
     */
    public function confirmDelete(string $blogPostId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogPost::findOrFail($blogPostId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_post', targetId: $target->id);

        $this->deletingBlogPostId = $target->id;
        $this->deletingBlogPostTitle = $target->title;
        $this->showDeleteModal = true;
    }

    /**
     * Authorize and soft-delete the confirmed post.
     *
     * Re-resolves a FRESH BlogPost::findOrFail($this->deletingBlogPostId) immediately before
     * authorizing -- never an instance carried in component state -- per
     * docs/security/model-instance-trust.md. It also fails closed (ModelNotFoundException) if the
     * target was deleted by someone else since confirmDelete().
     */
    public function deleteBlogPost(DeleteBlogPost $deleteBlogPost, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        if ($this->deletingBlogPostId === null) {
            return;
        }

        $target = BlogPost::findOrFail($this->deletingBlogPostId);

        $logRefusedPrivilegedAttempt->authorize('delete', $target, targetType: 'blog_post', targetId: $target->id);

        $deleteBlogPost($target);

        unset($this->posts, $this->trashedPosts);
        $this->closeDeleteModal();
    }

    /**
     * Close the delete-confirmation modal and reset its state, including the whole error bag: a
     * refused action followed by Cancel would otherwise leave a stale message rendering with no
     * field and no context (0018's blocking bug, recorded independently by 0025).
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset(['deletingBlogPostId', 'deletingBlogPostTitle']);
        $this->resetErrorBag();
    }

    /**
     * Restore a soft-deleted post through 0061's RestoreBlogPost.
     *
     * The target is resolved `withTrashed()`: the default query cannot see the row this section
     * exists to show, so a plain findOrFail() would 404 on every trashed post. Gated on `restore`
     * (`blog.edit`, not `blog.delete` -- 0061 D-20), the same ability the row's control hint reads.
     */
    public function restoreBlogPost(string $blogPostId, RestoreBlogPost $restoreBlogPost, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
    {
        $target = BlogPost::withTrashed()->findOrFail($blogPostId);

        $logRefusedPrivilegedAttempt->authorize('restore', $target, targetType: 'blog_post', targetId: $target->id);

        $restoreBlogPost($target);

        unset($this->posts, $this->trashedPosts);
    }

    /**
     * The paginated post list: EXPLICIT columns, never `body` (a mediumText kept inline in the
     * clustered index, 0061 R-7 -- a post body dwarfs a product description) and not `slug` either;
     * a category and tag eager load with their own column lists, so no row triggers a query; and a
     * `created_at DESC, id ASC` order -- never by title, which posts do not keep unique (0061 D-10).
     * The `id` tiebreak is a meaningful creation-order tiebreak under UUIDv7 and keeps two posts
     * sharing a timestamp from reshuffling between pages.
     *
     * Both filters use 0061's scopes (which apply the soft-delete scope, so trashed posts never
     * match) and compose.
     *
     * The row array's generic argument is `mixed` because LengthAwarePaginator's TValue template is
     * not covariant; see App\Livewire\Products\Index::products(). The row shape is
     * {id, title, categoryName, status, date, tags, canEdit, canDelete}.
     *
     * @return LengthAwarePaginator<int, mixed>
     */
    #[Computed]
    public function posts(): LengthAwarePaginator
    {
        return BlogPost::query()
            ->select(['id', 'blog_category_id', 'title', 'status', 'published_at', 'created_at'])
            ->with(['category:id,name', 'tags:id,name'])
            ->when($this->categoryFilter !== '', fn ($query) => $query->forCategory($this->categoryFilter))
            ->when($this->tagFilter !== '', fn ($query) => $query->forTag($this->tagFilter))
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (BlogPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'categoryName' => $post->category->name,
                'status' => $post->status,
                'date' => $this->formatDate($post->published_at ?? $post->created_at),
                'tags' => $post->tags->pluck('name')->all(),
                'canEdit' => Gate::allows('update', $post),
                'canDelete' => Gate::allows('delete', $post),
            ]);
    }

    /**
     * The deleted-posts section: only trashed rows, most recently deleted first, explicit columns
     * (no `body`, no `slug`), rendered independently of the filters -- it deliberately does NOT use
     * forCategory()/forTag(), whose default soft-delete scope would return nothing. `get()`, not a
     * paginator: a small, rarely-touched block, like Sales Regions' collapsed section.
     *
     * @return array<int, array{id: string, title: string, categoryName: string, deletedAt: string, canRestore: bool}>
     */
    #[Computed]
    public function trashedPosts(): array
    {
        return BlogPost::onlyTrashed()
            ->select(['id', 'blog_category_id', 'title', 'deleted_at'])
            ->with('category:id,name')
            ->orderByDesc('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (BlogPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'categoryName' => $post->category->name,
                'deletedAt' => $this->formatDate($post->deleted_at),
                'canRestore' => Gate::allows('restore', $post),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return BlogCategory::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::FILTER_OPTIONS_LIMIT)
            ->get()
            ->map(fn (BlogCategory $category): array => ['id' => $category->id, 'name' => $category->name])
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function tagOptions(): array
    {
        return BlogTag::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::FILTER_OPTIONS_LIMIT)
            ->get()
            ->map(fn (BlogTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])
            ->all();
    }

    /**
     * A filter is bookmarkable, so both properties accept arbitrary input from the URL; anything
     * that is not the id of a category / tag in the option sets is treated as "no filter", never a
     * 500 and never a query against a forged value.
     */
    private function sanitizeFilters(): void
    {
        if ($this->categoryFilter !== '' && ! collect($this->categoryOptions())->contains('id', $this->categoryFilter)) {
            $this->categoryFilter = '';
        }

        if ($this->tagFilter !== '' && ! collect($this->tagOptions())->contains('id', $this->tagFilter)) {
            $this->tagFilter = '';
        }
    }

    private function formatDate(?DateTimeInterface $date): string
    {
        return $date?->format('d/m/Y H:i') ?? '';
    }
}
