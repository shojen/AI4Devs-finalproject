<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0076 (D-2, D-4, D-11, D-12, D-13, D-17, D-19): the product_translations table, the pair of
// migrations that create it and drop products.name/description, and the drop migration's guard.
// RefreshDatabase has already run both migrations before every test body.

/**
 * @return array<string, array<string, mixed>> the table's columns keyed by name
 */
function p76Columns(string $table): array
{
    $columns = [];

    foreach (Schema::getColumns($table) as $column) {
        $columns[$column['name']] = $column;
    }

    return $columns;
}

function p76MigrationPath(string $suffix): string
{
    $matches = glob(database_path('migrations/*_'.$suffix.'.php'));

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

test('product_translations has the declared columns, types and nullability, including the slug and SEO columns story 0076a adds', function () {
    $columns = p76Columns('product_translations');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'product_id', 'store_language_id', 'name', 'description', 'slug', 'meta_title', 'meta_description', 'created_at', 'updated_at',
    ])
        ->and($columns['id']['type'])->toBe('char(36)')
        ->and($columns['product_id']['type'])->toBe('char(36)')
        ->and($columns['product_id']['nullable'])->toBeFalse()
        ->and($columns['store_language_id']['type'])->toBe('char(36)')
        ->and($columns['store_language_id']['nullable'])->toBeFalse()
        ->and($columns['name']['type'])->toBe('varchar(255)')
        ->and($columns['name']['nullable'])->toBeFalse()
        ->and($columns['description']['type'])->toBe('mediumtext')
        ->and($columns['description']['nullable'])->toBeTrue();
});

test('products no longer carries name or description once the drop migration has run', function () {
    expect(Schema::hasColumn('products', 'name'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'description'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'sku'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'featured_media_id'))->toBeTrue();
});

test('product_translations carries exactly three indexes, the natural key is unique and named, and nothing indexes name', function () {
    $indexes = collect(Schema::getIndexes('product_translations'))->keyBy('name');

    expect($indexes)->toHaveCount(3);

    $naturalKey = $indexes->get('product_translations_product_language_unique');

    expect($naturalKey)->not->toBeNull()
        ->and($naturalKey['unique'])->toBeTrue()
        ->and($naturalKey['columns'])->toBe(['product_id', 'store_language_id']);

    foreach ($indexes as $index) {
        expect($index['columns'])->not->toContain('name');
    }
});

test('a second row for the same product and language is refused by the natural key, naming the index', function () {
    $language = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();

    ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $product->id]);

    $caught = null;

    try {
        ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $product->id]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1062)
        ->and($caught->getMessage())->toContain('product_translations_product_language_unique');
});

test('two products may carry byte-identical names in the same language: there is no unique on name', function () {
    $language = StoreLanguage::factory()->default()->create();
    $first = Product::factory()->withoutTranslations()->create();
    $second = Product::factory()->withoutTranslations()->create();

    ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $first->id, 'name' => 'Camiseta basica']);
    ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $second->id, 'name' => 'Camiseta basica']);

    expect(DB::table('product_translations')->where('name', 'Camiseta basica')->count())->toBe(2);
});

test('deleting a product through the model removes every language translation with it', function () {
    $default = StoreLanguage::factory()->default()->create();
    $fr = StoreLanguage::factory()->create();
    $de = StoreLanguage::factory()->create();
    $product = Product::factory()->withoutTranslations()->create();
    $survivor = Product::factory()->withoutTranslations()->create();

    foreach ([$default, $fr, $de] as $language) {
        ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $product->id]);
    }

    ProductTranslation::factory()->forLanguage($default)->create(['product_id' => $survivor->id]);

    $product->delete();

    expect(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(0)
        ->and(DB::table('product_translations')->where('product_id', $survivor->id)->count())->toBe(1);
});

test('the store_languages foreign key restricts: a language that still holds product translations cannot be hard-deleted', function () {
    $language = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();

    ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $product->id]);

    $caught = null;

    try {
        DB::table('store_languages')->where('id', $language->id)->delete();
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1451);
});

test('the create migration sorts after the products and store_languages tables, and the drop migration after the create one', function () {
    $create = basename(p76MigrationPath('create_product_translations_table'));
    $drop = basename(p76MigrationPath('drop_translatable_columns_from_products_table'));
    $products = basename(p76MigrationPath('create_products_table'));
    $storeLanguages = basename(p76MigrationPath('create_store_languages_table'));

    expect(strcmp($create, $products))->toBeGreaterThan(0)
        ->and(strcmp($create, $storeLanguages))->toBeGreaterThan(0)
        ->and(strcmp($drop, $create))->toBeGreaterThan(0);
});

test('the drop migration refuses to run while a product has no translation row, before any DDL', function () {
    Product::factory()->withoutTranslations()->create();

    $migration = require p76MigrationPath('drop_translatable_columns_from_products_table');

    $caught = null;

    try {
        $migration->up();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toContain('Cannot drop products.name/description')
        ->and($caught->getMessage())->toContain('Re-run the backfill');

    // Both columns were already dropped by RefreshDatabase: a guard that threw only AFTER dropColumn()
    // would surface as a QueryException here instead, not as the RuntimeException above.
    expect(Schema::hasColumn('products', 'name'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'description'))->toBeFalse();
});

test('the create migration refuses before any DDL when products exist and no default store language does', function () {
    Product::factory()->withoutTranslations()->create([
        'product_category_id' => ProductCategory::factory()->withoutTranslations()->create()->id,
    ]);

    expect(StoreLanguage::query()->count())->toBe(0);

    $migration = require p76MigrationPath('create_product_translations_table');

    // The table already exists, so a Schema::create() reached before the guard would raise a
    // QueryException ("table already exists") rather than this RuntimeException.
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
});

test('the drop migration down() restores both columns as nullable without data', function () {
    $migration = require p76MigrationPath('drop_translatable_columns_from_products_table');

    $migration->down();

    try {
        $columns = p76Columns('products');

        expect($columns['name']['nullable'])->toBeTrue()
            ->and($columns['name']['type'])->toBe('varchar(255)')
            ->and($columns['description']['nullable'])->toBeTrue()
            ->and($columns['description']['type'])->toBe('mediumtext');
    } finally {
        // DDL implicitly commits on MySQL: restore the post-migration shape so no state leaks.
        Schema::table('products', function ($table): void {
            $table->dropColumn(['name', 'description']);
        });
    }

    expect(Schema::hasColumn('products', 'name'))->toBeFalse();
});

test('a translation row accepts a null description but refuses a null name', function () {
    $language = StoreLanguage::factory()->default()->create();
    $product = Product::factory()->withoutTranslations()->create();

    DB::table('product_translations')->insert([
        'id' => (string) Str::uuid7(),
        'product_id' => $product->id,
        'store_language_id' => $language->id,
        'name' => 'Con nombre',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $other = StoreLanguage::factory()->create();
    $caught = null;

    try {
        DB::table('product_translations')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $product->id,
            'store_language_id' => $other->id,
            'name' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->errorInfo[1])->toBe(1048);
});
