<?php

use App\Actions\Blog\BackfillBlogPostTranslations;
use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Concerns\BlogPostValidationRules;
use App\Models\BlogPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Story 0078 (D-12, D-11, D-6): the contracts that need no database -- the public signatures of both
// actions (unchanged by the retrofit), the new required parameter of titleSlugRules(), and the pure
// transform of the backfill.

/**
 * @return list<array{name: string, type: string, allowsNull: bool, optional: bool}>
 */
function bptSignature(string $class, string $method): array
{
    return array_map(fn (ReflectionParameter $parameter): array => [
        'name' => $parameter->getName(),
        'type' => (string) $parameter->getType()?->getName(),
        'allowsNull' => $parameter->getType()?->allowsNull() ?? true,
        'optional' => $parameter->isOptional(),
    ], (new ReflectionMethod($class, $method))->getParameters());
}

test('CreateBlogPost::__invoke keeps its parameter names and types, status staying a string', function () {
    expect(bptSignature(CreateBlogPost::class, '__invoke'))->toBe([
        ['name' => 'title', 'type' => 'string', 'allowsNull' => false, 'optional' => false],
        ['name' => 'body', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'blogCategoryId', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'status', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'publishedAt', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'tagNames', 'type' => 'array', 'allowsNull' => false, 'optional' => false],
    ])->and((string) (new ReflectionMethod(CreateBlogPost::class, '__invoke'))->getReturnType())->toBe(BlogPost::class);
});

test('UpdateBlogPost::__invoke keeps its parameter names and types, status staying a string', function () {
    expect(bptSignature(UpdateBlogPost::class, '__invoke'))->toBe([
        ['name' => 'blogPost', 'type' => BlogPost::class, 'allowsNull' => false, 'optional' => false],
        ['name' => 'title', 'type' => 'string', 'allowsNull' => false, 'optional' => false],
        ['name' => 'body', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'blogCategoryId', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'status', 'type' => 'string', 'allowsNull' => false, 'optional' => false],
        ['name' => 'publishedAt', 'type' => 'string', 'allowsNull' => true, 'optional' => false],
        ['name' => 'tagNames', 'type' => 'array', 'allowsNull' => false, 'optional' => false],
    ])->and((string) (new ReflectionMethod(UpdateBlogPost::class, '__invoke'))->getReturnType())->toBe(BlogPost::class);
});

test('titleSlugRules() requires the store language id first and takes an optional post id to ignore', function () {
    $harness = new class
    {
        use BlogPostValidationRules;
    };

    expect(bptSignature($harness::class, 'titleSlugRules'))->toBe([
        ['name' => 'storeLanguageId', 'type' => 'string', 'allowsNull' => false, 'optional' => false],
        ['name' => 'ignoreBlogPostId', 'type' => 'string', 'allowsNull' => true, 'optional' => true],
    ]);
});

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
