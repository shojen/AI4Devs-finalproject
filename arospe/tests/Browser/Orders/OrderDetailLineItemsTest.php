<?php

// Story 0055 (D-3): the orders screens, split across six browser files BY CONCERN so a red test names
// its own subject. SELECTOR STRATEGY: select by data-test hook (`@hook`), never by visible text --
// every row action is icon-only, and "Orders" / "Cancel" / "Total" all collide with other copy on the
// page. The selects are DRIVEN THE WAY A PERSON DRIVES THEM (->select() on the native <select>): the
// null-property / native-select desync in docs/errors-log-archive.md is invisible to both
// Livewire::test()->set() and a programmatic value write, and this screen binds three selects.
// Every assertion that matters is followed by a SERVER-SIDE check, not just what the DOM says.

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function orderLineItemsBrowserActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.edit']);

    return $actor;
}

test('adding a line item through the picker updates the order on the server', function () {
    $this->actingAs(orderLineItemsBrowserActor());

    $order = Order::factory()->withItems(1)->create();
    $product = Product::factory()->active()->create(['name' => 'Producto Navegador', 'sku' => 'SKU-NAV-1', 'price' => '9.00']);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->select('@new-product-id', 'Producto Navegador (SKU-NAV-1)')
        ->fill('@new-quantity', '2')
        ->click('@add-line-item')
        ->assertNoJavaScriptErrors()
        ->assertSee('SKU-NAV-1');

    $added = $order->items()->where('product_id', $product->id)->first();

    expect($added)->not->toBeNull()
        ->and($added->quantity)->toBe(2)
        ->and((string) $added->unit_price)->toBe('9.00')
        ->and((string) $added->line_total)->toBe('18.00');
});

test('changing a quantity re-totals the order on the server', function () {
    $this->actingAs(orderLineItemsBrowserActor());

    $product = Product::factory()->create(['price' => '10.00']);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 1]);
    $order->forceFill(['subtotal' => '10.00', 'total' => '10.00'])->save();

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->fill('@quantity-input-'.$item->id, '3')
        ->click('@save-quantity-'.$item->id)
        ->assertNoJavaScriptErrors()
        ->assertSee('€ 30.00');

    expect($item->fresh()->quantity)->toBe(3)
        ->and((string) $order->fresh()->subtotal)->toBe('30.00');
});

test('removing one of two line items drops it from the page and the order', function () {
    $this->actingAs(orderLineItemsBrowserActor());

    $order = Order::factory()->withItems(2)->create();
    [$keep, $remove] = [$order->items()->orderBy('id')->first(), $order->items()->orderByDesc('id')->first()];

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->click('@remove-line-item-'.$remove->id)
        ->assertNoJavaScriptErrors()
        ->assertMissing('@line-item-'.$remove->id)
        ->assertPresent('@line-item-'.$keep->id);

    expect(OrderItem::query()->whereKey($remove->id)->exists())->toBeFalse();
});

test('removing the only remaining line item shows the refusal and keeps the item', function () {
    $this->actingAs(orderLineItemsBrowserActor());

    $order = Order::factory()->withItems(1)->create();
    $item = $order->items()->sole();

    visit('/orders/'.$order->id)
        ->click('@remove-line-item-'.$item->id)
        ->assertNoJavaScriptErrors()
        ->assertSee(__('orders.errors.last_line_item_cannot_be_removed'))
        ->assertPresent('@line-item-'.$item->id);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('on a shipped order every line-item control is disabled in the real page', function () {
    $this->actingAs(orderLineItemsBrowserActor());

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $item = $order->items()->sole();

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->assertDisabled('@add-line-item')
        ->assertDisabled('@remove-line-item-'.$item->id)
        ->assertDisabled('@save-quantity-'.$item->id)
        ->assertMissing('@confirm-dialog-backward-transition');
});
