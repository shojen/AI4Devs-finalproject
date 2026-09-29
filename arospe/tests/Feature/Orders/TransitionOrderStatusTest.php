<?php

use App\Actions\Orders\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Exceptions\OrderStatusRegressionRequiresConfirmationException;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0049, Phase 3 (TDD "red" step): none of App\Actions\Orders\TransitionOrderStatus,
// App\Enums\OrderStatus::rank()/isBackwardFrom(), App\Exceptions\
// OrderStatusRegressionRequiresConfirmationException, OrderPolicy::transitionStatus() or the
// lang/{en,es}/orders.php `transitions` key group exist yet -- every test below is expected to
// fail (mostly "Target class [TransitionOrderStatus] does not exist", a handful with a different
// but equally real cause noted inline) until backend-expert implements them.
//
// D-3's five-step ordering (permission -> cancellation -> same-status -> regression -> write) is
// what several tests below are actually pinning, not merely the individual refusals in isolation.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderEditorForTransition(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    return $actor;
}

// --- Advancing -- happy paths ---

test('a forward adjacent transition succeeds unconfirmed, and the refetched order carries the new status as an enum instance', function (OrderStatus $from, OrderStatus $to) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $from]);

    app(TransitionOrderStatus::class)($order, $to);

    $fresh = $order->fresh();
    expect($fresh->status)->toBeInstanceOf(OrderStatus::class)
        ->and($fresh->status)->toBe($to);
})->with([
    'Pending -> Processing' => [OrderStatus::Pending, OrderStatus::Processing],
    'Processing -> Shipped' => [OrderStatus::Processing, OrderStatus::Shipped],
    'Shipped -> Delivered' => [OrderStatus::Shipped, OrderStatus::Delivered],
]);

// D-8: a forward move may skip an intermediate status with no confirmation -- tested explicitly
// rather than assumed, since an implementation that quietly required adjacency would pass every
// adjacent-pair test above.
test('a forward skip succeeds unconfirmed, proving the rule is about direction rather than distance', function (OrderStatus $from, OrderStatus $to) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $from]);

    app(TransitionOrderStatus::class)($order, $to);

    expect($order->fresh()->status)->toBe($to);
})->with([
    'Pending -> Shipped' => [OrderStatus::Pending, OrderStatus::Shipped],
    'Pending -> Delivered' => [OrderStatus::Pending, OrderStatus::Delivered],
    'Processing -> Delivered' => [OrderStatus::Processing, OrderStatus::Delivered],
]);

test('the action returns the Order carrying the new status, so a caller need not re-fetch', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $result = app(TransitionOrderStatus::class)($order, OrderStatus::Processing);

    expect($result)->toBeInstanceOf(Order::class)
        ->and($result->id)->toBe($order->id)
        ->and($result->status)->toBe(OrderStatus::Processing);
});

test('a successful transition leaves payment_status untouched', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Pending]);
    $paymentStatusBefore = $order->payment_status;

    app(TransitionOrderStatus::class)($order, OrderStatus::Processing);

    expect($order->fresh()->payment_status)->toBe($paymentStatusBefore);
});

// --- Moving backward ---

test('a backward adjacent transition unconfirmed throws the regression exception, and the persisted status is unchanged', function (OrderStatus $from, OrderStatus $to) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $from]);

    expect(fn () => app(TransitionOrderStatus::class)($order, $to))
        ->toThrow(OrderStatusRegressionRequiresConfirmationException::class);

    expect($order->fresh()->status)->toBe($from);
})->with([
    'Processing -> Pending' => [OrderStatus::Processing, OrderStatus::Pending],
    'Shipped -> Processing' => [OrderStatus::Shipped, OrderStatus::Processing],
    'Delivered -> Shipped' => [OrderStatus::Delivered, OrderStatus::Shipped],
]);

