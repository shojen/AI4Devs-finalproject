<?php

// Story 0096: the refund modal's "Return to stock" choice. SELECTOR STRATEGY: `@hook` selectors. The
// per-line inputs only appear in "Some units" (a client-side toggle over deferred bindings); every
// journey ends with a SERVER-SIDE check of the stock, not just what the DOM says.

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Pest\Browser\Execution;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.refund']);
    $this->actingAs($actor);
});

/**
 * Waits (up to 5 s) for the refund round trip to reach the line.
 */
function waitForRefundedUnits(OrderItem $item, int $expected): void
{
    for ($attempt = 0; $attempt < 50 && $item->fresh()->refunded_quantity !== $expected; $attempt++) {
        Execution::instance()->wait(0.1);
    }
}

/**
 * @return array{0: Order, 1: OrderItem, 2: Product}
 */
function refundStockBrowserOrder(): array
{
    $product = Product::factory()->active()->named('Mug')->create(['price' => '10.00', 'stock' => 10]);
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 4]);

    return [$order, $item, $product];
}

test('the stock inputs appear only when "Some units" is chosen', function () {
    [$order, $item] = refundStockBrowserOrder();

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->click('@record-refund')
        ->assertVisible('@refund-stock-return')
        ->assertMissing('@refund-stock-quantity-'.$item->id)
        ->click('@refund-stock-return-partial')
        ->assertVisible('@refund-stock-quantity-'.$item->id)
        ->click('@refund-stock-return-none')
        ->assertMissing('@refund-stock-quantity-'.$item->id)
        ->assertNoJavaScriptErrors();
});

test('refunding with "Some units" puts only those units back in stock', function () {
    [$order, $item, $product] = refundStockBrowserOrder();

    visit('/orders/'.$order->id)
        ->click('@record-refund')
        ->fill('@refund-quantity-'.$item->id, '3')
        ->click('@refund-stock-return-partial')
        ->fill('@refund-stock-quantity-'.$item->id, '1')
        ->click('@refund-confirm')
        ->assertNoJavaScriptErrors();

    waitForRefundedUnits($item, 3);

    expect($item->fresh()->refunded_quantity)->toBe(3)
        ->and($product->fresh()->stock)->toBe(11);
});

test('refunding with "All refunded units" returns every refunded unit', function () {
    [$order, $item, $product] = refundStockBrowserOrder();

    visit('/orders/'.$order->id)
        ->click('@record-refund')
        ->fill('@refund-quantity-'.$item->id, '2')
        ->click('@refund-stock-return-all')
        ->click('@refund-confirm')
        ->assertNoJavaScriptErrors();

    waitForRefundedUnits($item, 2);

    expect($product->fresh()->stock)->toBe(12);
});

test('the default choice leaves the stock alone', function () {
    [$order, $item, $product] = refundStockBrowserOrder();

    visit('/orders/'.$order->id)
        ->click('@record-refund')
        ->fill('@refund-quantity-'.$item->id, '2')
        ->click('@refund-confirm');

    waitForRefundedUnits($item, 2);

    expect($product->fresh()->stock)->toBe(10);
});
