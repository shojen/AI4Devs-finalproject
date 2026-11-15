<?php

// Story 0094: saving a new quantity on an order line asks whether to move stock. Also owns the
// interactive checks of story 0093's secondary action (Esc / backdrop / X abort, loading-disable, 375px
// layout). SELECTOR STRATEGY: `@hook` selectors (the row actions are icon-only); button copy is asserted
// as visible text because the three labels ARE the behaviour under test. Every journey ends with a
// SERVER-SIDE check of the stock and the line, not just what the DOM says.

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.edit']);
    $this->actingAs($actor);
});

/**
 * The save is a Livewire round trip that finishes after the browser call returns: wait (up to 5 s)
 * for the line to reach the expected quantity before the database assertions run.
 */
function waitForLineQuantity(OrderItem $item, int $expected): void
{
    for ($attempt = 0; $attempt < 50 && $item->fresh()->quantity !== $expected; $attempt++) {
        usleep(100_000);
    }
}

/**
 * A pending order with one "Mug" line of $quantity units (all deducted from stock) and $stock units left.
 *
 * @return array{0: Order, 1: OrderItem, 2: Product}
 */
function stockDialogBrowserOrder(int $quantity = 2, int $stock = 10, bool $legacyUntracked = false): array
{
    $product = Product::factory()->active()->named('Mug')->create(['price' => '10.00', 'stock' => $stock]);
    $order = Order::factory()->create();
    $factory = OrderItem::factory()->for($order);
    $item = ($legacyUntracked ? $factory->legacyStockUntracked() : $factory)
        ->create(['product_id' => $product->id, 'quantity' => $quantity]);

    return [$order, $item, $product];
}

test('raising a quantity opens a dialog with three labelled choices and the stock preview', function () {
    [$order, $item] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->assertSeeIn('@confirm-dialog-line-stock-confirm', 'Deduct 3 units from stock')
        ->assertSeeIn('@confirm-dialog-line-stock-secondary', 'Save without changing stock')
        ->assertSeeIn('@confirm-dialog-line-stock-dismiss', 'Keep editing')
        ->assertPresent('@stock-preview-row-'.$item->id)
        ->assertSeeIn('@stock-preview-row-'.$item->id, 'Mug')
        ->assertSeeIn('@stock-preview-row-'.$item->id, '10')
        ->assertSeeIn('@stock-preview-row-'.$item->id, '7')
        ->assertMissing('@stock-negative-warning')
        ->assertNoJavaScriptErrors();

    expect($item->fresh()->quantity)->toBe(2);
});

test('deducting the added units updates the line and the stock', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->click('@confirm-dialog-line-stock-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock')
        ->assertSee('€ 50.00');

    waitForLineQuantity($item, 5);

    expect($item->fresh()->quantity)->toBe(5)
        ->and($product->fresh()->stock)->toBe(7);
});

test('saving without changing stock runs only the secondary choice', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->click('@confirm-dialog-line-stock-secondary')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock')
        ->assertSee('€ 50.00');

    waitForLineQuantity($item, 5);

    expect($item->fresh()->quantity)->toBe(5)
        ->and($product->fresh()->stock)->toBe(10);
});

test('keeping editing changes neither the line nor the stock', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->click('@confirm-dialog-line-stock-dismiss')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock');

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('Escape aborts the stock dialog without changing anything', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->keys('@confirm-dialog-line-stock-dismiss', 'Escape')
        ->assertMissing('@confirm-dialog-line-stock')
        ->assertNoJavaScriptErrors();

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('clicking the backdrop aborts the stock dialog without changing anything', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    $page = visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock');

    // The native <dialog> closes on a click whose target is the dialog element itself (the backdrop).
    $page->script("document.querySelector('dialog[open]').dispatchEvent(new MouseEvent('click', { bubbles: true }))");

    $page->assertMissing('@confirm-dialog-line-stock')->assertNoJavaScriptErrors();

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('the close X aborts the stock dialog without changing anything', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->click('dialog[open] [aria-label="Close modal"]')
        ->assertMissing('@confirm-dialog-line-stock')
        ->assertNoJavaScriptErrors();

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('an increase that leaves negative stock is warned about as an alert but still allowed', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 1);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@stock-negative-warning')
        ->assertSeeIn('@stock-negative-warning', 'This leaves Mug at -2 units.')
        ->assertScript("document.querySelector('[data-test=\"stock-negative-warning\"]').closest('[role=\"alert\"]') !== null")
        ->assertScript("! document.querySelector('[data-test=\"confirm-dialog-line-stock-confirm\"]').disabled")
        ->click('@confirm-dialog-line-stock-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock');

    waitForLineQuantity($item, 5);

    expect($item->fresh()->quantity)->toBe(5)
        ->and($product->fresh()->stock)->toBe(-2);
});

test('lowering a quantity offers to return the removed units', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(5, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '2')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->assertSeeIn('@confirm-dialog-line-stock-confirm', 'Return 3 units to stock')
        ->assertSeeIn('@confirm-dialog-line-stock-secondary', 'Save without changing stock')
        ->assertSeeIn('@confirm-dialog-line-stock-dismiss', 'Keep editing')
        ->click('@confirm-dialog-line-stock-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock');

    waitForLineQuantity($item, 2);

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(13);
});

test('lowering a line with nothing to return explains it and offers only two buttons', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(5, 10, legacyUntracked: true);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '2')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->assertPresent('@stock-nothing-to-return')
        ->assertSeeIn('@stock-nothing-to-return', 'No units of this line are currently out of stock')
        ->assertMissing('@confirm-dialog-line-stock-secondary')
        ->assertSeeIn('@confirm-dialog-line-stock-confirm', 'Save without changing stock')
        ->assertDontSee('Return 3 units to stock')
        ->assertSeeIn('@confirm-dialog-line-stock-dismiss', 'Keep editing')
        ->click('@confirm-dialog-line-stock-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock');

    waitForLineQuantity($item, 2);

    expect($item->fresh()->quantity)->toBe(2)
        ->and($product->fresh()->stock)->toBe(10);
});

