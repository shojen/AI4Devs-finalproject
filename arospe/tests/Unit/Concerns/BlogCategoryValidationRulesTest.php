<?php

use App\Actions\NormalizeForSearch;
use App\Concerns\BlogCategoryValidationRules;

// Story 0058, Phase 3 (TDD "red" step): App\Concerns\BlogCategoryValidationRules does not exist yet.
//
// The exhaustive folding table is NOT re-asserted here -- tests/Unit/Actions/NormalizeForSearchTest.php
// owns it (story 0022, D-12). This file only proves the trait is well-formed and threads the shared
// normaliser and the blog category id through; the actual uniqueness comparison needs a real row and
// is pinned end to end by the Feature tests (this directory is DB-free on purpose).

function blogCategoryValidationRulesHarness(): object
{
    return new class
    {
        use BlogCategoryValidationRules;

        /**
         * @return array<int, mixed>
         */
        public function exposedNameRules(NormalizeForSearch $normalizeForSearch, ?string $blogCategoryId = null): array
        {
            return $this->nameRules($normalizeForSearch, $blogCategoryId);
        }

        /**
         * @return array<string, array<int, mixed>>
         */
        public function exposedBlogCategoryRules(NormalizeForSearch $normalizeForSearch, ?string $blogCategoryId = null): array
        {
            return $this->blogCategoryRules($normalizeForSearch, $blogCategoryId);
        }
    };
}

it('nameRules() returns bail/required/string/max:255 followed by the two closure rules', function () {
    $rules = blogCategoryValidationRulesHarness()->exposedNameRules(app(NormalizeForSearch::class));

    expect($rules)->toHaveCount(6)
        ->and($rules[0])->toBe('bail')
        ->and($rules[1])->toBe('required')
        ->and($rules[2])->toBe('string')
        ->and($rules[3])->toBe('max:255')
        ->and($rules[4])->toBeInstanceOf(Closure::class)
        ->and($rules[5])->toBeInstanceOf(Closure::class);
});

it('nameRules($id) threads the id into the uniqueness closure\'s captured state, distinct from nameRules(null)', function () {
    $normalizeForSearch = app(NormalizeForSearch::class);
    $harness = blogCategoryValidationRulesHarness();

    $withoutId = (new ReflectionFunction($harness->exposedNameRules($normalizeForSearch, null)[5]))->getStaticVariables();
    $withId = (new ReflectionFunction($harness->exposedNameRules($normalizeForSearch, 'a-blog-category-id')[5]))->getStaticVariables();

    expect($withoutId)->toHaveKey('blogCategoryId')
        ->and($withoutId['blogCategoryId'])->toBeNull()
        ->and($withId['blogCategoryId'])->toBe('a-blog-category-id');
});

it('blogCategoryRules() wraps nameRules() under the "name" key', function () {
    $rules = blogCategoryValidationRulesHarness()->exposedBlogCategoryRules(app(NormalizeForSearch::class), 'a-blog-category-id');

    expect($rules)->toHaveKey('name')
        ->and($rules)->toHaveCount(1)
        ->and($rules['name'])->toHaveCount(6);
});