// R-3's mitigation: a suite that only proves the refusal would stay green against an
// implementation where confirmed: true also refuses (an inverted `!`) -- asserting the PERSISTED
// status, not the returned instance, is what a write-then-throw implementation could not fake.
test('the same backward adjacent pairs succeed once confirmed, and the persisted status is the earlier one', function (OrderStatus $from, OrderStatus $to) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $from]);

    app(TransitionOrderStatus::class)($order, $to, true);

    expect($order->fresh()->status)->toBe($to);
})->with([
    'Processing -> Pending' => [OrderStatus::Processing, OrderStatus::Pending],
    'Shipped -> Processing' => [OrderStatus::Shipped, OrderStatus::Processing],
    'Delivered -> Shipped' => [OrderStatus::Delivered, OrderStatus::Shipped],
]);

test('a backward skip (Delivered to Pending) is refused unconfirmed and succeeds once confirmed', function () {
    actingOrderEditorForTransition();

    $unconfirmedOrder = Order::factory()->create(['status' => OrderStatus::Delivered]);
    expect(fn () => app(TransitionOrderStatus::class)($unconfirmedOrder, OrderStatus::Pending))
        ->toThrow(OrderStatusRegressionRequiresConfirmationException::class);
    expect($unconfirmedOrder->fresh()->status)->toBe(OrderStatus::Delivered);

    $confirmedOrder = Order::factory()->create(['status' => OrderStatus::Delivered]);
    app(TransitionOrderStatus::class)($confirmedOrder, OrderStatus::Pending, true);
    expect($confirmedOrder->fresh()->status)->toBe(OrderStatus::Pending);
});

// D-2: 409, deliberately not 423 (a credential-freshness refusal) and not 403 (an authorization
// refusal) -- asserted through the exception's own render(), exactly as
// tests/Unit/Exceptions/OrderNotEditableExceptionTest.php already does for its own 409 sibling.
test('the regression exception renders a 409 for an HTML request, not 423 and not 403', function () {
    $exception = new OrderStatusRegressionRequiresConfirmationException;

    $response = $exception->render(Request::create('/orders/1/status'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getStatusCode())->not->toBe(423)
        ->and($response->getStatusCode())->not->toBe(403);
});

test('the regression exception renders a 409 with a JSON body for a JSON request, not 423 and not 403', function () {
    $exception = new OrderStatusRegressionRequiresConfirmationException;

    $request = Request::create('/orders/1/status');
    $request->headers->set('Accept', 'application/json');

    $response = $exception->render($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getStatusCode())->not->toBe(423)
        ->and($response->getStatusCode())->not->toBe(403)
        ->and($response->headers->get('Content-Type'))->toContain('application/json');
});

// The message is a constant resolved from lang/{en,es}/orders.php, never interpolated with
// either status or the order's number -- the message-is-a-constant rule from
// docs/architecture/authorization.md.
test('the regression exception message equals the requires_confirmation translation, with no interpolation', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    try {
        app(TransitionOrderStatus::class)($order, OrderStatus::Pending);
        test()->fail('Expected OrderStatusRegressionRequiresConfirmationException to be thrown.');
    } catch (OrderStatusRegressionRequiresConfirmationException $e) {
        expect($e->getMessage())->toBe(__('orders.transitions.requires_confirmation'))
            ->and($e->getMessage())->not->toContain((string) $order->order_number)
            ->and($e->getMessage())->not->toContain('Shipped')
            ->and($e->getMessage())->not->toContain('Pending');
    }
});

// --- Transitioning to the current status ---

test('the same-status ValidationException carries the refusal on the status field', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Processing]);

    try {
        app(TransitionOrderStatus::class)($order, OrderStatus::Processing);
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('status');
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

// D-4: confirmation is not a way past it -- the two refusals are different in kind.
test('confirming does not make a same-status transition valid', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Processing]);

    expect(fn () => app(TransitionOrderStatus::class)($order, OrderStatus::Processing, true))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

test('the identity transition is rejected from all four linear statuses', function (OrderStatus $status) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $status]);

    expect(fn () => app(TransitionOrderStatus::class)($order, $status))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe($status);
})->with([
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
]);

// --- Cancelled is refused in both directions -- the non-scope guard (D-6) ---

