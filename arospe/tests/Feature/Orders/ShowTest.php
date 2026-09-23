<?php

// Story 0055 -- App\Livewire\Orders\Show: route + access + per-control authorization. Per
// docs/testing/README.md a Livewire::test() authorization test and an HTTP one are NOT substitutes:
// route middleware and the in-component gate fail in different places, and /livewire/update never
// re-runs every route middleware.
//
// ⚠️ Every disabled-state assertion here runs against a NON-Super-Admin actor. Gate::before grants a
// Super Admin every ability, so a disabled assertion written with a Super Admin fixture is a
// guaranteed false negative (R-2).

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// =====================================================================
// HTTP layer -- the route gate
// =====================================================================

test('a signed-out visitor requesting an order detail is redirected to login', function () {
    $order = Order::factory()->withItems()->create();

    $this->get(route('orders.show', $order))->assertRedirect(route('login'));
});

test('a signed-in user holding no orders permission is forbidden from an order detail', function () {
    $order = Order::factory()->withItems()->create();
    $this->actingAs(OrdersUi::actor([]));

    $this->get(route('orders.show', $order))->assertForbidden();
});

test('a user holding exactly orders.view gets the order detail, and can do nothing on it (D-5)', function () {
    $order = Order::factory()->withItems()->create();
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $this->get(route('orders.show', $order))->assertOk();
});

test('a Super Admin holding zero permission rows gets the order detail through Gate::before', function () {
    $order = Order::factory()->withItems()->create();
    $superAdmin = OrdersUi::superAdmin();
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('orders.show', $order))->assertOk();
});

test('the orders.show route is gated by exactly can:orders.view -- widening it to orders.edit must be deliberate (D-5)', function () {
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'orders.show');

    expect($route)->not->toBeNull();

    $canMiddleware = collect($route->gatherMiddleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'can:'))
        ->values()
        ->all();

    expect($canMiddleware)->toBe(['can:orders.view']);
});

test('GET /orders still resolves to orders.index once the parameterised route is registered', function () {
    $route = app('router')->getRoutes()->match(Request::create('/orders', 'GET'));

    expect($route->getName())->toBe('orders.index');
});

test('a malformed, non-UUID order segment is not found', function () {
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $this->get('/orders/not-a-uuid')->assertNotFound();
});

test('an unknown but well-formed order reference is reported as not found', function () {
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $this->get('/orders/'.fake()->uuid())->assertNotFound();
});

// =====================================================================
// Component layer -- the in-component gate
// =====================================================================

test('mounting the Show component without orders.view throws AuthorizationException', function () {
    // withoutExceptionHandling() is required: mount(Order $order) is model-bound, and Livewire's
    // harness otherwise renders the AuthorizationException into a 403 response.
    $this->withoutExceptionHandling();

    $order = Order::factory()->withItems()->create();
    $this->actingAs(OrdersUi::actor([]));

    expect(fn () => Livewire::test(Show::class, ['order' => $order]))->toThrow(AuthorizationException::class);
});

test('orderId is #[Locked]: the client cannot re-point the component at another order', function () {
    $order = Order::factory()->withItems()->create();
    $other = Order::factory()->withItems()->create();
    $this->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->set('orderId', $other->id))
        ->toThrow('Cannot update locked property');
});

test('pendingStatus is #[Locked]: the client cannot forge which status a confirmation would apply', function () {
    $order = Order::factory()->withItems()->create(['status' => OrderStatus::Shipped]);
    $this->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->set('pendingStatus', 'pending'))
        ->toThrow('Cannot update locked property');
});

