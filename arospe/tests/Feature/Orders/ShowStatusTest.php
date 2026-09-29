<?php

// Story 0055 -- status transitions (0049). A backward move is PREDICTED via OrderStatus::isBackwardFrom()
// (the predicate 0049's guard wraps, D-11), never discovered by catching the 409; the 409 catch survives
// only as defence in depth.

use App\Enums\OrderStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function ordersUiStatusEditor(): void
{
    test()->actingAs(OrdersUi::actor(['orders.view', 'orders.edit']));
}

function ordersUiStatusOrder(OrderStatus $status): Order
{
    return Order::factory()->withItems(1)->create(['status' => $status]);
}

// --- statusOptions() (D-8) ---

test('statusOptions never offers Cancelled and never offers the current status', function (OrderStatus $current) {
    ordersUiStatusEditor();

    $values = collect(Livewire::test(Show::class, ['order' => ordersUiStatusOrder($current)])->instance()->statusOptions())
        ->pluck('value')->all();

    expect($values)->not->toContain('cancelled')
        ->not->toContain($current->value)
        ->toHaveCount(3);
})->with([
    'Pending' => [OrderStatus::Pending],
    'Processing' => [OrderStatus::Processing],
    'Shipped' => [OrderStatus::Shipped],
    'Delivered' => [OrderStatus::Delivered],
]);

test('on a Cancelled order the status select renders disabled and statusOptions() is empty', function () {
    ordersUiStatusEditor();

    $component = Livewire::test(Show::class, ['order' => ordersUiStatusOrder(OrderStatus::Cancelled)]);

    expect($component->instance()->statusOptions())->toBe([])
        ->and(OrdersUi::disabled($component->html(), 'status-select'))->toBeTrue()
        ->and(OrdersUi::disabled($component->html(), 'apply-status'))->toBeTrue();
});

test('the status select binds a real backing-value string, defaulting to the order own status', function () {
    ordersUiStatusEditor();

    Livewire::test(Show::class, ['order' => ordersUiStatusOrder(OrderStatus::Processing)])
        ->assertSet('selectedStatus', 'processing');
});

// --- Forward: no confirmation ---

test('choosing a forward status and applying it changes the order WITHOUT opening a confirmation', function (OrderStatus $from, OrderStatus $to) {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder($from);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', $to->value)
        ->call('requestStatusChange')
        ->assertHasNoErrors()
        ->assertSet('showBackwardConfirm', false);

    expect($order->fresh()->status)->toBe($to)
        ->and(OrdersUi::present($component->html(), 'confirm-dialog-backward-transition'))->toBeFalse();
})->with([
    'Pending -> Processing' => [OrderStatus::Pending, OrderStatus::Processing],
    'Processing -> Shipped' => [OrderStatus::Processing, OrderStatus::Shipped],
    'Shipped -> Delivered' => [OrderStatus::Shipped, OrderStatus::Delivered],
]);

// --- Backward: confirmation first ---

test('choosing a backward status opens the confirmation dialog and does NOT write', function (OrderStatus $from, OrderStatus $to) {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder($from);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', $to->value)
        ->call('requestStatusChange')
        ->assertSet('showBackwardConfirm', true)
        ->assertSet('pendingStatus', $to->value);

    expect($order->fresh()->status)->toBe($from)
        ->and(OrdersUi::present($component->html(), 'confirm-dialog-backward-transition'))->toBeTrue();
})->with([
    'Processing -> Pending' => [OrderStatus::Processing, OrderStatus::Pending],
    'Shipped -> Processing' => [OrderStatus::Shipped, OrderStatus::Processing],
    'Delivered -> Shipped' => [OrderStatus::Delivered, OrderStatus::Shipped],
]);

test('a backward SKIP (Delivered -> Pending) opens the dialog too: the rule is direction, not distance (0049 D-8)', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Delivered);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('requestStatusChange')
        ->assertSet('showBackwardConfirm', true);

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

