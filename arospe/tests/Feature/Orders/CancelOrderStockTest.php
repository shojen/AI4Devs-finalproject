<?php

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderCancellationBlockedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0091: CancelOrder gains `bool $adjustStock = false`. With true every line returns its
// returnable units R = (Q - F) - U to its variant or product stock and U grows by R, in one
// transaction under the order lock (D-2..D-4). K (stock_refunded_not_out_quantity) is never touched.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  list<string>  $permissions
 */
function actingCancelStockActor(array $permissions = ['orders.edit', 'orders.refund']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);
    test()->actingAs($actor);

    return $actor;
}

function actingCancelStockSuperAdmin(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    test()->actingAs($actor);

    return $actor;
}

/**
 * Adds a line to the order and returns it with its stock row.
 *
 * @param  'product'|'variant'  $target
 * @param  array<string, mixed>  $itemAttributes
 * @return array{item: OrderItem, stocked: Product|ProductVariant}
 */
function cancelStockLine(Order $order, string $target = 'product', int $quantity = 3, int $stock = 10, array $itemAttributes = [], bool $legacy = false): array
{
    $product = Product::factory()->create(['stock' => $target === 'product' ? $stock : 99, 'price' => '10.00']);
    $variant = null;
    $factory = OrderItem::factory()->for($order);

    if ($target === 'variant') {
        $variant = ProductVariant::factory()->for($product)->create(['stock' => $stock, 'price' => '10.00']);
        $factory = $factory->forVariant($variant);
    } else {
        $factory = $factory->state(['product_id' => $product->id]);
    }

    if ($legacy) {
        $factory = $factory->legacyStockUntracked();
    }

    return [
        'item' => $factory->create(['quantity' => $quantity, ...$itemAttributes]),
        'stocked' => $variant ?? $product,
    ];
}

/**
 * @param  array<string, mixed>  $orderAttributes
 * @return array{order: Order, item: OrderItem, stocked: Product|ProductVariant}
 */
function cancelStockFixture(string $target = 'product', int $quantity = 3, int $stock = 10, array $itemAttributes = [], bool $legacy = false, array $orderAttributes = []): array
{
    $order = Order::factory()->create(['status' => OrderStatus::Pending, ...$orderAttributes]);

    return ['order' => $order, ...cancelStockLine($order, $target, $quantity, $stock, $itemAttributes, $legacy)];
}

/**
 * @return array{products: array<string, int>, variants: array<string, int>}
 */
function cancelStockSnapshot(): array
{
    return [
        'products' => Product::query()->orderBy('id')->pluck('stock', 'id')->all(),
        'variants' => ProductVariant::query()->orderBy('id')->pluck('stock', 'id')->all(),
    ];
}

/**
 * @return array<string, array{int, int}>
 */
function cancelStockCounters(Order $order): array
{
    return OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()
        ->mapWithKeys(fn (OrderItem $item): array => [$item->id => [$item->stock_undeducted_quantity, $item->stock_refunded_not_out_quantity]])
        ->all();
}

// --- Gherkin scenarios ---

test('cancelling with stock return puts the units of a product line and a variant line back', function () {
    actingCancelStockActor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $mug = cancelStockLine($order, 'product', 3, 10);
    $redM = cancelStockLine($order, 'variant', 2, 4);

    app(CancelOrder::class)($order, true);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($mug['stocked']->fresh()->stock)->toBe(13)
        ->and($redM['stocked']->fresh()->stock)->toBe(6);
});

test('cancelling without stock return leaves stock unchanged', function (string $target, ?bool $adjustStock) {
    actingCancelStockActor();
    $fixture = cancelStockFixture($target);

    $adjustStock === null
        ? app(CancelOrder::class)($fixture['order'])
        : app(CancelOrder::class)($fixture['order'], adjustStock: $adjustStock);

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(0);
})->with(['product', 'variant'])->with(['explicit false' => false, 'parameter omitted' => null]);

test('cancelling a legacy order with stock return moves no stock', function (string $target) {
    actingCancelStockActor();
    $fixture = cancelStockFixture($target, legacy: true);
    $before = cancelStockSnapshot();

    app(CancelOrder::class)($fixture['order'], true);

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(cancelStockSnapshot())->toBe($before)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(3);
})->with(['product', 'variant']);

test('a line whose product was deleted is skipped while the other line returns its units', function () {
    actingCancelStockActor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $mug = cancelStockLine($order, 'product', 3, 10);
    $deleted = cancelStockLine($order, 'product', 2, 7);
    $deleted['item']->forceFill(['product_id' => null])->save();

    app(CancelOrder::class)($order, true);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($mug['stocked']->fresh()->stock)->toBe(13)
        ->and($deleted['stocked']->fresh()->stock)->toBe(7)
        ->and($deleted['item']->fresh()->stock_undeducted_quantity)->toBe(0);
});

test('a line whose variant was deleted is skipped', function () {
    actingCancelStockActor();
    $fixture = cancelStockFixture('product', itemAttributes: ['product_variant_id' => null, 'ordered_as_variant' => true]);
    $before = cancelStockSnapshot();

    app(CancelOrder::class)($fixture['order'], true);

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(cancelStockSnapshot())->toBe($before)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(0);
});

