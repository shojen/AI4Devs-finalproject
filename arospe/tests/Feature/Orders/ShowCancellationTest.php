<?php

// Story 0055 -- manual cancellation (0050). The Cancel hint is Gate::allows('cancel', $order), which is
// orders.edit AND orders.refund (0050 D-6 -- amendment 1) AND Order::isManuallyCancellable(). The
// component re-derives neither the status set nor the PartiallyRefunded exclusion.
//
// ⚠️ Every disabled assertion uses a NON-Super-Admin actor holding BOTH abilities, so the only reason
// a control can be disabled is the ORDER STATE -- the other dimension is covered in ShowTest's matrix.

use App\Actions\Orders\CancelOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
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
    'Processing / PendingPayment' => [OrderStatus::Processing, PaymentStatus::PendingPayment, true],
    'Shipped / Paid' => [OrderStatus::Shipped, PaymentStatus::Paid, false],
    'Cancelled / Paid' => [OrderStatus::Cancelled, PaymentStatus::Paid, false],
    'Pending / PartiallyRefunded' => [OrderStatus::Pending, PaymentStatus::PartiallyRefunded, false],
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
        ->call('cancelOrder', true)
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
        ->call('cancelOrder', true)
        ->html();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($html)->toContain(__('orders.statuses.cancelled'))
        ->toContain(__('orders.payment_statuses.paid'));
});

