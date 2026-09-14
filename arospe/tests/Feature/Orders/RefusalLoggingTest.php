<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
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
