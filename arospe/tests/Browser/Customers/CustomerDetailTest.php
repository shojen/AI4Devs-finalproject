<?php

// Pest 4 browser tests for the customer detail screen (story 0047), per
// ai-spec/tasks/in-progress/0047-customer-order-history-view-ui.md's "Tests to perform" section.
//
// SELECTOR STRATEGY: select by data-test hook for the one interaction this file drives (the
// list's row detail action); content assertions (a customer's own name, the empty-history copy)
// are naturally text-based.

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function customerDetailBrowserActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['customers.view', 'orders.view']);

    return $actor;
}

test('clicking a customer row\'s detail action from the list lands on its detail page with name and orders', function () {
    $customer = Customer::factory()->create(['name' => 'Diego Ferrer']);
    $order = Order::factory()->forCustomer($customer)->withItems()->create();

    $this->actingAs(customerDetailBrowserActor());

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@view-customer-'.$customer->id)
        ->assertNoJavaScriptErrors()
        ->assertSee('Diego Ferrer')
        ->assertSee($order->order_number);
});

test('a customer with no orders shows the empty-history message on the detail page', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(customerDetailBrowserActor());

    visit(route('customers.show', $customer))
        ->assertNoJavaScriptErrors()
        ->assertSee(__('customers.detail.no_orders'));
});
