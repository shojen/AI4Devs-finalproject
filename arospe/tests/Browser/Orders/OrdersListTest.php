<?php

// Story 0055 (D-3): the orders screens, split across six browser files BY CONCERN so a red test names
// its own subject. SELECTOR STRATEGY: select by data-test hook (`@hook`), never by visible text --
// every row action is icon-only, and "Orders" / "Cancel" / "Total" all collide with other copy on the
// page. The selects are DRIVEN THE WAY A PERSON DRIVES THEM (->select() on the native <select>): the
// null-property / native-select desync in docs/errors-log-archive.md is invisible to both
// Livewire::test()->set() and a programmatic value write, and this screen binds three selects.
// Every assertion that matters is followed by a SERVER-SIDE check, not just what the DOM says.

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('the sidebar leads to the orders list, and a row detail action lands on that order', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view', 'customers.view']);
    $this->actingAs($actor);

    $customer = Customer::factory()->create(['name' => 'Marta Navegador']);
    $order = Order::factory()->forCustomer($customer)->withItems()->create(['order_number' => 'ORD-BROWSER-1']);

    visit('/dashboard')
        ->assertNoJavaScriptErrors()
        ->click('@sidebar-link-orders')
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/orders')
        ->assertSee('ORD-BROWSER-1')
        ->assertSee('Marta Navegador')
        ->click('@view-order-'.$order->id)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/orders/'.$order->id)
        ->assertSee('ORD-BROWSER-1')
        ->assertSee('Marta Navegador');
});

test('a flagged order shows the needs-attention marker on the list and an unflagged one does not', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['orders.view']);
    $this->actingAs($actor);

    $flagged = Order::factory()->create(['flagged_for_review' => true, 'flag_reason' => null]);
    $plain = Order::factory()->create();

    visit('/orders')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@order-flagged-'.$flagged->id)
        ->assertMissing('@order-flagged-'.$plain->id);
});
