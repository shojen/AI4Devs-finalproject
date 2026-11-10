<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0088 (Phase 3, red step): the add_stock_tracking_columns_to_order_items_table migration does
// not exist yet. The backfill (D6, D9) only runs on rows that exist BEFORE the migration, so each
// test rolls the migration back (down() drops the three columns), inserts "legacy" rows with raw
// DB::table() inserts (the model/factory already know the new columns and would write them), and
// re-applies the migration so the backfill executes against them -- the precedent is
// tests/Feature/Database/DropRedundantUsersUuidIndexTest.php, pinned by --path rather than --step.
//
// DDL implicitly commits in MySQL, so RefreshDatabase's per-test transaction cannot undo what these
// tests write after the rollback: the finally block therefore re-applies the migration if an
// assertion threw, and empties every table except `migrations` so no later test sees leftover rows.

/**
 * The migration's path relative to the project root, resolved by name because its timestamp is not
 * known to this test.
 */
function stockTrackingMigrationPath(): string
{
    $matches = glob(database_path('migrations/*_add_stock_tracking_columns_to_order_items_table.php')) ?: [];

    expect($matches)->toHaveCount(1);

    return 'database/migrations/'.basename($matches[0]);
}

function emptyEveryTableExceptMigrations(): void
{
    Schema::disableForeignKeyConstraints();

    foreach (Schema::getTables() as $table) {
        if ($table['name'] !== 'migrations') {
            DB::table($table['name'])->delete();
        }
    }

    Schema::enableForeignKeyConstraints();
}

/**
 * Rolls the migration back, lets $arrange insert the legacy rows, re-applies the migration, then
 * hands $arrange's return value to $assert.
 *
 * @param  Closure(): array<string, string>|Closure(): array<int, string>  $arrange
 * @param  Closure(array<array-key, string>): void  $assert
 */
function withStockMigrationReapplied(Closure $arrange, Closure $assert): void
{
    $path = stockTrackingMigrationPath();

    try {
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

        $arranged = $arrange();

        Artisan::call('migrate', ['--path' => $path, '--force' => true]);

        $assert($arranged);
    } finally {
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        emptyEveryTableExceptMigrations();
    }
}

