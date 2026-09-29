<?php

// Story 0055 -- refunds (0051/0052). The refund control has TWO dimensions handled DIFFERENTLY and
// independently (D-10):
//   payment STATE      -> the control is ABSENT from the DOM   (PRD §3.2: "the refund action does not render")
//   PERMISSION         -> the control is present but DISABLED  (the repo default, hook on both branches)
// A single mechanism handling both would fail one of the two, and which one it fails is a
// security-relevant difference. The DOM absence is the UI half of a defence-in-depth pair; 0051 owns
// the backend refusal and neither substitutes for the other.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function ordersUiRefunder(): void
{
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.refund']));
}

test('the refund control is ABSENT from the rendered response in PendingPayment and Refunded, not merely disabled', function (PaymentStatus $payment) {
    ordersUiRefunder();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => $payment]);

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect(OrdersUi::present($html, 'record-refund'))->toBeFalse()
        ->and($html)->not->toContain('openRefundModal');
})->with([
    'PendingPayment' => [PaymentStatus::PendingPayment],
    'Refunded' => [PaymentStatus::Refunded],
]);

test('the refund control IS present in Paid and PartiallyRefunded', function (PaymentStatus $payment) {
    ordersUiRefunder();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => $payment]);

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    expect(OrdersUi::present($html, 'record-refund'))->toBeTrue()
        ->and(OrdersUi::enabled($html, 'record-refund'))->toBeTrue();
})->with([
    'Paid' => [PaymentStatus::Paid],
    'PartiallyRefunded' => [PaymentStatus::PartiallyRefunded],
]);

test('the two dimensions are treated differently: no orders.refund + Paid = disabled and present; orders.refund + Refunded = absent', function () {
    $paid = Order::factory()->withItems(1)->paid()->create(['status' => OrderStatus::Processing]);
    $refunded = Order::factory()->withItems(1)->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Refunded]);

    // PERMISSION dimension: an actor LACKING orders.refund sees the control disabled -- present, hook on it.
    $this->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
    $html = Livewire::test(Show::class, ['order' => $paid])->html();
    expect(OrdersUi::present($html, 'record-refund'))->toBeTrue()
        ->and(OrdersUi::disabled($html, 'record-refund'))->toBeTrue();

    // STATE dimension: an actor HOLDING orders.refund sees no control at all against a Refunded order.
    $this->actingAs(OrdersUi::actor(['orders.view', 'orders.refund']));
    $html = Livewire::test(Show::class, ['order' => $refunded])->html();
    expect(OrdersUi::present($html, 'record-refund'))->toBeFalse();
});

test('recording a refund of two of three units updates refunded_amount, the line refunded_quantity and the payment badge', function () {
    ordersUiRefunder();

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 3]);
    $unitPrice = (string) $item->fresh()->unit_price;

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('openRefundModal')
        ->assertSet('showRefundModal', true)
        ->set("refundQuantities.{$item->id}", 2)
        ->call('recordRefund')
        ->assertHasNoErrors()
        ->assertSet('showRefundModal', false);

    $expectedRefund = bcmul($unitPrice, '2', 2);

    expect($item->fresh()->refunded_quantity)->toBe(2)
        ->and((string) $order->fresh()->refunded_amount)->toBe($expectedRefund)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($component->html())->toContain('€ '.$expectedRefund)
        ->toContain(__('orders.payment_statuses.partially_refunded'));
});

test('an over-refund renders the 0051 refusal against the refund field and writes nothing', function () {
    ordersUiRefunder();

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    Livewire::test(Show::class, ['order' => $order])
        ->call('openRefundModal')
        ->set("refundQuantities.{$item->id}", 5)
        ->call('recordRefund')
        ->assertHasErrors(['refund'])
        ->assertSee(__('orders.refunds.exceeds_outstanding_units'));

    expect($item->fresh()->refunded_quantity)->toBe(0)
        ->and((string) $order->fresh()->refunded_amount)->toBe('0.00');
});

test('submitting the refund form with nothing entered shows an inline message and does not call the action', function () {
    ordersUiRefunder();

    $order = Order::factory()->withItems(1)->paid()->create(['status' => OrderStatus::Processing]);

    Livewire::test(Show::class, ['order' => $order])
        ->call('openRefundModal')
        ->call('recordRefund')
        ->assertHasErrors(['refund'])
        ->assertSee(__('orders.refunds.nothing_selected'));

    expect((string) $order->fresh()->refunded_amount)->toBe('0.00');
});

test('a forged recordRefund against a PendingPayment order renders 0051 own refusal: the DOM absence never substitutes for the backend guard', function () {
    ordersUiRefunder();

    $order = Order::factory()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PendingPayment]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    Livewire::test(Show::class, ['order' => $order])
        ->set("refundQuantities.{$item->id}", 1)
        ->call('recordRefund')
        ->assertHasErrors(['refund'])
        ->assertSee(__('orders.refunds.invalid_payment_state'));

    expect($item->fresh()->refunded_quantity)->toBe(0);
});

// 0052's auto-cancel, observed where an administrator would actually see it. A screen that cached
// $order across the write would show the STALE status.
test('a full refund of every unit re-renders the order as Cancelled (0052 auto-cancel) with the payment badge Refunded', function () {
    ordersUiRefunder();

    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('openRefundModal')
        ->set("refundQuantities.{$item->id}", 2)
        ->call('recordRefund')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($component->html())->toContain(__('orders.statuses.cancelled'))
        ->toContain(__('orders.payment_statuses.refunded'))
        ->and(OrdersUi::present($component->html(), 'record-refund'))->toBeFalse();
});
