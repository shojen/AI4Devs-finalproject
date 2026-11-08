<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use App\Concerns\Translatable;
use App\Enums\BlogPostStatus;
use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A blog post -- Epic 4's central entity (story 0061).
 *
 * The third model in this repo to use SoftDeletes, after App\Models\User and App\Models\Customer
 * (D-7 called it the second; Customer landed in between): a post is authored
 * content whose accidental deletion must be recoverable, unlike the two blog taxonomies, which
 * hard-delete. There is deliberately NO `delete()` override: unlike User, a trashed post keeps its
 * translation rows, so a restore returns it to its own URL and the per-language unique index keeps the slug reserved (D-7b, 0078 D-5).
 *
 * @property string $id
 * @property string $blog_category_id
 * @property BlogPostStatus $status
 * @property Carbon|null $published_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read BlogCategory $category
 * @property-read Collection<int, BlogTag> $tags
 * @property-read User|null $creator
 * @property-read Collection<int, BlogPostTranslation> $translations
 */
#[Fillable(['blog_category_id', 'status'])]
class BlogPost extends Model implements Translatable
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory, HasTranslations, HasUuids, SoftDeletes;

    /**
     * Length of `title` and of `slug` on `blog_post_translations`, and the `max:` its validation rule enforces
     * (R-4). Kept in one place so the three cannot drift apart.
     */
    public const TITLE_MAX_LENGTH = 255;

    /**
     * The exclusive bounds of `published_at`. The column is a MySQL TIMESTAMP, which holds 1970-01-01
     * to 2038-01-19 03:14:07 UTC, and strict mode turns a date outside it into a raw 1292 error on
     * INSERT. The rule stops at the whole day before the ceiling and after the day of the floor, so a
     * timezone offset cannot walk a submitted date across either edge.
     */
    public const PUBLISHED_AT_AFTER = '1970-01-01';

    public const PUBLISHED_AT_BEFORE = '2038-01-19';

    /**
     * The most tags one save may carry. Each unknown name mints a tag row inside the save's single
     * transaction, so an unbounded list is an unbounded write.
     */
    public const MAX_TAGS = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BlogPostStatus::class,
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /**
     * The post's creator (story 0064b, D-7) -- deliberately absent from #[Fillable] and written only
     * by App\Actions\Blog\CreateBlogPost, from the authenticated actor, once, at creation.
     * `created_by` never changes afterwards: it means CREATOR, never "last editor".
     *
     * Resolves to null for BOTH a NULL column (a legacy row predating this column, or a post created
     * with no actor) and a SOFT-DELETED creator -- `users` uses SoftDeletes, so a deleted user's row is
     * excluded by the default SoftDeletingScope on User::query() and this relation returns null exactly
     * as if no creator had ever been recorded, with no withTrashed() anywhere. The caller
     * (App\Actions\Blog\NotifyScheduledBlogPostPublishFailed, D-8) treats both causes identically and
     * falls back to the blog.edit holders.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Written only by App\Actions\Blog\SyncBlogPostTags.
     *
     * @return BelongsToMany<BlogTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag', 'blog_post_id', 'blog_tag_id');
    }

    /**
     * The title to show: the default-language one, else whichever stored translation exists, so a post
     * left without a default-language title is never rendered nameless. Needs `translations` loaded.
     */
    public function displayTitle(): ?string
    {
        return $this->translated('title')
            ?? $this->translations->map(fn (BlogPostTranslation $translation): string => $translation->title)->first();
    }

    /**
     * Eager-load the requested and default languages (plus one fallback row for a post holding neither), with the columns a list needs (D-13):
     * `blog_post_id, store_language_id, title`. No `body` and no `slug`.
     *
     * Footgun: `translated('body')` on this partial relation returns null even when a body is stored.
     *
     * @param  Builder<BlogPost>  $query
     */
    public function scopeWithListTranslations(Builder $query, ?string $storeLanguageId = null): void
    {
        $ids = array_unique(array_filter([$storeLanguageId, StoreLanguage::defaultStoreLanguage()?->id]));

        $query->with(['translations' => fn ($q) => $q
            ->select(['blog_post_id', 'store_language_id', 'title'])
            ->where(function ($rows) use ($ids): void {
                $rows->whereIn('store_language_id', $ids)
                    ->orWhere(function ($fallback) use ($ids): void {
                        // A post with no requested/default row keeps ONE row of any language (the
                        // first stored), so displayTitle() never renders it nameless. Same query.
                        $fallback->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                            ->from('blog_post_translations as present')
                            ->whereColumn('present.blog_post_id', 'blog_post_translations.blog_post_id')
                            ->whereIn('present.store_language_id', $ids))
                            ->whereRaw('blog_post_translations.id = (select min(first_row.id) from blog_post_translations as first_row where first_row.blog_post_id = blog_post_translations.blog_post_id)');
                    });
            })]);
    }

    protected function translationModel(): string
    {
        return BlogPostTranslation::class;
    }

    /**
     * A query helper for the admin list's category filter (D-11) -- not authorization.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeForCategory(Builder $query, string $blogCategoryId): Builder
    {
        return $query->where('blog_category_id', $blogCategoryId);
    }

    /**
     * A query helper for the admin list's tag filter (D-11) -- not authorization.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeForTag(Builder $query, string $blogTagId): Builder
    {
        return $query->whereRelation('tags', 'blog_tags.id', $blogTagId);
    }
}
