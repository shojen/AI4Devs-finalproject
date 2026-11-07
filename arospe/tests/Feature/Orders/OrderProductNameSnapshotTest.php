<?php

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Story 0076 (D-21): order_items.product_name freezes the STORE DEFAULT language's name through
// Product::snapshotName(), for all three writers (CreateOrder, AddOrderItem, OrderItemFactory).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.create', 'orders.edit']);
    $this->actingAs($actor);
});

/**
 * @param  list<Product>  $products
 */
function p76PlaceOrder(array $products): Order
{
    return app(CreateOrder::class)([
        'customer_id' => Customer::factory()->create()->id,
        'payment_method_id' => PaymentMethod::query()->first()?->id ?? PaymentMethod::factory()->create()->id,
        'items' => array_map(fn (Product $product): array => ['product_id' => $product->id, 'quantity' => 1], $products),
    ]);
}

function p76AddFrenchRow(Product $product, string $name): StoreLanguage
{
    $fr = StoreLanguage::query()->where('code', 'fr')->first() ?? StoreLanguage::factory()->create(['code' => 'fr']);
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $product->id, 'name' => $name]);

    return $fr;
}

test('CreateOrder freezes the default-language name even when the product has a French row', function () {
    $product = Product::factory()->named('Zapatillas Running')->create();
    p76AddFrenchRow($product, 'Chaussures de course');

    $order = p76PlaceOrder([$product]);

    expect($order->items()->sole()->product_name)->toBe('Zapatillas Running');
});

test('AddOrderItem freezes the default-language name even when the product has a French row', function () {
    $product = Product::factory()->named('Zapatillas Running')->create();
    p76AddFrenchRow($product, 'Chaussures de course');
    $order = Order::factory()->create();

    $item = app(AddOrderItem::class)($order, $product->id, null, 1);

    expect($item->product_name)->toBe('Zapatillas Running')
        ->and($item->fresh()->product_name)->toBe('Zapatillas Running');
});

test('OrderItemFactory freezes the default-language name through the same seam', function () {
    $product = Product::factory()->named('Zapatillas Running')->create();
    p76AddFrenchRow($product, 'Chaussures de course');

    $item = OrderItem::factory()->for(Order::factory())->create(['product_id' => $product->id]);

    expect($item->fresh()->product_name)->toBe('Zapatillas Running');
});

test('renaming the product afterwards leaves the frozen order line name unchanged', function () {
    $product = Product::factory()->named('Zapatillas Running')->create();
    $order = p76PlaceOrder([$product]);

    DB::table('product_translations')->where('product_id', $product->id)->update(['name' => 'Zapatillas Trail']);

    expect($order->fresh()->items()->sole()->product_name)->toBe('Zapatillas Running');
});

test('a product with no default-language row freezes its oldest stored translation instead of failing', function () {
    $product = Product::factory()->withoutTranslations()->create();
    $fr = StoreLanguage::factory()->create(['code' => 'fr']);
    $de = StoreLanguage::factory()->create(['code' => 'de']);
    $newer = ProductTranslation::factory()->forLanguage($de)->create(['product_id' => $product->id, 'name' => 'Neuer']);
    $older = ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $product->id, 'name' => 'Ancien']);
    DB::table('product_translations')->where('id', $newer->id)->update(['created_at' => '2026-03-01 00:00:00']);
    DB::table('product_translations')->where('id', $older->id)->update(['created_at' => '2026-02-01 00:00:00']);

    $order = p76PlaceOrder([$product]);

    expect($order->items()->sole()->product_name)->toBe('Ancien');
});

test('a product with no usable name makes CreateOrder throw LogicException and writes no order or line', function () {
    $product = Product::factory()->withoutTranslations()->create();

    expect(fn () => p76PlaceOrder([$product]))->toThrow(LogicException::class);

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0);
});

test('a product with no usable name makes AddOrderItem throw LogicException and writes no line', function () {
    $product = Product::factory()->withoutTranslations()->create();
    $order = Order::factory()->create();
    $before = OrderItem::query()->count();

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 1))->toThrow(LogicException::class);

    expect(OrderItem::query()->count())->toBe($before);
});

test('CreateOrder loads product translations in a bounded number of queries however many lines it carries', function () {
    $measure = function (int $lines): int {
        $products = Product::factory()->count($lines)->create()->all();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_translations')) {
                $queries[] = $query->sql;
            }
        });

        p76PlaceOrder($products);

        return count($queries);
    };

    expect($measure(2))->toBe(1)
        ->and($measure(6))->toBe(1);
});
