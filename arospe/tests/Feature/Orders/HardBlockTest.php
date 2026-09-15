<?php

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\RemoveOrderItem;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0048, Phase 3 (TDD "red" step): none of AddOrderItem / RemoveOrderItem /
// UpdateOrderItemQuantity / OrderNotEditableException exist yet -- every test below is expected to
// fail with "Target class [...] does not exist" until backend-expert implements them.
//
// D-5: the hard block is a direct `throw`, one guard, three call sites -- this file is the single
// place all three converge, per the task file's own "one story because they share a single
// invariant" framing. R-3's mitigation: the block is specified as blocking the two named statuses
// (Shipped, Delivered), never as "anything but Pending" -- Processing must never be caught by it.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingOrderEditorForHardBlock(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    return $actor;
}

// --- The hard block itself: three operations, both blocked statuses (6 cases total) ---

// Asserting only the exception would pass against an implementation that writes first and throws
// second -- so this test asserts BOTH the exception AND that the order's line items/totals are
// byte-identical afterwards.
test('AddOrderItem is blocked on a shipped or delivered order, and the orders line items are unchanged', function (OrderStatus $status) {
    actingOrderEditorForHardBlock();

    $order = Order::factory()->withItems(1)->create(['status' => $status]);
    $product = Product::factory()->create();
    $itemIdsBefore = $order->items()->pluck('id')->sort()->values()->all();
    $subtotalBefore = (string) $order->fresh()->subtotal;

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 1))
        ->toThrow(OrderNotEditableException::class);

    expect($order->items()->pluck('id')->sort()->values()->all())->toBe($itemIdsBefore)
        ->and((string) $order->fresh()->subtotal)->toBe($subtotalBefore);
})->with('blocked_order_statuses');

test('RemoveOrderItem is blocked on a shipped or delivered order, and the orders line items are unchanged', function (OrderStatus $status) {
    actingOrderEditorForHardBlock();

    $order = Order::factory()->withItems(2)->create(['status' => $status]);
    $itemToRemove = $order->items()->first();
    $itemIdsBefore = $order->items()->pluck('id')->sort()->values()->all();
    $subtotalBefore = (string) $order->fresh()->subtotal;

    expect(fn () => app(RemoveOrderItem::class)($order, $itemToRemove->id))
        ->toThrow(OrderNotEditableException::class);

    expect($order->items()->pluck('id')->sort()->values()->all())->toBe($itemIdsBefore)
        ->and((string) $order->fresh()->subtotal)->toBe($subtotalBefore);
})->with('blocked_order_statuses');

test('UpdateOrderItemQuantity is blocked on a shipped or delivered order, and the line item is unchanged', function (OrderStatus $status) {
    actingOrderEditorForHardBlock();

    $order = Order::factory()->withItems(1)->create(['status' => $status]);
    $item = $order->items()->sole();
    $quantityBefore = $item->quantity;
    $subtotalBefore = (string) $order->fresh()->subtotal;

    expect(fn () => app(UpdateOrderItemQuantity::class)($order, $item->id, 9))
        ->toThrow(OrderNotEditableException::class);

    expect($item->fresh()->quantity)->toBe($quantityBefore)
        ->and((string) $order->fresh()->subtotal)->toBe($subtotalBefore);
})->with('blocked_order_statuses');

// --- Positive control: without it, a guard that blocks EVERYTHING would pass every case above ---

test('all three operations succeed against an order in Pending or Processing', function (OrderStatus $status) {
    actingOrderEditorForHardBlock();

    $order = Order::factory()->withItems(2)->create(['status' => $status]);
    $items = $order->items()->get();
    $product = Product::factory()->create();

    app(AddOrderItem::class)($order, $product->id, null, 1);
    expect($order->items()->count())->toBe(3);

    app(UpdateOrderItemQuantity::class)($order, $items->first()->id, 5);
    expect($items->first()->fresh()->quantity)->toBe(5);

    app(RemoveOrderItem::class)($order, $items->last()->id);
    expect(OrderItem::query()->whereKey($items->last()->id)->exists())->toBeFalse();
})->with('open_order_statuses');

// --- A Super Admin is bound identically -- proving the block is a direct throw, not a Gate ability ---

// If this were Gate-mediated, Gate::before's Super Admin bypass would make the check inert, and
// every other test in this file would still pass while the block was open for this one actor.
test('a Super Admin is also blocked from editing a shipped orders line items, for all three operations', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $orderForAdd = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->create();
    expect(fn () => app(AddOrderItem::class)($orderForAdd, $product->id, null, 1))
        ->toThrow(OrderNotEditableException::class);

    $orderForRemove = Order::factory()->withItems(2)->create(['status' => OrderStatus::Shipped]);
    $itemToRemove = $orderForRemove->items()->first();
    expect(fn () => app(RemoveOrderItem::class)($orderForRemove, $itemToRemove->id))
        ->toThrow(OrderNotEditableException::class);

    $orderForQuantity = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $itemToChange = $orderForQuantity->items()->sole();
    expect(fn () => app(UpdateOrderItemQuantity::class)($orderForQuantity, $itemToChange->id, 9))
        ->toThrow(OrderNotEditableException::class);
});

// --- T-B: the block offers no confirmation path around it ---
//
// PRD §3.2 states the block is "always blocked, with no confirmation path around it" -- sitting
// three scenarios away from "moving an order's status backward requires explicit confirmation"
// (story 0049). This test is satisfiable whether or not 0049 has landed: story 0049 does NOT
// exist in this codebase yet (verified before writing this test), so per the task file's own
// guidance there is no confirmation mechanism to name -- the assertion is that no parameter, flag
// or session key of ANY kind unlocks the block, expressed by calling each action with every
// argument it accepts (the full, ordinary argument list -- never a partial call) and confirming
// the refusal is invariant. If story 0049 later introduces a real confirmation mechanism (a
// $confirmed flag, a session key, a component property), THIS TEST MUST BE REVISITED to also
// attempt the operation WITH that mechanism engaged and assert the refusal still holds -- do not
// let that future addition go untested here.
test('the block on a shipped order is not bypassed by any argument any of the three actions accepts', function () {
    actingOrderEditorForHardBlock();

    $orderForAdd = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->create();
    // Every argument AddOrderItem::__invoke() accepts is supplied -- including a variant id, the
    // one optional/nullable argument -- so no omitted argument could be mistaken for an unlocking
    // default.
    $variant = ProductVariant::factory()->for($product)->create();
    expect(fn () => app(AddOrderItem::class)($orderForAdd, $product->id, $variant->id, 1))
        ->toThrow(OrderNotEditableException::class);
    expect($orderForAdd->items()->count())->toBe(1);

    $orderForRemove = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $itemToRemove = $orderForRemove->items()->sole();
    expect(fn () => app(RemoveOrderItem::class)($orderForRemove, $itemToRemove->id))
        ->toThrow(OrderNotEditableException::class);
    expect(OrderItem::query()->whereKey($itemToRemove->id)->exists())->toBeTrue();

    $orderForQuantity = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $itemToChange = $orderForQuantity->items()->sole();
    $quantityBefore = $itemToChange->quantity;
    expect(fn () => app(UpdateOrderItemQuantity::class)($orderForQuantity, $itemToChange->id, 999))
        ->toThrow(OrderNotEditableException::class);
    expect($itemToChange->fresh()->quantity)->toBe($quantityBefore);
});
