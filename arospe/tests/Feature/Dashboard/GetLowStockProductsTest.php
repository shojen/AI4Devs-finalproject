<?php

use App\Actions\Dashboard\GetLowStockProducts;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;
use Tests\Support\Orders\OrdersUi;

// Story 0082 (D-4, D-9), Phase 3 TDD red step: App\Actions\Dashboard\GetLowStockProducts does not
// exist yet. Effective stock = the lowest variant stock when the product has variants (the parent's
// own stock is then ignored), else products.stock; only ACTIVE PHYSICAL parents are eligible.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    test()->actingAs(OrdersUi::actor(['products.view']));
});

/**
 * An active physical product; `$variantStocks` adds one variant per stock value.
 *
 * @param  list<int>  $variantStocks
 * @param  array<string, mixed>  $attributes
 */
function lowStockProduct(int $stock, array $variantStocks = [], array $attributes = []): Product
{
    $product = Product::factory()->active()->physical()->create(array_merge(['stock' => $stock], $attributes));

    foreach ($variantStocks as $variantStock) {
        ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => $variantStock]);
    }

    return $product;
}

/**
 * @return list<string> the listed product ids, in order
 */
function lowStockIds(): array
{
    return array_column(app(GetLowStockProducts::class)(), 'id');
}

it('lists the 3 lowest-stock active physical products in ascending order and skips a virtual one', function () {
    $fifty = lowStockProduct(50);
    $two = lowStockProduct(2);
    $zero = lowStockProduct(0);
    $seven = lowStockProduct(7);
    $virtual = Product::factory()->active()->virtual()->create(['stock' => 0]);

    $ids = lowStockIds();

    expect($ids)->toBe([$zero->id, $two->id, $seven->id])
        ->and($ids)->not->toContain($virtual->id, $fifty->id);
});

it('never lists a product that cannot run out', function (string $kind) {
    $hidden = match ($kind) {
        'draft' => Product::factory()->draft()->physical()->create(['stock' => 0]),
        'virtual' => Product::factory()->active()->virtual()->create(['stock' => 0]),
    };
    $visible = lowStockProduct(9);

    expect(lowStockIds())->toBe([$visible->id])
        ->and(lowStockIds())->not->toContain($hidden->id);
})->with(['still a draft' => ['draft'], 'a virtual product' => ['virtual']]);

it('returns the documented shape per product', function () {
    $product = lowStockProduct(4, [], ['name' => 'Widget', 'sku' => 'WID-1']);

    $rows = app(GetLowStockProducts::class)();

    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]))->toBe(['id', 'name', 'sku', 'effectiveStock', 'isOutOfStock', 'hasVariants', 'lowVariantCount'])
        ->and($rows[0]['id'])->toBe($product->id)
        ->and($rows[0]['name'])->toBe('Widget')
        ->and($rows[0]['sku'])->toBe('WID-1')
        ->and($rows[0]['effectiveStock'])->toBe(4)
        ->and($rows[0]['isOutOfStock'])->toBeFalse()
        ->and($rows[0]['hasVariants'])->toBeFalse()
        ->and($rows[0]['lowVariantCount'])->toBe(0);
});

it('lists a product owed to customers first and flags zero and negative stock as out of stock', function () {
    $three = lowStockProduct(3);
    $negative = lowStockProduct(-2);
    $zero = lowStockProduct(0);

    $rows = app(GetLowStockProducts::class)();

    expect(array_column($rows, 'id'))->toBe([$negative->id, $zero->id, $three->id])
        ->and(array_column($rows, 'effectiveStock'))->toBe([-2, 0, 3])
        ->and(array_column($rows, 'isOutOfStock'))->toBe([true, true, false]);
});

it('breaks equal stock ties by id ascending, never by name, and lists the same products on every call', function () {
    $products = collect(range(1, 4))->map(fn (): Product => lowStockProduct(1))->sortBy('id')->values();

    // Names run opposite to id order, so a name tie-break would pick different products.
    foreach ($products as $index => $product) {
        $product->forceFill(['name' => chr(ord('D') - $index)])->save();
    }

    $expected = $products->take(3)->pluck('id')->all();

    expect(lowStockIds())->toBe($expected)
        ->and(lowStockIds())->toBe($expected);
});

it('keeps a tie that straddles the cut-off stable and by id', function () {
    $low = lowStockProduct(1);
    $ties = collect(range(1, 3))->map(fn (): Product => lowStockProduct(2))->sortBy('id')->values();

    $expected = [$low->id, $ties[0]->id, $ties[1]->id];

    expect(lowStockIds())->toBe($expected)
        ->and(lowStockIds())->toBe($expected);
});

it('lists a variable product once, as the parent, ranked by its lowest variant', function () {
    $variable = lowStockProduct(500, [40, 1, 2]);
    $simple = lowStockProduct(9);

    $rows = app(GetLowStockProducts::class)();
    $variantIds = ProductVariant::query()->pluck('id')->all();

    expect(array_column($rows, 'id'))->toBe([$variable->id, $simple->id])
        ->and($rows[0]['effectiveStock'])->toBe(1)
        ->and($rows[0]['hasVariants'])->toBeTrue()
        ->and($rows[1]['hasVariants'])->toBeFalse()
        ->and(array_intersect(array_column($rows, 'id'), $variantIds))->toBe([]);
});

