<?php

use App\Actions\Products\BackfillProductTranslations;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0076 (D-9, D-13): the database-facing half of the products backfill. Both migrations have
// already run before every test body, so products no longer carries `name`/`description`: the
// guards run against the real schema, and the real runs re-add the legacy columns for one test.
// MySQL DDL implicitly commits, which defeats RefreshDatabase's rollback, so every real run cleans
// every table it touched and drops both columns again in a finally block.

test('with products present and no default store language, the backfill refuses loudly and writes nothing', function () {
    Product::factory()->withoutTranslations()->create([
        'product_category_id' => ProductCategory::factory()->withoutTranslations()->create()->id,
    ]);

    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => app(BackfillProductTranslations::class)->assertCanBackfill())->toThrow(RuntimeException::class);
    expect(fn () => app(BackfillProductTranslations::class)())->toThrow(RuntimeException::class);

    expect(DB::table('product_translations')->count())->toBe(0);
});

// The property whose absence failed the whole suite under RefreshDatabase (Phase 2 finding 1).
test('with zero products, assertCanBackfill() does not throw even with no default store language', function () {
    expect(StoreLanguage::query()->count())->toBe(0)
        ->and(DB::table('products')->count())->toBe(0);

    app(BackfillProductTranslations::class)->assertCanBackfill();

    expect(app(BackfillProductTranslations::class)())->toBe(0);
});

test('with products present and a default store language, assertCanBackfill() does not throw', function () {
    StoreLanguage::factory()->default()->create();
    Product::factory()->withoutTranslations()->create();

    expect(fn () => app(BackfillProductTranslations::class)->assertCanBackfill())->not->toThrow(RuntimeException::class);
});

/**
 * Re-adds the legacy columns, runs $callback, then removes every row and both columns again.
 */
function p76WithLegacyProductColumns(Closure $callback): void
{
    try {
        Schema::table('products', function ($table): void {
            $table->string('name', 255)->nullable();
            $table->mediumText('description')->nullable();
        });

        $callback();
    } finally {
        // MySQL commits implicitly before any DDL, even one that errors, so rows the test arranged
        // are cleaned unconditionally and the columns are dropped only if they exist.
        if (Schema::hasTable('product_translations')) {
            DB::table('product_translations')->delete();
        }

        DB::table('products')->delete();
        DB::table('product_category_translations')->delete();
        DB::table('product_categories')->delete();
        DB::table('store_languages')->delete();

        if (Schema::hasColumn('products', 'name')) {
            Schema::table('products', function ($table): void {
                $table->dropColumn(['name', 'description']);
            });
        }
    }
}

/**
 * @param  list<array{name: string, description: ?string}>  $legacy
 * @return list<string> the inserted product ids, in insertion order
 */
