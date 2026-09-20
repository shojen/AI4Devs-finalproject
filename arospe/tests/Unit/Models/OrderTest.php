<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;

// Story 0050, Phase 3 (TDD "red" step): App\Models\Order::isManuallyCancellable() does not exist
// yet -- every test below is expected to fail with a BadMethodCallException until backend-expert
// implements it. Pure in-memory predicate over two already-cast enum properties, so this is a
// genuine Unit test with no database round trip and no Laravel app boot: `tests/Pest.php` binds
// `RefreshDatabase` only to the Feature/Browser suites, so a plain `new Order` plus direct
// property assignment (never `Order::factory()`, which needs a booted container) is what keeps
// this file a real Unit test, matching `tests/Unit/Models/SalesRegionTest.php`'s own `new
// SalesRegion` pattern.

function makeOrder(OrderStatus $status, PaymentStatus $paymentStatus): Order
{
    $order = new Order;
    $order->status = $status;
    $order->payment_status = $paymentStatus;

    return $order;
}

test('isManuallyCancellable is true for Pending/Processing crossed with Paid/PendingPayment', function (OrderStatus $status, PaymentStatus $paymentStatus) {
    expect(makeOrder($status, $paymentStatus)->isManuallyCancellable())->toBeTrue();
})->with([
    'Pending, Paid' => [OrderStatus::Pending, PaymentStatus::Paid],
    'Pending, PendingPayment' => [OrderStatus::Pending, PaymentStatus::PendingPayment],
    'Processing, Paid' => [OrderStatus::Processing, PaymentStatus::Paid],
    'Processing, PendingPayment' => [OrderStatus::Processing, PaymentStatus::PendingPayment],
]);

test('isManuallyCancellable is false for Shipped, Delivered and Cancelled regardless of payment state', function (OrderStatus $status) {
    expect(makeOrder($status, PaymentStatus::Paid)->isManuallyCancellable())->toBeFalse();
})->with([
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
    'Cancelled' => [OrderStatus::Cancelled],
]);

// The cross-dimension case, asserted on the predicate directly as well as through the action
// (see CancelOrderTest.php) -- these are two different layers, per this story's own R-1: a guard
// copy-adapted from TransitionOrderStatus (which reads `status` and nothing else) would pass every
// other test in this file and silently miss this one.
test('isManuallyCancellable is false for Pending/Processing when payment_status is PartiallyRefunded', function (OrderStatus $status) {
    expect(makeOrder($status, PaymentStatus::PartiallyRefunded)->isManuallyCancellable())->toBeFalse();
})->with([
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
]);

test('isManuallyCancellable never throws for any OrderStatus case, Cancelled included', function (OrderStatus $status) {
    $order = makeOrder($status, PaymentStatus::Paid);

    expect(fn () => $order->isManuallyCancellable())->not->toThrow(Throwable::class);
})->with([
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
    'Cancelled' => [OrderStatus::Cancelled],
]);
