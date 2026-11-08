<?php

use Illuminate\Support\Facades\Schema;

// Story 0076a (D-5, D-9b, D-11b, D-34): the additive migration that gives product_translations its
// slug, meta_title and meta_description columns and the named per-language slug unique.
// RefreshDatabase has already run every migration before each test body.

const S76A_MIGRATION_SUFFIX = 'add_slug_and_seo_columns_to_product_translations_table';
const S76A_SLUG_INDEX = 'product_translations_language_slug_unique';
const S76A_FK_INDEX = 'product_translations_store_language_id_foreign';

function s76aMigrationPath(string $suffix = S76A_MIGRATION_SUFFIX): string
{
    $matches = glob(database_path('migrations/*_'.$suffix.'.php'));

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

/**
 * @return array<string, array<string, mixed>> keyed by index name
 */
function s76aIndexes(): array
{
    return collect(Schema::getIndexes('product_translations'))->keyBy('name')->all();
}

test('the three new columns are nullable with the declared widths', function () {
    $columns = collect(Schema::getColumns('product_translations'))->keyBy('name');

    expect($columns->get('slug')['type'])->toBe('varchar(160)')
        ->and($columns->get('slug')['nullable'])->toBeTrue()
        ->and($columns->get('meta_title')['type'])->toBe('varchar(160)')
        ->and($columns->get('meta_title')['nullable'])->toBeTrue()
        ->and($columns->get('meta_description')['type'])->toBe('varchar(500)')
        ->and($columns->get('meta_description')['nullable'])->toBeTrue();
});

test('product_translations carries exactly three indexes: primary, the natural key and the per-language slug unique (D-11b)', function () {
    $indexes = s76aIndexes();

    expect(array_keys($indexes))->toEqualCanonicalizing(['primary', 'product_translations_product_language_unique', S76A_SLUG_INDEX])
        ->and($indexes[S76A_SLUG_INDEX]['unique'])->toBeTrue()
        ->and($indexes[S76A_SLUG_INDEX]['columns'])->toBe(['store_language_id', 'slug'])
        ->and($indexes['product_translations_product_language_unique']['columns'])->toBe(['product_id', 'store_language_id']);
});

test('the migration sorts after the two product_translations migrations of story 0076', function () {
    $mine = basename(s76aMigrationPath());
    $create = basename(s76aMigrationPath('create_product_translations_table'));
    $drop = basename(s76aMigrationPath('drop_translatable_columns_from_products_table'));

    expect(strcmp($mine, $create))->toBeGreaterThan(0)
        ->and(strcmp($mine, $drop))->toBeGreaterThan(0);
});

// D-34. DDL commits implicitly on MySQL, so the shared test schema must never be left rolled back:
// the finally block restores it, and only when down() actually removed the columns, so a down()
// that failed before touching anything is not followed by an up() that would fail on a duplicate column.
test('down() runs without error 1553, leaves three indexes, and up() restores exactly the same three, never four', function () {
    $migration = require s76aMigrationPath();

    try {
        $migration->down();

        $indexesAfterDown = s76aIndexes();
        $leftmostIsLanguage = collect($indexesAfterDown)->contains(
            fn (array $index): bool => ($index['columns'][0] ?? null) === 'store_language_id',
        );

        expect(Schema::hasColumn('product_translations', 'slug'))->toBeFalse()
            ->and(Schema::hasColumn('product_translations', 'meta_title'))->toBeFalse()
            ->and(Schema::hasColumn('product_translations', 'meta_description'))->toBeFalse()
            ->and($indexesAfterDown)->not->toHaveKey(S76A_SLUG_INDEX)
            ->and($leftmostIsLanguage)->toBeTrue()
            ->and($indexesAfterDown)->toHaveCount(3);

        $migration->up();

        $indexesAfterUp = s76aIndexes();

        expect(Schema::hasColumn('product_translations', 'slug'))->toBeTrue()
            ->and(array_keys($indexesAfterUp))->toEqualCanonicalizing(['primary', 'product_translations_product_language_unique', S76A_SLUG_INDEX])
            ->and($indexesAfterUp)->not->toHaveKey(S76A_FK_INDEX)
            ->and($indexesAfterUp)->toHaveCount(3);
    } finally {
        if (! Schema::hasColumn('product_translations', 'slug')) {
            $migration->up();
        }
    }
});
