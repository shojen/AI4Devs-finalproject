<?php

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\Actions\Translations\SetTranslation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0076a (D-6, D-18, D-31, D-35, R-22): the per-store-language slug and SEO fields on
// product_translations, written through CreateProduct / UpdateProduct and the bare SetTranslation
// primitive. The editor-preservation test lives in EditorTest.php, next to the helpers it shares.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function s76aPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Zapatillas Running',
        'sku' => 'S76A-'.Str::upper(Str::random(8)),
        'productCategoryId' => $overrides['productCategoryId'] ?? ProductCategory::factory()->create()->id,
        'type' => 'physical',
        'status' => 'active',
        'price' => '19.99',
        'stock' => 5,
        'description' => '<p>Ligeras</p>',
        'featuredMediaId' => null,
        'orderedGalleryMediaIds' => [],
    ], $overrides);
}

/**
 * Goes through CreateProduct. When `slug`, `metaTitle` and `metaDescription` are not in $overrides
 * they are not passed at all, which proves the three trailing parameters are optional on create.
 *
 * @param  array<string, mixed>  $overrides
 */
function s76aCreate(array $overrides = []): Product
{
    return app(CreateProduct::class)(...s76aPayload($overrides));
}

/**
 * Re-saves $product through UpdateProduct holding every field at its current value; the three new
 * parameters are required there, so they default to null here unless the test overrides them.
 *
 * @param  array<string, mixed>  $overrides
 */
function s76aUpdate(Product $product, array $overrides = []): Product
{
    return app(UpdateProduct::class)(...array_merge(
        ['product' => $product, 'slug' => null, 'metaTitle' => null, 'metaDescription' => null],
        s76aPayload(array_merge([
            'name' => (string) $product->translated('name'),
            'sku' => $product->sku,
            'productCategoryId' => $product->product_category_id,
            'price' => (string) $product->price,
            'stock' => $product->stock,
        ], $overrides)),
    ));
}

function s76aDefaultLanguage(): StoreLanguage
{
    return StoreLanguage::query()->where('is_default', true)->sole();
}

function s76aSecondLanguage(): StoreLanguage
{
    return StoreLanguage::factory()->create(['code' => 'fr']);
}

function s76aRow(Product $product, StoreLanguage $language): ?object
{
    return DB::table('product_translations')
        ->where('product_id', $product->id)
        ->where('store_language_id', $language->id)
        ->first();
}

function s76aStoredSlug(Product $product): ?string
{
    return s76aRow($product, s76aDefaultLanguage())?->slug;
}

