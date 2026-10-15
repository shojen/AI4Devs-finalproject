<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use App\Concerns\Translatable;
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
 * Story 0074 (D-2): identity-only -- every tag's name lives in App\Models\BlogTagTranslation, one
 * row per store language, read through HasTranslations::translated('name').
 *
 * Not soft-deleted, on purpose (D-5): the `blog_post_tag` pivot story 0061 adds cascades on this
 * table's DELETE, and a soft delete is an UPDATE, so the cascade would never fire.
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class BlogTag extends Model implements Translatable
{
    /** @use HasFactory<BlogTagFactory> */
    use HasFactory, HasTranslations, HasUuids;

    /**
     * Length of `name` on `blog_tag_translations` and the `max:` its validation rule enforces
     * (R-4). Kept in one place so the two cannot drift apart.
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

    protected function translationModel(): string
    {
        return BlogTagTranslation::class;
    }
}
