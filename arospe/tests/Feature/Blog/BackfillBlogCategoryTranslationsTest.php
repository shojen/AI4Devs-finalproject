<?php

use App\Actions\Blog\BackfillBlogCategoryTranslations;
use App\Actions\NormalizeForSearch;
use App\Models\BlogCategory;
use App\Models\StoreLanguage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Story 0072 (D-5): the backfill carries each category's name into the default store language,
// RECOMPUTING normalized_name through the shared normaliser. Both migrations have already run
// before every test body, so blog_categories no longer carries a `name` column: the pure transform
// is exercised with {id, name} fixtures, the database-facing guards against the real schema.
// Per-row assertions throughout -- a count would pass even if every row got the wrong name.

function blogBackfillFixture(string $id, string $name): object
{
    return (object) ['id' => $id, 'name' => $name];
}

test('translationRowsFor() writes one row per category, in the given language, name byte-identical and the fold recomputed', function () {
    $now = Carbon::parse('2026-10-07 10:00:00');
    $languageId = (string) Str::uuid7();
    $categories = [
        blogBackfillFixture((string) Str::uuid7(), 'Guías'),
        blogBackfillFixture((string) Str::uuid7(), 'Novedades'),
    ];

    $rows = app(BackfillBlogCategoryTranslations::class)->translationRowsFor($categories, $languageId, $now);

    expect($rows)->toHaveCount(2);

    foreach ($rows as $index => $row) {
        expect($row['blog_category_id'])->toBe($categories[$index]->id)
            ->and($row['store_language_id'])->toBe($languageId)
            ->and($row['name'])->toBe($categories[$index]->name)
            ->and($row['normalized_name'])->toBe(app(NormalizeForSearch::class)($categories[$index]->name))
            ->and($row['created_at'])->toBe($now)
            ->and($row['updated_at'])->toBe($now)
            ->and(Str::isUuid($row['id'], 7))->toBeTrue();
    }

    expect(array_unique(array_column($rows, 'id')))->toHaveCount(2)
        ->and($rows[0]['normalized_name'])->toBe('guias');
});

test('translationRowsFor() preserves edge whitespace and a boundary-length name unchanged', function () {
    $boundary = str_repeat('a', 255);
    $rows = app(BackfillBlogCategoryTranslations::class)->translationRowsFor([
        blogBackfillFixture((string) Str::uuid7(), '  Guías  '),
        blogBackfillFixture((string) Str::uuid7(), $boundary),
    ], (string) Str::uuid7(), Carbon::now());

    expect($rows[0]['name'])->toBe('  Guías  ')
        ->and($rows[0]['normalized_name'])->toBe('guias')
        ->and($rows[1]['name'])->toBe($boundary)
        ->and($rows[1]['normalized_name'])->toBe($boundary);
});

test('translationRowsFor() over zero categories returns an empty list', function () {
    expect(app(BackfillBlogCategoryTranslations::class)->translationRowsFor([], (string) Str::uuid7(), Carbon::now()))->toBe([]);
});

test('with existing categories and no default store language, the backfill refuses loudly and writes nothing', function () {
    BlogCategory::factory()->withoutTranslations()->create();

    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => app(BackfillBlogCategoryTranslations::class)->assertCanBackfill())->toThrow(RuntimeException::class);
    expect(fn () => app(BackfillBlogCategoryTranslations::class)())->toThrow(RuntimeException::class);

    expect(DB::table('blog_category_translations')->count())->toBe(0);
});

// Every fresh install and every RefreshDatabase run takes exactly this branch.
test('with zero categories the backfill is a no-op returning 0, whatever store_languages holds', function () {
    expect(app(BackfillBlogCategoryTranslations::class)())->toBe(0);

    StoreLanguage::factory()->default()->create();

    expect(app(BackfillBlogCategoryTranslations::class)())->toBe(0)
        ->and(DB::table('blog_category_translations')->count())->toBe(0);
});
