<?php

namespace App\Actions\Products;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 0076 (D-13): carries every existing `products` row's name and description into
 * `product_translations`, in the store default language. Extracted out of the migration closure so
 * the pure transform is directly testable and `assertCanBackfill()` can run BEFORE any DDL.
 *
 * Query builder throughout, never the Eloquent model: no model event fires, so `description` is
 * copied byte-for-byte and never re-sanitized (D-9).
 */
class BackfillProductTranslations
{
    /**
     * Refuse ONLY when there is data to carry over and nowhere to carry it.
     */
    public function assertCanBackfill(): void
    {
        if (! DB::table('products')->exists()) {
            return;
        }

        throw_if(
            ! DB::table('store_languages')->where('is_default', true)->exists(),
            new RuntimeException(
                'Cannot backfill product_translations: products exist but no default '
                .'store language does. Run StoreLanguageSeeder (php artisan db:seed --class=StoreLanguageSeeder) '
                .'and re-run the migration.',
            ),
        );
    }

    /**
     * @param  iterable<object{id: string, name: string, description: string|null}>  $products
     * @return list<array{id: string, product_id: string, store_language_id: string, name: string, description: string|null, created_at: CarbonInterface, updated_at: CarbonInterface}>
     */
    public function translationRowsFor(iterable $products, string $storeLanguageId, CarbonInterface $now): array
    {
        $rows = [];

        foreach ($products as $product) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'product_id' => $product->id,
                'store_language_id' => $storeLanguageId,
                'name' => $product->name,
                'description' => $product->description,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Idempotent: a product that already has a default-language row is skipped.
     *
     * @return int the number of translation rows written (0 when there is nothing left to carry)
     */
    public function __invoke(): int
    {
        $this->assertCanBackfill();

        if (! DB::table('products')->exists()) {
            return 0;
        }

        /** @var string $storeLanguageId */
        $storeLanguageId = DB::table('store_languages')->where('is_default', true)->value('id');
        $now = now();
        $written = 0;

        DB::table('products')
            ->select(['products.id', 'products.name', 'products.description'])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('product_translations')
                ->whereColumn('product_translations.product_id', 'products.id')
                ->where('product_translations.store_language_id', $storeLanguageId))
            ->chunkById(500, function (Collection $products) use ($storeLanguageId, $now, &$written): void {
                /** @var iterable<object{id: string, name: string, description: string|null}> $products */
                $rows = $this->translationRowsFor($products, $storeLanguageId, $now);

                DB::table('product_translations')->insert($rows);

                $written += count($rows);
            }, 'products.id', 'id');

        return $written;
    }
}
