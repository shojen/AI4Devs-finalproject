<?php

use App\Actions\Blog\BackfillBlogPostTranslations;
use App\Concerns\BlogPostValidationRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Story 0078 (D-12, D-11, D-6): the contracts that need no database -- the pure transform of the
// backfill.

test('titleRules() carries no uniqueness rule', function () {
    $harness = new class
    {
        use BlogPostValidationRules;

        /** @return array<int, mixed> */
        public function rules(): array
        {
            return $this->titleRules();
        }
    };

    foreach ($harness->rules() as $rule) {
        expect(is_string($rule) ? $rule : $rule::class)->not->toContain('unique');
    }
});

test('translationRowsFor() writes one row per post, copying title, slug and body without recomputing the slug', function () {
    $now = Carbon::parse('2026-11-08 10:00:00');
    $languageId = (string) Str::uuid7();
    $posts = [
        (object) ['id' => (string) Str::uuid7(), 'title' => '  Botas  ', 'slug' => 'legacy-fold', 'body' => '<script>old()</script>'],
        (object) ['id' => (string) Str::uuid7(), 'title' => str_repeat('a', 255), 'slug' => str_repeat('a', 255), 'body' => null],
    ];

    $rows = (new BackfillBlogPostTranslations)->translationRowsFor($posts, $languageId, $now);

    expect($rows)->toHaveCount(2);

    foreach ($rows as $index => $row) {
        expect($row['blog_post_id'])->toBe($posts[$index]->id)
            ->and($row['store_language_id'])->toBe($languageId)
            ->and($row['title'])->toBe($posts[$index]->title)
            ->and($row['slug'])->toBe($posts[$index]->slug)
            ->and($row['body'])->toBe($posts[$index]->body)
            ->and($row['created_at'])->toBe($now)
            ->and($row['updated_at'])->toBe($now)
            ->and(Str::isUuid($row['id'], 7))->toBeTrue();
    }
});
