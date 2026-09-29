<?php

use App\Actions\Orders\CreateOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0045, Phase 3 (TDD "red" step): App\Actions\Orders\CreateOrder does not exist yet -- every
// test below is expected to fail with "Target class [App\Actions\Orders\CreateOrder] does not
// exist" until backend-expert implements it.
//
// D-4: orders carries its own frozen copy of the customer's shipping and billing addresses,
// written at creation and never re-read from the customer afterwards -- the same "never re-derive
// historical financial/logistical data from a mutable source" rule PriceSnapshotTest.php pins for
// unit_price, applied to the twelve address columns.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function actingAddressSnapshotCreator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo('orders.create');
    test()->actingAs($actor);

    return $actor;
}

test('an order for a customer holding both addresses copies all twelve columns onto the order row', function () {
    actingAddressSnapshotCreator();

    $customer = Customer::factory()->create([
        'shipping_address_line1' => 'Calle Mayor 1',
        'shipping_address_line2' => 'Piso 2',
        'shipping_city' => 'Madrid',
        'shipping_postal_code' => '28013',
        'shipping_province' => 'Madrid',
        'shipping_country' => 'ES',
        'billing_address_line1' => 'Avenida Diagonal 100',
        'billing_address_line2' => 'Puerta 4',
        'billing_city' => 'Barcelona',
        'billing_postal_code' => '08019',
        'billing_province' => 'Barcelona',
        'billing_country' => 'ES',
    ]);
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $fresh = $order->fresh();

    expect($fresh->shipping_address_line1)->toBe('Calle Mayor 1')
        ->and($fresh->shipping_address_line2)->toBe('Piso 2')
        ->and($fresh->shipping_city)->toBe('Madrid')
        ->and($fresh->shipping_postal_code)->toBe('28013')
        ->and($fresh->shipping_province)->toBe('Madrid')
        ->and($fresh->shipping_country)->toBe('ES')
        ->and($fresh->billing_address_line1)->toBe('Avenida Diagonal 100')
        ->and($fresh->billing_address_line2)->toBe('Puerta 4')
        ->and($fresh->billing_city)->toBe('Barcelona')
        ->and($fresh->billing_postal_code)->toBe('08019')
        ->and($fresh->billing_province)->toBe('Barcelona')
        ->and($fresh->billing_country)->toBe('ES');
});

// Dedicated regression, same failure shape as the price snapshot: change the customer's address
// AFTER the order exists, re-fetch the order, assert the order's own copy is unaffected.
test('a later change to the customer shipping city does not move an existing order shipping_city', function () {
    actingAddressSnapshotCreator();

    $customer = Customer::factory()->create(['shipping_city' => 'Madrid']);
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $customer->shipping_city = 'Valencia';
    $customer->save();

    $fresh = $order->fresh();

    expect($fresh->shipping_city)->toBe('Madrid');
});

// Story 0041 D-3: a customer may legitimately hold no addresses at all -- the order must still be
// creatable, persisting twelve nulls rather than failing.
test('an order for a customer holding no addresses persists twelve nulls rather than failing', function () {
    actingAddressSnapshotCreator();

    $customer = Customer::factory()->minimal()->create();
    $paymentMethod = PaymentMethod::factory()->create();
    $product = Product::factory()->create();

    $order = app(CreateOrder::class)([
        'customer_id' => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $fresh = $order->fresh();

    expect($fresh->shipping_address_line1)->toBeNull()
        ->and($fresh->shipping_address_line2)->toBeNull()
        ->and($fresh->shipping_city)->toBeNull()
        ->and($fresh->shipping_postal_code)->toBeNull()
        ->and($fresh->shipping_province)->toBeNull()
        ->and($fresh->shipping_country)->toBeNull()
        ->and($fresh->billing_address_line1)->toBeNull()
        ->and($fresh->billing_address_line2)->toBeNull()
        ->and($fresh->billing_city)->toBeNull()
        ->and($fresh->billing_postal_code)->toBeNull()
        ->and($fresh->billing_province)->toBeNull()
        ->and($fresh->billing_country)->toBeNull();
});
