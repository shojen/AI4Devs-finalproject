<?php

use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// Story 0070 (D-14) -- the first REAL entry appended to 0068's `translation_relations` registry.
// tests/Feature/StoreLanguages/TranslationUsageCountTest.php (0068's own file, untouched by this
// story) already proves the summing MECHANISM generically against a temporarily-registered
// stand-in relation; this file is deliberately separate and proves two things 0068 could not:
// that the REAL, shipped config entry for product_category_translations sums real rows end to
// end, and the drift guard itself -- that every registered {table, column} pair resolves against
// the live schema, AND every `*_translations`-suffixed table is registered (the likelier real
// failure: a sibling story forgetting to append its own entry).
//
// Deliberately never overrides config() in this file: both tests read the REAL,
// config/store-languages.php-shipped registry, so they keep working as genuine regression guards
// as future sibling stories (0072/0074/0076/0078) append their own entries.

test('StoreLanguage::translationUsageCount() returns the true count against the real, registered product_category_translations relation', function () {
    $french = StoreLanguage::factory()->create();
    $spanish = StoreLanguage::factory()->default()->create();

    $categoryA = ProductCategory::factory()->withoutTranslations()->create();
    $categoryB = ProductCategory::factory()->withoutTranslations()->create();

    ProductCategoryTranslation::factory()->forLanguage($french)->create(['product_category_id' => $categoryA->id]);
    ProductCategoryTranslation::factory()->forLanguage($french)->create(['product_category_id' => $categoryB->id]);
    ProductCategoryTranslation::factory()->forLanguage($spanish)->create(['product_category_id' => $categoryA->id]);

    expect(StoreLanguage::translationUsageCount($french->id))->toBe(2)
        ->and(StoreLanguage::translationUsageCount($spanish->id))->toBe(1);
});

// D-14's HARD half: a translation table that exists but was never registered is the likelier real
// failure. Derived from the LIVE schema rather than a hardcoded list, so no sibling story (0072,
// 0074, 0076, 0078) ever has to edit this test to add its own entry.
//
// Scoped to the current connection's schema only: this repo runs one testing_<id> database per
// worktree on a shared MySQL server (docs/testing/worktree-databases.md), so an unscoped
// getTableListing() would list every *_translations table from every worktree's database at once,
// not just this connection's -- a false positive under normal parallel development.
test('every registered translation_relations pair resolves against the live schema, and every *_translations table is registered', function () {
    /** @var array<int, array{table: string, column: string}> $relations */
    $relations = config('store-languages.translation_relations', []);

    foreach ($relations as $relation) {
        expect(Schema::hasTable($relation['table']))->toBeTrue("registered table [{$relation['table']}] does not exist")
            ->and(Schema::hasColumn($relation['table'], $relation['column']))->toBeTrue("registered column [{$relation['table']}.{$relation['column']}] does not exist");
    }

    $registeredTables = collect($relations)->pluck('table')->sort()->values()->all();

    $translationTables = collect(Schema::getTableListing(schema: Schema::getCurrentSchemaListing(), schemaQualified: false))
        ->filter(fn (string $table): bool => str_ends_with($table, '_translations'))
        ->sort()
        ->values()
        ->all();

    // Sanity check on the fixture itself: this story's own migration must have run.
    expect($translationTables)->toContain('product_category_translations');

    expect($registeredTables)->toBe($translationTables);
});

test('php artisan config:cache succeeds with the appended translation_relations entry', function () {
    try {
        $exitCode = Artisan::call('config:cache');

        expect($exitCode)->toBe(0);
    } finally {
        Artisan::call('config:clear');
    }
});
