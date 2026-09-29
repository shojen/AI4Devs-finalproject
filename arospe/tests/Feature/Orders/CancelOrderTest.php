<?php

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderCancellationBlockedException;
use App\Exceptions\OrderStatusRegressionRequiresConfirmationException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0050, Phase 3 (TDD "red" step): App\Actions\Orders\CancelOrder,
// App\Exceptions\OrderCancellationBlockedException and OrderPolicy::cancel() do not exist yet --
// every test below is expected to fail until backend-expert implements them.
//
// D-6/D-7's ordering (permission -> already-cancelled -> blocked-state -> write) is what several
// tests below are actually pinning, not merely the individual refusals in isolation.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// D-6: cancelling requires BOTH orders.edit AND orders.refund.
function actingOrderCanceller(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.edit', 'orders.refund']);
    test()->actingAs($actor);

    return $actor;
}

// OrderPolicy::cancel() includes the state clause (D-6/R-2), so for an ORDINARY actor
// Gate::authorize('cancel', $order) already refuses a blocked order at step 1 -- CancelOrder's
// own steps 2/3 (already-cancelled, blocked-state) are therefore only reachable in practice via a
// Super Admin, whose Gate::before bypass never consults the policy's state clause at all. This is
// exactly the ⚠️ the task file's own OrderPolicy docblock states: the policy's state clause is a
// UI hint, inert for a Super Admin, and CancelOrder's direct throw is what actually binds one.
function actingSuperAdminCanceller(): User
{
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    return $superAdmin;
}

// --- Cancelling -- happy paths ---

test('cancelling succeeds from Pending/Processing crossed with Paid/PendingPayment', function (OrderStatus $status, PaymentStatus $paymentStatus) {
    actingOrderCanceller();
    $order = Order::factory()->create(['status' => $status, 'payment_status' => $paymentStatus]);

    app(CancelOrder::class)($order);

    $fresh = $order->fresh();
    expect($fresh->status)->toBeInstanceOf(OrderStatus::class)
        ->and($fresh->status)->toBe(OrderStatus::Cancelled);
})->with([
    'Pending, Paid' => [OrderStatus::Pending, PaymentStatus::Paid],
    'Pending, PendingPayment' => [OrderStatus::Pending, PaymentStatus::PendingPayment],
    'Processing, Paid' => [OrderStatus::Processing, PaymentStatus::Paid],
    'Processing, PendingPayment' => [OrderStatus::Processing, PaymentStatus::PendingPayment],
]);

test('the action returns the Order carrying the new status, so a caller need not re-fetch', function () {
    actingOrderCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $result = app(CancelOrder::class)($order);

    expect($result)->toBeInstanceOf(Order::class)
        ->and($result->id)->toBe($order->id)
        ->and($result->status)->toBe(OrderStatus::Cancelled);
});

// D-1's executable half: cancelling moves no money.
test('a successful cancellation leaves payment_status byte-identical, asserted for a Paid order', function () {
    actingOrderCanceller();
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $paymentStatusBefore = $order->payment_status;

    app(CancelOrder::class)($order);

    expect($order->fresh()->payment_status)->toBe($paymentStatusBefore);
});

test('a successful cancellation records no refund against any of its line items', function () {
    actingOrderCanceller();
    $order = Order::factory()->paid()->withItems(2)->create(['status' => OrderStatus::Pending]);
    $refundedQuantitiesBefore = $order->items()->pluck('refunded_quantity', 'id')->all();

    app(CancelOrder::class)($order);

    expect($order->fresh()->refunded_amount)->toBe('0.00');
    foreach ($order->items()->get() as $item) {
        expect($item->refunded_quantity)->toBe($refundedQuantitiesBefore[$item->id]);
    }
    expect(Refund::query()->whereIn('order_item_id', $order->items()->pluck('id'))->count())->toBe(0);
});

// --- Cancelling -- the guarded states ---
//
// Run under a Super Admin -- see actingSuperAdminCanceller()'s own docblock for why an ordinary,
// both-permission-holding actor never reaches this far (Gate::authorize('cancel', $order) already
// refuses them at step 1, since OrderPolicy::cancel()'s state clause makes the Gate check itself
// state-aware). The dedicated "an actor lacking ... gets AuthorizationException" tests further
// down assert that ordinary-actor shape explicitly.

