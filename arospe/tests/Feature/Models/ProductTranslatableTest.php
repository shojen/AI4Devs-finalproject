<?php

use App\Concerns\Translatable;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Story 0076 (D-3, D-10, D-14, D-21): Product as a Translatable model -- fillable shape, relations,
// the per-field fallback wiring against a real two-field table, the order-line name seam and the
// translated-name ordering scope.

beforeEach(function () {
    StoreLanguage::flushDefaultStoreLanguage();
});

/**
 * @return array{default: StoreLanguage, fr: StoreLanguage, de: StoreLanguage}
 */
function p76Languages(): array
{
    return [
        'default' => StoreLanguage::factory()->default()->create(['code' => 'es']),
        'fr' => StoreLanguage::factory()->create(['code' => 'fr']),
        'de' => StoreLanguage::factory()->create(['code' => 'de']),
    ];
}

function p76Translate(Product $product, StoreLanguage $language, string $name, ?string $description = null, ?string $createdAt = null): ProductTranslation
{
    $translation = ProductTranslation::factory()->forLanguage($language)->create([
        'product_id' => $product->id,
        'name' => $name,
        'description' => $description,
    ]);

    if ($createdAt !== null) {
        DB::table('product_translations')->where('id', $translation->id)->update(['created_at' => $createdAt]);
    }

    return $translation;
}

// --- Model shape ---------------------------------------------------------------------------

test('Product implements Translatable and exposes the translations relation', function () {
    $product = Product::factory()->withoutTranslations()->create();

    expect($product)->toBeInstanceOf(Translatable::class)
        ->and($product->translations())->toBeInstanceOf(HasMany::class)
        ->and($product->translations()->getRelated())->toBeInstanceOf(ProductTranslation::class);
});

test('the fillable set is exactly the six parent columns, with name, description and featured_media_id absent', function () {
    expect((new Product)->getFillable())->toBe([
        'sku', 'product_category_id', 'type', 'status', 'price', 'stock',
    ])->and((new Product)->getFillable())->not->toContain('featured_media_id');
});

test('the five existing relations survive the retrofit', function () {
    $product = Product::factory()->withoutTranslations()->create();

    expect($product->category())->toBeInstanceOf(BelongsTo::class)
        ->and($product->featuredImage())->toBeInstanceOf(BelongsTo::class)
        ->and($product->gallery())->toBeInstanceOf(BelongsToMany::class)
        ->and($product->salesRegions())->toBeInstanceOf(BelongsToMany::class)
        ->and($product->variants())->toBeInstanceOf(HasMany::class)
        ->and($product->gallery()->getRelated())->toBeInstanceOf(Media::class);
});

test('a forged mass assignment of name is silently dropped and raises no QueryException', function () {
    $category = Product::factory()->withoutTranslations()->create()->category;

    $product = Product::create([
        'name' => 'Forjado',
        'description' => 'Forjada',
        'sku' => 'FORGED-1',
        'product_category_id' => $category->id,
        'type' => 'physical',
        'status' => 'draft',
        'price' => '5.00',
        'stock' => 1,
    ]);

    expect($product->getAttributes())->not->toHaveKey('name')
        ->and($product->getAttributes())->not->toHaveKey('description')
        ->and(Schema::hasColumn('products', 'name'))->toBeFalse();
});

// --- Per-field fallback (D-3) --------------------------------------------------------------

test('a product translated into the requested language returns that language', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');
    p76Translate($product, $fr, 'Chaussures de course');

    $product = $product->fresh();

    expect($product->translated('name', $fr->id))->toBe('Chaussures de course')
        ->and($product->translated('name'))->toBe('Zapatillas Running');
});

test('a missing translation falls back to the default store language', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');

    expect($product->fresh()->translated('name', $fr->id))->toBe('Zapatillas Running');
});

test('missing in both languages resolves each field to null without throwing', function () {
    ['fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();

    $product = $product->fresh();

    expect($product->translated('name', $fr->id))->toBeNull()
        ->and($product->translated('description', $fr->id))->toBeNull();
});

test('a half-translated product keeps its French title and falls back only on the description', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running', '<p>Descripcion por defecto</p>');
    p76Translate($product, $fr, 'Chaussures de course', null);

    $product = $product->fresh();

    expect($product->translated('description', $fr->id))->toBe('<p>Descripcion por defecto</p>')
        ->and($product->translated('name', $fr->id))->toBe('Chaussures de course');
});

