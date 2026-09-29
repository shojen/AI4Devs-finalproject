<?php

// Story 0070, Phase 5 round-1 finding 2 -- code-reviewer's own reproduction fixture
// (['Zapatos', 'bolsos', 'Árboles']) proved that a bare `$nameA <=> $nameB` byte comparison
// regressed the case-/accent-insensitive ordering `orderBy('name')` gave under the column's
// `utf8mb4_unicode_ci` collation before `product_categories.name` moved off the table. This is a
// pure `tests/Unit/` test per docs/testing/backend/unit-tests.md: App\Actions\Translations\
// CompareTranslatedNames takes plain strings, not models, so no database or RefreshDatabase is
// needed -- exactly NormalizeForSearchTest's own reasoning for the fold it reuses.
//
// Every construction below is `app(CompareTranslatedNames::class)`, never `new
// CompareTranslatedNames` (docs/conventions/code-style.md's "an action must be resolved from the
// container, never `new`-ed -- including in tests" rule), matching NormalizeForSearchTest's own
// precedent.

use App\Actions\Translations\CompareTranslatedNames;

it('sorts case- and accent-insensitively, matching MySQL utf8mb4_unicode_ci rather than byte order', function () {
    $compareTranslatedNames = app(CompareTranslatedNames::class);

    // code-reviewer's own reproduction fixture: byte order would put "Zapatos" (uppercase Z)
    // before "bolsos" (lowercase b) and "Árboles" (accented) after both ASCII names.
    $names = [
        ['name' => 'Zapatos', 'id' => 'id-zapatos'],
        ['name' => 'bolsos', 'id' => 'id-bolsos'],
        ['name' => 'Árboles', 'id' => 'id-arboles'],
    ];

    usort(
        $names,
        fn (array $a, array $b): int => $compareTranslatedNames($a['name'], $a['id'], $b['name'], $b['id']),
    );

    expect(array_column($names, 'name'))->toBe(['Árboles', 'bolsos', 'Zapatos']);
});

it('sorts case-insensitively alone', function () {
    $compareTranslatedNames = app(CompareTranslatedNames::class);

    expect($compareTranslatedNames('bolsos', 'id-a', 'Zapatos', 'id-b'))->toBeLessThan(0)
        ->and($compareTranslatedNames('Zapatos', 'id-a', 'bolsos', 'id-b'))->toBeGreaterThan(0);
});

it('sorts accent-insensitively alone', function () {
    $compareTranslatedNames = app(CompareTranslatedNames::class);

    expect($compareTranslatedNames('Árboles', 'id-a', 'Bolsos', 'id-b'))->toBeLessThan(0)
        ->and($compareTranslatedNames('Bolsos', 'id-a', 'Árboles', 'id-b'))->toBeGreaterThan(0);
});

it('sorts a null name last, regardless of which side it is on', function () {
    $compareTranslatedNames = app(CompareTranslatedNames::class);

    expect($compareTranslatedNames(null, 'id-a', 'Calzado', 'id-b'))->toBeGreaterThan(0)
        ->and($compareTranslatedNames('Calzado', 'id-a', null, 'id-b'))->toBeLessThan(0);
});

it('breaks a tie between two null names on id', function () {
    $compareTranslatedNames = app(CompareTranslatedNames::class);

    expect($compareTranslatedNames(null, 'id-a', null, 'id-b'))->toBe(-1)
        ->and($compareTranslatedNames(null, 'id-b', null, 'id-a'))->toBe(1)
        ->and($compareTranslatedNames(null, 'id-a', null, 'id-a'))->toBe(0);
});

it('breaks a tie between two names that fold identically on id', function () {
    $compareTranslatedNames = app(CompareTranslatedNames::class);

    // "Café" and "cafe" fold to the same normalized value (lowercased, accent-stripped), so the
    // id tiebreak is what makes the comparison deterministic rather than "equal, order undefined".
    expect($compareTranslatedNames('Café', 'id-a', 'cafe', 'id-b'))->toBe(-1)
        ->and($compareTranslatedNames('Café', 'id-b', 'cafe', 'id-a'))->toBe(1)
        ->and($compareTranslatedNames('Café', 'id-a', 'cafe', 'id-a'))->toBe(0);
});
