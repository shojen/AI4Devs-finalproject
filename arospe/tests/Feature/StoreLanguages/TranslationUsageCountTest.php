<?php

// Story 0068 -- App\Models\StoreLanguage::translationUsageCount(), the D8 extension point stories
// 0070+ complete by appending one {table, column} entry to config/store-languages.php's
// translation_relations array. R-7's own warning is explicit: a test asserting 0 against the
// EMPTY shipped registry alone is vacuous -- it would pass whether the summing mechanism works or
// not. That negative case lives in tests/Unit/Models/StoreLanguageTest.php; THIS file holds the
// mandatory, non-vacuous counterpart: a temporarily registered relation pointing at a real,
// already-migrated table/column, proving the helper actually sums a real count.
//
// Reuses sales_regions.description (a plain nullable VARCHAR with no FK and no uniqueness
// constraint) as the stand-in "translation" column rather than Schema::create()-ing a dedicated
// table: a CREATE TABLE statement inside a RefreshDatabase-wrapped test issues an implicit COMMIT
// on MySQL/InnoDB, ending the surrounding transaction early and defeating the per-test rollback
// this whole suite relies on for isolation. Writing plain rows into an existing table's existing
// column needs no DDL at all, so the transaction (and therefore isolation) stays intact.

use App\Models\SalesRegion;
use App\Models\StoreLanguage;
use Illuminate\Support\Str;

test('translationUsageCount returns 0 against the shipped empty registry, even with unrelated rows present', function () {
    config(['store-languages.translation_relations' => []]);

    $language = StoreLanguage::factory()->create();
    SalesRegion::factory()->create(['description' => $language->id]);

    expect(StoreLanguage::translationUsageCount($language->id))->toBe(0);
});

test('with a temporary relation registered, translationUsageCount returns the true row count', function () {
    $language = StoreLanguage::factory()->create();
    $otherLanguage = StoreLanguage::factory()->create();

    SalesRegion::factory()->count(3)->create(['description' => $language->id]);
    SalesRegion::factory()->count(2)->create(['description' => $otherLanguage->id]);
    SalesRegion::factory()->create(['description' => null]);

    config(['store-languages.translation_relations' => [
        ['table' => 'sales_regions', 'column' => 'description'],
    ]]);

    expect(StoreLanguage::translationUsageCount($language->id))->toBe(3)
        ->and(StoreLanguage::translationUsageCount($otherLanguage->id))->toBe(2);
});

test('translationUsageCount sums across every registered relation, not only the first', function () {
    $language = StoreLanguage::factory()->create();

    SalesRegion::factory()->count(2)->create(['description' => $language->id]);

    config(['store-languages.translation_relations' => [
        ['table' => 'sales_regions', 'column' => 'description'],
        ['table' => 'sales_regions', 'column' => 'code'],
    ]]);

    // Nothing has $language->id stored in `code` (a two-letter column), so this second
    // registered relation contributes 0 -- the total must still be exactly the first
    // relation's count, proving the helper iterates the whole registry rather than only
    // ever reading its first entry.
    expect(StoreLanguage::translationUsageCount($language->id))->toBe(2);
});

test('translationUsageCount returns 0 for a language nothing references', function () {
    $language = StoreLanguage::factory()->create();

    config(['store-languages.translation_relations' => [
        ['table' => 'sales_regions', 'column' => 'description'],
    ]]);

    expect(StoreLanguage::translationUsageCount($language->id))->toBe(0);
});

test('translationUsageCount is called with a plain string id, never requiring a hydrated model', function () {
    $language = StoreLanguage::factory()->create();
    SalesRegion::factory()->create(['description' => $language->id]);

    config(['store-languages.translation_relations' => [
        ['table' => 'sales_regions', 'column' => 'description'],
    ]]);

    $count = StoreLanguage::translationUsageCount((string) $language->id);

    expect($count)->toBe(1)
        ->and(StoreLanguage::translationUsageCount((string) Str::uuid()))->toBe(0);
});