/**
 * Inserts a pre-migration order line with a raw query, so no new column is written.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertLegacyOrderItem(string $orderId, ?string $productId, ?string $variantId, string $sku, int $quantity, int $refunded, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('order_items')->insert([
        'id' => $id,
        'order_id' => $orderId,
        'product_id' => $productId,
        'product_variant_id' => $variantId,
        'product_name' => 'Legacy product',
        'product_sku' => $sku,
        'quantity' => $quantity,
        'unit_price' => '10.00',
        'line_total' => number_format(10 * $quantity, 2, '.', ''),
        'refunded_quantity' => $refunded,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

test('the backfill sets stock_undeducted_quantity to quantity minus refunded and stock_refunded_not_out_quantity to refunded, for rows with and without refunds', function () {
    withStockMigrationReapplied(
        function (): array {
            $order = Order::factory()->create();
            $product = Product::factory()->create();

            return [
                'plain' => insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 3, 0),
                'refunded' => insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 5, 2),
                'fullyRefunded' => insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 4, 4),
            ];
        },
        function (array $ids): void {
            $plain = DB::table('order_items')->find($ids['plain']);
            $refunded = DB::table('order_items')->find($ids['refunded']);
            $fullyRefunded = DB::table('order_items')->find($ids['fullyRefunded']);

            expect([$plain->stock_undeducted_quantity, $plain->stock_refunded_not_out_quantity])->toBe([3, 0])
                ->and([$refunded->stock_undeducted_quantity, $refunded->stock_refunded_not_out_quantity])->toBe([3, 2])
                ->and([$fullyRefunded->stock_undeducted_quantity, $fullyRefunded->stock_refunded_not_out_quantity])->toBe([0, 4]);
        },
    );
});

test('after the backfill every legacy row has no returnable units and no refunded units out of stock', function () {
    withStockMigrationReapplied(
        function (): array {
            $order = Order::factory()->create();
            $product = Product::factory()->create();

            return [
                insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 3, 0),
                insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 5, 2),
                insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 4, 4),
            ];
        },
        function (array $ids): void {
            foreach ($ids as $id) {
                $item = OrderItem::query()->findOrFail($id);

                expect($item->returnableStockUnits())->toBe(0)
                    ->and($item->refundedUnitsOutOfStock())->toBe(0);
            }
        },
    );
});

test('a row inserted after the migration defaults both counters to 0 and ordered_as_variant to false', function () {
    $order = Order::factory()->create();
    $product = Product::factory()->create();

    $id = insertLegacyOrderItem($order->id, $product->id, null, $product->sku, 3, 0);
    $row = DB::table('order_items')->find($id);

    expect($row->stock_undeducted_quantity)->toBe(0)
        ->and($row->stock_refunded_not_out_quantity)->toBe(0)
        ->and((bool) $row->ordered_as_variant)->toBeFalse();
});

test('the two counter columns are unsigned and NOT NULL, and ordered_as_variant is a NOT NULL boolean defaulting to false', function () {
    $columns = collect(Schema::getColumns('order_items'))->keyBy('name');

    foreach (['stock_undeducted_quantity', 'stock_refunded_not_out_quantity'] as $name) {
        expect($columns->has($name))->toBeTrue()
            ->and($columns[$name]['nullable'])->toBeFalse()
            ->and($columns[$name]['type'])->toContain('unsigned')
            ->and((string) $columns[$name]['default'])->toBe('0');
    }

    expect($columns->has('ordered_as_variant'))->toBeTrue()
        ->and($columns['ordered_as_variant']['nullable'])->toBeFalse()
        ->and($columns['ordered_as_variant']['type'])->toBe('tinyint(1)')
        ->and((string) $columns['ordered_as_variant']['default'])->toBe('0');
});

test('the D9 backfill flags ordered_as_variant for variant rows, SKU-mismatched rows and the first accepted blind spot, and leaves matching plain rows, deleted-product rows and the second blind spot false', function () {
    withStockMigrationReapplied(
        function (): array {
            $order = Order::factory()->create();

            $variantProduct = Product::factory()->create();
            $variant = ProductVariant::factory()->for($variantProduct)->create();
            $variantRow = insertLegacyOrderItem($order->id, $variantProduct->id, $variant->id, $variant->sku, 1, 0);

            $mismatchProduct = Product::factory()->create();
            $skuMismatchRow = insertLegacyOrderItem($order->id, $mismatchProduct->id, null, 'SNAPSHOT-OF-A-DELETED-VARIANT', 1, 0);

            $plainProduct = Product::factory()->create();
            $plainMatchingRow = insertLegacyOrderItem($order->id, $plainProduct->id, null, $plainProduct->sku, 1, 0);

            $deletedProduct = Product::factory()->create();
            $deletedProductRow = insertLegacyOrderItem($order->id, $deletedProduct->id, null, 'SKU-OF-A-DELETED-PRODUCT', 1, 0);
            $deletedProduct->delete();

            // First accepted blind spot: a plain-product line whose product SKU was edited after the order.
            $editedProduct = Product::factory()->create();
            $editedSkuRow = insertLegacyOrderItem($order->id, $editedProduct->id, null, $editedProduct->sku, 1, 0);
            $editedProduct->forceFill(['sku' => 'EDITED-AFTER-THE-ORDER'])->save();

            // Second accepted blind spot: the variant was deleted before the upgrade and its SKU equalled the product's.
            $sameSkuProduct = Product::factory()->create();
            $deletedSameSkuVariantRow = insertLegacyOrderItem($order->id, $sameSkuProduct->id, null, $sameSkuProduct->sku, 1, 0);

            return compact(
                'variantRow',
                'skuMismatchRow',
                'plainMatchingRow',
                'deletedProductRow',
                'editedSkuRow',
                'deletedSameSkuVariantRow',
            );
        },
        function (array $ids): void {
            $flag = fn (string $key): bool => (bool) DB::table('order_items')->find($ids[$key])->ordered_as_variant;

            expect($flag('variantRow'))->toBeTrue()
                ->and($flag('skuMismatchRow'))->toBeTrue()
                ->and($flag('plainMatchingRow'))->toBeFalse()
                ->and($flag('deletedProductRow'))->toBeFalse()
                ->and($flag('editedSkuRow'))->toBeTrue()
                ->and($flag('deletedSameSkuVariantRow'))->toBeFalse();
        },
    );
});

test('down() drops exactly the three stock-tracking columns and re-applying restores them', function () {
    $path = stockTrackingMigrationPath();

    try {
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

        expect(Schema::hasColumn('order_items', 'ordered_as_variant'))->toBeFalse()
            ->and(Schema::hasColumn('order_items', 'stock_undeducted_quantity'))->toBeFalse()
            ->and(Schema::hasColumn('order_items', 'stock_refunded_not_out_quantity'))->toBeFalse()
            ->and(Schema::hasColumn('order_items', 'refunded_quantity'))->toBeTrue();
    } finally {
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
    }

    expect(Schema::hasColumns('order_items', ['ordered_as_variant', 'stock_undeducted_quantity', 'stock_refunded_not_out_quantity']))->toBeTrue();
});
