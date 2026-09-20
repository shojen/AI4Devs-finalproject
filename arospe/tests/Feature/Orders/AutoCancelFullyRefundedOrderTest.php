<?php

use App\Actions\Orders\AutoCancelFullyRefundedOrder;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderFullyRefunded;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0052 -- system-triggered auto-cancel when a refund leaves every line fully refunded.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function autoCancelActor(bool $canRefund = true): User
{
    $actor = User::factory()->create();

    if ($canRefund) {
        $actor->givePermissionTo(['orders.refund', 'orders.edit']);
    }

    test()->actingAs($actor);

    return $actor;
}

/**
 * @param  array<int, int>  $quantities
 * @return array{0: Order, 1: array<int, OrderItem>}
 */
function paidOrderWithLines(array $quantities, OrderStatus $status = OrderStatus::Pending): array
{
    $order = Order::factory()->paid()->create(['status' => $status]);
    $items = [];

    foreach ($quantities as $quantity) {
        $items[] = OrderItem::factory()->for($order)->create([
            'product_id' => Product::factory()->create(['price' => '10.00'])->id,
            'quantity' => $quantity,
        ]);
    }

    return [$order, $items];
}

test('a full refund cancels the order from every starting status, including shipped and delivered', function (OrderStatus $status) {
    autoCancelActor();
    [$order, [$item]] = paidOrderWithLines([3], $status);

    app(RecordRefund::class)($order, [$item->id => 3]);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
})->with([OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered]);

test('the refund emptying the last outstanding line of a multi-line order cancels it', function () {
    autoCancelActor();
    [$order, [$first, $second, $third]] = paidOrderWithLines([1, 2, 3]);

    app(RecordRefund::class)($order, [$first->id => 1, $second->id => 2]);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    app(RecordRefund::class)($order->fresh(), [$third->id => 3]);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('RecordRefund dispatches exactly one OrderFullyRefunded carrying the order id', function () {
    Event::fake([OrderFullyRefunded::class]);
    autoCancelActor();
    [$order, [$item]] = paidOrderWithLines([2]);

    app(RecordRefund::class)($order, [$item->id => 2]);

    Event::assertDispatchedTimes(OrderFullyRefunded::class, 1);
    Event::assertDispatched(OrderFullyRefunded::class, fn (OrderFullyRefunded $event): bool => $event->orderId === $order->id);
});

test('the action works directly with no authenticated user and dispatches no event', function () {
    Event::fake([OrderFullyRefunded::class]);
    Auth::forgetGuards();
    [$order] = paidOrderWithLines([1], OrderStatus::Shipped);

    app(AutoCancelFullyRefundedOrder::class)($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    Event::assertNotDispatched(OrderFullyRefunded::class);
});

test('the same shipped order refuses manual cancellation yet is auto-cancelled by a full refund', function () {
    autoCancelActor();
    [$order, [$item]] = paidOrderWithLines([2], OrderStatus::Shipped);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);

    app(RecordRefund::class)($order->fresh(), [$item->id => 2]);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('CancelOrder still refuses shipped, delivered and partially refunded orders', function () {
    autoCancelActor();

    foreach ([OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
        [$order] = paidOrderWithLines([1], $status);

        expect(fn () => app(CancelOrder::class)($order))->toThrow(AuthorizationException::class);
        expect($order->fresh()->status)->toBe($status);
    }

    [$order] = paidOrderWithLines([2]);
    $order->forceFill(['payment_status' => PaymentStatus::PartiallyRefunded])->save();

    expect(fn () => app(CancelOrder::class)($order->fresh()))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('refunds that do not empty every line leave the order status unchanged and dispatch nothing', function (array $quantities, array $refund) {
    Event::fake([OrderFullyRefunded::class]);
    autoCancelActor();
    [$order, $items] = paidOrderWithLines($quantities);
    $payload = [];

    foreach ($refund as $index => $units) {
        $payload[$items[$index]->id] = $units;
    }

    app(RecordRefund::class)($order, $payload);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderFullyRefunded::class);
})->with([
    'partial single line' => [[5], [2]],
    'one line emptied of two' => [[2, 2], [2]],
    'every line touched, none emptied' => [[4, 4], [1, 1]],
]);

test('a rejected refund cancels nothing, writes nothing and dispatches nothing', function () {
    Event::fake([OrderFullyRefunded::class]);
    autoCancelActor();
    [$order, [$first, $second, $third]] = paidOrderWithLines([1, 1, 1]);

    expect(fn () => app(RecordRefund::class)($order, [$first->id => 1, $second->id => 1, $third->id => 2]))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and((int) OrderItem::where('order_id', $order->id)->sum('refunded_quantity'))->toBe(0);
    Event::assertNotDispatched(OrderFullyRefunded::class);
});

test('a refund refused for lack of permission cancels nothing', function () {
    autoCancelActor(canRefund: false);
    [$order, [$item]] = paidOrderWithLines([1]);

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 1]))->toThrow(AuthorizationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('a refund whose transaction rolls back cancels nothing and dispatches nothing', function () {
    Event::fake([OrderFullyRefunded::class]);
    autoCancelActor();
    [$order, [$item]] = paidOrderWithLines([1]);

    DB::listen(function ($query): void {
        if (str_starts_with($query->sql, 'update `orders`')) {
            throw new RuntimeException('forced failure');
        }
    });

    expect(fn () => app(RecordRefund::class)($order, [$item->id => 1]))->toThrow(RuntimeException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($item->fresh()->refunded_quantity)->toBe(0)
        ->and(DB::table('refunds')->count())->toBe(0);
    Event::assertNotDispatched(OrderFullyRefunded::class);
});

test('a repeated trigger against an already cancelled order changes nothing, not even updated_at', function () {
    [$order] = paidOrderWithLines([1]);

    app(AutoCancelFullyRefundedOrder::class)($order->id);
    $this->travel(5)->minutes();
    $updatedAt = $order->fresh()->updated_at;

    event(new OrderFullyRefunded($order->id));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('the action returns silently for a missing order', function () {
    app(AutoCancelFullyRefundedOrder::class)('00000000-0000-7000-8000-000000000000');

    expect(Order::query()->count())->toBe(0);
});

test('the listener is registered exactly once', function () {
    expect(Event::getListeners(OrderFullyRefunded::class))->toHaveCount(1);
});
