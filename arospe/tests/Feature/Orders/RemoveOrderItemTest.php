<?php

use App\Actions\Orders\RemoveOrderItem;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0048, Phase 3 (TDD "red" step): App\Actions\Orders\RemoveOrderItem does not exist yet --
// every test below is expected to fail with "Target class [...] does not exist" until
// backend-expert implements it. That failure is the correct, intended "red" outcome.
//
// Signature assumed, per the task file's own "Files to create" section:
// __invoke(Order $order, string $orderItemId): void.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderEditorForRemoval(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    return $actor;
}

// --- Recalculation, both open statuses (R-3's mitigation) ---

test('removing a line item deletes the row and lowers subtotal by exactly that lines total', function (OrderStatus $status) {
    actingOrderEditorForRemoval();

    $order = Order::factory()->create(['status' => $status]);
    $productA = Product::factory()->create(['price' => '10.00']);
    $productB = Product::factory()->create(['price' => '25.00']);
    $itemToKeep = OrderItem::factory()->for($order)->create(['product_id' => $productA->id, 'quantity' => 1]);
    $itemToRemove = OrderItem::factory()->for($order)->create(['product_id' => $productB->id, 'quantity' => 1]);

    $order->forceFill([
        'subtotal' => bcadd($itemToKeep->fresh()->line_total, $itemToRemove->fresh()->line_total, 2),
    ])->save();
    $originalSubtotal = (string) $order->fresh()->subtotal;
    $removedLineTotal = (string) $itemToRemove->fresh()->line_total;

    app(RemoveOrderItem::class)($order, $itemToRemove->id);

    $fresh = $order->fresh();

    expect(OrderItem::query()->whereKey($itemToRemove->id)->exists())->toBeFalse()
        ->and((string) $fresh->subtotal)->toBe(bcsub($originalSubtotal, $removedLineTotal, 2));
})->with('open_order_statuses');

test('total equals subtotal plus tax_amount plus shipping_amount after a remove', function (OrderStatus $status) {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(2)->create(['status' => $status]);
    $itemToRemove = $order->items()->first();

    app(RemoveOrderItem::class)($order, $itemToRemove->id);

    $fresh = $order->fresh();
    $expectedTotal = bcadd(bcadd($fresh->subtotal, $fresh->tax_amount, 2), $fresh->shipping_amount, 2);

    expect((string) $fresh->total)->toBe($expectedTotal);
})->with('open_order_statuses');

// --- Rejecting an invalid removal (D-1) ---

test('removing an orders only remaining line item is rejected, and the item remains', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(1)->create();
    $onlyItem = $order->items()->sole();
    $originalSubtotal = (string) $order->fresh()->subtotal;
    $originalTotal = (string) $order->fresh()->total;

    expect(fn () => app(RemoveOrderItem::class)($order, $onlyItem->id))
        ->toThrow(ValidationException::class);

    expect(OrderItem::query()->whereKey($onlyItem->id)->exists())->toBeTrue()
        ->and((string) $order->fresh()->subtotal)->toBe($originalSubtotal)
        ->and((string) $order->fresh()->total)->toBe($originalTotal);
});

// The boundary from the other side -- a rule asserted only from its refusing side cannot
// distinguish count <= 1 from count <= 2.
test('removing one of two line items succeeds', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(2)->create();
    $itemToRemove = $order->items()->first();

    app(RemoveOrderItem::class)($order, $itemToRemove->id);

    expect($order->items()->count())->toBe(1);
});

