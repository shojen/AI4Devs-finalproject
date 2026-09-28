<?php

namespace App\Actions\ProductCategories;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 0070 (D-11, D-16): carries every existing `product_categories` row's name into
 * `product_category_translations`, in the store default language, the one time
 * `create_product_category_translations_table` runs against a database that already holds
 * categories. Extracted out of the migration closure -- not for reuse, but because the pure
 * transform (`translationRowsFor()`) is the part that can actually be wrong and needs to be
 * directly testable, while `assertCanBackfill()`'s precondition needs to run BEFORE any DDL
 * (MySQL auto-commits `CREATE TABLE`, so a throw after it would leave an orphan table).
 *
 * Query builder throughout, never the Eloquent model -- a migration must not depend on a model
 * whose shape a later story can change.
 */
class BackfillProductCategoryTranslations
{
    /**
     * Refuse ONLY when there is data to carry over and nowhere to carry it. Zero categories ->
     * no-op, whatever store_languages holds. This is the branch every fresh install and every
     * RefreshDatabase test run takes: store_languages is populated only by StoreLanguageSeeder,
     * never by a migration, so it is EMPTY at migration time there (D-16).
     */
    public function assertCanBackfill(): void
    {
        if (! DB::table('product_categories')->exists()) {
            return;
        }

        throw_if(
            ! DB::table('store_languages')->where('is_default', true)->exists(),
            new RuntimeException(
                'Cannot backfill product_category_translations: product categories exist but no default '
                .'store language does. Run StoreLanguageSeeder (php artisan db:seed --class=StoreLanguageSeeder) '
                .'and re-run the migration.',
            ),
        );
    }

    /**
     * The pure transform: one translation row per category, in the given language, name copied
     * byte-for-byte, a fresh UUIDv7 id, and the given timestamp for both `created_at` and
     * `updated_at`. No database access -- this is what the backfill tests exercise (D-11).
     *
     * @param  iterable<object{id: string, name: string}>  $categories
     * @return list<array{id: string, product_category_id: string, store_language_id: string, name: string, created_at: CarbonInterface, updated_at: CarbonInterface}>
     */
    public function translationRowsFor(iterable $categories, string $storeLanguageId, CarbonInterface $now): array
    {
        $rows = [];

        foreach ($categories as $category) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'product_category_id' => $category->id,
                'store_language_id' => $storeLanguageId,
                'name' => $category->name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @return int the number of translation rows written (0 when there are no categories)
     */
    public function __invoke(): int
    {
        $this->assertCanBackfill();

        if (! DB::table('product_categories')->exists()) {
            return 0;
        }

        /** @var string $storeLanguageId */
        $storeLanguageId = DB::table('store_languages')->where('is_default', true)->value('id');
        $now = now();
        $written = 0;

        DB::table('product_categories')
            ->select(['id', 'name'])
            ->chunkById(500, function (Collection $categories) use ($storeLanguageId, $now, &$written): void {
                /** @var iterable<object{id: string, name: string}> $categories */
                $rows = $this->translationRowsFor($categories, $storeLanguageId, $now);

                DB::table('product_category_translations')->insert($rows);

                $written += count($rows);
            });

        return $written;
    }
}