test('the mirror: a French description is kept while an empty French name falls back to the default title', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running', '<p>Por defecto</p>');
    // name is NOT NULL, so "absent" is an empty string, which translated() treats as absent.
    p76Translate($product, $fr, '', '<p>Description francaise</p>');

    $product = $product->fresh();

    expect($product->translated('name', $fr->id))->toBe('Zapatillas Running')
        ->and($product->translated('description', $fr->id))->toBe('<p>Description francaise</p>');
});

test('a French description stored as an empty string falls back to the default description', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running', '<p>Por defecto</p>');
    p76Translate($product, $fr, 'Chaussures de course', '');

    expect($product->fresh()->translated('description', $fr->id))->toBe('<p>Por defecto</p>');
});

test('an inactive requested language still resolves, because removal preserves stored content', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');
    p76Translate($product, $fr, 'Chaussures de course');
    $fr->update(['is_active' => false]);

    expect($product->fresh()->translated('name', $fr->id))->toBe('Chaussures de course');
});

test('after the store default changes, a product translated only into the old default resolves to null without error', function () {
    ['default' => $old, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $old, 'Zapatillas Running');

    expect($product->fresh()->translated('name'))->toBe('Zapatillas Running');

    $old->update(['is_default' => false]);
    $fr->update(['is_default' => true]);
    StoreLanguage::flushDefaultStoreLanguage();

    $product = $product->fresh();

    expect($product->translated('name'))->toBeNull()
        ->and($product->translated('description'))->toBeNull();
});

// --- snapshotName() (D-21, N-3, N-4) -------------------------------------------------------

test('snapshotName() returns the default-language name even when a French row exists', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');
    p76Translate($product, $fr, 'Chaussures de course');

    expect($product->fresh()->snapshotName())->toBe('Zapatillas Running');
});

test('snapshotName() on the common path issues no query once translations are loaded', function () {
    ['default' => $default] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');
    $product = Product::query()->with('translations')->findOrFail($product->id);

    DB::enableQueryLog();
    $name = $product->snapshotName();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($name)->toBe('Zapatillas Running')
        ->and($queries)->toBe([]);
});

test('snapshotName() with a blank default name freezes the oldest non-blank translation, ordered by created_at then id', function () {
    ['default' => $default, 'fr' => $fr, 'de' => $de] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, '', null, '2026-01-01 00:00:00');
    p76Translate($product, $de, 'Neuer', null, '2026-03-01 00:00:00');
    p76Translate($product, $fr, 'Ancien', null, '2026-02-01 00:00:00');

    expect($product->fresh()->snapshotName())->toBe('Ancien');
});

test('snapshotName() with no default-language row freezes the oldest stored translation', function () {
    ['fr' => $fr, 'de' => $de] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $de, 'Neuer', null, '2026-03-01 00:00:00');
    p76Translate($product, $fr, 'Ancien', null, '2026-02-01 00:00:00');

    expect($product->fresh()->snapshotName())->toBe('Ancien');
});

test('snapshotName() breaks a created_at tie by id', function () {
    ['fr' => $fr, 'de' => $de] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    $a = p76Translate($product, $de, 'Name DE', null, '2026-02-01 00:00:00');
    $b = p76Translate($product, $fr, 'Name FR', null, '2026-02-01 00:00:00');

    $expected = strcmp($a->id, $b->id) < 0 ? 'Name DE' : 'Name FR';

    expect($product->fresh()->snapshotName())->toBe($expected);
});

test('a relation filtered by withTranslationsFor(DE) with an older FR row and no default row still freezes FR', function () {
    ['fr' => $fr, 'de' => $de] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $fr, 'Ancien', null, '2026-02-01 00:00:00');
    p76Translate($product, $de, 'Neuer', null, '2026-03-01 00:00:00');

    $filtered = Product::query()->withTranslationsFor($de->id)->findOrFail($product->id);

    expect($filtered->translations->pluck('store_language_id')->all())->toBe([$de->id])
        ->and($filtered->snapshotName())->toBe('Ancien');
});

test('a relation filtered to a language other than the default never hides the default-language name', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');
    p76Translate($product, $fr, 'Chaussures de course');

    $filtered = Product::query()
        ->with(['translations' => fn ($query) => $query->where('store_language_id', $fr->id)])
        ->findOrFail($product->id);

    expect($filtered->snapshotName())->toBe('Zapatillas Running');
});

test('snapshotName() throws LogicException for a product with no translation at all', function () {
    p76Languages();
    $product = Product::factory()->withoutTranslations()->create();

    expect(fn () => $product->fresh()->snapshotName())->toThrow(LogicException::class);
});

test('snapshotName() throws LogicException when every stored name is blank', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, '');
    p76Translate($product, $fr, '');

    expect(fn () => $product->fresh()->snapshotName())->toThrow(LogicException::class);
});

