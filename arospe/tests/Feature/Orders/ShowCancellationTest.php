<?php

// Story 0055 -- manual cancellation (0050). The Cancel hint is Gate::allows('cancel', $order), which is
// orders.edit AND orders.refund (0050 D-6 -- amendment 1) AND Order::isManuallyCancellable(). The
// component re-derives neither the status set nor the PartiallyRefunded exclusion.
//
// ⚠️ Every disabled assertion uses a NON-Super-Admin actor holding BOTH abilities, so the only reason
// a control can be disabled is the ORDER STATE -- the other dimension is covered in ShowTest's matrix.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function ordersUiCanceller(): void
{
    test()->actingAs(OrdersUi::actor(OrdersUi::PROFILES['both']));
}

// The cross-DIMENSION dataset. The PartiallyRefunded cells are the highest-risk ones: a hint written as
// "is status in {Pending, Processing}" passes every other row (0050 R-1, arriving one layer up).
dataset('orders_ui_cancel_states', [
    'Pending / Paid' => [OrderStatus::Pending, PaymentStatus::Paid, true],
    'Pending / PendingPayment' => [OrderStatus::Pending, PaymentStatus::PendingPayment, true],
    'Processing / Paid' => [OrderStatus::Processing, PaymentStatus::Paid, true],
    'Processing / PendingPayment' => [OrderStatus::Processing, PaymentStatus::PendingPayment, true],
    'Shipped / Paid' => [OrderStatus::Shipped, PaymentStatus::Paid, false],
    'Delivered / Paid' => [OrderStatus::Delivered, PaymentStatus::Paid, false],
    'Cancelled / Paid' => [OrderStatus::Cancelled, PaymentStatus::Paid, false],
    'Pending / PartiallyRefunded' => [OrderStatus::Pending, PaymentStatus::PartiallyRefunded, false],
    'Processing / PartiallyRefunded' => [OrderStatus::Processing, PaymentStatus::PartiallyRefunded, false],
]);

test('the cancel control renders enabled or disabled per BOTH status dimensions', function (OrderStatus $status, PaymentStatus $payment, bool $enabled) {
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->create(['status' => $status, 'payment_status' => $payment]);

    $html = Livewire::test(Show::class, ['order' => $order])->html();

    // Same hook on both branches.
    expect(OrdersUi::present($html, 'cancel-order'))->toBeTrue()
        ->and(OrdersUi::enabled($html, 'cancel-order'))->toBe($enabled);
})->with('orders_ui_cancel_states');

test('cancelling opens the confirmation dialog before writing: cancellation is irreversible (D-9)', function () {
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->assertSet('showCancelConfirm', true);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(OrdersUi::present($component->html(), 'confirm-dialog-cancel-order'))->toBeTrue();
});

test('confirming the dialog cancels the order and the page shows Cancelled', function () {
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder')
        ->assertHasNoErrors()
        ->assertSet('showCancelConfirm', false);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($component->html())->toContain(__('orders.statuses.cancelled'));
});

test('dismissing the cancel dialog leaves the order alone', function () {
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('dismissCancelConfirm')
        ->assertSet('showCancelConfirm', false);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('cancelling a paid order leaves payment_status visibly unchanged: a cancelled paid order legitimately reads Cancelled / Paid (0050 D-1)', function () {
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->paid()->create(['status' => OrderStatus::Processing]);

    $html = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder')
        ->html();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($html)->toContain(__('orders.statuses.cancelled'))
        ->toContain(__('orders.payment_statuses.paid'));
});

test('an ordinary actor forging cancelOrder against a Shipped order is refused by the policy, and nothing is written', function () {
    $this->withoutExceptionHandling();
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->call('cancelOrder'))
        ->toThrow(AuthorizationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

// ⚠️ DOCUMENTED DRIFT, not correct behaviour. Gate::before grants a Super Admin every ability, so
// OrderPolicy::cancel()'s state clause is INERT for that actor: the control renders ENABLED against a
// Shipped order and the click is refused by CancelOrder's own direct throw (a 409). That is the
// enabled-then-refused shape 0050 records and docs/architecture/authorization.md lists -- always in that
// direction, never the reverse. Pinned here so a later story cannot silently "fix" the wrong half.
test('DOCUMENTED DRIFT: for a Super Admin the cancel control renders enabled on a Shipped order and the click renders the 409 message', function () {
    $this->actingAs(OrdersUi::superAdmin());

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);

    $component = Livewire::test(Show::class, ['order' => $order]);

    expect(OrdersUi::enabled($component->html(), 'cancel-order'))->toBeTrue();

    $component->call('cancelOrder')
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.cancellation.blocked'));

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});
