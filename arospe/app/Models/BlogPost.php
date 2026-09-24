<?php

namespace App\Models;

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
use Illuminate\Support\Str;

/**
 * A blog post -- Epic 4's central entity (story 0061).
 *
 * The third model in this repo to use SoftDeletes, after App\Models\User and App\Models\Customer
 * (D-7 called it the second; Customer landed in between): a post is authored
 * content whose accidental deletion must be recoverable, unlike the two blog taxonomies, which
 * hard-delete. There is deliberately NO `delete()` override: unlike User, a trashed post keeps its
 * slug, so a restore returns it to its own URL and the unique index keeps the slug reserved (D-7b).
 *
 * @property string $id
 * @property string $blog_category_id
 * @property string $title
 * @property string $slug
 * @property string|null $body
 * @property BlogPostStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read BlogCategory $category
 * @property-read Collection<int, BlogTag> $tags
 */
#[Fillable(['title', 'body', 'blog_category_id', 'status'])]
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Length of `title` and of `slug` in the migration, and the `max:` its validation rule enforces
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
     * `slug` is derived from the mutable `title`, so it is locked at the model layer rather than
     * left to every writer to remember (D-3): omitted from #[Fillable], and rewritten here whenever
     * a save changes `title`. `Str::slug()`, explicitly not App\Actions\NormalizeForSearch (D-10).
     *
     * `booted()`, not `boot()`: App\Models\Role's `boot()` is a vendor-hook-ordering workaround that
     * does not apply to a model extending Eloquent's Model directly.
     */
    protected static function booted(): void
    {
        static::saving(function (self $post): void {
            if ($post->isDirty('title')) {
                $post->slug = Str::slug($post->title);
            }
        });
    }

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
     * Written only by App\Actions\Blog\SyncBlogPostTags.
     *
     * @return BelongsToMany<BlogTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag', 'blog_post_id', 'blog_tag_id');
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
