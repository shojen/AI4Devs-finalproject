<?php

namespace App\Models;

use App\Actions\Products\SanitizeProductDescription;
use Database\Factories\ProductTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Story 0076: one row per (product, store language). `name` and `description` are form-supplied
 * and so fillable; `product_id` and `store_language_id` are written only by
 * App\Actions\Translations\SetTranslation's explicit key list.
 *
 * @property string $id
 * @property string $product_id
 * @property string $store_language_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class ProductTranslation extends Model
{
    /** @use HasFactory<ProductTranslationFactory> */
    use HasFactory, HasUuids;

    /**
     * Second sanitizer layer (D-8): binds every writer, including a bare SetTranslation call.
     * The actions still sanitize before validating (layer 1), so this is idempotent on that path.
     *
     * `booted()`, not `boot()`: this class extends Eloquent's Model directly.
     */
    protected static function booted(): void
    {
        static::saving(function (self $translation): void {
            if ($translation->isDirty('description')) {
                $translation->description = app(SanitizeProductDescription::class)($translation->description);
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<StoreLanguage, $this>
     */
    public function storeLanguage(): BelongsTo
    {
        return $this->belongsTo(StoreLanguage::class);
    }
}