test('an ordinary actor forging cancelOrder against a Shipped order is refused by the policy, and nothing is written', function () {
    $this->withoutExceptionHandling();
    ordersUiCanceller();

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->call('cancelOrder', true))
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

    $component->call('confirmCancel')
        ->call('cancelOrder', true)
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.cancellation.blocked'));

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

// --- Story 0095 -- the cancel dialog asks whether to return the units to stock ---

/**
 * A pending order with a 3-unit "Mug" line (stock 10) and a 2-unit "Red / M" variant line (stock 4).
 *
 * @return array{0: Order, 1: OrderItem, 2: Product, 3: OrderItem, 4: ProductVariant}
 */
function cancelDialogOrder(): array
{
    ordersUiCanceller();

    $mug = Product::factory()->named('Mug')->create(['stock' => 10]);
    $shirt = Product::factory()->create(['stock' => 50]);
    $variant = ProductVariant::factory()->create(['product_id' => $shirt->id, 'stock' => 4]);
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $mugLine = OrderItem::factory()->for($order)->create(['product_id' => $mug->id, 'quantity' => 3]);
    $variantLine = OrderItem::factory()->for($order)->forVariant($variant)->create(['quantity' => 2]);

    return [$order, $mugLine, $mug, $variantLine, $variant];
}

function assertCancelPendingCleared(Testable $component): void
{
    $component
        ->assertSet('showCancelConfirm', false)
        ->assertSet('pendingCancelFromStatus', '');
}

test('asking to cancel opens the three-way dialog with the preview of every returnable line', function () {
    [$order, $mugLine, , $variantLine] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->assertSet('showCancelConfirm', true)
        ->assertSet('pendingCancelFromStatus', 'pending')
        ->assertSet('pendingStockAction', '');

    $html = $component->html();

    expect($component->get('stockPreview'))->toHaveCount(2)
        ->and($html)->toContain(trans_choice('orders.stock.cancel_and_return', 5, ['count' => 5]))
        ->and($html)->toContain(__('orders.stock.cancel_without_stock'))
        ->and($html)->toContain(__('orders.stock.keep_order'))
        ->and(OrdersUi::present($html, 'cancel-stock-preview-row-'.$mugLine->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'cancel-stock-preview-row-'.$variantLine->id))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-cancel-order-secondary'))->toBeTrue();
});

test('cancelling and returning moves the units back to stock', function () {
    [$order, , $mug, , $variant] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder', true)
        ->assertHasNoErrors();

    assertCancelPendingCleared($component);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($mug->fresh()->stock)->toBe(13)
        ->and($variant->fresh()->stock)->toBe(6);
});

test('cancelling without changing stock leaves the stock alone', function () {
    [$order, , $mug, , $variant] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder', false)
        ->assertHasNoErrors();

    assertCancelPendingCleared($component);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($mug->fresh()->stock)->toBe(10)
        ->and($variant->fresh()->stock)->toBe(4);
});

test('keeping the order changes nothing and clears the guard', function () {
    [$order, , $mug] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('dismissCancelConfirm');

    assertCancelPendingCleared($component);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($mug->fresh()->stock)->toBe(10);
});

test('the cancel dialog does not touch the line stock dialog flag', function () {
    [$order] = cancelDialogOrder();

    Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->assertSet('showStockConfirm', false)
        ->call('cancelOrder', true)
        ->assertSet('showStockConfirm', false);
});

test('an order with nothing to return shows the note and only the two options', function () {
    ordersUiCanceller();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    OrderItem::factory()->for($order)->legacyStockUntracked()->create(['quantity' => 3]);

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel');
    $html = $component->html();

    expect($component->get('stockPreview'))->toBe([])
        ->and(OrdersUi::present($html, 'cancel-stock-nothing-to-return'))->toBeTrue()
        ->and(OrdersUi::present($html, 'confirm-dialog-cancel-order-secondary'))->toBeFalse()
        ->and($html)->toContain(__('orders.stock.cancel_nothing_to_return'))
        ->and($html)->toContain(__('orders.stock.cancel_without_stock'))
        ->and($html)->not->toContain('Cancel and return');

    $component->call('cancelOrder', false)->assertHasNoErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('the preview leaves out lines whose catalog row was deleted and lines with no returnable units', function () {
    [$order, $mugLine] = cancelDialogOrder();
    $orphan = OrderItem::factory()->for($order)->orphanedVariant()->create(['product_id' => $mugLine->product_id, 'quantity' => 4]);
    $untracked = OrderItem::factory()->for($order)->legacyStockUntracked()->create(['quantity' => 2]);

    $rows = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel')->get('stockPreview');
    $ids = array_column($rows, 'itemId');

    expect($rows)->toHaveCount(2)
        ->and($ids)->toContain($mugLine->id)
        ->and($ids)->not->toContain($orphan->id)
        ->and($ids)->not->toContain($untracked->id);
});

test('two lines of the same product show the combined resulting stock', function () {
    ordersUiCanceller();
    $mug = Product::factory()->named('Mug')->create(['stock' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    OrderItem::factory()->for($order)->create(['product_id' => $mug->id, 'quantity' => 3]);
    OrderItem::factory()->for($order)->create(['product_id' => $mug->id, 'quantity' => 2]);

    $rows = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel')->get('stockPreview');

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'resulting'))->toBe([15, 15])
        ->and(array_column($rows, 'current'))->toBe([10, 10]);
});

test('the preview is the line preview while a line request is pending and the cancel one only when none is', function () {
    [$order, $mugLine] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('requestRemoveLineItem', $mugLine->id);

    expect($component->get('stockPreview'))->toHaveCount(1)
        ->and($component->get('stockPreview')[0]['itemId'])->toBe($mugLine->id);

    $component->call('dismissStockConfirm')->call('confirmCancel');

    expect($component->get('stockPreview'))->toHaveCount(2);
});

test('a pending quantity request previews the line, not the order', function () {
    [$order, $mugLine] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->set('editingQuantities.'.$mugLine->id, 1)
        ->call('requestQuantityChange', $mugLine->id);

    expect($component->get('stockPreview'))->toHaveCount(1)
        ->and($component->get('stockPreview')[0]['itemId'])->toBe($mugLine->id)
        ->and($component->get('stockPreview')[0]['units'])->toBe(2);
});

test('opening the cancel dialog drops a leftover line request so the preview is the order preview', function () {
    [$order, $mugLine] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('requestRemoveLineItem', $mugLine->id)
        ->call('confirmCancel')
        ->assertSet('pendingStockAction', '')
        ->assertSet('showStockConfirm', false);

    expect($component->get('stockPreview'))->toHaveCount(2);
});

test('the guard cannot be written by the client', function () {
    [$order] = cancelDialogOrder();

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->set('pendingCancelFromStatus', 'pending'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a direct cancelOrder call without confirmCancel is refused like a stale guard and moves no stock', function () {
    [$order, , $mug] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('cancelOrder', true)
        ->assertHasErrors(['cancel']);

    assertCancelPendingCleared($component);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($mug->fresh()->stock)->toBe(10);
});

test('an order moved meanwhile is not cancelled blindly', function () {
    [$order, , $mug] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel');

    $order->forceFill(['status' => OrderStatus::Processing])->save();

    $component->call('cancelOrder', true)
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.stock.cancel_changed_meanwhile'));

    assertCancelPendingCleared($component);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($mug->fresh()->stock)->toBe(10);
});

test('revoking orders.refund before applying is refused as forbidden', function () {
    $this->withoutExceptionHandling();
    [$order, , $mug] = cancelDialogOrder();

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel');

    $actor = auth()->user();
    $actor->revokePermissionTo('orders.refund');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $component->call('cancelOrder', true))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($mug->fresh()->stock)->toBe(10);
});

test('an actor who may not refund cannot open the cancel dialog', function () {
    $this->withoutExceptionHandling();
    test()->actingAs(OrdersUi::actor(OrdersUi::PROFILES['edit-only']));
    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);

    expect(fn () => Livewire::test(Show::class, ['order' => $order])->call('confirmCancel'))
        ->toThrow(AuthorizationException::class);
});

test('a super administrator confirming a return on a shipped order is told it is blocked and no stock moves', function () {
    $this->actingAs(OrdersUi::superAdmin());
    $mug = Product::factory()->named('Mug')->create(['stock' => 10]);
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);
    OrderItem::factory()->for($order)->create(['product_id' => $mug->id, 'quantity' => 3]);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder', true)
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.cancellation.blocked'));

    assertCancelPendingCleared($component);

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and($mug->fresh()->stock)->toBe(10);
});

test('an order cancelled by someone else meanwhile is refused by the stale guard and clears it', function () {
    [$order] = cancelDialogOrder();
    $this->actingAs(OrdersUi::superAdmin());

    $component = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel');

    $order->forceFill(['status' => OrderStatus::Cancelled])->save();

    $component->call('cancelOrder', true)
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.stock.cancel_changed_meanwhile'));

    assertCancelPendingCleared($component);
});

test('the action refusing a validation rule renders its message under cancel, clears the guard and moves no stock', function () {
    [$order, , $mug] = cancelDialogOrder();
    $this->mock(CancelOrder::class, fn ($mock) => $mock->shouldReceive('__invoke')
        ->once()
        ->andThrow(ValidationException::withMessages(['status' => __('orders.cancellation.already_cancelled')])));

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder', true)
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.cancellation.already_cancelled'));

    assertCancelPendingCleared($component);

    expect($mug->fresh()->stock)->toBe(10);
});

test('a concurrent-change LogicException or ModelNotFoundException renders the changed-meanwhile message and clears the guard', function (Throwable $exception) {
    [$order] = cancelDialogOrder();
    $this->mock(CancelOrder::class, fn ($mock) => $mock->shouldReceive('__invoke')->once()->andThrow($exception));

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('confirmCancel')
        ->call('cancelOrder', true)
        ->assertHasErrors(['cancel'])
        ->assertSee(__('orders.stock.cancel_changed_meanwhile'));

    assertCancelPendingCleared($component);
})->with([
    'LogicException' => fn () => new LogicException('locked re-check failed'),
    'ModelNotFoundException' => fn () => new ModelNotFoundException,
]);

test('only one modal is bound to showCancelConfirm', function () {
    [$order] = cancelDialogOrder();

    $html = Livewire::test(Show::class, ['order' => $order])->call('confirmCancel')->html();

    expect(preg_match_all('/wire:model[\w.]*="showCancelConfirm"/', $html))->toBe(1);
});

test('the cancel copy exists in both locales with identical stock keys', function () {
    foreach (['cancel_and_return', 'cancel_without_stock', 'keep_order', 'cancel_nothing_to_return', 'cancel_changed_meanwhile'] as $key) {
        expect(trans('orders.stock.'.$key, [], 'en'))->not->toBe('orders.stock.'.$key)
            ->and(trans('orders.stock.'.$key, [], 'es'))->not->toBe('orders.stock.'.$key);
    }
});