function s76aCaught(Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * Runs $callback exactly once, immediately BEFORE the first statement that starts with $prefix, so
 * a conflicting row lands after validation has passed and before the action's own write -- the
 * ProductSkuUniquenessTest technique, without touching model event listeners.
 */
function s76aBeforeFirstStatement(string $prefix, Closure $callback): stdClass
{
    $state = new stdClass;
    $state->fired = false;

    DB::connection()->beforeExecuting(function (string $query) use ($state, $prefix, $callback): void {
        if ($state->fired || ! str_starts_with($query, $prefix)) {
            return;
        }

        $state->fired = true;
        $callback();
    });

    return $state;
}

// --- Canonical form (D-6) -------------------------------------------------------------------

test('updating a product stores the slug trimmed and canonical', function () {
    $product = s76aCreate();

    s76aUpdate($product, ['slug' => '  Chaussures De Course  ']);

    expect(s76aStoredSlug($product))->toBe('chaussures-de-course');
});

test('creating a product stores the slug canonical, and the SEO fields as given', function () {
    $product = s76aCreate(['slug' => 'Zapatillas De Trail', 'metaTitle' => 'Zapatillas', 'metaDescription' => 'Las mejores zapatillas.']);

    $row = s76aRow($product, s76aDefaultLanguage());

    expect($row->slug)->toBe('zapatillas-de-trail')
        ->and($row->meta_title)->toBe('Zapatillas')
        ->and($row->meta_description)->toBe('Las mejores zapatillas.');
});

test('a slug with no usable characters or only whitespace is saved as no slug through each action', function (string $slug) {
    $created = s76aCreate(['slug' => $slug]);

    expect(s76aRow($created, s76aDefaultLanguage())->slug)->toBeNull();

    $updated = s76aCreate();
    s76aUpdate($updated, ['slug' => $slug]);

    expect(s76aRow($updated, s76aDefaultLanguage())->slug)->toBeNull();
})->with([
    'punctuation only' => ['!!!'],
    'whitespace only' => ['   '],
    'empty string' => [''],
]);

test('a product can be created without passing any slug or SEO argument', function () {
    $product = s76aCreate();

    $row = s76aRow($product, s76aDefaultLanguage());

    expect($row->slug)->toBeNull()
        ->and($row->meta_title)->toBeNull()
        ->and($row->meta_description)->toBeNull();
});

test('a bare SetTranslation write canonicalises the slug in the saving hook, with no action in the path', function (?string $given, ?string $stored) {
    $product = s76aCreate();

    app(SetTranslation::class)($product, s76aDefaultLanguage(), ['slug' => $given]);

    expect(s76aStoredSlug($product))->toBe($stored);
})->with([
    'padded mixed case' => ['  Chaussures De Course  ', 'chaussures-de-course'],
    'punctuation only' => ['!!!', null],
    'whitespace only' => ['   ', null],
    'empty string' => ['', null],
]);

test('a bare SetTranslation write trims the meta fields and stores blank as null (D-35)', function () {
    $product = s76aCreate();
    $language = s76aDefaultLanguage();

    app(SetTranslation::class)($product, $language, ['meta_title' => '', 'meta_description' => '   ']);

    $blank = s76aRow($product, $language);

    expect($blank->meta_title)->toBeNull()
        ->and($blank->meta_description)->toBeNull();

    app(SetTranslation::class)($product, $language, ['meta_title' => '  Zapatillas  ']);

    expect(s76aRow($product, $language)->meta_title)->toBe('Zapatillas');
});

// --- Per-language uniqueness (D-6, D-31) ----------------------------------------------------

test('two products cannot share a slug within one store language', function () {
    s76aCreate(['slug' => 'chaussures-de-course']);
    $product = s76aCreate();

    $caught = s76aCaught(fn () => s76aUpdate($product, ['slug' => 'chaussures-de-course']));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('slug')
        ->and(s76aStoredSlug($product))->toBeNull();
});

test('a slug that collides only after canonicalisation is still refused on slug', function () {
    s76aCreate(['slug' => 'chaussures-de-course']);
    $product = s76aCreate();

    $caught = s76aCaught(fn () => s76aUpdate($product, ['slug' => 'Chaussures De Course']));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('slug');
});

test('creating a product with a taken slug is refused on slug', function () {
    s76aCreate(['slug' => 'chaussures-de-course']);

    $caught = s76aCaught(fn () => s76aCreate(['slug' => 'Chaussures De Course']));

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('slug')
        ->and(Product::query()->count())->toBe(1);
});

test('the same slug is accepted in another store language: uniqueness is scoped to one language', function () {
    $other = s76aCreate();
    $french = s76aSecondLanguage();

    app(SetTranslation::class)($other, $french, ['name' => 'Chaussures', 'slug' => 'chaussures-de-course']);

    $product = s76aCreate();

    s76aUpdate($product, ['slug' => 'chaussures-de-course']);

    expect(s76aStoredSlug($product))->toBe('chaussures-de-course')
        ->and(s76aRow($other, $french)->slug)->toBe('chaussures-de-course');
});

test('re-saving a product under its own unchanged slug is accepted, while a free slug is still accepted', function () {
    $product = s76aCreate(['slug' => 'chaussures']);
    s76aCreate(['slug' => 'bottes']);

    s76aUpdate($product, ['slug' => 'chaussures']);

    expect(s76aStoredSlug($product))->toBe('chaussures');

    s76aUpdate($product, ['slug' => 'nouvelle-paire']);

    expect(s76aStoredSlug($product))->toBe('nouvelle-paire');
});

test('writing a slug into a language where the product has no row yet inserts the row and is accepted', function () {
    $product = Product::factory()->withoutTranslations()->create();

    expect(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(0);

    s76aUpdate($product, ['name' => 'Primera fila', 'slug' => 'primera-fila']);

    expect(s76aStoredSlug($product))->toBe('primera-fila');
});

test('two products may both hold no slug in the same language', function () {
    $first = s76aCreate();
    $second = s76aCreate();

    s76aUpdate($first, ['slug' => '!!!']);
    s76aUpdate($second, ['slug' => '   ']);

    expect(DB::table('product_translations')->whereNull('slug')->count())->toBe(2);
});

// --- Races: the translator attributes the 1062 by index name (D-31, R-22) ----------------------

test('a raced duplicate slug through CreateProduct surfaces as a ValidationException on slug, never sku and never a 500', function () {
    $rival = s76aCreate();
    $language = s76aDefaultLanguage();

    $state = s76aBeforeFirstStatement('insert into `product_translations`', function () use ($rival, $language): void {
        DB::table('product_translations')
            ->where('product_id', $rival->id)
            ->where('store_language_id', $language->id)
            ->update(['slug' => 'raced-slug']);
    });

    $caught = s76aCaught(fn () => s76aCreate(['slug' => 'raced-slug']));

    expect($state->fired)->toBeTrue()
        ->and($caught)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($caught->errors()))->toBe(['slug'])
        ->and(Product::query()->count())->toBe(1);
});

test('a raced duplicate slug through UpdateProduct surfaces as a ValidationException on slug, never sku and never a 500', function () {
    $rival = s76aCreate();
    $product = s76aCreate();
    $language = s76aDefaultLanguage();

    $state = s76aBeforeFirstStatement('update `product_translations`', function () use ($rival, $language): void {
        DB::table('product_translations')
            ->where('product_id', $rival->id)
            ->where('store_language_id', $language->id)
            ->update(['slug' => 'raced-slug']);
    });

    $caught = s76aCaught(fn () => s76aUpdate($product, ['slug' => 'raced-slug']));

    expect($state->fired)->toBeTrue()
        ->and($caught)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($caught->errors()))->toBe(['slug']);
});