it('ignores the parent own stock when the product has variants', function (int $parentStock) {
    $variable = lowStockProduct($parentStock, [40, 50]);

    $rows = app(GetLowStockProducts::class)();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($variable->id)
        ->and($rows[0]['effectiveStock'])->toBe(40)
        ->and($rows[0]['isOutOfStock'])->toBeFalse();
})->with(['parent 999' => [999], 'parent 0' => [0]]);

it('ranks a product by a negative variant stock and flags it out of stock', function () {
    $variable = lowStockProduct(100, [-3, 10]);
    lowStockProduct(0);

    $rows = app(GetLowStockProducts::class)();

    expect($rows[0]['id'])->toBe($variable->id)
        ->and($rows[0]['effectiveStock'])->toBe(-3)
        ->and($rows[0]['isOutOfStock'])->toBeTrue();
});

it('never lets a draft or virtual parent surface through its low variant', function (string $kind) {
    $parent = match ($kind) {
        'draft' => Product::factory()->draft()->physical()->create(['stock' => 50]),
        'virtual' => Product::factory()->active()->virtual()->create(['stock' => 50]),
    };
    ProductVariant::factory()->create(['product_id' => $parent->id, 'stock' => 0]);
    $visible = lowStockProduct(30);

    expect(lowStockIds())->toBe([$visible->id]);
})->with(['draft parent' => ['draft'], 'virtual parent' => ['virtual']]);

it('falls back to the parent own stock when all of its variants were deleted', function () {
    $product = lowStockProduct(12, [1, 2]);
    $product->variants()->get()->each->delete();

    $rows = app(GetLowStockProducts::class)();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['effectiveStock'])->toBe(12)
        ->and($rows[0]['hasVariants'])->toBeFalse()
        ->and($rows[0]['lowVariantCount'])->toBe(0);
});

it('returns a single row for a product with 60 variants', function () {
    $product = lowStockProduct(5);
    ProductVariant::factory()->count(60)->create(['product_id' => $product->id, 'stock' => 25]);

    $rows = app(GetLowStockProducts::class)();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($product->id)
        ->and($rows[0]['effectiveStock'])->toBe(25)
        ->and($rows[0]['hasVariants'])->toBeTrue();
});

it('returns fewer than 3 rows when fewer products are eligible and an empty list for an empty catalog', function () {
    expect(app(GetLowStockProducts::class)())->toBe([]);

    lowStockProduct(3);
    lowStockProduct(4);

    expect(app(GetLowStockProducts::class)())->toHaveCount(2);
});

// --- lowVariantCount (D-4): variants with stock <= the effectiveStock of the LAST returned row ---

it('counts the variants at or below the cut-off of the last returned row', function () {
    $variable = lowStockProduct(0, [40, 1, 2]);
    $five = lowStockProduct(5);
    $six = lowStockProduct(6);
    lowStockProduct(50);

    $rows = collect(app(GetLowStockProducts::class)())->keyBy('id');

    expect(array_keys($rows->all()))->toBe([$variable->id, $five->id, $six->id])
        ->and($rows[$variable->id]['effectiveStock'])->toBe(1)
        ->and($rows[$variable->id]['lowVariantCount'])->toBe(2)
        ->and($rows[$five->id]['lowVariantCount'])->toBe(0)
        ->and($rows[$six->id]['lowVariantCount'])->toBe(0);
});

it('reports 2 variants at or below a 6-unit cut-off for variants of 1 and 2 units', function () {
    $variable = lowStockProduct(0, [1, 2]);
    lowStockProduct(5);
    lowStockProduct(6);

    $row = collect(app(GetLowStockProducts::class)())->firstWhere('id', $variable->id);

    expect($row['lowVariantCount'])->toBe(2);
});

it('moves the cut-off with the last returned row so variants above it are not counted', function () {
    $variable = lowStockProduct(0, [1, 3, 5]);
    lowStockProduct(1);
    lowStockProduct(1);

    $row = collect(app(GetLowStockProducts::class)())->firstWhere('id', $variable->id);

    // Three rows all at effective stock 1 -> cut-off 1 -> only the 1-unit variant counts.
    expect($row['effectiveStock'])->toBe(1)
        ->and($row['lowVariantCount'])->toBe(1);
});

// --- D-9 translatable-content seam ---

it('reads the name only through the private resolveName seam method', function () {
    $action = new ReflectionClass(GetLowStockProducts::class);

    expect($action->hasMethod('resolveName'))->toBeTrue()
        ->and($action->getMethod('resolveName')->isPrivate())->toBeTrue();
});

it('never names the name column in its queries', function () {
    lowStockProduct(1, [2]);
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    app(GetLowStockProducts::class)();

    $productQueries = array_values(array_filter($sql, fn (string $statement): bool => str_contains($statement, 'products')));

    expect($productQueries)->not->toBeEmpty();

    foreach ($productQueries as $statement) {
        expect($statement)->not->toMatch('/[`.]name`?\b/i');
    }
});

it('touches only the products and product_variants tables, in two statements', function () {
    lowStockProduct(1, [2]);
    lowStockProduct(3);

    $queries = DomainQueryLog::capture(fn () => app(GetLowStockProducts::class)());

    expect($queries['products'])->toBe(1)
        ->and($queries['product_variants'])->toBe(2)
        ->and($queries['orders'] + $queries['blog_posts'] + $queries['media'] + $queries['users'] + $queries['customers'])->toBe(0);
});

it('resolves the name from the viewer locale translation')->todo();
it('falls back to the default-language name when the viewer locale has no translation')->todo();