test('a line whose product was deleted is saved without any stock dialog', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);
    Product::query()->whereKey($item->product_id)->delete();

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '4')
        ->click('@save-quantity-'.$item->id)
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-line-stock');

    waitForLineQuantity($item, 4);

    expect($item->fresh()->quantity)->toBe(4);
});

test('both action buttons are disabled while one of them is loading', function () {
    [$order, $item, $product] = stockDialogBrowserOrder(2, 10);

    $page = visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock');

    // Delay every Livewire request so the loading window is long enough to observe.
    $page->script('const realFetch = window.fetch; window.fetch = (...args) => new Promise((resolve) => setTimeout(() => resolve(realFetch(...args)), 2500));');
    $page->script("document.querySelector('[data-test=\"confirm-dialog-line-stock-confirm\"]').click()");

    $page->assertScript("document.querySelector('[data-test=\"confirm-dialog-line-stock-confirm\"]').disabled && document.querySelector('[data-test=\"confirm-dialog-line-stock-secondary\"]').disabled")
        ->assertMissing('@confirm-dialog-line-stock')
        ->assertNoJavaScriptErrors();

    waitForLineQuantity($item, 5);

    expect($item->fresh()->quantity)->toBe(5)
        ->and($product->fresh()->stock)->toBe(7);
});

test('the three buttons fit inside the dialog on a desktop viewport', function () {
    [$order, $item] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->assertScript(<<<'JS'
            (() => {
                const dialog = document.querySelector('dialog[open]').querySelector('[data-flux-modal-content]') ?? document.querySelector('dialog[open]');
                const box = dialog.getBoundingClientRect();

                return ['confirm', 'secondary', 'dismiss'].every((name) => {
                    const rect = document.querySelector('[data-test="confirm-dialog-line-stock-' + name + '"]').getBoundingClientRect();

                    return rect.left >= box.left - 1 && rect.right <= box.right + 1;
                });
            })()
            JS)
        ->assertNoJavaScriptErrors();
});

test('the dialog stacks its buttons on a 375px phone with comfortable touch targets', function () {
    [$order, $item] = stockDialogBrowserOrder(2, 10);

    visit('/orders/'.$order->id)
        ->resize(375, 800)
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
        ->assertScript(<<<'JS'
            (() => {
                const rects = ['confirm', 'secondary', 'dismiss'].map((name) =>
                    document.querySelector('[data-test="confirm-dialog-line-stock-' + name + '"]').getBoundingClientRect());
                const stacked = rects.every((rect, index) => index === 0 || rect.top >= rects[index - 1].bottom - 1);
                const tallEnough = rects.every((rect) => rect.height >= 44);
                const fitsViewport = rects.every((rect) => rect.left >= 0 && rect.right <= window.innerWidth);

                return stacked && tallEnough && fitsViewport;
            })()
            JS)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

test('the dialog is legible in dark mode', function () {
    [$order, $item] = stockDialogBrowserOrder(2, 1);

    visit('/orders/'.$order->id)
        ->inDarkMode()
        ->fill('@quantity-input-'.$item->id, '5')
        ->click('@save-quantity-'.$item->id)
        ->assertVisible('@confirm-dialog-line-stock')
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
                const heading = luminance(getComputedStyle(document.querySelector('[data-test="confirm-dialog-line-stock"] [data-flux-heading]') ?? document.querySelector('[data-test="confirm-dialog-line-stock"]')).color);
                const warning = luminance(getComputedStyle(document.querySelector('[data-test="stock-negative-warning"]')).color);

                return background < 0.2 && heading > background + 0.3 && warning > background + 0.2;
            })()
            JS)
        ->assertNoJavaScriptErrors();
});
