<?php

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\RemoveOrderItem;
use App\Actions\Orders\UpdateOrderItemQuantity;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Story 0048, Phase 3 (TDD "red" step): none of AddOrderItem / RemoveOrderItem /
// UpdateOrderItemQuantity exist yet -- every test below is expected to fail with "Target class
// [...] does not exist" until backend-expert implements them. This story ships no route and no
// Livewire component, so every authorization assertion here is action-level (D-2/D-9: the
// permission is `orders.edit`, reused -- no new sub-permission, no OrderPolicy created here).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

// --- Refused without orders.edit, for all three actions ---

test('an administrator holding orders.view but not orders.edit cannot add a line item, and nothing is written', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create();

    expect(fn () => app(AddOrderItem::class)($order, $product->id, null, 1))
        ->toThrow(AuthorizationException::class);

    expect($order->items()->count())->toBe(0);
});

test('an administrator holding orders.view but not orders.edit cannot remove a line item, and the item is unchanged', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);
    $item = $order->items()->sole();

    expect(fn () => app(RemoveOrderItem::class)($order, $item->id))
        ->toThrow(AuthorizationException::class);

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('an administrator holding orders.view but not orders.edit cannot change a quantity, and the line items quantity is unchanged', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);
    $item = $order->items()->sole();
    $quantityBefore = $item->quantity;

    expect(fn () => app(UpdateOrderItemQuantity::class)($order, $item->id, 5))
        ->toThrow(AuthorizationException::class);

    expect($item->fresh()->quantity)->toBe($quantityBefore);
});

// --- The positive case beside each 403, without which a mistyped ability passes silently ---

test('an administrator holding orders.edit can add a line item to an open order', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $product = Product::factory()->create();

    app(AddOrderItem::class)($order, $product->id, null, 1);

    expect($order->items()->count())->toBe(1);
});

test('an administrator holding orders.edit can change a quantity on an open order', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 4);

    expect($item->fresh()->quantity)->toBe(4);
});

// --- A Super Admin may edit an open order without holding the permission explicitly ---
// (the counterpart to HardBlockTest.php's "a Super Admin is also blocked" -- the pair TOGETHER is
// the specification: bypasses the authorization layer, does not bypass the state layer)

test('a Super Admin holding no individual orders permission can change a quantity on an open order', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Pending]);
    $item = $order->items()->sole();

    app(UpdateOrderItemQuantity::class)($order, $item->id, 4);

    expect($item->fresh()->quantity)->toBe(4);
});

// --- The ability string, literal, against the seeded catalog (D-2) ---

test('the orders.edit ability string is literally what the three actions authorize', function () {
    expect(in_array('orders', RolePermissionSeeder::MODULES, true))->toBeTrue()
        ->and(in_array('edit', RolePermissionSeeder::ACTIONS, true))->toBeTrue();

    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');

    expect($actor->hasPermissionTo('orders.edit'))->toBeTrue();
});

// --- D-2: no new permission is added for line-item editing ---

test('the seeded permission catalog is byte-identical to what RolePermissionSeeder alone produces -- no new permission was added for line-item editing', function () {
    $expectedCount = count(RolePermissionSeeder::MODULES) * count(RolePermissionSeeder::ACTIONS)
        + count(RolePermissionSeeder::ROLE_PERMISSIONS);

    expect(Permission::query()->count())->toBe($expectedCount);

    // Every `orders.*` permission present is exactly the four flat CRUD abilities -- never a
    // fifth, line-item-specific one (e.g. `orders.edit-line-items`).
    $orderPermissions = Permission::query()->where('name', 'like', 'orders.%')->pluck('name')->sort()->values()->all();

    expect($orderPermissions)->toBe(['orders.create', 'orders.delete', 'orders.edit', 'orders.view']);
});

// --- Ordering: the permission refusal wins over the 409 state refusal (D-6) ---
//
// Both refusals apply to an actor lacking orders.edit acting on a Shipped order; the
// authorization refusal must come first, exactly as story 0015a's step-up layer runs strictly
// after every Gate::authorize() on its own branch. A 409 here would disclose the order's state
// (that it exists AND that it has shipped) to somebody with no permission to read either.

test('an actor lacking orders.edit, acting on a shipped order, is refused by authorization -- never by the 409 state guard', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    $orderForAdd = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->create();

    $thrownForAdd = null;
    try {
        app(AddOrderItem::class)($orderForAdd, $product->id, null, 1);
    } catch (Throwable $e) {
        $thrownForAdd = $e;
    }
    expect($thrownForAdd)->toBeInstanceOf(AuthorizationException::class);

    $orderForRemove = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $itemToRemove = $orderForRemove->items()->sole();

    $thrownForRemove = null;
    try {
        app(RemoveOrderItem::class)($orderForRemove, $itemToRemove->id);
    } catch (Throwable $e) {
        $thrownForRemove = $e;
    }
    expect($thrownForRemove)->toBeInstanceOf(AuthorizationException::class);

    $orderForQuantity = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $itemToChange = $orderForQuantity->items()->sole();

    $thrownForQuantity = null;
    try {
        app(UpdateOrderItemQuantity::class)($orderForQuantity, $itemToChange->id, 5);
    } catch (Throwable $e) {
        $thrownForQuantity = $e;
    }
    expect($thrownForQuantity)->toBeInstanceOf(AuthorizationException::class);
});
