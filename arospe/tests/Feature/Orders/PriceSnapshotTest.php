<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet -- every
// test below is expected to fail with "Target class [App\Actions\Orders\CreateOrder] does not
// exist" until backend-expert implements it.
//
// This file is THE highest-risk case in the story (backend-qa's own words, R-2/R-7): a live join
// instead of a snapshot is invisible until a price changes, and by then the historical data is
// already wrong. Every test here is a DEDICATED mutate-then-re-fetch regression, never folded into
// a happy-path creation test, and every fixture deliberately gives the product and its variant
// DIFFERENT prices/names/SKUs -- equal ones make a wrong implementation pass too (D-15).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingSnapshotCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

test('the line item stores the price at the time of order', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['price' => '10.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    expect((string) $order->items()->sole()->unit_price)->toBe('10.00');
});

// R-2, dedicated regression: mutate the product's price AFTER the order exists, then re-fetch the
// ORDER from the database (never the in-memory instance) and assert the snapshot survived.
test('a later product price change does not move an existing order line item unit_price', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['price' => '10.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $product->price = '25.00';
    $product->save();

    $fresh = $order->fresh();

    expect((string) $fresh->items()->sole()->unit_price)->toBe('10.00');
});

test('a line item for a variant stores the variant own price, not the product price', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['price' => '10.00']);
    $variant = ProductVariant::factory()->for($product)->create(['price' => '15.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1],
        ],
    ]);

    expect((string) $order->items()->sole()->unit_price)->toBe('15.00');
});

// D-15's own dedicated regression: mutate the VARIANT's price (not the parent product's), because
// an implementation that snapshots from products.price would pass a test that only mutates the
// parent -- this is R-7's exact failure shape and needs its own test.
test('a later VARIANT price change does not move an existing order line item unit_price', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['price' => '10.00']);
    $variant = ProductVariant::factory()->for($product)->create(['price' => '15.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1],
        ],
    ]);

    $variant->price = '40.00';
    $variant->save();

    $fresh = $order->fresh();

    expect((string) $fresh->items()->sole()->unit_price)->toBe('15.00');
});

test('a line item for a variant stores the variant own SKU, while product_name comes from the parent product', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['name' => 'Camiseta', 'sku' => 'CAM-BASE', 'price' => '10.00']);
    // ProductVariantFactory::configure() ALWAYS overwrites `sku` with a real
    // DeriveVariantSku() derivation, regardless of a `create(['sku' => ...])`
    // override (see the factory's own file banner) -- so the expected value
    // below is read from the variant AFTER creation, never hardcoded, or this
    // test would assert against a string the row can never actually hold.
    $variant = ProductVariant::factory()->for($product)->create(['price' => '15.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1],
        ],
    ]);

    $item = $order->items()->sole();

    expect($item->product_sku)->toBe($variant->sku)
        ->and($item->product_sku)->not->toBe($product->sku)
        ->and($item->product_name)->toBe('Camiseta');
});

test('the line item stores the product name and code at the time of order', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['name' => 'Silla de oficina', 'sku' => 'SIL-001', 'price' => '10.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $item = $order->items()->sole();

    expect($item->product_name)->toBe('Silla de oficina')
        ->and($item->product_sku)->toBe('SIL-001');
});

// The same regression as unit_price, for name/sku -- rename/re-code the product after the order
// exists, and assert the order's own line item is unaffected.
test('renaming or re-coding a product after the order exists does not move the existing line item snapshot', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['name' => 'Original Name', 'sku' => 'ORIG-SKU', 'price' => '10.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $product->name = 'Renamed Name';
    $product->sku = 'NEW-SKU';
    $product->save();

    $fresh = $order->fresh();
    $item = $fresh->items()->sole();

    expect($item->product_name)->toBe('Original Name')
        ->and($item->product_sku)->toBe('ORIG-SKU');
});

test('line_total equals unit_price times quantity for quantity 1 and quantity 3, compared as decimal strings', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $productA = Product::factory()->create(['price' => '10.00']);
    $productB = Product::factory()->create(['price' => '7.50']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $productA->id, 'quantity' => 1],
            ['product_id' => $productB->id, 'quantity' => 3],
        ],
    ]);

    $items = $order->items()->orderBy('id')->get()->keyBy(fn ($item): string => $item->product_id);

    expect((string) $items[$productA->id]->line_total)->toBe('10.00')
        ->and((string) $items[$productB->id]->line_total)->toBe('22.50');
});

// D-2's nullOnDelete() behaviour, asserted rather than assumed: deleting the catalog product nulls
// order_items.product_id while the snapshot columns survive intact.
test('deleting the catalog product nulls order_items.product_id while the snapshot columns survive', function () {
    actingSnapshotCreator();

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create(['name' => 'Doomed Product', 'sku' => 'DOOM-1', 'price' => '10.00']);

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $itemId = $order->items()->sole()->id;

    $product->delete();

    $item = OrderItem::query()->find($itemId);

    expect($item->product_id)->toBeNull()
        ->and($item->product_name)->toBe('Doomed Product')
        ->and($item->product_sku)->toBe('DOOM-1')
        ->and((string) $item->unit_price)->toBe('10.00');
});
