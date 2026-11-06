<?php

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Story 0076 (D-22, N-1): ProductFactory writes the default-language translation in afterCreating,
// and its named()/withoutTranslations() states change ONLY that step, so withGallery() (itself an
// afterCreating callback) survives in any order.

test('a plain create produces a named product through a default-language translation', function () {
    $product = Product::factory()->create();

    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $translations = ProductTranslation::query()->where('product_id', $product->id)->get();

    expect($translations)->toHaveCount(1)
        ->and($translations[0]->store_language_id)->toBe($default->id)
        ->and($translations[0]->name)->not->toBe('');
});

test('named() writes the given name and description into the default-language translation', function () {
    $product = Product::factory()->named('Silla de oficina', '<p>Ergonomica</p>')->create();

    $row = DB::table('product_translations')->where('product_id', $product->id)->sole();

    expect($row->name)->toBe('Silla de oficina')
        ->and($row->description)->toBe('<p>Ergonomica</p>')
        ->and($row->store_language_id)->toBe(StoreLanguage::query()->where('is_default', true)->sole()->id);
});

test('named() without a description stores a null description', function () {
    $product = Product::factory()->named('Silla')->create();

    expect(DB::table('product_translations')->where('product_id', $product->id)->sole()->description)->toBeNull();
});

test('chaining named() twice leaves exactly one translation carrying the last name', function () {
    $product = Product::factory()->named('Primero')->named('Segundo')->create();

    $rows = DB::table('product_translations')->where('product_id', $product->id)->get();

    expect($rows)->toHaveCount(1)->and($rows[0]->name)->toBe('Segundo');
});

test('withoutTranslations() creates a bare parent with no translation row', function () {
    $product = Product::factory()->withoutTranslations()->create();

    expect(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(0);
});

test('withGallery(2)->named() yields both the two-image gallery and the name', function () {
    $product = Product::factory()->withGallery(2)->named('X')->create();

    expect(DB::table('product_media')->where('product_id', $product->id)->count())->toBe(2)
        ->and(DB::table('product_translations')->where('product_id', $product->id)->sole()->name)->toBe('X');
});

test('named()->withGallery(2) yields both as well, so the order of the states does not matter', function () {
    $product = Product::factory()->named('X')->withGallery(2)->create();

    expect(DB::table('product_media')->where('product_id', $product->id)->count())->toBe(2)
        ->and(DB::table('product_translations')->where('product_id', $product->id)->sole()->name)->toBe('X');
});

test('withGallery(2)->withoutTranslations() and the reverse yield the gallery and zero translations', function () {
    $first = Product::factory()->withGallery(2)->withoutTranslations()->create();
    $second = Product::factory()->withoutTranslations()->withGallery(2)->create();

    foreach ([$first, $second] as $product) {
        expect(DB::table('product_media')->where('product_id', $product->id)->count())->toBe(2)
            ->and(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(0);
    }
});

test('forging name or description as a create() attribute fails loudly because they are no longer columns', function () {
    expect(fn () => Product::factory()->create(['name' => 'Forjado']))
        ->toThrow(fn (QueryException $exception) => expect($exception->errorInfo[1])->toBe(1054));
});

test('ProductTranslation::factory()->forLanguage() binds the row to that language', function () {
    $language = StoreLanguage::factory()->create();
    $product = Product::factory()->withoutTranslations()->create();

    $translation = ProductTranslation::factory()->forLanguage($language)->create(['product_id' => $product->id]);

    expect($translation->store_language_id)->toBe($language->id)
        ->and($translation->product_id)->toBe($product->id);
});