test('a fully refunded order not yet cancelled automatically returns no refunded units', function (string $target) {
    actingCancelStockSuperAdmin();
    $fixture = cancelStockFixture($target, quantity: 5, itemAttributes: ['refunded_quantity' => 5, 'stock_refunded_not_out_quantity' => 5], orderAttributes: [
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Refunded,
    ]);

    app(CancelOrder::class)($fixture['order'], true);

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and(cancelStockCounters($fixture['order']))->toBe([$fixture['item']->id => [0, 5]]);
})->with(['product', 'variant']);

// --- Counters ---

test('cancelling with stock return grows the undeducted counter by the returned units and leaves the refunded-not-out counter alone', function (string $target) {
    actingCancelStockActor();
    $fixture = cancelStockFixture($target, quantity: 3, itemAttributes: ['stock_undeducted_quantity' => 1]);

    app(CancelOrder::class)($fixture['order'], true);

    $line = $fixture['item']->fresh();

    expect($fixture['stocked']->fresh()->stock)->toBe(12)
        ->and([$line->stock_undeducted_quantity, $line->stock_refunded_not_out_quantity])->toBe([3, 0])
        ->and($line->returnableStockUnits())->toBe(0);
})->with(['product', 'variant']);

test('cancelling with stock return leaves the counters of skipped lines unchanged', function () {
    actingCancelStockActor();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $legacy = cancelStockLine($order, 'product', 3, 10, legacy: true);
    $orphan = cancelStockLine($order, 'product', 2, 10, ['product_variant_id' => null, 'ordered_as_variant' => true]);
    $before = cancelStockCounters($order);

    app(CancelOrder::class)($order, true);

    expect(cancelStockCounters($order))->toBe($before)
        ->and($legacy['item']->fresh()->stock_undeducted_quantity)->toBe(3)
        ->and($orphan['item']->fresh()->stock_undeducted_quantity)->toBe(0);
});

test('cancelling without stock return leaves every counter unchanged', function () {
    actingCancelStockActor();
    $fixture = cancelStockFixture('product', itemAttributes: ['stock_undeducted_quantity' => 1]);
    $before = cancelStockCounters($fixture['order']);

    app(CancelOrder::class)($fixture['order'], false);

    expect(cancelStockCounters($fixture['order']))->toBe($before);
});

test('after a cancellation with stock return no line has returnable units and refunded units out of stock are unchanged', function () {
    actingCancelStockSuperAdmin();
    $order = Order::factory()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Refunded]);
    $open = cancelStockLine($order, 'product', 3, 10);
    $refunded = cancelStockLine($order, 'variant', 5, 4, ['refunded_quantity' => 5]);
    $refundedOutBefore = $refunded['item']->fresh()->refundedUnitsOutOfStock();

    app(CancelOrder::class)($order, true);

    expect($open['item']->fresh()->returnableStockUnits())->toBe(0)
        ->and($refunded['item']->fresh()->returnableStockUnits())->toBe(0)
        ->and($refunded['item']->fresh()->refundedUnitsOutOfStock())->toBe($refundedOutBefore)->toBe(5)
        ->and($open['stocked']->fresh()->stock)->toBe(13)
        ->and($refunded['stocked']->fresh()->stock)->toBe(4);
});

// --- 0088 sequences through the real actions ---

test('sequence 1 through the real actions: raise without deducting, lower with return, cancel with return restores the original stock', function () {
    actingCancelStockActor(['orders.edit', 'orders.refund']);
    $fixture = cancelStockFixture('product', quantity: 2, stock: 8);

    app(UpdateOrderItemQuantity::class)($fixture['order'], $fixture['item']->id, 5, adjustStock: false);
    app(UpdateOrderItemQuantity::class)($fixture['order'], $fixture['item']->id, 2, adjustStock: true);

    expect($fixture['stocked']->fresh()->stock)->toBe(8);

    app(CancelOrder::class)($fixture['order']->fresh(), true);

    expect($fixture['stocked']->fresh()->stock)->toBe(10);
});

test('sequence 10 through the real actions: raise with deduction then cancel with return restores the original stock', function () {
    actingCancelStockActor(['orders.edit', 'orders.refund']);
    $fixture = cancelStockFixture('product', quantity: 2, stock: 8);

    app(UpdateOrderItemQuantity::class)($fixture['order'], $fixture['item']->id, 5, adjustStock: true);

    expect($fixture['stocked']->fresh()->stock)->toBe(5);

    app(CancelOrder::class)($fixture['order']->fresh(), true);

    expect($fixture['stocked']->fresh()->stock)->toBe(10);
});

// --- Refusals move no stock: ordinary administrator (policy state clause) ---