test('transitioning to Cancelled is refused outright from every linear status, confirmed and unconfirmed', function (OrderStatus $from, bool $confirmed) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $from]);

    expect(fn () => app(TransitionOrderStatus::class)($order, OrderStatus::Cancelled, $confirmed))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe($from);
})->with([
    'Pending, unconfirmed' => [OrderStatus::Pending, false],
    'Pending, confirmed' => [OrderStatus::Pending, true],
    'Processing, unconfirmed' => [OrderStatus::Processing, false],
    'Processing, confirmed' => [OrderStatus::Processing, true],
    'Shipped, unconfirmed' => [OrderStatus::Shipped, false],
    'Shipped, confirmed' => [OrderStatus::Shipped, true],
    'Delivered, unconfirmed' => [OrderStatus::Delivered, false],
    'Delivered, confirmed' => [OrderStatus::Delivered, true],
]);

test('transitioning away from Cancelled is refused outright to every linear status, confirmed and unconfirmed', function (OrderStatus $to, bool $confirmed) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    expect(fn () => app(TransitionOrderStatus::class)($order, $to, $confirmed))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
})->with([
    'Pending, unconfirmed' => [OrderStatus::Pending, false],
    'Pending, confirmed' => [OrderStatus::Pending, true],
    'Processing, unconfirmed' => [OrderStatus::Processing, false],
    'Processing, confirmed' => [OrderStatus::Processing, true],
    'Shipped, unconfirmed' => [OrderStatus::Shipped, false],
    'Shipped, confirmed' => [OrderStatus::Shipped, true],
    'Delivered, unconfirmed' => [OrderStatus::Delivered, false],
    'Delivered, confirmed' => [OrderStatus::Delivered, true],
]);

// D-3: same-status runs ABOVE the regression check, so a same-status check running first would
// reach rank() for the other Cancelled cases -- but Cancelled -> Cancelled is caught by the
// cancellation guard (step 2), which must run BEFORE same-status (step 3). Distinguished here by
// message, since both are (per D-6's recommended shape) a ValidationException on the same field.
test('Cancelled to Cancelled is refused by the cancellation guard, not by the same-status validation', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    try {
        app(TransitionOrderStatus::class)($order, OrderStatus::Cancelled);
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        $message = $e->errors()['status'][0] ?? null;

        expect($message)->toBe(__('orders.transitions.cancellation_unsupported'))
            ->and($message)->not->toBe(__('orders.transitions.same_status'));
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

// A caller must not be able to retry the cancellation refusal with confirmed: true and succeed --
// the strict ValidationException::class match in the two dataset tests above already proves this
// (a regression exception would fail that assertion), stated here explicitly for the one case
// that combines both guards.
test('the cancellation refusal is never the regression-confirmation exception', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Processing]);

    try {
        app(TransitionOrderStatus::class)($order, OrderStatus::Cancelled);
        test()->fail('Expected a ValidationException.');
    } catch (OrderStatusRegressionRequiresConfirmationException $e) {
        test()->fail('Cancellation must not be refused as a confirmable regression.');
    } catch (ValidationException $e) {
        expect($e)->toBeInstanceOf(ValidationException::class);
    }
});

