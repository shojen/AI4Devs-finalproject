<?php

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\MarkOrderAsPaid;
use App\Actions\Orders\RecordRefund;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0084 (reworked), Phase 3 red step: the three-parameter MarkOrderAsPaid and order_payments do not exist yet. The REAL RecordRefund and
// CancelOrder are used (never mocked) -- this file is the proof that marking as paid is what
// unlocks refunds on real orders.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.edit', 'orders.refund']);
    test()->actingAs($actor);
});

function markPaidRefundRun(Order $order): Order
{
    $method = PaymentMethod::query()->first() ?? PaymentMethod::factory()->create();

    return app(MarkOrderAsPaid::class)($order, $method, OrderPaymentType::Transfer);
}

function markPaidRefundPaymentCount(Order $order): int
{
    return DB::table('order_payments')->where('order_id', $order->id)->count();
}

/**
 * A pending-payment order with one line item of quantity 2.
 */
function markPaidRefundOrder(): Order
{
    $order = Order::factory()->create();
    $product = Product::factory()->create(['price' => '10.00']);
    OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 2]);

    return $order->fresh();
}

test('regression guard for the story premise: RecordRefund refuses an order that is still pending payment', function () {
    $order = markPaidRefundOrder();
    $item = $order->items()->firstOrFail();

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 1]))->toThrow(ValidationException::class);
});

test('marking an order as paid makes a partial refund acceptable', function () {
    $order = markPaidRefundOrder();
    $item = $order->items()->firstOrFail();

    markPaidRefundRun($order);
    $result = app(RecordRefund::class)($order->fresh(), [$item->id => 1]);

    expect($result->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and(markPaidRefundPaymentCount($order))->toBe(1);
});

test('mark, then fully refund (which auto-cancels), then mark again is refused as already paid', function () {
    $order = markPaidRefundOrder();
    $item = $order->items()->firstOrFail();

    markPaidRefundRun($order);
    app(RecordRefund::class)($order->fresh(), [$item->id => 2]);
    $settled = $order->fresh();

    expect($settled->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($settled->status)->toBe(OrderStatus::Cancelled)
        ->and(markPaidRefundPaymentCount($order))->toBe(1);

    try {
        markPaidRefundRun($settled);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.already_paid')]);
    }

    expect(markPaidRefundPaymentCount($order))->toBe(1);
});

test('cancelling a pending order and then marking it as paid is refused as cancelled', function () {
    $order = markPaidRefundOrder();

    app(CancelOrder::class)($order);

    try {
        markPaidRefundRun($order->fresh());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['payment_status'])->toBe([__('orders.payment.cancelled_blocked')]);
    }

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(markPaidRefundPaymentCount($order))->toBe(0);
});
