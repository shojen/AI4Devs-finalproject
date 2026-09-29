<?php

use App\Actions\ProductCategories\BackfillProductCategoryTranslations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Story 0070 (D-11), the PURE-transform half of the backfill: translationRowsFor() takes
// {id, name} objects and returns one row per category, in the given language, with the name
// copied byte-for-byte and a fresh UUID id. No database access -- Unit, DB-free, matching
// database-expert's own docblock framing of "the part that can actually be wrong".
//
// database-expert already shipped App\Actions\ProductCategories\BackfillProductCategoryTranslations
// with this method (verified against the live tree, 2026-09-28, before writing this file), so
// these cases are expected to PASS already: they PIN behaviour that exists today against a future
// regression, rather than driving new implementation. That is a legitimate, intended outcome for
// this file -- not every new test in this story's suite needs to start red, only the ones that
// exercise a surface backend-expert has not built yet.

function backfillFixtureCategory(string $id, string $name): object
{
    return (object) ['id' => $id, 'name' => $name];
}

// A count-only assertion would pass even if every row got the wrong name, an empty string, or all
// rows collapsed to one value -- each row is checked individually against its source category.
test('translationRowsFor() returns exactly one row per category, each carrying the given language and a byte-identical name', function () {
    $now = Carbon::parse('2026-09-28 10:00:00');
    $languageId = (string) Str::uuid7();

    $categories = [
        backfillFixtureCategory((string) Str::uuid7(), 'Calzado'),
        backfillFixtureCategory((string) Str::uuid7(), 'Bolsos'),
    ];

    $rows = app(BackfillProductCategoryTranslations::class)->translationRowsFor($categories, $languageId, $now);

    expect($rows)->toHaveCount(2);

    foreach ($rows as $index => $row) {
        expect($row['product_category_id'])->toBe($categories[$index]->id)
            ->and($row['store_language_id'])->toBe($languageId)
            ->and($row['name'])->toBe($categories[$index]->name)
            ->and($row['created_at'])->toBe($now)
            ->and($row['updated_at'])->toBe($now)
            ->and($row['id'])->toBeString()->not->toBe('');
    }

    $ids = array_column($rows, 'id');
    expect(array_unique($ids))->toHaveCount(2);
});

test('translationRowsFor() over zero categories returns an empty list', function () {
    $rows = app(BackfillProductCategoryTranslations::class)->translationRowsFor([], (string) Str::uuid7(), Carbon::now());

    expect($rows)->toBe([]);
});

test('translationRowsFor() preserves leading/trailing whitespace and a 255-character boundary name unchanged', function () {
    $now = Carbon::now();
    $languageId = (string) Str::uuid7();

    $padded = '  Calzado  ';
    $boundary = str_repeat('a', 255);

    $rows = app(BackfillProductCategoryTranslations::class)->translationRowsFor([
        backfillFixtureCategory((string) Str::uuid7(), $padded),
        backfillFixtureCategory((string) Str::uuid7(), $boundary),
    ], $languageId, $now);

    expect($rows[0]['name'])->toBe($padded)
        ->and($rows[1]['name'])->toBe($boundary)
        ->and(mb_strlen($rows[1]['name']))->toBe(255);
});
