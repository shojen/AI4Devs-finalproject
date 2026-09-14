<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder and App\Policies\OrderPolicy
// do not exist yet -- every test below is expected to fail (class not found, or a valid actor
// still refused because no policy grants the ability) until backend-expert implements them. This
// story ships no route and no Livewire component (D-13's whole "no per-target rule" is precisely
// because CreateOrder is the ONLY reachable enforcement point), so every authorization assertion
// here is action-level, matching tests/Feature/ShippingRates/CreateShippingRateTest.php's own
// authorization block.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function validOrderPayload(): array
{
    $customer = Customer::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    return [
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ];
}

test('an administrator holding orders.view but not orders.create is refused by CreateOrder, and zero rows are written', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.view');
    test()->actingAs($actor);

    expect(fn () => app(CreateOrder::class)(validOrderPayload()))
        ->toThrow(AuthorizationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('an administrator with no orders permission at all is refused by CreateOrder, and zero rows are written', function () {
    $actor = User::factory()->create();
    test()->actingAs($actor);

    expect(fn () => app(CreateOrder::class)(validOrderPayload()))
        ->toThrow(AuthorizationException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// The positive case beside the 403 -- without which a mistyped ability passes silently.
test('an administrator holding orders.create succeeds', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    $order = app(CreateOrder::class)(validOrderPayload());

    expect(Order::count())->toBe(1)
        ->and($order->exists)->toBeTrue();
});

test('a Super Admin holding no individual orders permission succeeds via Gate::before', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    test()->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $order = app(CreateOrder::class)(validOrderPayload());

    expect(Order::count())->toBe(1)
        ->and($order->exists)->toBeTrue();
});

// R-6: the ability string is asserted LITERALLY against the seeded catalog, so a typo cannot fail
// closed unnoticed. The constants are now the single place each string is written.
test('the four OrderPolicy permission constants equal the seeded orders.* catalog names', function () {
    expect(OrderPolicy::VIEW_PERMISSION)->toBe('orders.view')
        ->and(OrderPolicy::CREATE_PERMISSION)->toBe('orders.create')
        ->and(OrderPolicy::EDIT_PERMISSION)->toBe('orders.edit')
        ->and(OrderPolicy::DELETE_PERMISSION)->toBe('orders.delete');

    expect(in_array('orders', RolePermissionSeeder::MODULES, true))->toBeTrue();

    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'orders.create', 'orders.edit', 'orders.delete']);

    expect($actor->getAllPermissions())->toHaveCount(4);
});
