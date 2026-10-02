<?php

use App\Actions\Orders\MarkOrderAsPaid;
use App\Enums\OrderPaymentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

// Story 0084 (D-2, reworked), Phase 3 red step: OrderPolicy::markPaid(), order_payments and the three-parameter MarkOrderAsPaid do not exist yet.
// markPaid is exactly orders.edit -- no orders.refund requirement, no state clause.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  list<string>  $permissions
 */
function markPaidAuthActor(array $permissions): User
{
    $actor = User::factory()->create();
    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }
    test()->actingAs($actor);

    return $actor;
}

function markPaidAuthRun(Order $order): Order
{
    $method = PaymentMethod::query()->first() ?? PaymentMethod::factory()->create();

    return app(MarkOrderAsPaid::class)($order, $method, OrderPaymentType::Transfer);
}

function markPaidAuthPaymentCount(Order $order): int
{
    return DB::table('order_payments')->where('order_id', $order->id)->count();
}

function markPaidAuthOrder(PaymentStatus $paymentStatus = PaymentStatus::PendingPayment, OrderStatus $status = OrderStatus::Pending): Order
{
    return Order::factory()->create(['payment_status' => $paymentStatus, 'status' => $status])->fresh();
}

test('an actor holding none of these permissions, or only the wrong ones, is refused and the order does not change', function (array $permissions) {
    markPaidAuthActor($permissions);
    $order = markPaidAuthOrder();

    expect(fn () => markPaidAuthRun($order))->toThrow(AuthorizationException::class);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(markPaidAuthPaymentCount($order))->toBe(0);
})->with([
    'no permission at all' => [[]],
    'orders.view only' => [['orders.view']],
    'orders.create only' => [['orders.create']],
    'orders.delete only' => [['orders.delete']],
    'orders.refund only' => [['orders.refund']],
]);

test('an actor holding orders.edit can mark an order as paid, with or without orders.refund', function (array $permissions) {
    markPaidAuthActor($permissions);
    $order = markPaidAuthOrder();

    markPaidAuthRun($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(markPaidAuthPaymentCount($order))->toBe(1);
})->with([
    'orders.edit only (no refund needed)' => [['orders.edit']],
    'orders.edit and orders.refund' => [['orders.edit', 'orders.refund']],
]);

test('a Super Admin can mark an order as paid', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);
    $order = markPaidAuthOrder();

    markPaidAuthRun($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(markPaidAuthPaymentCount($order))->toBe(1);
});

test('a user holding the seeded Administrator role can mark an order as paid', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    test()->actingAs($administrator);
    $order = markPaidAuthOrder();

    markPaidAuthRun($order);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(markPaidAuthPaymentCount($order))->toBe(1);
});

test('a guest is refused', function () {
    $order = markPaidAuthOrder();

    expect(fn () => markPaidAuthRun($order))->toThrow(AuthorizationException::class);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PendingPayment)
        ->and(markPaidAuthPaymentCount($order))->toBe(0);
});

test('the markPaid ability has no state clause: an orders.edit holder is allowed for paid, cancelled and pending orders alike', function (PaymentStatus $paymentStatus, OrderStatus $status) {
    $actor = markPaidAuthActor(['orders.edit']);
    $order = markPaidAuthOrder($paymentStatus, $status);

    expect(Gate::forUser($actor)->allows('markPaid', $order))->toBeTrue();
})->with([
    'paid' => [PaymentStatus::Paid, OrderStatus::Processing],
    'cancelled' => [PaymentStatus::PendingPayment, OrderStatus::Cancelled],
    'pending' => [PaymentStatus::PendingPayment, OrderStatus::Pending],
]);

test('the markPaid ability is denied to an actor without orders.edit, whatever the order', function () {
    $actor = markPaidAuthActor(['orders.view', 'orders.refund']);

    expect(Gate::forUser($actor)->allows('markPaid', markPaidAuthOrder()))->toBeFalse();
});

test('an actor without orders.edit gets the identical AuthorizationException for every payment state, so nothing about the order leaks', function () {
    markPaidAuthActor(['orders.view']);

    $outcomes = [];
    foreach ([
        [PaymentStatus::Paid, OrderStatus::Pending],
        [PaymentStatus::PendingPayment, OrderStatus::Cancelled],
        [PaymentStatus::PendingPayment, OrderStatus::Pending],
        [PaymentStatus::Refunded, OrderStatus::Cancelled],
        [PaymentStatus::PartiallyRefunded, OrderStatus::Shipped],
    ] as [$paymentStatus, $status]) {
        $order = markPaidAuthOrder($paymentStatus, $status);

        try {
            markPaidAuthRun($order);
            $outcomes[] = 'no exception';
        } catch (Throwable $e) {
            $outcomes[] = $e::class.'|'.$e->getMessage();
        }
    }

    expect(DB::table('order_payments')->count())->toBe(0)
        ->and($outcomes)->toHaveCount(5)
        ->and(array_unique($outcomes))->toHaveCount(1)
        ->and($outcomes[0])->toStartWith(AuthorizationException::class);
});
