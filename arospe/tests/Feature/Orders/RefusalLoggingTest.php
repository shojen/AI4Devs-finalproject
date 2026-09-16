<?php

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RemoveOrderItem;
use App\Enums\OrderStatus;
use App\Exceptions\OrderNotEditableException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet -- every
// test below is expected to fail (class not found) until backend-expert implements it.
//
// Shape copied from tests/Feature/ShippingZones/RefusalLoggingTest.php's CreateShippingZone case
// (a class-level target, no targetId) -- CreateOrder authorizes `create` against Order::class with
// no row yet, matching that exact shape (task file's step 1: "No targetId -- this is a class-level
// `create` check with no row yet"). `array_key_exists('target_id', $context)` is asserted alongside
// `=== null` deliberately -- an absent key and a null value are different bugs and `??` conflates
// them (per the task file's own instruction).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('a refused CreateOrder logs the refusal through LogRefusedPrivilegedAttempt', function () {
    Log::spy();

    $actor = User::factory()->create();
    test()->actingAs($actor);

    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    try {
        app(CreateOrder::class)([
            'customer_id' => $customer->id,
            'payment_method_id' => $paymentMethod->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'create'
            && ($context['target_type'] ?? null) === 'order'
            && array_key_exists('target_id', $context) && $context['target_id'] === null)
        ->once();

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// --- Story 0048, Phase 4 security audit finding F-6 ---
//
// None of the three line-item actions' direct-throw refusals (the hard block, D-1's last-item
// guard, F-2's line-item ceiling) were logged through LogRefusedPrivilegedAttempt -- unlike
// CreateOrder's own Gate-mediated refusal above. Fixed by calling ::log() immediately before each
// existing `throw`, matching this project's story 0015b convention (e.g.
// App\Actions\Shipping\DeleteShippingZone's own 'zone_in_use' reason).

test('AddOrderItem logs order_not_editable when the hard block refuses', function () {
    Log::spy();

    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->withItems(1)->create(['status' => OrderStatus::Shipped]);
    $product = Product::factory()->create();

    try {
        app(AddOrderItem::class)($order, $product->id, null, 1);
    } catch (OrderNotEditableException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'order_not_editable'
            && ($context['target_type'] ?? null) === 'order'
            && ($context['target_id'] ?? null) === $order->id)
        ->once();
});

test('RemoveOrderItem logs last_line_item when D-1s last-item guard refuses', function () {
    Log::spy();

    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->withItems(1)->create();
    $onlyItem = $order->items()->sole();

    try {
        app(RemoveOrderItem::class)($order, $onlyItem->id);
    } catch (ValidationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'last_line_item'
            && ($context['target_type'] ?? null) === 'order'
            && ($context['target_id'] ?? null) === $order->id)
        ->once();
});

test('AddOrderItem logs order_item_limit_reached when the line-item ceiling refuses', function () {
    Log::spy();

    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.edit');
    test()->actingAs($actor);

    $order = Order::factory()->withItems(AddOrderItem::MAX_ITEMS)->create();
    $product = Product::factory()->create();

    try {
        app(AddOrderItem::class)($order, $product->id, null, 1);
    } catch (ValidationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'order_item_limit_reached'
            && ($context['target_type'] ?? null) === 'order'
            && ($context['target_id'] ?? null) === $order->id)
        ->once();
});
