<?php

use App\Concerns\BlogPostValidationRules;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Tests\TestCase;

uses(TestCase::class);

// Story 0061. Proves the trait's shape in isolation; the behaviour needing a real row (a colliding
// slug, an existing category) is pinned in tests/Feature/Blog.

function blogPostValidationRulesHarness(): object
{
    return new class
    {
        use BlogPostValidationRules;

        /** @return array<int, mixed> */
        public function title(): array
        {
            return $this->titleRules();
        }

        /** @return array<int, mixed> */
        public function blogCategoryId(): array
        {
            return $this->blogCategoryIdRules();
        }

        /** @return array<int, mixed> */
        public function status(): array
        {
            return $this->statusRules();
        }

        /** @return array<int, mixed> */
        public function body(BlogPostStatus $status): array
        {
            return $this->bodyRules($status);
        }

        /** @return array<int, mixed> */
        public function publishedAt(BlogPostStatus $status, bool $enforceFuture = true): array
        {
            return $this->publishedAtRules($status, $enforceFuture);
        }

        /** @return array<int, mixed> */
        public function tagNames(): array
        {
            return $this->tagNamesRules();
        }
    };
}

it('titleRules() is required/string/max at the column width', function () {
    expect(blogPostValidationRulesHarness()->title())->toBe(['required', 'string', 'max:'.BlogPost::TITLE_MAX_LENGTH])
        ->and(BlogPost::TITLE_MAX_LENGTH)->toBe(255);
});

it('blogCategoryIdRules() is required/string plus an exists rule on blog_categories.id', function () {
    $rules = blogPostValidationRulesHarness()->blogCategoryId();

    expect($rules)->toHaveCount(3)
        ->and($rules[0])->toBe('required')
        ->and($rules[1])->toBe('string')
        ->and($rules[2])->toBeInstanceOf(Exists::class);
});

it('statusRules() is required plus an enum rule over BlogPostStatus', function () {
    $rules = blogPostValidationRulesHarness()->status();

    expect($rules)->toHaveCount(2)
        ->and($rules[0])->toBe('required')
        ->and($rules[1])->toBeInstanceOf(Enum::class);
});

it('bodyRules() is nullable for a Draft and required for BOTH Published and Scheduled', function () {
    $harness = blogPostValidationRulesHarness();

    expect($harness->body(BlogPostStatus::Draft))->toBe(['nullable', 'string'])
        ->and($harness->body(BlogPostStatus::Published))->toBe(['required', 'string'])
        ->and($harness->body(BlogPostStatus::Scheduled))->toBe(['required', 'string']);
});

it('publishedAtRules() returns a different rule set per status', function () {
    $harness = blogPostValidationRulesHarness();

    expect($harness->publishedAt(BlogPostStatus::Draft))->toBe(['prohibited'])
        ->and($harness->publishedAt(BlogPostStatus::Scheduled))->toBe(['required', 'date', 'after:now', 'before:2038-01-19'])
        ->and($harness->publishedAt(BlogPostStatus::Published))->toBe(['nullable', 'date', 'after:1970-01-01', 'before:2038-01-19']);
});

it('publishedAtRules() drops after:now for a Scheduled post keeping its stored date, and only then', function () {
    $harness = blogPostValidationRulesHarness();

    expect($harness->publishedAt(BlogPostStatus::Scheduled, enforceFuture: false))->toBe(['required', 'date', 'before:2038-01-19'])
        ->and($harness->publishedAt(BlogPostStatus::Published, enforceFuture: false))->toBe(['nullable', 'date', 'after:1970-01-01', 'before:2038-01-19'])
        ->and($harness->publishedAt(BlogPostStatus::Draft, enforceFuture: false))->toBe(['prohibited']);
});

it('tagNamesRules() requires an array whose every entry is a non-blank string', function () {
    $rules = blogPostValidationRulesHarness()->tagNames();

    expect($rules[0])->toBe('array')
        ->and($rules[1])->toBe('max:'.BlogPost::MAX_TAGS);

    $refuses = function (mixed $value) use ($rules): bool {
        $failed = false;
        $rules[2]('tag_names', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    };

    expect($refuses(['running', 'invierno']))->toBeFalse()
        ->and($refuses([]))->toBeFalse()
        ->and($refuses(['running', '   ']))->toBeTrue()
        ->and($refuses(['running', 5]))->toBeTrue()
        ->and($refuses([['nested']]))->toBeTrue();
});