test('a concurrent first save of the default-language row asks the administrator to retry, on name, never sku, slug or a 500', function () {
    $product = Product::factory()->withoutTranslations()->create();
    $language = s76aDefaultLanguage();

    $state = s76aBeforeFirstStatement('insert into `product_translations`', function () use ($product, $language): void {
        DB::table('product_translations')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $product->id,
            'store_language_id' => $language->id,
            'name' => 'Inserted by the other administrator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $caught = s76aCaught(fn () => s76aUpdate($product, ['name' => 'My save']));

    expect($state->fired)->toBeTrue()
        ->and($caught)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($caught->errors()))->toBe(['name'])
        ->and($caught->errors()['name'][0])->toBe(trans('products.editor.concurrent_save_retry'))
        ->and($caught->errors()['name'][0])->not->toBe('products.editor.concurrent_save_retry');
});

// --- SEO fields ------------------------------------------------------------------------------

test('meta title is accepted at 160 characters and refused at 161, through each action', function () {
    $product = s76aCreate();

    s76aUpdate($product, ['metaTitle' => str_repeat('a', 160)]);
    s76aCreate(['metaTitle' => str_repeat('a', 160)]);

    foreach ([
        fn () => s76aUpdate($product, ['metaTitle' => str_repeat('a', 161)]),
        fn () => s76aCreate(['metaTitle' => str_repeat('a', 161)]),
    ] as $attempt) {
        $caught = s76aCaught($attempt);

        expect($caught)->toBeInstanceOf(ValidationException::class)
            ->and($caught->errors())->toHaveKey('meta_title');
    }
});

test('meta description is accepted at 500 characters and refused at 501, through each action', function () {
    $product = s76aCreate();

    s76aUpdate($product, ['metaDescription' => str_repeat('a', 500)]);
    s76aCreate(['metaDescription' => str_repeat('a', 500)]);

    foreach ([
        fn () => s76aUpdate($product, ['metaDescription' => str_repeat('a', 501)]),
        fn () => s76aCreate(['metaDescription' => str_repeat('a', 501)]),
    ] as $attempt) {
        $caught = s76aCaught($attempt);

        expect($caught)->toBeInstanceOf(ValidationException::class)
            ->and($caught->errors())->toHaveKey('meta_description');
    }
});

test('blank meta fields sent through each action are stored as null, never an empty string (D-35)', function (string $blank) {
    $created = s76aCreate(['metaTitle' => $blank, 'metaDescription' => $blank]);
    $updated = s76aCreate(['metaTitle' => 'Previo', 'metaDescription' => 'Previo']);
    s76aUpdate($updated, ['metaTitle' => $blank, 'metaDescription' => $blank]);

    foreach ([$created, $updated] as $product) {
        $row = s76aRow($product, s76aDefaultLanguage());

        expect($row->meta_title)->toBeNull()
            ->and($row->meta_description)->toBeNull();
    }
})->with([
    'whitespace only' => ['   '],
]);

test('updating the default language SEO fields leaves another language row byte-identical', function () {
    $product = s76aCreate();
    $french = s76aSecondLanguage();

    app(SetTranslation::class)($product, $french, ['name' => 'Chaussures', 'slug' => 'chaussures', 'meta_title' => 'Chaussures']);

    $before = (array) s76aRow($product, $french);

    s76aUpdate($product, ['slug' => 'zapatillas', 'metaTitle' => 'Zapatillas']);

    expect(s76aRow($product, s76aDefaultLanguage())->meta_title)->toBe('Zapatillas')
        ->and((array) s76aRow($product, $french))->toBe($before);
});

// --- Fallback --------------------------------------------------------------------------------

test('each of name, description, slug and meta title resolves per field: French where present, the default language otherwise', function () {
    $product = s76aCreate([
        'name' => 'Zapatillas',
        'description' => '<p>Descripcion por defecto</p>',
        'slug' => 'zapatillas',
        'metaTitle' => 'Meta por defecto',
    ]);
    $french = s76aSecondLanguage();

    app(SetTranslation::class)($product, $french, ['name' => 'Chaussures', 'slug' => 'chaussures']);

    $product = Product::query()->with('translations')->findOrFail($product->id);

    expect($product->translated('name', $french->id))->toBe('Chaussures')
        ->and($product->translated('slug', $french->id))->toBe('chaussures')
        ->and($product->translated('description', $french->id))->toBe('<p>Descripcion por defecto</p>')
        ->and($product->translated('meta_title', $french->id))->toBe('Meta por defecto')
        ->and($product->translated('meta_description', $french->id))->toBeNull();
});

// --- Signatures (D-18) -----------------------------------------------------------------------

test('CreateProduct takes the three new parameters last and optional, and UpdateProduct takes them last and required', function () {
    $new = ['slug', 'metaTitle', 'metaDescription'];

    $create = (new ReflectionMethod(CreateProduct::class, '__invoke'))->getParameters();
    $update = (new ReflectionMethod(UpdateProduct::class, '__invoke'))->getParameters();

    expect(array_map(fn (ReflectionParameter $p): string => $p->getName(), array_slice($create, -3)))->toBe($new)
        ->and(array_map(fn (ReflectionParameter $p): string => $p->getName(), array_slice($update, -3)))->toBe($new);

    foreach (array_slice($create, -3) as $parameter) {
        expect($parameter->isDefaultValueAvailable())->toBeTrue()
            ->and($parameter->getDefaultValue())->toBeNull();
    }

    foreach (array_slice($update, -3) as $parameter) {
        expect($parameter->isOptional())->toBeFalse();
    }
});

// --- Translations ----------------------------------------------------------------------------

test('both locales define the retry message and the three attribute labels, key-for-key identical', function () {
    $en = require lang_path('en/products.php');
    $es = require lang_path('es/products.php');

    foreach ([$en, $es] as $messages) {
        foreach (['editor.concurrent_save_retry', 'attributes.slug', 'attributes.meta_title', 'attributes.meta_description'] as $key) {
            expect(Arr::get($messages, $key))->toBeString()->not->toBe('');
        }
    }

    expect(array_values(array_diff(array_keys(Arr::dot($en)), array_keys(Arr::dot($es)))))->toBe([])
        ->and(array_values(array_diff(array_keys(Arr::dot($es)), array_keys(Arr::dot($en)))))->toBe([]);
});
