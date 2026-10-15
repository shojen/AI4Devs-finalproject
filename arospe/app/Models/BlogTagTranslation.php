<?php

namespace App\Models;

use App\Actions\NormalizeForSearch;
use Database\Factories\BlogTagTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Story 0074: one row per (blog tag, store language). `name` is form-supplied and so fillable;
 * `blog_tag_id` and `store_language_id` are written only by
 * App\Actions\Translations\SetTranslation's explicit key list, and `normalized_name` is DERIVED
 * (D-4) and therefore never fillable.
 *
 * @property string $id
 * @property string $blog_tag_id
 * @property string $store_language_id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class BlogTagTranslation extends Model
{
    /** @use HasFactory<BlogTagTranslationFactory> */
    use HasFactory, HasUuids;

    /**
     * Derives `normalized_name` from the mutable `name` (D-4), so SetTranslation's generic
     * updateOrCreate needs no knowledge of the column. Also re-derived when `normalized_name`
     * itself was assigned, so a forced value can never decouple the key from its name.
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
     * @return BelongsTo<BlogTag, $this>
     */
    public function blogTag(): BelongsTo
    {
        return $this->belongsTo(BlogTag::class);
    }

    /**
     * @return BelongsTo<StoreLanguage, $this>
     */
    public function storeLanguage(): BelongsTo
    {
        return $this->belongsTo(StoreLanguage::class);
    }
}
