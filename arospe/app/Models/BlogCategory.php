<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use App\Concerns\Translatable;
use Database\Factories\BlogCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blog category -- deliberately a standalone taxonomy, sharing no table, model or namespace with
 * App\Models\ProductCategory (story 0058, D-11).
 *
 * Story 0072 (D-2): carries no translatable column of its own any more -- every category's name
 * lives in App\Models\BlogCategoryTranslation, one row per store language, read through
 * App\Concerns\HasTranslations::translated('name').
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class BlogCategory extends Model implements Translatable
{
    /** @use HasFactory<BlogCategoryFactory> */
    use HasFactory, HasTranslations, HasUuids;

    /**
     * Length of `name` and of `normalized_name` on `blog_category_translations`, and the `max:`
     * its validation rule enforces (R-4). Kept in one place so the three cannot drift apart.
     */
    public const NAME_MAX_LENGTH = 255;

    /**
     * The relation App\Actions\Blog\DeleteBlogCategory counts through to hard-block deletion while
     * any post still uses this category (story 0061, D-18). That count must say `withTrashed()`.
     *
     * @return HasMany<BlogPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'blog_category_id');
    }

    protected function translationModel(): string
    {
        return BlogCategoryTranslation::class;
    }
}