function p76InsertLegacyProducts(string $categoryId, array $legacy): array
{
    $ids = [];
    $rows = [];

    foreach ($legacy as $index => $row) {
        $ids[] = $id = (string) Str::uuid7();
        $rows[] = [
            'id' => $id,
            'product_category_id' => $categoryId,
            'sku' => 'LEG-'.$index,
            'type' => 'physical',
            'status' => 'draft',
            'price' => '10.00',
            'stock' => 1,
            'name' => $row['name'],
            'description' => $row['description'],
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($rows) === 500) {
            DB::table('products')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('products')->insert($rows);
    }

    return $ids;
}

/**
 * @return list<array{name: string, description: ?string}>
 */
function p76LegacyFixtures(int $count): array
{
    $fixtures = [];

    for ($i = 0; $i < $count; $i++) {
        $fixtures[] = ['name' => 'Producto '.$i, 'description' => $i % 2 === 0 ? '<p>Desc '.$i.'</p>' : null];
    }

    return $fixtures;
}

test('a real run gives every existing product a default-language translation copied byte-for-byte, a null description staying null', function () {
    $category = ProductCategory::factory()->create();
    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $other = StoreLanguage::factory()->create();

    p76WithLegacyProductColumns(function () use ($category, $default, $other): void {
        $legacy = [
            ['name' => '  Zapatillas  Running ', 'description' => '<p>Ligeras</p>'],
            ['name' => str_repeat('a', 255), 'description' => null],
            // A description the sanitizer would rewrite must survive untouched (D-9).
            ['name' => 'Historico', 'description' => '<script>old()</script><p>kept</p>'],
        ];
        $ids = p76InsertLegacyProducts($category->id, $legacy);

        expect(app(BackfillProductTranslations::class)())->toBe(3);

        foreach ($legacy as $index => $row) {
            $translations = DB::table('product_translations')->where('product_id', $ids[$index])->get();

            expect($translations)->toHaveCount(1)
                ->and($translations[0]->store_language_id)->toBe($default->id)
                ->and($translations[0]->name)->toBe($row['name'])
                ->and($translations[0]->description)->toBe($row['description']);
        }

        expect(DB::table('product_translations')->where('store_language_id', $other->id)->count())->toBe(0);
    });
});

test('a second run returns 0 and leaves the translation rows untouched', function () {
    $category = ProductCategory::factory()->create();

    p76WithLegacyProductColumns(function () use ($category): void {
        p76InsertLegacyProducts($category->id, p76LegacyFixtures(3));

        expect(app(BackfillProductTranslations::class)())->toBe(3);

        $before = DB::table('product_translations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        expect(app(BackfillProductTranslations::class)())->toBe(0);

        $after = DB::table('product_translations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        expect($after)->toHaveCount(3)->and($after)->toBe($before);
    });
});

test('a re-run over a partially translated catalogue writes only the missing default rows and never overwrites existing text', function () {
    $category = ProductCategory::factory()->create();
    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $other = StoreLanguage::factory()->create();

    p76WithLegacyProductColumns(function () use ($category, $default, $other): void {
        $ids = p76InsertLegacyProducts($category->id, p76LegacyFixtures(5));
        $now = now();

        foreach ([0, 1] as $index) {
            DB::table('product_translations')->insert([
                'id' => (string) Str::uuid7(),
                'product_id' => $ids[$index],
                'store_language_id' => $default->id,
                'name' => 'Edited '.$index,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Product 2 only has a non-default translation: it still lacks the default-language one.
        DB::table('product_translations')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $ids[2],
            'store_language_id' => $other->id,
            'name' => 'Otro',
            'description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(app(BackfillProductTranslations::class)())->toBe(3);

        foreach ([0, 1] as $index) {
            $rows = DB::table('product_translations')->where('product_id', $ids[$index])->get();

            expect($rows)->toHaveCount(1)
                ->and($rows[0]->name)->toBe('Edited '.$index)
                ->and($rows[0]->description)->toBeNull();
        }

        foreach ([2, 3, 4] as $index) {
            $rows = DB::table('product_translations')
                ->where('product_id', $ids[$index])
                ->where('store_language_id', $default->id)
                ->get();

            expect($rows)->toHaveCount(1)
                ->and($rows[0]->name)->toBe('Producto '.$index);
        }

        expect(DB::table('product_translations')->where('product_id', $ids[2])->count())->toBe(2)
            ->and(DB::table('product_translations')->count())->toBe(6);
    });
});

test('a catalogue larger than one chunk gets exactly one default-language translation per product', function () {
    $category = ProductCategory::factory()->create();
    $default = StoreLanguage::query()->where('is_default', true)->sole();

    p76WithLegacyProductColumns(function () use ($category, $default): void {
        $ids = p76InsertLegacyProducts($category->id, p76LegacyFixtures(1201));

        expect(app(BackfillProductTranslations::class)())->toBe(1201)
            ->and(DB::table('product_translations')->count())->toBe(1201)
            ->and(DB::table('product_translations')->distinct()->count('product_id'))->toBe(1201)
            ->and(DB::table('product_translations')->where('store_language_id', $default->id)->count())->toBe(1201);

        foreach ([0, 499, 500, 999, 1000, 1200] as $index) {
            $row = DB::table('product_translations')->where('product_id', $ids[$index])->first();

            expect($row->name)->toBe('Producto '.$index)
                ->and($row->description)->toBe($index % 2 === 0 ? '<p>Desc '.$index.'</p>' : null);
        }

        expect(app(BackfillProductTranslations::class)())->toBe(0);
    });
});

test('the backfill reads through the query builder, so no ProductTranslation model event fires', function () {
    $category = ProductCategory::factory()->create();
    $fired = 0;

    p76WithLegacyProductColumns(function () use ($category, &$fired): void {
        p76InsertLegacyProducts($category->id, [['name' => 'Historico', 'description' => '<script>old()</script>']]);

        Event::listen('eloquent.saving: '.ProductTranslation::class, function () use (&$fired): void {
            $fired++;
        });

        app(BackfillProductTranslations::class)();

        expect($fired)->toBe(0)
            ->and(DB::table('product_translations')->value('description'))->toBe('<script>old()</script>');
    });
});
