<?php

namespace App\Actions\Blog;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 0078 (D-6): carries every existing `blog_posts` row's title, slug and body -- TRASHED POSTS
 * INCLUDED -- into `blog_post_translations`, in the store default language. Extracted out of the
 * migration closure so the pure transform is directly testable and `assertCanBackfill()` can run
 * BEFORE any DDL.
 *
 * Query builder throughout, never the Eloquent model: no global scope hides a trashed post and no
 * model event fires, so the values are copied byte-for-byte (the slug is never recomputed and the
 * body never re-sanitized).
 */
class BackfillBlogPostTranslations
{
    /**
     * Refuse ONLY when there is data to carry over and nowhere to carry it.
     */
    public function assertCanBackfill(): void
    {
        if (! DB::table('blog_posts')->exists()) {
            return;
        }

        throw_if(
            ! DB::table('store_languages')->where('is_default', true)->exists(),
            new RuntimeException(
                'Cannot backfill blog_post_translations: blog posts exist but no default '
                .'store language does. Run StoreLanguageSeeder (php artisan db:seed --class=StoreLanguageSeeder) '
                .'and re-run the migration.',
            ),
        );
    }

    /**
     * @param  iterable<object{id: string, title: string, slug: string, body: string|null}>  $posts
     * @return list<array{id: string, blog_post_id: string, store_language_id: string, title: string, slug: string, body: string|null, created_at: CarbonInterface, updated_at: CarbonInterface}>
     */
    public function translationRowsFor(iterable $posts, string $storeLanguageId, CarbonInterface $now): array
    {
        $rows = [];

        foreach ($posts as $post) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'blog_post_id' => $post->id,
                'store_language_id' => $storeLanguageId,
                'title' => $post->title,
                'slug' => $post->slug,
                'body' => $post->body,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Idempotent: a post that already has a default-language row is skipped.
     *
     * @return int the number of translation rows written (0 when there is nothing left to carry)
     */
    public function __invoke(): int
    {
        $this->assertCanBackfill();

        if (! DB::table('blog_posts')->exists()) {
            return 0;
        }

        /** @var string $storeLanguageId */
        $storeLanguageId = DB::table('store_languages')->where('is_default', true)->value('id');
        $now = now();
        $written = 0;

        DB::table('blog_posts')
            ->select(['blog_posts.id', 'blog_posts.title', 'blog_posts.slug', 'blog_posts.body'])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('blog_post_translations')
                ->whereColumn('blog_post_translations.blog_post_id', 'blog_posts.id')
                ->where('blog_post_translations.store_language_id', $storeLanguageId))
            ->chunkById(500, function (Collection $posts) use ($storeLanguageId, $now, &$written): void {
                /** @var iterable<object{id: string, title: string, slug: string, body: string|null}> $posts */
                $rows = $this->translationRowsFor($posts, $storeLanguageId, $now);

                DB::table('blog_post_translations')->insert($rows);

                $written += count($rows);
            }, 'blog_posts.id', 'id');

        return $written;
    }
}
