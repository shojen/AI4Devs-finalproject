<?php

use App\Actions\Products\CreateProduct;
use App\Actions\Products\CreateProductVariant;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\UpdateProduct;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductAttributeType;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0076 (D-4, D-8, D-15, D-16, D-18, N-2): CreateProduct / UpdateProduct keep their signatures
// and now write the DEFAULT store language's name and description into product_translations,
// inside their existing transactions and below their self-authorization.

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
function p76Payload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Zapatillas Running',
        'sku' => 'P76-'.Str::upper(Str::random(8)),
        'productCategoryId' => ProductCategory::factory()->create()->id,
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
 * @param  array<string, mixed>  $overrides
 */
function p76CreateProduct(array $overrides = []): Product
{
    return app(CreateProduct::class)(...p76Payload($overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function p76UpdateProduct(Product $product, array $overrides = []): Product
{
    return app(UpdateProduct::class)(...array_merge(
        ['product' => $product],
        p76Payload(array_merge([
            'sku' => $product->sku,
            'productCategoryId' => $product->product_category_id,
            'price' => (string) $product->price,
            'stock' => $product->stock,
        ], $overrides)),
    ));
}

function p76DefaultLanguage(): StoreLanguage
{
    return StoreLanguage::query()->where('is_default', true)->sole();
}

function p76SnapshotTables(): array
{
    return [
        'products' => DB::table('products')->count(),
        'product_translations' => DB::table('product_translations')->count(),
        'product_media' => DB::table('product_media')->count(),
    ];
}

// --- CreateProduct -------------------------------------------------------------------------

test('creating a product leaves one products row and one default-language translation with the trimmed name and sanitized description', function () {
    $product = p76CreateProduct([
        'name' => '  Zapatillas Running  ',
        'description' => '<p>Ligeras</p><script>alert(1)</script>',
    ]);

    $translations = DB::table('product_translations')->where('product_id', $product->id)->get();

    expect(DB::table('products')->count())->toBe(1)
        ->and(DB::table('product_translations')->count())->toBe(1)
        ->and($translations)->toHaveCount(1)
        ->and($translations[0]->store_language_id)->toBe(p76DefaultLanguage()->id)
        ->and($translations[0]->name)->toBe('Zapatillas Running')
        ->and($translations[0]->description)->toBe('<p>Ligeras</p>');
});

test('creating a product without a description stores a null description', function () {
    $product = p76CreateProduct(['description' => null]);

    expect(DB::table('product_translations')->where('product_id', $product->id)->value('description'))->toBeNull();
});

test('two products with byte-identical names both save, because a product title carries no uniqueness rule', function () {
    $first = p76CreateProduct(['name' => 'Camiseta basica']);
    $second = p76CreateProduct(['name' => 'Camiseta basica']);

    expect($first->id)->not->toBe($second->id)
        ->and(DB::table('product_translations')->where('name', 'Camiseta basica')->count())->toBe(2);
});

test('a whitespace-only name is refused before any write', function () {
    $payload = p76Payload(['name' => "   \t "]);
    $before = p76SnapshotTables();

    expect(fn () => app(CreateProduct::class)(...$payload))->toThrow(ValidationException::class);

    expect(p76SnapshotTables())->toBe($before);
});

test('with no default store language, CreateProduct throws RuntimeException after authorization and writes nothing', function () {
    $categoryId = ProductCategory::factory()->withoutTranslations()->create()->id;

    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => p76CreateProduct(['productCategoryId' => $categoryId]))->toThrow(RuntimeException::class);

    expect(DB::table('products')->count())->toBe(0)
        ->and(DB::table('product_translations')->count())->toBe(0);
});

test('with no default store language, an unauthorized actor is refused on authorization first, not on the missing language', function () {
    $categoryId = ProductCategory::factory()->withoutTranslations()->create()->id;
    $this->actingAs(User::factory()->create());

    expect(fn () => p76CreateProduct(['productCategoryId' => $categoryId]))->toThrow(AuthorizationException::class);
});

test('a translation write that fails rolls back the product, the translation and the gallery pivot rows together', function () {
    $mediaIds = Media::factory()->count(2)->create()->pluck('id')->all();
    $payload = p76Payload(['featuredMediaId' => $mediaIds[0], 'orderedGalleryMediaIds' => $mediaIds]);

    ProductTranslation::saving(function (): void {
        throw new RuntimeException('p76 injected translation failure');
    });

    expect(fn () => app(CreateProduct::class)(...$payload))->toThrow(RuntimeException::class, 'p76 injected translation failure');

    expect(DB::table('products')->count())->toBe(0)
        ->and(DB::table('product_translations')->count())->toBe(0)
        ->and(DB::table('product_media')->count())->toBe(0);
});

test('a gallery failure after the translation write leaves zero product_translations rows as well as zero products', function () {
    $media = Media::factory()->count(2)->create();
    $mediaIds = $media->pluck('id')->all();
    $payload = p76Payload(['featuredMediaId' => $mediaIds[0], 'orderedGalleryMediaIds' => $mediaIds]);

    // The media rows vanish between validation and the pivot insert: right after the translation
    // insert has run, so the later SyncProductGallery write violates its foreign key.
    DB::listen(function ($query) use ($mediaIds): void {
        if (str_contains($query->sql, 'insert into `product_translations`')) {
            DB::table('media')->whereIn('id', $mediaIds)->delete();
        }
    });

    $caught = null;

    try {
        app(CreateProduct::class)(...$payload);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect(DB::table('products')->count())->toBe(0)
        ->and(DB::table('product_translations')->count())->toBe(0)
        ->and(DB::table('product_media')->count())->toBe(0);
});

test('a validation failure is raised before the transaction opens: no savepoint and no row', function () {
    $payload = p76Payload(['price' => 'not-a-number']);
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    expect(fn () => app(CreateProduct::class)(...$payload))->toThrow(ValidationException::class);

    expect(collect($statements)->filter(fn (string $sql) => str_starts_with($sql, 'savepoint')))->toBeEmpty()
        ->and(DB::table('product_translations')->count())->toBe(0);
});

// --- UpdateProduct -------------------------------------------------------------------------

test('updating rewrites the default-language row in place and leaves a French row byte-identical', function () {
    $product = p76CreateProduct(['name' => 'Zapatillas Running', 'description' => '<p>Ligeras</p>']);
    $fr = StoreLanguage::factory()->create(['code' => 'fr']);
    ProductTranslation::factory()->forLanguage($fr)->create([
        'product_id' => $product->id,
        'name' => 'Chaussures de course',
        'description' => '<p>Legeres</p>',
    ]);

    $defaultRowId = DB::table('product_translations')
        ->where('product_id', $product->id)->where('store_language_id', p76DefaultLanguage()->id)->value('id');
    $frenchBefore = (array) DB::table('product_translations')
        ->where('product_id', $product->id)->where('store_language_id', $fr->id)->first();

    p76UpdateProduct($product, ['name' => 'Zapatillas Trail', 'description' => '<p>Robustas</p>']);

    $defaultRow = DB::table('product_translations')->where('id', $defaultRowId)->first();
    $frenchAfter = (array) DB::table('product_translations')
        ->where('product_id', $product->id)->where('store_language_id', $fr->id)->first();

    expect(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(2)
        ->and($defaultRow->name)->toBe('Zapatillas Trail')
        ->and($defaultRow->description)->toBe('<p>Robustas</p>')
        ->and($frenchAfter)->toBe($frenchBefore);
});

test('updating a product that has no default-language row inserts one and keeps the other languages', function () {
    $oldDefault = StoreLanguage::factory()->default()->create(['code' => 'es']);
    $product = Product::factory()->withoutTranslations()->create();
    ProductTranslation::factory()->forLanguage($oldDefault)->create(['product_id' => $product->id, 'name' => 'Solo antiguo']);

    $newDefault = StoreLanguage::factory()->create(['code' => 'fr']);
    $oldDefault->update(['is_default' => false]);
    $newDefault->update(['is_default' => true]);
    StoreLanguage::flushDefaultStoreLanguage();

    p76UpdateProduct($product, ['name' => 'Nouveau nom']);

    $rows = DB::table('product_translations')->where('product_id', $product->id)->get()->keyBy('store_language_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$newDefault->id]->name)->toBe('Nouveau nom')
        ->and($rows[$oldDefault->id]->name)->toBe('Solo antiguo');
});

test('a blank title on update is refused and no translation row is written or changed', function () {
    $product = p76CreateProduct(['name' => 'Zapatillas Running']);
    $before = (array) DB::table('product_translations')->where('product_id', $product->id)->first();

    expect(fn () => p76UpdateProduct($product, ['name' => '   ']))->toThrow(ValidationException::class);

    expect((array) DB::table('product_translations')->where('product_id', $product->id)->first())->toBe($before)
        ->and(DB::table('product_translations')->count())->toBe(1);
});

test('with no default store language, UpdateProduct throws RuntimeException after authorization and writes nothing', function () {
    $product = Product::factory()->withoutTranslations()->create([
        'product_category_id' => ProductCategory::factory()->withoutTranslations()->create()->id,
    ]);

    expect(StoreLanguage::query()->count())->toBe(0);

    expect(fn () => p76UpdateProduct($product, ['name' => 'Nuevo']))->toThrow(RuntimeException::class);

    expect(DB::table('product_translations')->count())->toBe(0);
});

test('a failing translation write rolls back the parent update', function () {
    $product = p76CreateProduct(['price' => '10.00']);
    $original = $product->fresh();

    ProductTranslation::saving(function (): void {
        throw new RuntimeException('p76 injected translation failure');
    });

    expect(fn () => p76UpdateProduct($product, ['sku' => 'P76-CHANGED', 'price' => '99.00', 'name' => 'Cambiado']))
        ->toThrow(RuntimeException::class, 'p76 injected translation failure');

    $fresh = $product->fresh();

    expect($fresh->sku)->toBe($original->sku)
        ->and((string) $fresh->price)->toBe('10.00')
        ->and(DB::table('product_translations')->where('product_id', $product->id)->value('name'))->toBe('Zapatillas Running');
});

// N-2: the FIXED collision, not the F-6 race. SetTranslation has already run when the in-transaction
// pre-check refuses the re-derived variant SKU, so only a rollback keeps the old translation.
test('a variant-cascade collision after the translation write rolls the translation, the sku and the price back', function () {
    $product = p76CreateProduct(['sku' => '0015', 'name' => 'Original', 'description' => '<p>Original desc</p>', 'price' => '19.99']);
    $type = ProductAttributeType::factory()->create(['name' => 'Talla', 'position' => 0]);
    $value = ProductAttributeValue::factory()->create(['product_attribute_type_id' => $type->id, 'value' => 'M', 'position' => 0]);
    $variant = app(CreateProductVariant::class)($product, [$value->id], '19.99', 5);

    // An existing product whose sku equals the variant sku the new parent sku '0009' would derive.
    p76CreateProduct(['sku' => '0009-M']);

    $caught = null;

    try {
        p76UpdateProduct($product, ['sku' => '0009', 'name' => 'Renombrado', 'description' => '<p>Nueva</p>', 'price' => '77.00']);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors()['sku'][0])->toBe(__('products.variants.parent_sku_change_collides'));

    $row = DB::table('product_translations')->where('product_id', $product->id)->first();

    expect($row->name)->toBe('Original')
        ->and($row->description)->toBe('<p>Original desc</p>')
        ->and($product->fresh()->sku)->toBe('0015')
        ->and((string) $product->fresh()->price)->toBe('19.99')
        ->and($variant->fresh()->sku)->toBe('0015-M');
});

// --- Sanitizer ordering (D-8 layer 1) ------------------------------------------------------

test('a description over 65535 characters before sanitizing but within it after is accepted on create and on update', function () {
    $oversized = str_repeat('<script>x</script>', 5000).'<p>ok</p>';

    expect(mb_strlen($oversized))->toBeGreaterThan(65535);

    $product = p76CreateProduct(['description' => $oversized]);

    expect(DB::table('product_translations')->where('product_id', $product->id)->value('description'))->toBe('<p>ok</p>');

    p76UpdateProduct($product, ['description' => $oversized]);

    expect(DB::table('product_translations')->where('product_id', $product->id)->value('description'))->toBe('<p>ok</p>');
});

test('a script tag in a description saved through the update action is stored sanitized', function () {
    $product = p76CreateProduct();

    p76UpdateProduct($product, ['description' => '<p>Hola</p><script>alert(1)</script>']);

    $stored = DB::table('product_translations')->where('product_id', $product->id)->value('description');

    expect($stored)->not->toContain('script')->and($stored)->toContain('<p>Hola</p>');
});

// --- Authorization (D-16) ------------------------------------------------------------------

test('UpdateProduct by an actor without products.edit is refused and logged and the translation is unchanged', function () {
    $product = p76CreateProduct(['name' => 'Original']);
    $actor = User::factory()->create();
    $this->actingAs($actor);

    Log::spy();

    expect(fn () => p76UpdateProduct($product, ['name' => 'Should Not Persist']))->toThrow(AuthorizationException::class);

    expect(DB::table('product_translations')->where('product_id', $product->id)->value('name'))->toBe('Original');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_id'] ?? null) === $product->id)
        ->once();
});

test('a catalog administrator holding products.edit and no store-languages permission updates a product title', function () {
    $product = p76CreateProduct(['name' => 'Original']);
    $editor = User::factory()->create();
    $editor->givePermissionTo(['products.view', 'products.edit']);
    $this->actingAs($editor);

    expect($editor->getAllPermissions()->pluck('name')->filter(fn (string $name) => str_starts_with($name, 'store-languages')))->toBeEmpty();

    p76UpdateProduct($product, ['name' => 'Retitulado']);

    expect(DB::table('product_translations')
        ->where('product_id', $product->id)->where('store_language_id', p76DefaultLanguage()->id)->value('name'))
        ->toBe('Retitulado');
});

// --- Deletion ------------------------------------------------------------------------------

test('deleting a product through the action removes all three of its translations', function () {
    $product = p76CreateProduct();
    $fr = StoreLanguage::factory()->create();
    $de = StoreLanguage::factory()->create();
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $product->id]);
    ProductTranslation::factory()->forLanguage($de)->create(['product_id' => $product->id]);

    expect(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(3);

    app(DeleteProduct::class)($product);

    expect(DB::table('product_translations')->where('product_id', $product->id)->count())->toBe(0);
});