test('an ordinary administrator is refused by the policy for a blocked order and no stock moves', function (array $orderAttributes, array $itemAttributes) {
    actingCancelStockActor();
    $fixture = cancelStockFixture('product', quantity: 5, itemAttributes: $itemAttributes, orderAttributes: $orderAttributes);
    $before = cancelStockSnapshot();

    expect(fn () => app(CancelOrder::class)($fixture['order'], true))->toThrow(AuthorizationException::class);

    expect(cancelStockSnapshot())->toBe($before)
        ->and($fixture['order']->fresh()->status->value)->toBe($orderAttributes['status']->value);
})->with([
    'partially refunded' => [['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded], ['refunded_quantity' => 2]],
    'shipped' => [['status' => OrderStatus::Shipped], []],
    'already cancelled' => [['status' => OrderStatus::Cancelled], ['stock_undeducted_quantity' => 5]],
]);

test('an administrator who may not refund is refused as not authorized and no stock moves', function () {
    actingCancelStockActor(['orders.edit']);
    $fixture = cancelStockFixture('product');
    $before = cancelStockSnapshot();

    expect(fn () => app(CancelOrder::class)($fixture['order'], true))->toThrow(AuthorizationException::class);

    expect(cancelStockSnapshot())->toBe($before)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Pending);
});

test('authorization is refused first: an unauthorized caller gets no state refusal for a shipped order', function () {
    actingCancelStockActor(['orders.view']);
    $fixture = cancelStockFixture('product', orderAttributes: ['status' => OrderStatus::Shipped]);

    expect(fn () => app(CancelOrder::class)($fixture['order'], true))->toThrow(AuthorizationException::class);
});

// --- Refusals move no stock: super administrator (guards re-run by the action) ---

test('a super administrator cancelling an already cancelled order is refused and no stock moves', function () {
    actingCancelStockSuperAdmin();
    $fixture = cancelStockFixture('product', itemAttributes: ['stock_undeducted_quantity' => 3], orderAttributes: ['status' => OrderStatus::Cancelled]);
    $before = cancelStockSnapshot();

    try {
        app(CancelOrder::class)($fixture['order'], true);
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['status'][0])->toBe(__('orders.cancellation.already_cancelled'));
    }

    expect(cancelStockSnapshot())->toBe($before);
});

test('a super administrator cancelling a shipped or partially refunded order is refused and no stock moves', function (array $orderAttributes, array $itemAttributes) {
    actingCancelStockSuperAdmin();
    $fixture = cancelStockFixture('product', quantity: 5, itemAttributes: $itemAttributes, orderAttributes: $orderAttributes);
    $before = cancelStockSnapshot();

    expect(fn () => app(CancelOrder::class)($fixture['order'], true))->toThrow(OrderCancellationBlockedException::class);

    expect(cancelStockSnapshot())->toBe($before)
        ->and($fixture['order']->fresh()->status)->toBe($orderAttributes['status']);
})->with([
    'shipped' => [['status' => OrderStatus::Shipped], []],
    'partially refunded' => [['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::PartiallyRefunded], ['refunded_quantity' => 2]],
]);

// --- Concurrency: the order lock serializes two cancellations ---

test('two stale instances of one order: the second cancellation is refused and the stock is returned once', function () {
    actingCancelStockActor();
    $fixture = cancelStockFixture('product', quantity: 3);
    $first = Order::query()->findOrFail($fixture['order']->id);
    $second = Order::query()->findOrFail($fixture['order']->id);

    app(CancelOrder::class)($first, true);

    expect(fn () => app(CancelOrder::class)($second, true))->toThrow(ValidationException::class);

    expect($fixture['stocked']->fresh()->stock)->toBe(13)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(3);
});

test('another writer cancelling the order between authorization and the transaction leaves the stock untouched', function () {
    actingCancelStockActor();
    $fixture = cancelStockFixture('product', quantity: 3);
    $fired = 0;

    Gate::after(function ($user, string $ability) use ($fixture, &$fired): void {
        if ($ability !== 'cancel' || $fired > 0) {
            return;
        }

        $fired++;
        DB::table('orders')->where('id', $fixture['order']->id)->update(['status' => OrderStatus::Cancelled->value]);
    });

    expect(fn () => app(CancelOrder::class)($fixture['order'], true))->toThrow(ValidationException::class);

    expect($fired)->toBe(1)
        ->and($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(0);
});

// --- D-4 step 4 re-verify and rollback ---

test('a locked re-read whose counters differ from the first read refuses the call and rolls everything back', function () {
    actingCancelStockActor();
    $fixture = cancelStockFixture('product', quantity: 3);
    $itemId = $fixture['item']->id;
    $armed = true;

    Event::listen('eloquent.retrieved: '.OrderItem::class, function (OrderItem $model) use (&$armed, $itemId): void {
        if ($armed && $model->id === $itemId) {
            $armed = false;
            DB::table('order_items')->where('id', $itemId)->update(['stock_undeducted_quantity' => 1]);
        }
    });

    try {
        expect(fn () => app(CancelOrder::class)($fixture['order'], true))->toThrow(LogicException::class);
    } finally {
        $armed = false;
        Event::forget('eloquent.retrieved: '.OrderItem::class);
    }

    expect($fixture['stocked']->fresh()->stock)->toBe(10)
        ->and($fixture['item']->fresh()->stock_undeducted_quantity)->toBe(0)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Pending);
});
