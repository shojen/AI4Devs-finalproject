<?php

use App\Enums\BlogPostStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

// Story 0063 (D-18): BlogPostStatus gains `label()` at its first consumer, the blog post list's
// status badge (story 0061 deliberately shipped none). `label()` resolves through the translator
// (`__()`), which needs the app container -- bound per-file here rather than directory-wide, so
// this stays a tests/Unit/ test (no RefreshDatabase, no database touched), matching
// ProductStatusTest.php and UserStatusTest.php.

uses(TestCase::class);

// Pinned as an exact array via array_column(), never ->toContain(), which would stay green the
// moment anyone added a fourth persisted status.
test('cases are exactly draft, published and scheduled, in that order', function () {
    expect(array_column(BlogPostStatus::cases(), 'value'))->toBe(['draft', 'published', 'scheduled']);
});

test('from throws ValueError for an unrecognized value', function () {
    expect(fn () => BlogPostStatus::from('archived'))->toThrow(ValueError::class);
});

test('label resolves through the translator rather than returning a literal', function () {
    expect(BlogPostStatus::Draft->label())->toBe(trans('blog-posts.statuses.draft'))
        ->and(BlogPostStatus::Published->label())->toBe(trans('blog-posts.statuses.published'))
        ->and(BlogPostStatus::Scheduled->label())->toBe(trans('blog-posts.statuses.scheduled'));
});

// Asserting against trans() is green even against a hardcoded literal whose text equals the
// default 'en' copy. Switching to 'es' and asserting the Spanish copy is what proves the
// translator is consulted -- no hardcoded English literal could produce these strings. The locale
// is restored afterwards: nothing in tests/Pest.php resets it per test.
test('label speaks Borrador, Publicado and Programado under the es locale', function () {
    $originalLocale = App::getLocale();

    App::setLocale('es');

    try {
        expect(BlogPostStatus::Draft->label())->toBe('Borrador')
            ->and(BlogPostStatus::Published->label())->toBe('Publicado')
            ->and(BlogPostStatus::Scheduled->label())->toBe('Programado');
    } finally {
        App::setLocale($originalLocale);
    }
});

test('every status has a label in both locales, key-for-key identical', function () {
    $en = require lang_path('en/blog-posts.php');
    $es = require lang_path('es/blog-posts.php');
    $values = array_column(BlogPostStatus::cases(), 'value');

    expect(array_keys($en['statuses']))->toEqualCanonicalizing($values)
        ->and(array_keys($es['statuses']))->toEqualCanonicalizing($values);
});

test('the whole blog-posts copy file carries the same keys in both locales', function () {
    $en = require lang_path('en/blog-posts.php');
    $es = require lang_path('es/blog-posts.php');

    expect(array_keys(Arr::dot($es)))->toEqualCanonicalizing(array_keys(Arr::dot($en)));
});