test('removing a line item that belongs to a different order is rejected, and neither orders line items change', function () {
    actingOrderEditorForRemoval();

    $orderA = Order::factory()->withItems(1)->create();
    $orderB = Order::factory()->withItems(1)->create();
    $itemBelongingToOrderB = $orderB->items()->sole();

    $orderAItemsBefore = $orderA->items()->pluck('id')->sort()->values();
    $orderASubtotalBefore = (string) $orderA->fresh()->subtotal;
    $orderBSubtotalBefore = (string) $orderB->fresh()->subtotal;

    expect(fn () => app(RemoveOrderItem::class)($orderA, $itemBelongingToOrderB->id))
        ->toThrow(ValidationException::class);

    expect($orderA->items()->pluck('id')->sort()->values()->all())->toBe($orderAItemsBefore->all())
        ->and((string) $orderA->fresh()->subtotal)->toBe($orderASubtotalBefore)
        ->and(OrderItem::query()->whereKey($itemBelongingToOrderB->id)->exists())->toBeTrue()
        ->and((string) $orderB->fresh()->subtotal)->toBe($orderBSubtotalBefore);
});

test('a rejected edit leaves the orders stored subtotal and total exactly what they were before', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(1)->create();
    $onlyItem = $order->items()->sole();
    $originalSubtotal = (string) $order->fresh()->subtotal;
    $originalTotal = (string) $order->fresh()->total;

    try {
        app(RemoveOrderItem::class)($order, $onlyItem->id);
    } catch (ValidationException) {
        // expected -- the rejection itself is asserted by the D-1 test above.
    }

    expect((string) $order->fresh()->subtotal)->toBe($originalSubtotal)
        ->and((string) $order->fresh()->total)->toBe($originalTotal);
});

// --- Atomicity ---

// Forces a failure AFTER the transaction has opened and (per the task file's own six-step order)
// after the order_items write, but before the write commits -- by making the parent Order's own
// `saving` event throw. If the item write and the totals recompute are genuinely inside one
// transaction, the forced failure rolls BOTH back; if they are not, the item deletion survives
// while the totals write fails, which this test would catch. The listener is removed in `finally`
// so it cannot poison any later test in this process.
test('an operation that fails after the transaction opens leaves neither table modified', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(2)->create();
    $itemToRemove = $order->items()->first();
    $originalItemIds = $order->items()->pluck('id')->sort()->values()->all();
    $originalSubtotal = (string) $order->fresh()->subtotal;

    Order::saving(function (): void {
        throw new RuntimeException('Story 0048 test: forced failure to prove transactional atomicity.');
    });

    try {
        try {
            app(RemoveOrderItem::class)($order, $itemToRemove->id);
        } catch (Throwable) {
            // expected -- the forced failure above.
        }
    } finally {
        Order::flushEventListeners();
    }

    expect($order->items()->pluck('id')->sort()->values()->all())->toBe($originalItemIds)
        ->and((string) $order->fresh()->subtotal)->toBe($originalSubtotal);
});

// --- Tax recalculation coupling (D-8) ---

test('removing a line item recomputes tax_amount from the new subtotal when a rate is already resolved', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(2)->create(['tax_rate' => '0.210']);
    $itemToRemove = $order->items()->first();

    app(RemoveOrderItem::class)($order, $itemToRemove->id);

    $fresh = $order->fresh();

    expect((string) $fresh->tax_amount)->toBe(bcmul((string) $fresh->subtotal, '0.210', 2));
});

test('removing a line item from an order with no resolved tax rate leaves tax_amount at zero and resolves no sales region', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(2)->create(['tax_rate' => null, 'sales_region_id' => null]);
    $itemToRemove = $order->items()->first();

    app(RemoveOrderItem::class)($order, $itemToRemove->id);

    $fresh = $order->fresh();

    expect((string) $fresh->tax_amount)->toBe('0.00')
        ->and($fresh->sales_region_id)->toBeNull();
});

test('removing a line item never writes tax_rate itself', function () {
    actingOrderEditorForRemoval();

    $order = Order::factory()->withItems(2)->create(['tax_rate' => '0.100']);
    $itemToRemove = $order->items()->first();

    app(RemoveOrderItem::class)($order, $itemToRemove->id);

    expect((string) $order->fresh()->tax_rate)->toBe('0.100');
});
