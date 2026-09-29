<?php

namespace App\Models;

use App\Actions\NormalizeForSearch;
use Database\Factories\BlogTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A blog tag -- deliberately a standalone taxonomy, sharing no table, model or namespace with
 * App\Models\BlogCategory or App\Models\ProductCategory (story 0059).
 *
 * Not soft-deleted, on purpose (D-5): the `blog_post_tag` pivot story 0061 adds cascades on this
 * table's DELETE, and a soft delete is an UPDATE, so the cascade would never fire.
 *
 * @property string $id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class BlogTag extends Model
{
    /** @use HasFactory<BlogTagFactory> */
    use HasFactory, HasUuids;

    /**
     * Length of `name` in the migration and the `max:` its validation rule enforces (R-4). Kept in
     * one place so the two cannot drift apart.
     */
    public const NAME_MAX_LENGTH = 100;

    /**
     * Length of `normalized_name` in the migration -- a hard ceiling on the FOLDED name, not
     * headroom: the fold can be up to 5x longer than its input, so validation refuses a name whose
     * fold would not fit (R-4).
     */
    public const NORMALIZED_NAME_MAX_LENGTH = 255;

    /**
     * The posts carrying this tag, through `blog_post_tag` (story 0061). Read-only from here: the
     * pivot is written by App\Actions\Blog\SyncBlogPostTags alone. A plain `withCount('posts')`
     * excludes trashed posts, which is the right number for a screen (D-7c).
     *
     * @return BelongsToMany<BlogPost, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_tag', 'blog_tag_id', 'blog_post_id');
    }

    /**
     * `normalized_name` is the folded key the UNIQUE index guards and every lookup compares
     * against. It is derived from the mutable `name`, so it is locked at the model layer rather
     * than left to each of its writers to remember (D-4): omitted from #[Fillable], and rewritten
     * here whenever a save touches either column.
     *
     * `booted()`, not `boot()`: App\Models\Role's `boot()` is a vendor-hook-ordering workaround
     * that does not apply to a model extending Eloquent's Model directly.
     *
     * Also re-derived when `normalized_name` itself was assigned, not only when `name` was --
     * otherwise `$tag->normalized_name = 'x'` followed by a save that leaves `name` clean would
     * persist a key that no longer matches the name. An unrelated save (neither column dirty)
     * still leaves the column alone.
     */
    protected static function booted(): void
    {
        static::saving(function (self $tag): void {
            if ($tag->isDirty('name') || $tag->isDirty('normalized_name')) {
                $tag->normalized_name = app(NormalizeForSearch::class)($tag->name);
            }
        });
    }
}
