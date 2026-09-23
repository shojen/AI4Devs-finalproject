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

test('advancing forward applies immediately with no dialog', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->select('@status-select', __('orders.statuses.processing'))
        ->click('@apply-status')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-backward-transition')
        ->assertSee(__('orders.statuses.processing'));

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

test('moving backward asks first, and confirming applies it', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->select('@status-select', __('orders.statuses.pending'))
        ->click('@apply-status')
        ->assertVisible('@confirm-dialog-backward-transition')
        ->click('@confirm-dialog-backward-transition-confirm')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-backward-transition');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('moving backward and dismissing leaves the order alone and resets the select', function () {
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);

    visit('/orders/'.$order->id)
        ->select('@status-select', __('orders.statuses.pending'))
        ->click('@apply-status')
        ->assertVisible('@confirm-dialog-backward-transition')
        ->click('@confirm-dialog-backward-transition-dismiss')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@confirm-dialog-backward-transition')
        // A select left showing a value the server never accepted is the desync class of bug this
        // repo has already paid for once.
        ->assertValue('@status-select', 'shipped');

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});
