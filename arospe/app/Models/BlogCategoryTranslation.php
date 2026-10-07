<?php

namespace App\Models;

use App\Actions\NormalizeForSearch;
use Database\Factories\BlogCategoryTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Story 0072: one row per (blog category, store language). `name` is genuinely form-supplied and
 * so is fillable; `blog_category_id` and `store_language_id` are written only by
 * App\Actions\Translations\SetTranslation's explicit key list, and `normalized_name` is DERIVED
 * (D-3) and therefore never fillable.
 *
 * @property string $id
 * @property string $blog_category_id
 * @property string $store_language_id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class BlogCategoryTranslation extends Model
{
    /** @use HasFactory<BlogCategoryTranslationFactory> */
    use HasFactory, HasUuids;

    /**
     * `normalized_name` is the folded key the per-language UNIQUE index guards. It is derived from
     * the mutable `name`, so it is locked at the model layer rather than left to every writer to
     * remember (D-3). Also re-derived when `normalized_name` itself was assigned, so a forced
     * value can never decouple the key from the name it represents.
     *
     * `booted()`, not `boot()`: this class extends Eloquent's Model directly.
     */
    protected static function booted(): void
    {
        static::saving(function (self $translation): void {
            if ($translation->isDirty('name') || $translation->isDirty('normalized_name')) {
                $translation->normalized_name = app(NormalizeForSearch::class)($translation->name);
            }
        });
    }

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function blogCategory(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class);
    }

    /**
     * @return BelongsTo<StoreLanguage, $this>
     */
    public function storeLanguage(): BelongsTo
    {
        return $this->belongsTo(StoreLanguage::class);
    }
}