// --- scopeOrderByTranslatedName() (D-14) ---------------------------------------------------

test('the scope orders by the requested language and falls back to the default for a product untranslated in it', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $botas = Product::factory()->withoutTranslations()->create();
    $abrigo = Product::factory()->withoutTranslations()->create();
    $camisa = Product::factory()->withoutTranslations()->create();

    // Default order is Abrigo, Alfa en castellano, Botas; in French the order differs.
    p76Translate($botas, $default, 'Botas');
    p76Translate($botas, $fr, 'Zeta');
    p76Translate($abrigo, $default, 'Abrigo');
    p76Translate($abrigo, $fr, 'Beta');
    p76Translate($camisa, $default, 'Alfa en castellano');

    $inDefault = Product::query()->orderByTranslatedName()->orderBy('products.id')->get();
    $inFrench = Product::query()->orderByTranslatedName($fr->id)->orderBy('products.id')->get();

    expect($inDefault->pluck('id')->all())->toBe([$abrigo->id, $camisa->id, $botas->id])
        ->and($inDefault->map(fn (Product $p) => $p->getAttribute('translated_name'))->all())->toBe(['Abrigo', 'Alfa en castellano', 'Botas'])
        ->and($inFrench->pluck('id')->all())->toBe([$camisa->id, $abrigo->id, $botas->id])
        ->and($inFrench->map(fn (Product $p) => $p->getAttribute('translated_name'))->all())->toBe(['Alfa en castellano', 'Beta', 'Zeta']);
});

test('the scope treats an empty requested name as absent, matching translated()', function () {
    ['default' => $default, 'fr' => $fr] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');
    p76Translate($product, $fr, '');

    $row = Product::query()->orderByTranslatedName($fr->id)->sole();

    expect($row->getAttribute('translated_name'))->toBe('Zapatillas Running');
});

test('with no explicit select the scope selects products.* plus translated_name and keeps the products id', function () {
    ['default' => $default] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create(['price' => '12.50']);
    p76Translate($product, $default, 'Zapatillas Running');

    $row = Product::query()->orderByTranslatedName()->sole();

    expect($row->id)->toBe($product->id)
        ->and($row->sku)->toBe($product->sku)
        ->and((string) $row->price)->toBe('12.50')
        ->and($row->getAttribute('translated_name'))->toBe('Zapatillas Running');
});

test("with a caller's explicit qualified select the scope preserves it and adds only translated_name", function () {
    ['default' => $default] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Zapatillas Running');

    $row = Product::query()
        ->select(['products.id', 'products.sku'])
        ->orderByTranslatedName()
        ->orderBy('products.id')
        ->sole();

    expect(array_keys($row->getAttributes()))->toEqualCanonicalizing(['id', 'sku', 'translated_name'])
        ->and($row->id)->toBe($product->id);
});

test('products with no name in either language sort first and resolve to a null translated_name', function () {
    ['default' => $default] = p76Languages();
    $named = Product::factory()->withoutTranslations()->create();
    $nameless = Product::factory()->withoutTranslations()->create();
    p76Translate($named, $default, 'Abrigo');

    $rows = Product::query()->orderByTranslatedName()->orderBy('products.id')->get();

    expect($rows->pluck('id')->all())->toBe([$nameless->id, $named->id])
        ->and($rows[0]->getAttribute('translated_name'))->toBeNull();
});

test('two products with the same name are tie-broken by products.id so pages never reshuffle', function () {
    ['default' => $default] = p76Languages();
    $products = Product::factory()->withoutTranslations()->count(6)->create();

    foreach ($products as $product) {
        p76Translate($product, $default, 'Camiseta');
    }

    $expected = $products->pluck('id')->sort()->values()->all();
    $pageOne = Product::query()->orderByTranslatedName()->orderBy('products.id')->forPage(1, 3)->pluck('products.id')->all();
    $pageTwo = Product::query()->orderByTranslatedName()->orderBy('products.id')->forPage(2, 3)->pluck('products.id')->all();

    expect([...$pageOne, ...$pageTwo])->toBe($expected);
});

test('the scope builds a left join with COALESCE(NULLIF(...)) aliased as translated_name', function () {
    ['default' => $default] = p76Languages();
    $product = Product::factory()->withoutTranslations()->create();
    p76Translate($product, $default, 'Abrigo');

    $sql = strtolower(Product::query()->orderByTranslatedName()->toSql());

    expect($sql)->toContain('coalesce(nullif(')
        ->and($sql)->toContain('translated_name')
        ->and($sql)->toContain('left join');
});
