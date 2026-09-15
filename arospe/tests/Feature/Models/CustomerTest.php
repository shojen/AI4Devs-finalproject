<?php

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Story 0047's App\Models\Customer::orders() tests. All three live here rather than split with
// tests/Unit/Models/CustomerTest.php: building a HasMany relation object calls
// Model::newRelatedInstance(), which needs a resolved database connection -- Unit/Models tests in
// this repo boot no application container at all (Pest.php scopes RefreshDatabase, and the
// framework TestCase it comes bundled with, to Feature/Browser only), so even the "shape" check
// below (no query executed) cannot run there.

test('orders() returns a HasMany relation targeting Order via customer_id, with no default ordering', function () {
    $customer = Customer::factory()->create();
    $relation = $customer->orders();

    expect($relation)->toBeInstanceOf(HasMany::class)
        ->and($relation->getRelated())->toBeInstanceOf(Order::class)
        ->and($relation->getForeignKeyName())->toBe('customer_id')
        ->and($relation->getQuery()->getQuery()->orders)->toBeNull();
});

test('a customer\'s orders returns only that customer\'s own orders, never another customer\'s', function () {
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();

    $orderA = Order::factory()->forCustomer($customerA)->create();
    Order::factory()->forCustomer($customerB)->create();

    $orders = $customerA->orders()->get();

    expect($orders)->toHaveCount(1)
        ->and($orders->first()->id)->toBe($orderA->id);
});
