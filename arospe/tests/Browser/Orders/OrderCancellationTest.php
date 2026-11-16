<?php

// Story 0055 (D-3): the orders screens, split across six browser files BY CONCERN so a red test names
// its own subject. SELECTOR STRATEGY: select by data-test hook (`@hook`), never by visible text --
// every row action is icon-only, and "Orders" / "Cancel" / "Total" all collide with other copy on the
// page. The selects are DRIVEN THE WAY A PERSON DRIVES THEM (->select() on the native <select>): the
// null-property / native-select desync in docs/errors-log-archive.md is invisible to both
// Livewire::test()->set() and a programmatic value write, and this screen binds three selects.
// Every assertion that matters is followed by a SERVER-SIDE check, not just what the DOM says.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Pest\Browser\Execution;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    // orders.edit AND orders.refund: OrderPolicy::cancel() needs both (0050 D-6). A non-Super-Admin,
    // so the only reason Cancel can be disabled is the order's state.
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.edit', 'orders.refund']);
    $this->actingAs($actor);
});

test('cancelling a pending order goes through the confirmation dialog', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->click('@confirm-dialog-cancel-order-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-cancel-order')
        ->assertSee(__('orders.statuses.cancelled'));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('dismissing the cancel dialog leaves the order pending', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->click('@confirm-dialog-cancel-order-dismiss')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-cancel-order');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('the cancel control is not clickable on a shipped order', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->assertDisabled('@cancel-order')
        ->assertMissing('@confirm-dialog-cancel-order');
});

test('the cancel control is not clickable on a partially refunded processing order', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded]);

    visit('/orders/'.$order->id)
        ->assertDisabled('@cancel-order');
});

/**
 * The cancellation is a Livewire round trip that finishes after the browser call returns: wait (up to 5 s)
 * for the order to reach the expected status. Never usleep(): the HTTP server runs in the test process.
 */
function waitForOrderStatus(Order $order, OrderStatus $expected): void
{
    for ($attempt = 0; $attempt < 50 && $order->fresh()->status !== $expected; $attempt++) {
        Execution::instance()->wait(0.1);
    }
}

/**
 * A pending order with one 3-unit "Mug" line (all deducted) and 10 units left in stock.
 *
 * @return array{0: Order, 1: OrderItem, 2: Product}
 */
function cancelDialogBrowserOrder(bool $legacyUntracked = false): array
{
    $product = Product::factory()->active()->named('Mug')->create(['stock' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $factory = OrderItem::factory()->for($order);
    $item = ($legacyUntracked ? $factory->legacyStockUntracked() : $factory)
        ->create(['product_id' => $product->id, 'quantity' => 3]);

    return [$order, $item, $product];
}

test('the cancel dialog offers three labelled choices with the stock preview', function () {
    [$order, $item] = cancelDialogBrowserOrder();

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->assertSeeIn('@confirm-dialog-cancel-order-confirm', 'Cancel and return 3 units to stock')
        ->assertSeeIn('@confirm-dialog-cancel-order-secondary', 'Cancel without changing stock')
        ->assertSeeIn('@confirm-dialog-cancel-order-dismiss', 'Keep order')
        ->assertSeeIn('@cancel-stock-preview-row-'.$item->id, 'Mug')
        ->assertSeeIn('@cancel-stock-preview-row-'.$item->id, '13')
        ->assertNoJavaScriptErrors();
});

test('cancelling and returning the units restores the stock', function () {
    [$order, , $product] = cancelDialogBrowserOrder();

    visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->click('@confirm-dialog-cancel-order-confirm')
        ->assertMissing('@confirm-dialog-cancel-order')
        ->assertNoJavaScriptErrors();

    waitForOrderStatus($order, OrderStatus::Cancelled);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->stock)->toBe(13);
});

test('cancelling without changing stock leaves the stock alone', function () {
    [$order, , $product] = cancelDialogBrowserOrder();

    visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->click('@confirm-dialog-cancel-order-secondary')
        ->assertMissing('@confirm-dialog-cancel-order')
        ->assertNoJavaScriptErrors();

    waitForOrderStatus($order, OrderStatus::Cancelled);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->stock)->toBe(10);
});