// R-1/D-3's structural proof: the guard at step 2 is what keeps rank() unreachable for a Cancelled
// value. If this were ever reordered below the regression/same-status checks, this test is the one
// that goes red -- catching UnhandledMatchError explicitly and failing on it, while any other
// refusal (including "class does not exist yet", pre-implementation) is left to propagate and fail
// the test for its own, unrelated reason.
test('no UnhandledMatchError escapes for any Cancelled-involving transition', function (OrderStatus $from, OrderStatus $to, bool $confirmed) {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => $from]);

    try {
        app(TransitionOrderStatus::class)($order, $to, $confirmed);
        test()->fail('Expected the Cancelled-involving transition to be refused.');
    } catch (UnhandledMatchError $e) {
        test()->fail('rank() was reached on a Cancelled value: '.$e->getMessage());
    } catch (ValidationException $e) {
        expect($e)->toBeInstanceOf(ValidationException::class);
    }
})->with([
    'Pending -> Cancelled, unconfirmed' => [OrderStatus::Pending, OrderStatus::Cancelled, false],
    'Pending -> Cancelled, confirmed' => [OrderStatus::Pending, OrderStatus::Cancelled, true],
    'Processing -> Cancelled, unconfirmed' => [OrderStatus::Processing, OrderStatus::Cancelled, false],
    'Processing -> Cancelled, confirmed' => [OrderStatus::Processing, OrderStatus::Cancelled, true],
    'Shipped -> Cancelled, unconfirmed' => [OrderStatus::Shipped, OrderStatus::Cancelled, false],
    'Shipped -> Cancelled, confirmed' => [OrderStatus::Shipped, OrderStatus::Cancelled, true],
    'Delivered -> Cancelled, unconfirmed' => [OrderStatus::Delivered, OrderStatus::Cancelled, false],
    'Delivered -> Cancelled, confirmed' => [OrderStatus::Delivered, OrderStatus::Cancelled, true],
    'Cancelled -> Pending, unconfirmed' => [OrderStatus::Cancelled, OrderStatus::Pending, false],
    'Cancelled -> Pending, confirmed' => [OrderStatus::Cancelled, OrderStatus::Pending, true],
    'Cancelled -> Processing, unconfirmed' => [OrderStatus::Cancelled, OrderStatus::Processing, false],
    'Cancelled -> Processing, confirmed' => [OrderStatus::Cancelled, OrderStatus::Processing, true],
    'Cancelled -> Shipped, unconfirmed' => [OrderStatus::Cancelled, OrderStatus::Shipped, false],
    'Cancelled -> Shipped, confirmed' => [OrderStatus::Cancelled, OrderStatus::Shipped, true],
    'Cancelled -> Delivered, unconfirmed' => [OrderStatus::Cancelled, OrderStatus::Delivered, false],
    'Cancelled -> Delivered, confirmed' => [OrderStatus::Cancelled, OrderStatus::Delivered, true],
    'Cancelled -> Cancelled, unconfirmed' => [OrderStatus::Cancelled, OrderStatus::Cancelled, false],
    'Cancelled -> Cancelled, confirmed' => [OrderStatus::Cancelled, OrderStatus::Cancelled, true],
]);

// --- Authorization ---

test('an actor lacking orders.edit is refused by TransitionOrderStatus with an AuthorizationException, and the order is unchanged', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    expect(fn () => app(TransitionOrderStatus::class)($order, OrderStatus::Processing))
        ->toThrow(AuthorizationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

// The positive case beside the 403 -- without which a mistyped ability passes silently.
test('an actor holding orders.edit succeeds', function () {
    actingOrderEditorForTransition();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(TransitionOrderStatus::class)($order, OrderStatus::Processing);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

test('a Super Admin holding no individual orders permission succeeds via Gate::before', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(TransitionOrderStatus::class)($order, OrderStatus::Processing);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

// R-2: the ability string is asserted literally against the seeded catalog, so a typo cannot fail
// closed unnoticed. D-7: EDIT_PERMISSION is reused -- no new permission is declared.
test('OrderPolicy::EDIT_PERMISSION equals orders.edit and is present in the seeded catalog', function () {
    expect(OrderPolicy::EDIT_PERMISSION)->toBe('orders.edit');
    expect(in_array('orders', RolePermissionSeeder::MODULES, true))->toBeTrue();

    $actor = User::factory()->create();
    $actor->givePermissionTo(OrderPolicy::EDIT_PERMISSION);

    expect($actor->hasPermissionTo('orders.edit'))->toBeTrue();
});

// D-3's "the permission refusal always wins" ordering rule, the critical test: an unauthorized
// caller must not learn the order's current status from a confirmation prompt. If step 1
// (permission) and step 4 (regression) were ever swapped, this is the test that goes red.
test('an actor lacking orders.edit attempting an unconfirmed backward transition gets AuthorizationException, never the confirmation exception', function () {
    $actor = User::factory()->create();
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    try {
        app(TransitionOrderStatus::class)($order, OrderStatus::Pending);
        test()->fail('Expected an AuthorizationException.');
    } catch (OrderStatusRegressionRequiresConfirmationException $e) {
        test()->fail('The permission refusal must win over the confirmation refusal.');
    } catch (AuthorizationException $e) {
        expect($e)->toBeInstanceOf(AuthorizationException::class);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});
