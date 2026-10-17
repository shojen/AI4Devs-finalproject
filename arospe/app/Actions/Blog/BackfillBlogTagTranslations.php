<?php

namespace App\Actions\Blog;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 0074 (D-10): carries every existing `blog_tags` row's name into `blog_tag_translations`,
 * in the store default language. Extracted out of the migration closure so the pure transform is
 * directly testable and `assertCanBackfill()` can run BEFORE any DDL.
 *
 * Query builder throughout, never the Eloquent model. Both `name` and `normalized_name` are copied
 * byte-for-byte, never recomputed: the pre-retrofit global UNIQUE already proves the values are
 * pairwise distinct, so they cannot violate the new per-language composite UNIQUE.
 */
class BackfillBlogTagTranslations
{
    /**
     * Refuse ONLY when there is data to carry over and nowhere to carry it.
     */
    public function assertCanBackfill(): void
    {
        if (! DB::table('blog_tags')->exists()) {
            return;
        }

        throw_if(
            ! DB::table('store_languages')->where('is_default', true)->exists(),
            new RuntimeException(
                'Cannot backfill blog_tag_translations: blog tags exist but no default '
                .'store language does. Run StoreLanguageSeeder (php artisan db:seed --class=StoreLanguageSeeder) '
                .'and re-run the migration.',
            ),
        );
    }

    /**
     * @param  iterable<object{id: string, name: string, normalized_name: string}>  $tags
     * @return list<array{id: string, blog_tag_id: string, store_language_id: string, name: string, normalized_name: string, created_at: CarbonInterface, updated_at: CarbonInterface}>
     */
    public function translationRowsFor(iterable $tags, string $storeLanguageId, CarbonInterface $now): array
    {
        $rows = [];

        foreach ($tags as $tag) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'blog_tag_id' => $tag->id,
                'store_language_id' => $storeLanguageId,
                'name' => $tag->name,
                'normalized_name' => $tag->normalized_name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Idempotent: a tag that already has a default-language row is skipped, so a re-run (the
     * second migration's guard tells the operator to do exactly that) fills only the gaps instead
     * of tripping the per-tag-and-language UNIQUE.
     *
     * @return int the number of translation rows written (0 when there is nothing left to carry)
     */
    public function __invoke(): int
    {
        $this->assertCanBackfill();

        if (! DB::table('blog_tags')->exists()) {
            return 0;
        }

        /** @var string $storeLanguageId */
        $storeLanguageId = DB::table('store_languages')->where('is_default', true)->value('id');
        $now = now();
        $written = 0;

        DB::table('blog_tags')
            ->select(['blog_tags.id', 'blog_tags.name', 'blog_tags.normalized_name'])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('blog_tag_translations')
                ->whereColumn('blog_tag_translations.blog_tag_id', 'blog_tags.id')
                ->where('blog_tag_translations.store_language_id', $storeLanguageId))
            ->chunkById(500, function (Collection $tags) use ($storeLanguageId, $now, &$written): void {
                /** @var iterable<object{id: string, name: string, normalized_name: string}> $tags */
                $rows = $this->translationRowsFor($tags, $storeLanguageId, $now);

                DB::table('blog_tag_translations')->insert($rows);

                $written += count($rows);
            }, 'blog_tags.id', 'id');

        return $written;
    }
}