// Every mutating AND every disclosing method authorizes as its FIRST statement -- the dialog
// openers included, since opening a dialog discloses state (docs/security/livewire-authorization.md).
test('an actor holding only orders.view is refused by every mutating and disclosing method', function (string $method, array $arguments) {
    $this->withoutExceptionHandling();

    $order = Order::factory()->withItems(2)->paid()->create(['status' => OrderStatus::Processing]);
    $item = $order->items()->first();
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $component = Livewire::test(Show::class, ['order' => $order]);
    $arguments = array_map(fn ($argument) => $argument === '{item}' ? $item->id : $argument, $arguments);

    expect(fn () => $component->call($method, ...$arguments))->toThrow(AuthorizationException::class);
})->with([
    'addLineItem' => ['addLineItem', []],
    'removeLineItem' => ['removeLineItem', ['{item}']],
    'updateLineItemQuantity' => ['updateLineItemQuantity', ['{item}']],
    'requestStatusChange' => ['requestStatusChange', []],
    'applyStatusChange' => ['applyStatusChange', []],
    'confirmCancel' => ['confirmCancel', []],
    'cancelOrder' => ['cancelOrder', []],
    'openRefundModal' => ['openRefundModal', []],
    'recordRefund' => ['recordRefund', []],
]);

// =====================================================================
// The per-control authorization DATASET -- four actors x the whole control set.
//
// A dataset rather than four hand-written tests because the interesting cells are the MIXED ones: an
// implementation gating every control on one flag passes both single-ability rows and fails only the
// mixed pair. Cancel needs orders.edit AND orders.refund (0050 D-6 -- amendment 1), so it is enabled
// in the `both` row ONLY.
// =====================================================================

dataset('orders_ui_control_matrix', [
    'edit-only' => ['edit-only', ['lines' => true, 'status' => true, 'cancel' => false, 'refund' => false]],
    'refund-only' => ['refund-only', ['lines' => false, 'status' => false, 'cancel' => false, 'refund' => true]],
    'both' => ['both', ['lines' => true, 'status' => true, 'cancel' => true, 'refund' => true]],
    'view-only' => ['view-only', ['lines' => false, 'status' => false, 'cancel' => false, 'refund' => false]],
]);

test('every control renders enabled or disabled per the actor profile, on a Processing / Paid order', function (string $profile, array $expected) {
    $order = Order::factory()->withItems(2)->paid()->create(['status' => OrderStatus::Processing]);
    $item = $order->items()->first();
    $this->actingAs(OrdersUi::actor(OrdersUi::PROFILES[$profile]));

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    $controls = [
        'lines' => ['add-line-item', 'remove-line-item-'.$item->id, 'save-quantity-'.$item->id],
        'status' => ['status-select', 'apply-status'],
        'cancel' => ['cancel-order'],
        'refund' => ['record-refund'],
    ];

    foreach ($controls as $group => $hooks) {
        foreach ($hooks as $hook) {
            // The hook is on BOTH branches, so the browser selects the same way either way.
            expect(OrdersUi::present($html, $hook))->toBeTrue("{$hook} must be present on both branches ({$profile})");
            expect(OrdersUi::enabled($html, $hook))->toBe($expected[$group], "{$hook} enabled state for {$profile}");
        }
    }
})->with('orders_ui_control_matrix');

test('an actor holding orders.refund but not orders.edit can record a refund and cannot edit line items (independence, refund direction)', function () {
    $order = Order::factory()->paid()->create(['status' => OrderStatus::Processing]);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 1]);
    $this->actingAs(OrdersUi::actor(['orders.view', 'orders.refund']));

    Livewire::test(Show::class, ['order' => $order])
        ->set("refundQuantities.{$item->id}", 1)
        ->call('recordRefund')
        ->assertHasNoErrors();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);

    $this->withoutExceptionHandling();
    expect(fn () => Livewire::test(Show::class, ['order' => $order])->call('removeLineItem', $item->id))
        ->toThrow(AuthorizationException::class);
});

test('an actor holding orders.edit but not orders.refund can edit line items and cannot record a refund', function () {
    $this->withoutExceptionHandling();

    $order = Order::factory()->withItems(2)->paid()->create(['status' => OrderStatus::Processing]);
    $item = $order->items()->first();
    $this->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));

    Livewire::test(Show::class, ['order' => $order])
        ->call('removeLineItem', $item->id)
        ->assertHasNoErrors();

    expect($order->items()->count())->toBe(1);

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->call('recordRefund'))
        ->toThrow(AuthorizationException::class);
});
