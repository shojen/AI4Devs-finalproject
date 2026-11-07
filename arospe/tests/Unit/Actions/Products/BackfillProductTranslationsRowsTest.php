<?php

use App\Actions\Products\BackfillProductTranslations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Story 0076 (D-9, D-13): the pure transform of the products backfill. No database: fixtures are
// {id, name, description} objects. Assertions are per row, never a bare count, because a count would
// pass even if every row got the wrong name.

function p76LegacyProductRow(string $name, ?string $description): object
{
    return (object) ['id' => (string) Str::uuid7(), 'name' => $name, 'description' => $description];
}

test('translationRowsFor() returns one row per product, in the given language, name and description byte-identical', function () {
    $now = Carbon::parse('2026-11-06 10:00:00');
    $languageId = (string) Str::uuid7();
    $products = [
        p76LegacyProductRow('Zapatillas Running', '<p>Ligeras</p>'),
        p76LegacyProductRow('  Chaqueta  ', 'Texto plano'),
    ];

    $rows = (new BackfillProductTranslations)->translationRowsFor($products, $languageId, $now);

    expect($rows)->toHaveCount(2);

    foreach ($rows as $index => $row) {
        expect($row['product_id'])->toBe($products[$index]->id)
            ->and($row['store_language_id'])->toBe($languageId)
            ->and($row['name'])->toBe($products[$index]->name)
            ->and($row['description'])->toBe($products[$index]->description)
            ->and($row['created_at'])->toBe($now)
            ->and($row['updated_at'])->toBe($now)
            ->and(Str::isUuid($row['id'], 7))->toBeTrue();
    }

    expect(array_unique(array_column($rows, 'id')))->toHaveCount(2);
});

test('translationRowsFor() keeps a null description null rather than turning it into an empty string', function () {
    $rows = (new BackfillProductTranslations)->translationRowsFor(
        [p76LegacyProductRow('Sin descripcion', null)],
        (string) Str::uuid7(),
        Carbon::now(),
    );

    expect($rows[0]['description'])->toBeNull();
});

test('translationRowsFor() copies a description holding every allow-listed tag byte-identically, never re-sanitizing it', function () {
    $everyTag = '<p>a <strong>b</strong> <b>b</b> <em>c</em> <i>c</i> <u>d</u><br></p><h2>h</h2>'
        .'<ul><li>x</li></ul><ol><li>y</li></ol>'
        .'<a href="https://example.com/a?b=1&amp;c=2">link</a><img src="https://example.com/i.png" alt="i">';

    $rows = (new BackfillProductTranslations)->translationRowsFor(
        [p76LegacyProductRow('Todos los tags', $everyTag)],
        (string) Str::uuid7(),
        Carbon::now(),
    );

    expect($rows[0]['description'])->toBe($everyTag);
});

test('translationRowsFor() does not sanitize a legacy description: a migration is not an editorial event', function () {
    $legacy = '<script>legacy()</script><p>kept</p>';

    $rows = (new BackfillProductTranslations)->translationRowsFor(
        [p76LegacyProductRow('Historico', $legacy)],
        (string) Str::uuid7(),
        Carbon::now(),
    );

    expect($rows[0]['description'])->toBe($legacy);
});

test('translationRowsFor() over zero products returns an empty list', function () {
    expect((new BackfillProductTranslations)->translationRowsFor([], (string) Str::uuid7(), Carbon::now()))->toBe([]);
});
