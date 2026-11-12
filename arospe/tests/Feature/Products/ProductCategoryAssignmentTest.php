<?php

use App\Actions\Products\CreateProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0024, Phase 3 (TDD "red" step) — see CreateProductTest.php's file banner for the assumed
// CreateProduct::__invoke() signature; unchanged here.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function categoryAssignmentPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Runner Pro',
        'sku' => 'RNR-'.Str::random(10),
        'productCategoryId' => ProductCategory::factory()->create()->id,
        'type' => 'physical',
        'status' => 'active',
        'price' => '19.99',
        'stock' => 5,
        'description' => null,
        'featuredMediaId' => null,
        'orderedGalleryMediaIds' => [],
    ], $overrides);
}

test('a nonexistent but well-formed category id is refused by ValidationException on product_category_id, with zero rows', function () {
    $caught = null;

    try {
        app(CreateProduct::class)(...categoryAssignmentPayload(['productCategoryId' => (string) Str::uuid7()]));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('product_category_id');

    expect(Product::count())->toBe(0);
});

test('a malformed category id is refused by ValidationException, never a QueryException or a 500', function (mixed $malformed) {
    $caught = null;

    try {
        app(CreateProduct::class)(...categoryAssignmentPayload(['productCategoryId' => $malformed]));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class);
    expect(Product::count())->toBe(0);
})->with([
    'not a uuid at all' => ['not-a-uuid'],
    'a blank string' => [''],
    'an integer' => [123],
]);
