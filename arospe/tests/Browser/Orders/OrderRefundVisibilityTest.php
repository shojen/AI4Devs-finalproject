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
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.refund']);
    $this->actingAs($actor);
});

test('the refund control is absent in PendingPayment and Refunded and present in Paid and PartiallyRefunded', function () {
    foreach ([[PaymentStatus::PendingPayment, false], [PaymentStatus::Refunded, false], [PaymentStatus::Paid, true], [PaymentStatus::PartiallyRefunded, true]] as [$payment, $present]) {
        $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => $payment]);

        $page = visit('/orders/'.$order->id)->assertNoJavaScriptErrors();

        $present ? $page->assertPresent('@record-refund') : $page->assertMissing('@record-refund');
    }
});

test('a full refund through the modal re-renders the order as cancelled and removes the control', function () {
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    visit('/orders/'.$order->id)
        ->assertNoJavaScriptErrors()
        ->click('@record-refund')
        ->assertVisible('@refund-modal')
        ->fill('@refund-quantity-'.$item->id, '2')
        ->click('@refund-confirm')
        ->assertNoJavaScriptErrors()
        // 0052's auto-cancel, observed where an administrator would actually see it.
        ->assertSee(__('orders.statuses.cancelled'))
        ->assertMissing('@record-refund');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($item->fresh()->refunded_quantity)->toBe(2);
});

test('an over-refund shows the refusal inside the modal and writes nothing', function () {
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    visit('/orders/'.$order->id)
        ->click('@record-refund')
        ->fill('@refund-quantity-'.$item->id, '5')
        ->click('@refund-confirm')
        ->assertNoJavaScriptErrors()
        ->assertSee(__('orders.refunds.exceeds_outstanding_units'));

    expect($item->fresh()->refunded_quantity)->toBe(0);
});
