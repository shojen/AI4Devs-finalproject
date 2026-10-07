<?php

namespace App\Actions\Blog;

use App\Actions\NormalizeForSearch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 0072 (D-5, D-6): carries every existing `blog_categories` row's name into
 * `blog_category_translations`, in the store default language, the one time
 * `create_blog_category_translations_table` runs against a database that already holds
 * categories. Extracted out of the migration closure so the pure transform
 * (`translationRowsFor()`) is directly testable, and so `assertCanBackfill()` can run BEFORE any
 * DDL (MySQL auto-commits `CREATE TABLE`, so a throw after it would leave an orphan table).
 *
 * Query builder throughout, never the Eloquent model -- a migration must not depend on a model
 * whose shape a later story can change. Consequence: no model event fires, so `normalized_name`
 * is written explicitly, RECOMPUTED through the shared normaliser rather than copied from the
 * parent column (D-5): a change to the normaliser is a recompute event, and copying would carry a
 * possibly-stale fold into the table whose new UNIQUE index is built on it.
 */
class BackfillBlogCategoryTranslations
{
    public function __construct(
        private readonly NormalizeForSearch $normalizeForSearch,
    ) {}

    /**
     * Refuse ONLY when there is data to carry over and nowhere to carry it. Zero categories ->
     * no-op, whatever store_languages holds: store_languages is populated only by
     * StoreLanguageSeeder, never by a migration, so it is EMPTY at migration time on every fresh
     * install and every RefreshDatabase test run.
     */
    public function assertCanBackfill(): void
    {
        if (! DB::table('blog_categories')->exists()) {
            return;
        }

        throw_if(
            ! DB::table('store_languages')->where('is_default', true)->exists(),
            new RuntimeException(
                'Cannot backfill blog_category_translations: blog categories exist but no default '
                .'store language does. Run StoreLanguageSeeder (php artisan db:seed --class=StoreLanguageSeeder) '
                .'and re-run the migration.',
            ),
        );
    }

    /**
     * The pure transform: one translation row per category, in the given language, name copied
     * byte-for-byte, `normalized_name` freshly folded, a fresh UUIDv7 id, and the given timestamp
     * for both `created_at` and `updated_at`. No database access.
     *
     * @param  iterable<object{id: string, name: string}>  $categories
     * @return list<array{id: string, blog_category_id: string, store_language_id: string, name: string, normalized_name: string, created_at: CarbonInterface, updated_at: CarbonInterface}>
     */
    public function translationRowsFor(iterable $categories, string $storeLanguageId, CarbonInterface $now): array
    {
        $rows = [];

        foreach ($categories as $category) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'blog_category_id' => $category->id,
                'store_language_id' => $storeLanguageId,
                'name' => $category->name,
                'normalized_name' => ($this->normalizeForSearch)($category->name),
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

        if (! DB::table('blog_categories')->exists()) {
            return 0;
        }

        /** @var string $storeLanguageId */
        $storeLanguageId = DB::table('store_languages')->where('is_default', true)->value('id');
        $now = now();
        $written = 0;

        DB::table('blog_categories')
            ->select(['id', 'name'])
            ->chunkById(500, function (Collection $categories) use ($storeLanguageId, $now, &$written): void {
                /** @var iterable<object{id: string, name: string}> $categories */
                $rows = $this->translationRowsFor($categories, $storeLanguageId, $now);

                DB::table('blog_category_translations')->insert($rows);

                $written += count($rows);
            });

        return $written;
    }
}
