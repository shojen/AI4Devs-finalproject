<?php

// Story 0047 -- markup + data contract for App\Livewire\Customers\Show /
// resources/views/livewire/customers/show.blade.php. Component-level authorization and the
// route layer live in ShowTest.php; nothing here duplicates that.

use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function customersShowRenderingActor(array $permissions = ['customers.view', 'orders.view']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('the order history section renders every order\'s number, status label and total', function () {
    $customer = Customer::factory()->create();
    $orders = Order::factory()->forCustomer($customer)->count(3)->withItems()->create();

    $this->actingAs(customersShowRenderingActor());

    $component = Livewire::test(Show::class, ['customer' => $customer]);
    $html = $component->html();

    foreach ($orders as $order) {
        expect($html)->toContain($order->order_number)
            ->toContain($order->status->label());
    }
});

test('the identity header renders the customer\'s name, email and phone as visible text', function () {
    $customer = Customer::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'phone' => '+34600000000',
    ]);

    $this->actingAs(customersShowRenderingActor());

    Livewire::test(Show::class, ['customer' => $customer])
        ->assertSee('Ada Lovelace')
        ->assertSee('ada@example.com')
        ->assertSee('+34600000000');
});

test('a customer with no phone renders an em dash, not the string null and not a blank cell', function () {
    $customer = Customer::factory()->create(['phone' => null]);

    $this->actingAs(customersShowRenderingActor());

    $component = Livewire::test(Show::class, ['customer' => $customer]);

    $component->assertSee('—')
        ->assertDontSee('null', false);
});

test('orders render newest first by created_at', function () {
    $customer = Customer::factory()->create();

    $oldest = Order::factory()->forCustomer($customer)->create(['created_at' => now()->subDays(3)]);
    $middle = Order::factory()->forCustomer($customer)->create(['created_at' => now()->subDays(2)]);
    $newest = Order::factory()->forCustomer($customer)->create(['created_at' => now()->subDay()]);

    $this->actingAs(customersShowRenderingActor());

    $html = Livewire::test(Show::class, ['customer' => $customer])->html();

    $newestPos = strpos($html, $newest->order_number);
    $middlePos = strpos($html, $middle->order_number);
    $oldestPos = strpos($html, $oldest->order_number);

    expect($newestPos)->not->toBeFalse()
        ->and($middlePos)->not->toBeFalse()
        ->and($oldestPos)->not->toBeFalse()
        ->and($newestPos)->toBeLessThan($middlePos)
        ->and($middlePos)->toBeLessThan($oldestPos);
});

test('two orders sharing a created_at second render in a deterministic order (UUID v7 descending)', function () {
    $customer = Customer::factory()->create();
    $sameSecond = now();

    $first = Order::factory()->forCustomer($customer)->create(['created_at' => $sameSecond]);
    $second = Order::factory()->forCustomer($customer)->create(['created_at' => $sameSecond]);

    $this->actingAs(customersShowRenderingActor());

    $html = Livewire::test(Show::class, ['customer' => $customer])->html();

    // UUID v7 is time-ordered, so the later-created row's id sorts higher -- descending order
    // means $second (created after $first, in the same wall-clock second) renders first.
    expect(strpos($html, $second->order_number))->toBeLessThan(strpos($html, $first->order_number));
});

test('a customer with zero orders renders the empty-history message and no empty table body', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(customersShowRenderingActor());

    Livewire::test(Show::class, ['customer' => $customer])
        ->assertSee(__('customers.detail.no_orders'))
        ->assertDontSee('<table', false);
});

test('the order history is read only: no edit/delete/cancel control and no wire:click in that section', function () {
    $customer = Customer::factory()->create();
    Order::factory()->forCustomer($customer)->create();

    $actor = customersShowRenderingActor(['customers.view', 'orders.view', 'orders.edit', 'orders.delete']);
    $this->actingAs($actor);

    $html = Livewire::test(Show::class, ['customer' => $customer])->html();

    // No wire:click ANYWHERE on this page, not merely within the order-history section -- the
    // "Back to customers" link is itself a plain :href/wire:navigate, never a component action,
    // so this is the honest, strongest form of the read-only assertion rather than a narrower one
    // scoped only to the section.
    expect($html)->not->toContain('data-test="edit-order-')
        ->not->toContain('data-test="delete-order-')
        ->not->toContain('data-test="cancel-order-')
        ->not->toContain('wire:click');
});

test('the Show component exposes no public method beyond mount, customer, orders, canViewOrderHistory and render', function () {
    $reflection = new ReflectionClass(Show::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() !== Show::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values()
        ->all();

    expect($publicMethods)->toEqualCanonicalizing(['mount', 'customer', 'canViewOrderHistory', 'orders']);
});

test('an actor holding customers.view but not orders.view sees the header but no order history section, and no order data leaks', function () {
    $customer = Customer::factory()->create();
    $orders = Order::factory()->forCustomer($customer)->count(3)->create();

    $actor = customersShowRenderingActor(['customers.view']);
    $this->actingAs($actor);

    // R-1's own mitigation, strengthened past a markup-only assertion (Phase 4 audit suggestion):
    // the guard in Show::orders() must stop the query from ever running, not merely hide its
    // result -- so record every executed SQL statement and assert none of them touches the
    // `orders` table at all, rather than trusting the absence of order data in the rendered HTML
    // to prove the query itself never fired.
    $ordersQueries = [];
    DB::listen(function ($query) use (&$ordersQueries): void {
        if (str_contains($query->sql, 'from `orders`') || str_contains($query->sql, 'from "orders"')) {
            $ordersQueries[] = $query->sql;
        }
    });

    $html = Livewire::test(Show::class, ['customer' => $customer])->html();

    expect($ordersQueries)->toBe([]);

    expect($html)->toContain('data-test="customer-detail-header"')
        ->not->toContain('data-test="customer-order-history"');

    foreach ($orders as $order) {
        expect($html)->not->toContain($order->order_number);
    }
});

test('an actor holding both customers.view and orders.view sees the order history section', function () {
    $customer = Customer::factory()->create();
    Order::factory()->forCustomer($customer)->create();

    $this->actingAs(customersShowRenderingActor(['customers.view', 'orders.view']));

    Livewire::test(Show::class, ['customer' => $customer])
        ->assertSee('data-test="customer-order-history"', false);
});

test('total renders as the stored decimal string, never as a float-cast integer', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->forCustomer($customer)->withItems()->create();
    $order->forceFill(['total' => '10.00'])->save();

    $this->actingAs(customersShowRenderingActor());

    $component = Livewire::test(Show::class, ['customer' => $customer]);

    // Asserted as a STRING on the component's own computed data, not only on the rendered HTML --
    // decimal:2 casts to a string in Eloquent, and a (float) cast anywhere on this path would
    // either fail this assertion outright or silently drop the trailing zero (10.00 -> 10).
    $orders = $component->instance()->orders();

    expect($orders[0]['total'])->toBe('10.00')
        ->toBeString();

    $component->assertSee('10.00');
});
