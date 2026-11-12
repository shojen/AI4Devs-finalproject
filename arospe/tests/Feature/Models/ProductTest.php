<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

// Story 0024, Phase 3 (TDD "red" step): App\Models\Product, database/factories/ProductFactory.php
// and the products/product_media migrations do not exist yet — every test below is expected to
// fail (class/table not found) until database-expert/backend-expert implement them in the next
// step of the TDD cycle. That is the correct, intended "red" outcome.
//
// Mirrors tests/Feature/Models/UserTest.php's shape for the UUID v7 ordering assertion and
// tests/Feature/Models/ProductCategoryTest.php's shape for a greenfield UUID model with no
// SoftDeletes.

test('two products created in immediate succession sort lexicographically in creation order', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();

    expect(Str::isUuid($first->id, 7))->toBeTrue()
        ->and(Str::isUuid($second->id, 7))->toBeTrue()
        ->and(strcmp((string) $first->id, (string) $second->id))->toBeLessThan(0);
});

// R-4, the likeliest silent bug in this story: `decimal:2` returns a STRING. A test asserting
// only the numeric VALUE passes against either a string or a float cast and lets the drift ship.
test('price, stock, status and type round-trip through their declared casts', function () {
    $product = Product::factory()->create([
        'price' => '19.99',
        'stock' => 5,
        'status' => ProductStatus::Active,
        'type' => ProductType::Physical,
    ]);

    $fresh = $product->fresh();

    expect($fresh->price)->toBeString()
        ->and($fresh->price)->toBe('19.99')
        ->and($fresh->stock)->toBeInt()
        ->and($fresh->status)->toBeInstanceOf(ProductStatus::class)
        ->and($fresh->status)->toBe(ProductStatus::Active)
        ->and($fresh->type)->toBeInstanceOf(ProductType::class)
        ->and($fresh->type)->toBe(ProductType::Physical);
});

// Guards against a future column being added to #[Fillable] by reflex. `featured_media_id` is
// deliberately EXCLUDED: it is written only by App\Actions\Products\SyncProductGallery (D-9's
// single-writer rule), the same shape App\Models\SalesRegion already establishes for
// `is_default`/`is_active`.
test('the fillable set is exactly the columns a plain create/update payload may set directly', function () {
    expect((new Product)->getFillable())->toBe([
        'sku', 'product_category_id', 'type', 'status', 'price', 'stock',
    ]);
});

// D-12 regression guard: adding SoftDeletes later would silently change what Rule::unique() sees
// on `sku` (the story's central uniqueness rule) and 0029's variant cascade semantics.
test('the model does not use SoftDeletes', function () {
    expect(class_uses_recursive(Product::class))
        ->not->toHaveKey(SoftDeletes::class);
});
