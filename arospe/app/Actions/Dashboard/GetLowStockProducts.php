<?php

namespace App\Actions\Dashboard;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Story 0082 (D-4) -- the 3 active physical products with the lowest effective stock, for the
 * dashboard's "low stock" widget. `__invoke()` takes no argument, so `LogRefusedPrivilegedAttempt`
 * is constructor-injected (docs/conventions/code-style.md).
 *
 * Authorizes first (`viewAny` on Product, i.e. `products.view`); a refusal is logged and throws
 * before any query runs. A caller should check the ability itself and call this only when permitted.
 *
 * Effective stock is the lowest variant stock when the product has variants (the parent's own
 * `stock` is then ignored), else `products.stock`. A variable product is listed once, as the
 * parent. Only ACTIVE, PHYSICAL parents are eligible, so the variants of a draft or virtual parent
 * never surface. Ties break on `products.id` -- never on the name, which the translatable-content
 * retrofit moves. Stock is signed: negative stock ranks first.
 *
 * `isOutOfStock` is `effectiveStock <= 0`, computed here -- NOT `Product::isOutOfStock()`, which
 * reads the parent's own stock and is wrong for a variable product.
 *
 * `lowVariantCount` counts a product's variants whose stock is at or below the effective stock of
 * the LAST returned row (the cut-off), 0 for a simple product. Two domain-table queries in total
 * however large the catalog: the ranking query, then one grouped count for the returned parents.
 *
 * D-9 -- translatable-content seam: the name is read ONLY through `resolveName()`; the query
 * selects `products.*` and never names, filters or orders on `name` (story 0076 swaps that method).
 */
class GetLowStockProducts
{
    private const LIMIT = 3;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * @return list<array{id: string, name: ?string, sku: string, effectiveStock: int, isOutOfStock: bool, hasVariants: bool, lowVariantCount: int}>
     */
    public function __invoke(): array
    {
        $this->logRefusedPrivilegedAttempt->authorize('viewAny', Product::class, targetType: 'product');

        $variantMinimum = ProductVariant::query()
            ->selectRaw('product_id, MIN(stock) AS min_variant_stock')
            ->groupBy('product_id');

        $products = Product::query()
            ->leftJoinSub($variantMinimum, 'v', 'v.product_id', '=', 'products.id')
            ->where('products.status', ProductStatus::Active)
            ->where('products.type', ProductType::Physical)
            ->select('products.*')
            ->selectRaw('COALESCE(v.min_variant_stock, products.stock) AS effective_stock')
            ->selectRaw('v.min_variant_stock IS NOT NULL AS has_variants')
            ->orderBy('effective_stock')
            ->orderBy('products.id')
            ->limit(self::LIMIT)
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $cutOff = (int) $products->last()->getAttribute('effective_stock');

        /** @var array<string, int> $lowVariantCounts */
        $lowVariantCounts = ProductVariant::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('stock', '<=', $cutOff)
            ->selectRaw('product_id, COUNT(*) AS low_variant_count')
            ->groupBy('product_id')
            ->pluck('low_variant_count', 'product_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $rows = [];

        foreach ($products as $product) {
            $effectiveStock = (int) $product->getAttribute('effective_stock');

            $rows[] = [
                'id' => $product->id,
                'name' => $this->resolveName($product),
                'sku' => $product->sku,
                'effectiveStock' => $effectiveStock,
                'isOutOfStock' => $effectiveStock <= 0,
                'hasVariants' => (bool) $product->getAttribute('has_variants'),
                'lowVariantCount' => $lowVariantCounts[$product->id] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * D-9 seam: the product's name. Today the plain attribute; the translatable-content retrofit may
     * return null when no translation exists, which is why the row shape types it nullable.
     */
    private function resolveName(Product $product): string
    {
        return $product->name;
    }
}