test('Escape aborts the cancel dialog without changing anything', function () {
    [$order, , $product] = cancelDialogBrowserOrder();

    visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->keys('@confirm-dialog-cancel-order-dismiss', 'Escape')
        ->assertMissing('@confirm-dialog-cancel-order')
        ->assertNoJavaScriptErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($product->fresh()->stock)->toBe(10);
});

test('an order with nothing to return offers only cancelling without changing stock', function () {
    [$order] = cancelDialogBrowserOrder(legacyUntracked: true);

    visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->assertVisible('@cancel-stock-nothing-to-return')
        ->assertMissing('@confirm-dialog-cancel-order-secondary')
        ->assertSeeIn('@confirm-dialog-cancel-order-confirm', 'Cancel without changing stock')
        ->assertSeeIn('@confirm-dialog-cancel-order-dismiss', 'Keep order')
        ->assertNoJavaScriptErrors();
});

test('clicking the backdrop aborts the cancel dialog without changing anything', function () {
    [$order, , $product] = cancelDialogBrowserOrder();

    $page = visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order');

    // The native <dialog> closes on a click whose target is the dialog element itself (the backdrop).
    $page->script("document.querySelector('dialog[open]').dispatchEvent(new MouseEvent('click', { bubbles: true }))");

    $page->assertMissing('@confirm-dialog-cancel-order')->assertNoJavaScriptErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($product->fresh()->stock)->toBe(10);
});

test('the cancel dialog stacks its buttons on a 375px phone with comfortable touch targets', function () {
    [$order] = cancelDialogBrowserOrder();

    visit('/orders/'.$order->id)
        ->resize(375, 800)
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->assertScript(<<<'JS'
            (() => {
                const rects = ['confirm', 'secondary', 'dismiss'].map((name) =>
                    document.querySelector('[data-test="confirm-dialog-cancel-order-' + name + '"]').getBoundingClientRect());
                const stacked = rects.every((rect, index) => index === 0 || rect.top >= rects[index - 1].bottom - 1);
                const tallEnough = rects.every((rect) => rect.height >= 44);
                const fitsViewport = rects.every((rect) => rect.left >= 0 && rect.right <= window.innerWidth);

                return stacked && tallEnough && fitsViewport;
            })()
            JS)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

test('the cancel dialog is legible in dark mode', function () {
    [$order] = cancelDialogBrowserOrder();

    visit('/orders/'.$order->id)
        ->inDarkMode()
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->assertScript(<<<'JS'
            (() => {
                const luminance = (value) => {
                    const [r, g, b] = value.match(/[\d.]+/g).slice(0, 3).map(Number).map((channel) => {
                        const scaled = channel / 255;
                        return scaled <= 0.03928 ? scaled / 12.92 : Math.pow((scaled + 0.055) / 1.055, 2.4);
                    });
                    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
                };
                const dialog = document.querySelector('dialog[open]');
                const background = luminance(getComputedStyle(dialog).backgroundColor);
                const text = luminance(getComputedStyle(document.querySelector('[data-test="confirm-dialog-cancel-order"] [data-flux-heading]') ?? document.querySelector('[data-test="confirm-dialog-cancel-order"]')).color);

                return background < 0.2 && text > background + 0.3;
            })()
            JS)
        ->assertNoJavaScriptErrors();
});

test('a refused cancel closes the dialog and keeps the refusal message visible', function () {
    $this->actingAs(OrdersUi::superAdmin());
    $product = Product::factory()->active()->named('Mug')->create(['stock' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);
    OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);

    visit('/orders/'.$order->id)
        ->click('@cancel-order')
        ->assertVisible('@confirm-dialog-cancel-order')
        ->click('@confirm-dialog-cancel-order-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-cancel-order')
        ->assertSee(__('orders.cancellation.blocked'));

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and($product->fresh()->stock)->toBe(10);
});
