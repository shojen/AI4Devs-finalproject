<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use App\Concerns\Translatable;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Story 0070 (D-1, D-4): carries no translatable column of its own any more -- every category's
 * name lives in App\Models\ProductCategoryTranslation, one row per store language, read through
 * App\Concerns\HasTranslations::translated('name').
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class ProductCategory extends Model implements Translatable
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory, HasTranslations, HasUuids;

    /**
     * Story 0024b: the relation App\Actions\ProductCategories\DeleteProductCategory counts
     * through to hard-block deletion while any product still references this category.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_category_id');
    }

    protected function translationModel(): string
    {
        return ProductCategoryTranslation::class;
    }
}
