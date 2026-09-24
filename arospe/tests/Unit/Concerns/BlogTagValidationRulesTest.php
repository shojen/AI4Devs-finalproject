<?php

use App\Actions\NormalizeForSearch;
use App\Concerns\BlogTagValidationRules;

// Story 0059.
//
// The exhaustive folding table is NOT re-asserted here -- tests/Unit/Actions/NormalizeForSearchTest.php
// owns it (story 0022, D-2); a second specification of the fold could drift from the first. This file
// only proves the trait's shape (D-9); the behaviour that a colliding name passes the format rules and
// fails nameRules() needs a real row and is pinned in tests/Feature/Blog/FindOrCreateBlogTagTest.php.

function blogTagValidationRulesHarness(): object
{
    return new class
    {
        use BlogTagValidationRules;

        /**
         * @return array<int, mixed>
         */
        public function exposedNameFormatRules(NormalizeForSearch $normalizeForSearch): array
        {
            return $this->nameFormatRules($normalizeForSearch);
        }

        /**
         * @return array<int, mixed>
         */
        public function exposedNameRules(NormalizeForSearch $normalizeForSearch, ?string $blogTagId = null): array
        {
            return $this->nameRules($normalizeForSearch, $blogTagId);
        }
    };
}

it('nameFormatRules() returns bail/required/string/max:100 and the folded-length closure, and nothing else', function () {
    $rules = blogTagValidationRulesHarness()->exposedNameFormatRules(app(NormalizeForSearch::class));

    expect($rules)->toHaveCount(5)
        ->and($rules[0])->toBe('bail')
        ->and($rules[1])->toBe('required')
        ->and($rules[2])->toBe('string')
        ->and($rules[3])->toBe('max:100')
        ->and($rules[4])->toBeInstanceOf(Closure::class);
});

it('nameFormatRules() carries no uniqueness rule: it is nameRules() minus exactly one closure', function () {
    $normalizeForSearch = app(NormalizeForSearch::class);
    $harness = blogTagValidationRulesHarness();

    $format = $harness->exposedNameFormatRules($normalizeForSearch);
    $full = $harness->exposedNameRules($normalizeForSearch);

    expect($full)->toHaveCount(count($format) + 1)
        ->and(array_slice($full, 0, count($format)))->toEqual($format)
        ->and($full[array_key_last($full)])->toBeInstanceOf(Closure::class);

    $capturedByFormat = collect($format)
        ->filter(fn (mixed $rule): bool => $rule instanceof Closure)
        ->flatMap(fn (Closure $rule): array => array_keys((new ReflectionFunction($rule))->getStaticVariables()));

    expect($capturedByFormat)->not->toContain('blogTagId');
});

it('nameRules($id) threads the id into the uniqueness closure\'s captured state, distinct from nameRules(null)', function () {
    $normalizeForSearch = app(NormalizeForSearch::class);
    $harness = blogTagValidationRulesHarness();

    $withoutIdRules = $harness->exposedNameRules($normalizeForSearch, null);
    $withIdRules = $harness->exposedNameRules($normalizeForSearch, 'a-blog-tag-id');

    $withoutId = (new ReflectionFunction($withoutIdRules[array_key_last($withoutIdRules)]))->getStaticVariables();
    $withId = (new ReflectionFunction($withIdRules[array_key_last($withIdRules)]))->getStaticVariables();

    expect($withoutId)->toHaveKey('blogTagId')
        ->and($withoutId['blogTagId'])->toBeNull()
        ->and($withId['blogTagId'])->toBe('a-blog-tag-id');
});
