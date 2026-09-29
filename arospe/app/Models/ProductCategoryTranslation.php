<?php

namespace App\Models;

use Database\Factories\ProductCategoryTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Story 0070 (D-1, D-2, D-3, D-7): one row per (product category, store language). `name` is
 * genuinely form-supplied and so is fillable here -- unlike Media's server-derived columns --
 * while `product_category_id` and `store_language_id` are omitted and written only by
 * App\Actions\Translations\SetTranslation's explicit key list.
 *
 * @property string $id
 * @property string $product_category_id
 * @property string $store_language_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class ProductCategoryTranslation extends Model
{
    /** @use HasFactory<ProductCategoryTranslationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * @return BelongsTo<StoreLanguage, $this>
     */
    public function storeLanguage(): BelongsTo
    {
        return $this->belongsTo(StoreLanguage::class);
    }
}
