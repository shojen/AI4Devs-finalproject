<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use App\Concerns\Translatable;
use App\Enums\ProductDisplayStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A product in the catalog (story 0024).
 *
 * `featured_media_id` and every `product_media` pivot row are deliberately
 * absent from `#[Fillable]` and written only by the gallery-sync action
 * under app/Actions/Products/ -- the same single-writer shape
 * App\Models\SalesRegion already establishes for `is_default`/`is_active`
 * (D-9). No `SoftDeletes` (D-12): `Rule::unique()` does not apply the
 * soft-delete scope, so a trashed product would permanently squat its SKU,
 * and a cascade never fires on a soft delete, so 0029's variants would be
 * left live and reachable against a trashed parent.
 *
 * @property string $id
 * @property string $sku
 * @property string $product_category_id
 * @property ProductType|null $type
 * @property ProductStatus $status
 * @property string $price 'decimal:2' casts to a STRING, not a float
 * @property int $stock
 * @property string|null $featured_media_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductCategory|null $category
 * @property-read Collection<int, ProductTranslation> $translations
 * @property-read Media|null $featuredImage
 * @property-read Collection<int, Media> $gallery
 * @property-read Collection<int, SalesRegion> $salesRegions
 */
#[Fillable(['sku', 'product_category_id', 'type', 'status', 'price', 'stock'])]
class Product extends Model implements Translatable
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasTranslations, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * D-2: `price` casts to a STRING via `decimal:2` -- never a float,
     * since binary floating point cannot represent 21.00/0.10 exactly. R-4:
     * `@property float $price` reads as obviously correct and is the
     * likeliest silent bug in this story.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'price' => 'decimal:2',
            'stock' => 'integer',
        ];
    }

    /**
     * The category this product is filed under.
     *
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * The single image representing this product in a list, independent of
     * its gallery (D-9) -- setting one never implicitly populates the
     * other, and nothing prevents this pointing at a media row absent from
     * the gallery.
     *
     * @return BelongsTo<Media, $this>
     */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_media_id');
    }

    /**
     * The ordered image gallery shown on the product (D-8/D-17). `position`
     * is the caller's 0-based array index, written exclusively by the
     * gallery-sync action under app/Actions/Products/ -- the tiebreak on
     * `media_id` covers only a raw insert that bypasses that action, since
     * a bulk multi-select attach would otherwise leave every new row at the
     * `position` column's default of 0 and read back in arbitrary order.
     *
     * @return BelongsToMany<Media, $this>
     */
    public function gallery(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'product_media', 'product_id', 'media_id')
            ->withPivot('position')
            ->orderByPivot('position')
            ->orderByPivot('media_id');
    }

    /**
     * The Sales Region entries this product is assigned to (story 0026).
     * Table/column names written explicitly even though all three match
     * convention -- this relation is the one place a future rename would
     * silently start pointing at nothing. No inverse `SalesRegion::products()`
     * relation exists -- nothing in this story or its declared consumers
     * needs "which products target this region".
     *
     * @return BelongsToMany<SalesRegion, $this>
     */
    public function salesRegions(): BelongsToMany
    {
        return $this->belongsToMany(SalesRegion::class, 'product_sales_region', 'product_id', 'sales_region_id');
    }

    /**
     * This product's variants (story 0029, D-8/D-17.2). Ordered inside the
     * relationship, never at the call site: `position ASC, sku ASC` -- the
     * `sku` tiebreak is total because `sku` is `UNIQUE NOT NULL`, unlike
     * `gallery()`'s own `media_id` tiebreak above, which is only unique
     * within one product's gallery.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->orderBy('position')
            ->orderBy('sku');
    }

    /**
     * Whether this product is out of stock -- `<= 0`, not `=== 0`, so a
     * negative value (representable, though refused by validation today)
     * still reads correctly (D-3).
     */
    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * The badge status shown for this product (D-7, confirmed Phase 0
     * decision -- do not reopen). "Agotado" is COMPUTED here, never stored:
     * the out-of-stock badge overrides Active only (RQ-4) -- a Draft
     * product with zero stock still reads as Draft, since publication
     * state and availability are orthogonal axes one column cannot hold
     * without losing information.
     */
    public function displayStatus(): ProductDisplayStatus
    {
        if ($this->status === ProductStatus::Active && $this->isOutOfStock()) {
            return ProductDisplayStatus::OutOfStock;
        }

        return ProductDisplayStatus::from($this->status->value);
    }

    protected function translationModel(): string
    {
        return ProductTranslation::class;
    }

    /**
     * The name frozen onto an order line (D-21, N-3): the default-language name, else the oldest
     * non-blank translation. The common path reads the loaded relation with no extra query.
     *
     * @throws LogicException when the product has no usable name in any language
     */
    public function snapshotName(): string
    {
        $defaultId = StoreLanguage::defaultStoreLanguage()?->id;

        $name = $this->translations->firstWhere('store_language_id', $defaultId)?->name;

        if ($name !== null && $name !== '') {
            return $name;
        }

        /** @var Collection<int, ProductTranslation> $all */
        $all = $this->translations()->orderBy('created_at')->orderBy('id')->get();

        $name = $all->firstWhere('store_language_id', $defaultId)?->name;

        if ($name !== null && $name !== '') {
            return $name;
        }

        $oldest = $all->first(fn (ProductTranslation $translation): bool => $translation->name !== '');

        if ($oldest === null) {
            throw new LogicException("Product [{$this->id}] has no usable name to snapshot.");
        }

        return $oldest->name;
    }

    /**
     * Order by the name in the requested language, falling back per row to the default one (D-14),
     * and expose the resolved name as `translated_name`. Callers qualify their own columns with
     * `products.` because of the joins.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeOrderByTranslatedName(Builder $query, ?string $storeLanguageId = null): void
    {
        $defaultId = StoreLanguage::defaultStoreLanguage()?->id;
        $requestedId = $storeLanguageId ?? $defaultId;

        $query->leftJoin('product_translations as requested_translation', function ($join) use ($requestedId): void {
            $join->on('requested_translation.product_id', '=', 'products.id')
                ->where('requested_translation.store_language_id', '=', $requestedId ?? '');
        })->leftJoin('product_translations as default_translation', function ($join) use ($defaultId): void {
            $join->on('default_translation.product_id', '=', 'products.id')
                ->where('default_translation.store_language_id', '=', $defaultId ?? '');
        });

        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        $query->addSelect(DB::raw("COALESCE(NULLIF(requested_translation.name, ''), default_translation.name) AS translated_name"))
            ->orderBy('translated_name');
    }
}