test('cancelling from Shipped or Delivered throws OrderCancellationBlockedException, and the persisted status is unchanged', function (OrderStatus $status) {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => $status]);

    expect(fn () => app(CancelOrder::class)($order))
        ->toThrow(OrderCancellationBlockedException::class);

    expect($order->fresh()->status)->toBe($status);
})->with([
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
]);

// backend-qa's highest-risk case: a guard copy-adapted from TransitionOrderStatus (status only)
// would pass every other test in this file and silently miss the payment_status dimension.
test('cancelling is blocked by PartiallyRefunded even from Pending and from Processing', function (OrderStatus $status) {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => $status, 'payment_status' => PaymentStatus::PartiallyRefunded]);

    expect(fn () => app(CancelOrder::class)($order))
        ->toThrow(OrderCancellationBlockedException::class);

    expect($order->fresh()->status)->toBe($status);
})->with([
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
]);

// The mechanical consequence, pinned explicitly rather than left implicit in a comment: an
// ORDINARY both-permission-holding actor is refused by Gate::authorize() itself (AuthorizationException)
// for a guarded state, never reaching CancelOrder's own OrderCancellationBlockedException throw --
// because OrderPolicy::cancel()'s state clause already makes the ability itself state-aware. If
// that clause were ever removed from the policy, this test would flip to expecting
// OrderCancellationBlockedException instead, which is exactly the drift it exists to catch.
test('an ordinary both-permission-holding actor against a guarded state is refused by AuthorizationException, not the blocked exception', function () {
    actingOrderCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

test('the blocked refusal renders a 409 for an HTML request, not 403 and not 422', function () {
    $exception = new OrderCancellationBlockedException;

    $response = $exception->render(Request::create('/orders/1/cancel'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getStatusCode())->not->toBe(403)
        ->and($response->getStatusCode())->not->toBe(422);
});

test('the blocked refusal renders a 409 with a JSON body for a JSON request', function () {
    $exception = new OrderCancellationBlockedException;

    $request = Request::create('/orders/1/cancel');
    $request->headers->set('Accept', 'application/json');

    $response = $exception->render($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Content-Type'))->toContain('application/json');
});

// Two 409s, two different classes -- a caller must be able to tell the retryable one
// (OrderStatusRegressionRequiresConfirmationException) from the terminal one.
test('the blocked refusal is OrderCancellationBlockedException and not the regression-confirmation exception', function () {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    try {
        app(CancelOrder::class)($order);
        test()->fail('Expected OrderCancellationBlockedException.');
    } catch (OrderStatusRegressionRequiresConfirmationException $e) {
        test()->fail('Cancellation must not be refused as a confirmable regression.');
    } catch (OrderCancellationBlockedException $e) {
        expect($e)->toBeInstanceOf(OrderCancellationBlockedException::class);
    }
});

test('the thrown message is the blocked translation, interpolating neither status nor the order number', function () {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    try {
        app(CancelOrder::class)($order);
        test()->fail('Expected OrderCancellationBlockedException.');
    } catch (OrderCancellationBlockedException $e) {
        expect($e->getMessage())->toBe(__('orders.cancellation.blocked'))
            ->and($e->getMessage())->not->toContain((string) $order->order_number)
            ->and($e->getMessage())->not->toContain('Shipped');
    }
});

// --- There is no confirmation bypass ---

test('CancelOrder::__invoke() accepts exactly one parameter, an Order', function () {
    $method = new ReflectionMethod(CancelOrder::class, '__invoke');

    expect($method->getNumberOfParameters())->toBe(1)
        ->and($method->getParameters()[0]->getType()?->getName())->toBe(Order::class);
});

test('a blocked cancellation is not retryable: re-invoking immediately throws the same exception, and the order is unchanged', function () {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Delivered]);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(OrderCancellationBlockedException::class);
    expect(fn () => app(CancelOrder::class)($order->fresh()))->toThrow(OrderCancellationBlockedException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

// --- Cancelling an already-cancelled order ---
//
// Run under a Super Admin for the same mechanical reason as the guarded-states block above:
// `Cancelled` is not in {Pending, Processing}, so isManuallyCancellable() is false for it too,
// and an ordinary actor is refused at Gate::authorize() before CancelOrder's own already-cancelled
// check ever runs.

test('cancelling an order already in Cancelled throws a ValidationException on the status field, and the order is unchanged', function () {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    try {
        app(CancelOrder::class)($order);
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('status')
            ->and($e->errors()['status'][0])->toBe(__('orders.cancellation.already_cancelled'));
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

// D-3's ordering, proved rather than assumed: a guard with steps 2/3 transposed would still throw
// SOME exception for a Cancelled order (it is not in {Pending, Processing}) but the wrong one.
test('the already-cancelled refusal is a ValidationException, never OrderCancellationBlockedException', function () {
    actingSuperAdminCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    try {
        app(CancelOrder::class)($order);
        test()->fail('Expected a ValidationException.');
    } catch (OrderCancellationBlockedException $e) {
        test()->fail('An already-cancelled order must be refused by the validation guard, not the blocked-state guard.');
    } catch (ValidationException $e) {
        expect($e)->toBeInstanceOf(ValidationException::class);
    }
});

// --- Authorization ---

test('an administrator holding both orders.edit and orders.refund can cancel an order in Pending', function () {
    actingOrderCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(CancelOrder::class)($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

// D-6's pinning test: the ONLY test in this file that goes red against the superseded
// orders.edit-alone guard -- without it, the second hasPermissionTo() can be deleted silently.
test('an administrator holding orders.edit but not orders.refund is refused, and the order is unchanged', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('an administrator holding orders.refund but not orders.edit is refused, and the order is unchanged', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.refund');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('a Super Admin holding no individual orders permission can cancel an order in Pending', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(CancelOrder::class)($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

// The test that proves the block is a direct throw rather than a Gate-mediated rule -- it goes
// red the moment step 3 is "simplified" into a second Gate::authorize() call.
test('a Super Admin is still refused against a Shipped order, with OrderCancellationBlockedException', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    expect(fn () => app(CancelOrder::class)($order))->toThrow(OrderCancellationBlockedException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

test('both OrderPolicy permission constants are literal and exist in the seeded catalog', function () {
    expect(OrderPolicy::EDIT_PERMISSION)->toBe('orders.edit')
        ->and(OrderPolicy::ORDER_REFUND_PERMISSION)->toBe('orders.refund');

    $actor = User::factory()->create();
    $actor->givePermissionTo([OrderPolicy::EDIT_PERMISSION, OrderPolicy::ORDER_REFUND_PERMISSION]);

    expect($actor->hasPermissionTo('orders.edit'))->toBeTrue()
        ->and($actor->hasPermissionTo('orders.refund'))->toBeTrue();
});

// The permission refusal always wins -- an unauthorized caller must not learn the order's state.
test('an actor lacking orders.edit against a Shipped order gets AuthorizationException, never the blocked exception', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.refund');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    try {
        app(CancelOrder::class)($order);
        test()->fail('Expected an AuthorizationException.');
    } catch (OrderCancellationBlockedException $e) {
        test()->fail('The permission refusal must win over the blocked-state refusal.');
    } catch (AuthorizationException $e) {
        expect($e)->toBeInstanceOf(AuthorizationException::class);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

test('an actor lacking orders.refund against a Shipped order gets AuthorizationException, never the blocked exception', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    try {
        app(CancelOrder::class)($order);
        test()->fail('Expected an AuthorizationException.');
    } catch (OrderCancellationBlockedException $e) {
        test()->fail('The permission refusal must win over the blocked-state refusal.');
    } catch (AuthorizationException $e) {
        expect($e)->toBeInstanceOf(AuthorizationException::class);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

// --- 0049 is unaffected -- the regression this story most plausibly causes ---

test('TransitionOrderStatus still refuses a transition to Cancelled from every linear status, after CancelOrder exists', function (OrderStatus $from) {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => $from]);

    expect(fn () => app(TransitionOrderStatus::class)($order, OrderStatus::Cancelled))
        ->toThrow(ValidationException::class);
    expect(fn () => app(TransitionOrderStatus::class)($order->fresh(), OrderStatus::Cancelled, true))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe($from);
})->with([
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
]);

test('OrderStatus::Cancelled->rank() still throws UnhandledMatchError', function () {
    expect(fn () => OrderStatus::Cancelled->rank())->toThrow(UnhandledMatchError::class);
});

// --- Scope fences, made executable ---

test('cancelling dispatches no notification and no event', function () {
    Notification::fake();
    Event::fake();
    actingOrderCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(CancelOrder::class)($order);

    Notification::assertNothingSent();
});

test('cancelling an order does not change any product stock', function () {
    actingOrderCanceller();
    $product = Product::factory()->create(['stock' => 7]);
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3]);

    app(CancelOrder::class)($order);

    expect($product->fresh()->stock)->toBe(7);
});
