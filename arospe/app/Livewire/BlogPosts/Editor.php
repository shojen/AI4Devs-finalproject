<?php

namespace App\Livewire\BlogPosts;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Actions\NormalizeForSearch;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The routed blog post create/edit screen (story 0063, layer 2): a full page rather than a modal
 * (D-1), so the WYSIWYG's `wire:ignore`d region -- seeded from `$body` at client initialisation
 * only (0021 D9) -- is a fresh mount for every post, and a save can safely redirect.
 *
 * `blog-posts.create` and `blog-posts.edit` both resolve this class; mount() branches on whether a
 * `BlogPost` was route-model-bound (a trashed post's edit URL is already a 404 by default binding).
 * Both routes gate on `can:blog.view`; the finer `create`/`update` abilities are authorized here,
 * through LogRefusedPrivilegedAttempt with `target_type: 'blog_post'` passed explicitly (it
 * auto-resolves only User and Role targets), and again by CreateBlogPost/UpdateBlogPost themselves.
 *
 * The actions OWN the validation rules: this component neither composes BlogPostValidationRules nor
 * calls `$this->validate()`. Livewire only persists an error whose key is a declared public
 * property, so save() catches the ValidationException and RE-KEYS it (`blog_category_id` ->
 * `blogCategoryId`, `published_at` -> `publishedAt`, `tag_names`/`name` -> `tagNames`) so each
 * message renders beside the field it belongs to.
 *
 * ⚠️ THE TAG CHIP FIELD MUST NEVER HIDE A TAG. `$tagNames` is the post's COMPLETE set, hydrated from
 * every tag the post holds and resubmitted whole on every save, and the chips render ALL of it --
 * never filtered, truncated or paginated. UpdateBlogPost replaces the tag set with `sync()`, so a
 * tag the editor cannot see is a tag a save silently revokes (0061 D-17): an omission is only an
 * editor's decision while the field shows everything the post holds. A chip whose name is blank or
 * unresolvable in some language (0074's retrofit) must still be present, still removable and still
 * submitted. The tag row itself is never deleted by a save, only its link to this post.
 *
 * Tags are reached ONLY through the post actions (an architecture test fences SyncBlogPostTags and
 * FindOrCreateBlogTag out of this namespace): the whole save is one transaction, so a refused new
 * tag name rolls the post back with it. Nothing is created until Save. Whether the actor may mint a
 * new tag (`blog.create`) is a UI hint only -- the disabled control changes no server behaviour, and
 * the action's own refusal remains the control.
 *
 * Written against the pre-Epic-5 schema (`blog_posts.title/body`, `blog_tags.name`).
 */
#[Title('Blog post editor')]
class Editor extends Component
{
    /**
     * Upper bound on the suggestion list: enough to choose from, small enough that a one-letter
     * search on a large catalog stays a cheap query and a short list.
     */
    private const SUGGESTION_LIMIT = 8;

    /**
     * The error-bag keys the actions throw that are not declared public properties here, and the
     * property each one is shown against. Every other key (`title`, `body`, `status`) already is one.
     *
     * @var array<string, string>
     */
    private const RE_KEYED_ERRORS = [
        'blog_category_id' => 'blogCategoryId',
        'published_at' => 'publishedAt',
        'tag_names' => 'tagNames',
        'name' => 'tagNames',
    ];

    /**
     * null => create. Written only from the route-bound post's id inside mount(), never a client
     * argument; #[Locked] so a forged snapshot cannot re-point save() at another post.
     */
    #[Locked]
    public ?string $blogPostId = null;

    // --- form fields, all bound with wire:model. NONE of them is ever null (D-6) ---
    public string $title = '';

    /** '' matches the placeholder <option value="">, which is genuinely disabled: a category is required. */
    public string $blogCategoryId = '';

    /**
     * Plain string, deliberately NOT BlogPostStatus (0061 D-12, task 0015's finding F8): Livewire's
     * EnumSynth hydrates a client-supplied backing value through `from()` BEFORE validation, so a
     * typed enum property would turn a forged value into an uncaught \ValueError. The action refuses
     * an unknown status by validation; nothing here casts it.
     */
    public string $status = BlogPostStatus::Draft->value;

    /** `datetime-local`'s own native empty value; hydrated at SECONDS precision (see mount()). */
    public string $publishedAt = '';

    /** The WYSIWYG's #[Modelable] target. */
    public string $body = '';

    /**
     * The post's COMPLETE tag-name set -- see the class docblock. Bounded at the mutation point by
     * BlogPost::MAX_TAGS, the same maximum the action enforces on save.
     *
     * @var list<string>
     */
    public array $tagNames = [];

    /** The text being typed into the tag field; drives the debounced suggestions. Never null. */
    public string $tagInput = '';

    /**
     * mount() authorizes `create`/`update` as its own first statement. A finer ability than the
     * route's `can:blog.view`, so a refusal here IS reachable over HTTP and is logged.
     */
    public function mount(LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt, ?BlogPost $blogPost = null): void
    {
        $logRefusedPrivilegedAttempt->authorize(
            $blogPost === null ? 'create' : 'update',
            $blogPost ?? BlogPost::class,
            targetType: 'blog_post',
            targetId: $blogPost?->id,
        );

        if ($blogPost === null) {
            return;
        }

        $this->blogPostId = $blogPost->id;
        $this->title = $blogPost->title;
        $this->blogCategoryId = $blogPost->blog_category_id;
        $this->status = $blogPost->status->value;
        // Seconds precision, on purpose: a minute-precision value would resubmit 10:20:00 for a post
        // scheduled at 10:20:33, which is not the stored instant, so UpdateBlogPost's "same date"
        // exemption would not apply and retitling an overdue Scheduled post would be refused (R-9).
        // The application timezone is UTC, and so is the value.
        $this->publishedAt = $blogPost->published_at?->format('Y-m-d\TH:i:s') ?? '';
        $this->body = $blogPost->body ?? '';
        $this->tagNames = array_values($blogPost->tags()->orderBy('blog_tags.name')->pluck('blog_tags.name')->all());
    }

    /**
     * Confirm the text typed into the tag field as a chip.
     */
    public function addTypedTag(): void
    {
        $this->addTag($this->tagInput);
    }

    /**
     * Add one tag name to the chip set: trimmed, ignored when blank, deduplicated case- and
     * accent-insensitively (the same fold the server resolves names by) and bounded at the mutation
     * point. Creates nothing -- the tag row is resolved or minted only by Save.
     */
    public function addTag(string $name): void
    {
        $this->resetErrorBag('tagNames');

        $name = trim($name);
        $normalize = app(NormalizeForSearch::class);
        $key = $normalize($name);

        if (trim($key) === '') {
            $this->tagInput = '';

            return;
        }

        if (mb_strlen($name) > BlogTag::NAME_MAX_LENGTH) {
            $this->addError('tagNames', __('blog-posts.editor.tag_too_long', ['max' => BlogTag::NAME_MAX_LENGTH]));

            return;
        }

        $this->tagInput = '';

        if (in_array($key, array_map($normalize, $this->tagNames), true)) {
            return;
        }

        if (count($this->tagNames) >= BlogPost::MAX_TAGS) {
            $this->addError('tagNames', __('blog-posts.editor.tags_limit', ['max' => BlogPost::MAX_TAGS]));

            return;
        }

        $this->tagNames[] = $name;
    }

    /**
     * Remove one chip by its exact name. Nothing else is touched.
     */
    public function removeTag(string $name): void
    {
        $this->resetErrorBag('tagNames');

        $this->tagNames = array_values(array_filter($this->tagNames, fn (string $tag): bool => $tag !== $name));
    }

    /**
     * Persist the create/edit form through CreateBlogPost / UpdateBlogPost and return to the list.
     *
     * The target is re-read (`findOrFail()`: a post deleted while the editor was open is a 404, never
     * a resurrection) and re-authorized at click time. `publishedAt` is sent ONLY when the status is
     * Scheduled: the date field is merely hidden for the other statuses, so a value typed under
     * Scheduled and left behind must never silently schedule a Published post (0061a). The action is
     * its own transaction -- it is not wrapped again here -- and a refusal is re-keyed onto the
     * declared properties (see the class docblock). A success redirects; the form is never reset in
     * place (0021 D9 -- a server-side write to `$body` would not appear in the wire:ignore'd editor).
     */
    public function save(
        CreateBlogPost $createBlogPost,
        UpdateBlogPost $updateBlogPost,
        LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ): mixed {
        $blogPost = $this->blogPostId === null ? null : BlogPost::findOrFail($this->blogPostId);

        $logRefusedPrivilegedAttempt->authorize(
            $blogPost === null ? 'create' : 'update',
            $blogPost ?? BlogPost::class,
            targetType: 'blog_post',
            targetId: $blogPost?->id,
        );

        $this->resetErrorBag();

        $publishedAt = $this->status === BlogPostStatus::Scheduled->value && $this->publishedAt !== ''
            ? $this->publishedAt
            : null;

        try {
            if ($blogPost === null) {
                $createBlogPost(
                    title: $this->title,
                    body: $this->body,
                    blogCategoryId: $this->blogCategoryId,
                    status: $this->status,
                    publishedAt: $publishedAt,
                    tagNames: $this->tagNames,
                );
            } else {
                $updateBlogPost(
                    $blogPost,
                    title: $this->title,
                    body: $this->body,
                    blogCategoryId: $this->blogCategoryId,
                    status: $this->status,
                    publishedAt: $publishedAt,
                    tagNames: $this->tagNames,
                );
            }
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->rekeyErrors($exception->errors()));
        }

        return $this->redirectRoute('blog-posts.index');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return array_values(
            BlogCategory::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn (BlogCategory $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all()
        );
    }

    /**
     * @return list<BlogPostStatus>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return BlogPostStatus::cases();
    }

    /**
     * Tag names matching what is typed, for the suggestion list: matched on the folded
     * `normalized_name` (so case and accents never matter), LIKE wildcards in the typed text taken
     * literally, names already on the post excluded (compared by the same fold), a small fixed
     * limit. Gated on viewing the tag catalog -- the same ability FindOrCreateBlogTag asks for a
     * reuse -- through the logging helper, with the refusal attributed to a `blog_tag`.
     *
     * @return list<string>
     */
    #[Computed]
    public function tagSuggestions(): array
    {
        $term = $this->tagSearchTerm();

        if ($term === '') {
            return [];
        }

        $this->authorizeTagLookup();

        $normalize = app(NormalizeForSearch::class);

        return array_values(
            BlogTag::query()
                ->where('normalized_name', 'like', '%'.addcslashes($term, '\\%_').'%')
                ->whereNotIn('normalized_name', array_map($normalize, $this->tagNames))
                ->orderBy('name')
                ->orderBy('id')
                ->limit(self::SUGGESTION_LIMIT)
                ->pluck('name')
                ->all()
        );
    }

    /**
     * Whether the typed name matches no existing tag (folded), i.e. adding it would MINT one. A hint
     * for the add control only.
     */
    #[Computed]
    public function tagNameIsNew(): bool
    {
        $term = $this->tagSearchTerm();

        if ($term === '') {
            return false;
        }

        $this->authorizeTagLookup();

        return ! BlogTag::query()->where('normalized_name', $term)->exists();
    }

    /**
     * Whether the actor may mint a new tag. A UI hint over the server-side refusal inside the post
     * action, which remains the real control.
     */
    #[Computed]
    public function canCreateTags(): bool
    {
        return Gate::allows('create', BlogTag::class);
    }

    /**
     * The typed text folded the way the server folds tag names, capped at a tag name's length so an
     * oversized client-written value never reaches the query.
     */
    private function tagSearchTerm(): string
    {
        return app(NormalizeForSearch::class)(mb_substr($this->tagInput, 0, BlogTag::NAME_MAX_LENGTH));
    }

    private function authorizeTagLookup(): void
    {
        app(LogRefusedPrivilegedAttempt::class)->authorize('viewAny', BlogTag::class, targetType: 'blog_tag');
    }

    /**
     * Moves each message onto the declared public property it is displayed against, merging the
     * messages of keys that share one target.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private function rekeyErrors(array $errors): array
    {
        $rekeyed = [];

        foreach ($errors as $key => $messages) {
            $target = self::RE_KEYED_ERRORS[$key] ?? $key;
            $rekeyed[$target] = [...($rekeyed[$target] ?? []), ...$messages];
        }

        return $rekeyed;
    }
}
