<?php

// Story 0106 (T-9, T-31, T-32, FD-7, FD-13) -- the payment-status / order-status matrix with the new
// "Partially paid" status, its effect on TransitionOrderStatus, and the pin that MarkOrderAsPaid still
// refuses a partially paid order (changed by story 0106a).

use App\Actions\Orders\MarkOrderAsPaid;
use App\Actions\Orders\TransitionOrderStatus;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * The matrix as it was before story 0106, frozen: the allowed order statuses of the four existing
 * payment states. A change to any of them must be a deliberate edit of this test.
 *
 * @return array<string, list<OrderStatus>>
 */
function frozenPaymentMatrix(): array
{
    return [
        'pending_payment' => [OrderStatus::Pending, OrderStatus::Cancelled],
        'paid' => OrderStatus::cases(),
        'refunded' => [OrderStatus::Cancelled],
        'partially_refunded' => [OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered],
        'partially_paid' => [OrderStatus::Pending],
    ];
}

// T-9
test('PaymentStatus::PartiallyPaid exists with its backing value (T-9)', function () {
    expect(PaymentStatus::tryFrom('partially_paid'))->toBe(PaymentStatus::PartiallyPaid)
        ->and(PaymentStatus::cases())->toHaveCount(5);
});

test('every payment status x order status combination follows the matrix; PartiallyPaid allows Pending only (T-9)', function (string $payment, OrderStatus $status) {
    $allowed = in_array($status, frozenPaymentMatrix()[$payment], true);

    expect(PaymentStatus::from($payment)->allowsOrderStatus($status))->toBe($allowed);
})->with(function () {
    foreach (['pending_payment', 'paid', 'refunded', 'partially_refunded', 'partially_paid'] as $payment) {
        foreach (OrderStatus::cases() as $status) {
            yield "{$payment} x {$status->value}" => [$payment, $status];
        }
    }
});

test('the frozen matrix covers every payment status (T-9)', function () {
    expect(array_keys(frozenPaymentMatrix()))->toEqualCanonicalizing(array_map(fn (PaymentStatus $case): string => $case->value, PaymentStatus::cases()));
});

// T-31
test('TransitionOrderStatus on a partially paid order accepts Pending only, whatever the target (T-31)', function (OrderStatus $from, OrderStatus $target, bool $accepted) {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->partiallyPaid()->create(['status' => $from]);

    if ($accepted) {
        app(TransitionOrderStatus::class)($order, $target, true);

        expect($order->fresh()->status)->toBe($target);

        return;
    }

    expect(fn () => app(TransitionOrderStatus::class)($order, $target, true))->toThrow(ValidationException::class)
        ->and($order->fresh()->status)->toBe($from);
})->with([
    'Pending -> Processing' => [OrderStatus::Pending, OrderStatus::Processing, false],
    'Pending -> Shipped' => [OrderStatus::Pending, OrderStatus::Shipped, false],
    'Pending -> Delivered' => [OrderStatus::Pending, OrderStatus::Delivered, false],
    'Pending -> Cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, false],
    'Processing -> Pending' => [OrderStatus::Processing, OrderStatus::Pending, true],
    'Processing -> Shipped' => [OrderStatus::Processing, OrderStatus::Shipped, false],
    'Processing -> Delivered' => [OrderStatus::Processing, OrderStatus::Delivered, false],
    'Processing -> Cancelled' => [OrderStatus::Processing, OrderStatus::Cancelled, false],
]);

test('the refusal of a move from a partially paid order is the payment-state refusal on status (T-31)', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->partiallyPaid()->create(['status' => OrderStatus::Pending]);

    try {
        app(TransitionOrderStatus::class)($order, OrderStatus::Processing);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['status'])->toBe([__('orders.transitions.payment_state_blocked')]);
    }
});

// T-32
test('MarkOrderAsPaid still refuses a partially paid order; story 0106a changes this (T-32)', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo('orders.edit');
    $this->actingAs($editor);
    $order = Order::factory()->partiallyPaid()->create();

    expect(fn () => app(MarkOrderAsPaid::class)($order, PaymentMethod::query()->first() ?? PaymentMethod::factory()->create(), OrderPaymentType::Transfer))
        ->toThrow(ValidationException::class)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($order->payment()->exists())->toBeFalse();
});

test('isAwaitingPayment is true for pending payment and partially paid, false once cancelled (FD-8)', function (string $payment, OrderStatus $status, bool $expected) {
    $order = Order::factory()->create(['payment_status' => PaymentStatus::from($payment), 'status' => $status]);

    expect($order->isAwaitingPayment())->toBe($expected);
})->with([
    'pending payment' => ['pending_payment', OrderStatus::Pending, true],
    'partially paid' => ['partially_paid', OrderStatus::Pending, true],
    'partially paid but cancelled' => ['partially_paid', OrderStatus::Cancelled, false],
    'paid' => ['paid', OrderStatus::Pending, false],
    'refunded' => ['refunded', OrderStatus::Pending, false],
]);

test('refund and manual cancellation predicates are unchanged by story 0106: they ship in 0107 (FD-11)', function () {
    $partiallyPaid = Order::factory()->partiallyPaid()->create(['status' => OrderStatus::Pending]);
    $partiallyRefunded = Order::factory()->create(['status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::PartiallyRefunded]);

    expect($partiallyPaid->isRefundable())->toBeFalse()
        ->and($partiallyRefunded->isManuallyCancellable())->toBeFalse()
        ->and($partiallyPaid->isManuallyCancellable())->toBeTrue();
});
