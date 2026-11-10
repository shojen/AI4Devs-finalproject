<?php

use App\Actions\Orders\AddOrderItem;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0088a: AddOrderItem deducts the added line's units in its own transaction (lock order,
// counters, and refusals that must move no stock).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingStockOrderEditor(string $permission = 'orders.edit'): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permission);
    test()->actingAs($actor);

    return $actor;
}

if (! function_exists('orderStockFlowTags')) {
    /**
     * Reduces a query log to the ordered tags that matter for the lock-order convention (FD-7).
     *
     * @param  array<int, array{query: string}>  $queryLog
     * @return list<string>
     */
    function orderStockFlowTags(array $queryLog): array
    {
        $tags = [];

        foreach (collect($queryLog)->pluck('query') as $sql) {
            if (preg_match('/^\s*insert into `(orders|order_items)`/i', $sql, $match) === 1) {
                $tags[] = 'insert:'.$match[1];
            } elseif (preg_match('/^\s*select .* from `(orders|products|product_variants)` where .*for update/is', $sql, $match) === 1) {
                $tags[] = 'lock:'.$match[1];
            } elseif (preg_match('/^\s*update `(products|product_variants)` set `stock`/i', $sql, $match) === 1) {
                $tags[] = 'update:'.$match[1];
            }
        }

        return $tags;
    }
}

// --- Deduction ---

test('adding a plain-product line takes its units out of the product stock and writes U 0, K 0', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);

    $item = app(AddOrderItem::class)($order, $product->id, null, 2);

    $fresh = $item->fresh();

    expect($product->fresh()->stock)->toBe(8)
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(0)
        ->and($fresh->ordered_as_variant)->toBeFalse()
        ->and($fresh->returnableStockUnits())->toBe(2);
});

test('adding a variant line takes the units out of the variant only and flags the line as ordered as a variant', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Processing]);
    $product = Product::factory()->create(['stock' => 9]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

    $item = app(AddOrderItem::class)($order, $product->id, $variant->id, 3);

    $fresh = $item->fresh();

    expect($variant->fresh()->stock)->toBe(2)
        ->and($product->fresh()->stock)->toBe(9)
        ->and($fresh->ordered_as_variant)->toBeTrue()
        ->and($fresh->stock_undeducted_quantity)->toBe(0)
        ->and($fresh->stock_refunded_not_out_quantity)->toBe(0);
});

test('adding more units than in stock is allowed and stock goes negative', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 1]);

    app(AddOrderItem::class)($order, $product->id, null, 4);

    expect($product->fresh()->stock)->toBe(-3);
});

test('adding a second line of the same product deducts only the added units', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);

    app(AddOrderItem::class)($order, $product->id, null, 2);
    app(AddOrderItem::class)($order, $product->id, null, 3);

    expect($product->fresh()->stock)->toBe(5)
        ->and($order->items()->count())->toBe(2);
});

// --- Lock order (FD-7, D2) ---

test('the order is locked, then product and variant rows locked, then stock written, and only then the line inserted', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);
    $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(AddOrderItem::class)($order, $product->id, $variant->id, 1);

    $tags = orderStockFlowTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_search('lock:orders', $tags, true))->not->toBeFalse()
        ->and(array_search('lock:orders', $tags, true))->toBeLessThan(array_search('lock:products', $tags, true))
        ->and(array_search('lock:products', $tags, true))->toBeLessThan(array_search('lock:product_variants', $tags, true))
        ->and(array_search('lock:product_variants', $tags, true))->toBeLessThan(array_search('update:product_variants', $tags, true))
        ->and(array_search('update:product_variants', $tags, true))->toBeLessThan(array_search('insert:order_items', $tags, true));
});

test('with no variant no order_items insert precedes the product lock, and the stock write precedes the insert', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(AddOrderItem::class)($order, $product->id, null, 1);

    $tags = orderStockFlowTags(DB::getQueryLog());
    DB::disableQueryLog();

    expect(array_search('lock:products', $tags, true))->not->toBeFalse()
        ->and(array_search('lock:products', $tags, true))->toBeLessThan(array_search('insert:order_items', $tags, true))
        ->and(array_search('lock:products', $tags, true))->toBeLessThan(array_search('update:products', $tags, true))
        ->and(array_search('update:products', $tags, true))->toBeLessThan(array_search('insert:order_items', $tags, true));
});

// --- Refusals move no stock ---

test('a refused add on a shipped order takes nothing out of stock', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 2))
        ->toThrow(OrderNotEditableException::class);

    expect($product->fresh()->stock)->toBe(10);
});

test('a refused add on an order at the line-item ceiling takes nothing out of stock', function () {
    actingStockOrderEditor();
    $order = Order::factory()->withItems(AddOrderItem::MAX_ITEMS)->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 2))
        ->toThrow(ValidationException::class);

    expect($product->fresh()->stock)->toBe(10);
});

test('a refused add over the decimal column ceiling takes nothing out of stock', function () {
    actingStockOrderEditor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['price' => '99999999.99', 'stock' => 10]);

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 2))
        ->toThrow(ValidationException::class);

    expect($product->fresh()->stock)->toBe(10)
        ->and($order->items()->count())->toBe(0);
});

test('an administrator who may not edit orders is refused and no stock moves', function () {
    actingStockOrderEditor('orders.view');
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 2))
        ->toThrow(AuthorizationException::class);

    expect($product->fresh()->stock)->toBe(10);
});

// --- Legacy lines ---

test('a line written stock-neutral before automatic deduction still returns nothing', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $legacy = OrderItem::factory()->for($order)->legacyStockUntracked()->create(['product_id' => $product->id, 'quantity' => 3]);

    expect($legacy->fresh()->returnableStockUnits())->toBe(0)
        ->and($product->fresh()->stock)->toBe(10);
});