test('confirming applies the backward move with confirmed: true and persists the earlier status', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Shipped);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('requestStatusChange')
        ->call('applyStatusChange')
        ->assertHasNoErrors()
        ->assertSet('showBackwardConfirm', false)
        ->assertSet('pendingStatus', '')
        ->assertSet('selectedStatus', 'pending');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('dismissing leaves the persisted status untouched AND resets the select to the order real status', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Shipped);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('requestStatusChange')
        ->call('dismissBackwardConfirm')
        ->assertSet('showBackwardConfirm', false)
        ->assertSet('pendingStatus', '')
        ->assertSet('selectedStatus', 'shipped');

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

test('the dialog closing client-side (Esc / backdrop / X) is wired to the dismiss method, not merely to its own Cancel button', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Shipped);

    // The confirm-dialog binds its dismiss METHOD to the modal's own @close (amendment 14), which Flux
    // compiles to wire:close on the <dialog>. The Cancel button carries dismissBackwardConfirm too, so
    // the assertion is scoped to the <dialog> OPENING tag -- otherwise it would pass with @close removed.
    $html = Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('requestStatusChange')
        ->html();

    expect(preg_match('/<dialog\b[^>]*data-modal="backward-transition-modal"[^>]*>/s', $html, $modal))->toBe(1)
        ->and($modal[0])->toContain('wire:close="dismissBackwardConfirm"');
});

test('a failed status request leaves no stale error behind after a later valid one', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Pending);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('requestStatusChange')
        ->assertHasErrors(['selectedStatus'])
        ->set('selectedStatus', 'processing')
        ->call('requestStatusChange')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
});

test('a backward confirmation is refused when the order moved while the dialog was open', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Shipped);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('requestStatusChange')
        ->assertSet('showBackwardConfirm', true);

    // Another administrator moves the order while this one is looking at the dialog.
    $order->forceFill(['status' => OrderStatus::Delivered])->save();

    $component->call('applyStatusChange')
        ->assertHasErrors(['selectedStatus'])
        ->assertSet('showBackwardConfirm', false)
        ->assertSee(__('orders.transitions.stale_confirmation'));

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

// --- Defence in depth: forged inputs (D-11, D-12, amendment 12) ---

test('a forged applyStatusChange on a backward target with the confirmation never opened renders the 409 message and writes nothing', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Shipped);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->call('applyStatusChange')
        ->assertHasErrors(['selectedStatus'])
        ->assertSee(__('orders.transitions.requires_confirmation'));

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

test('forging showBackwardConfirm from the client does not confirm anything: only the locked pendingStatus does', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Shipped);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', 'pending')
        ->set('showBackwardConfirm', true)
        ->call('applyStatusChange')
        ->assertHasErrors(['selectedStatus']);

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

test('a forged cancelled / garbage / current-status value is refused before rank() can throw UnhandledMatchError', function (string $forged) {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Processing);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', $forged)
        ->call('requestStatusChange')
        ->assertHasErrors(['selectedStatus'])
        ->assertSet('showBackwardConfirm', false)
        ->assertSet('selectedStatus', 'processing');

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
})->with(['cancelled' => 'cancelled', 'garbage' => 'not-a-status', 'current status' => 'processing', 'empty' => '']);

test('a forged applyStatusChange with a garbage or cancelled selection also renders an error and writes nothing', function (string $forged) {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Processing);

    Livewire::test(Show::class, ['order' => $order])
        ->set('selectedStatus', $forged)
        ->call('applyStatusChange')
        ->assertHasErrors(['selectedStatus']);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
})->with(['cancelled' => 'cancelled', 'garbage' => 'not-a-status']);

test('a status change refused because the order was cancelled meanwhile renders the message rather than a 500', function () {
    ordersUiStatusEditor();

    $order = ordersUiStatusOrder(OrderStatus::Pending);
    $component = Livewire::test(Show::class, ['order' => $order]);

    // Another administrator cancels the order between this page's render and the click.
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();

    $component->set('selectedStatus', 'processing')
        ->call('requestStatusChange')
        ->assertHasErrors(['selectedStatus']);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});
