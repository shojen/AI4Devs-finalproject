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
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

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
