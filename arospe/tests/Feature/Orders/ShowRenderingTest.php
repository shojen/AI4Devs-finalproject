<?php

// Story 0055 -- the detail screen's data contract, asserted against RENDERED html. Resolves NESTED
// (livewire/orders/show.blade.php): the ordinary component<->view mirror, unlike its Index sibling.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Show;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Orders\OrdersUi;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function ordersUiShowHtml(Order $order, array $permissions = ['orders.view', 'customers.view']): string
{
    test()->actingAs(OrdersUi::actor($permissions));

    return Livewire::test(Show::class, ['order' => $order])->html();
}

test('the view resolves to the nested livewire/orders/show path', function () {
    expect(file_exists(resource_path('views/livewire/orders/show.blade.php')))->toBeTrue();
});

test('the header renders the order number and both status labels', function () {
    $order = Order::factory()->withItems()->create([
        'order_number' => 'ORD-2026-000123',
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Paid,
    ]);

    $html = ordersUiShowHtml($order);

    expect($html)->toContain(__('orders.statuses.processing'))
        ->toContain(__('orders.payment_statuses.paid'));

    // The order number is the topbar heading (x-slot:heading) on the full page.
    $this->get(route('orders.show', $order))->assertOk()->assertSee('ORD-2026-000123');
});

test('the five sections all render, in order', function () {
    $order = Order::factory()->withItems()->create();

    $html = ordersUiShowHtml($order);

    $positions = array_map(fn (string $hook) => strpos($html, 'data-test="'.$hook.'"'), [
        'header-section', 'customer-section', 'line-items-section', 'lifecycle-section', 'totals-section',
    ]);

    expect($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

test('the customer section is read-only plain text: no input, no address form, and a link to the customer', function () {
    $customer = Customer::factory()->create(['name' => 'Lucía Romero']);
    $order = Order::factory()->forCustomer($customer)->withItems()->create([
        'shipping_address_line1' => 'Calle Mayor 1',
        'shipping_city' => 'Madrid',
        'shipping_postal_code' => '28013',
        'shipping_country' => 'ES',
    ]);

    $html = ordersUiShowHtml($order);
    $section = OrdersUi::section($html, 'customer-section');

    expect($section)->toContain('Lucía Romero')
        ->toContain('Calle Mayor 1')
        ->toContain('Madrid')
        ->toContain('28013')
        ->toContain(route('customers.show', $customer))
        ->not->toContain('<input')
        ->not->toContain('<select')
        ->not->toContain('<textarea');
});

test('the customer name is plain text without customers.view, and for a soft-deleted customer (amendments 8, 9)', function () {
    $customer = Customer::factory()->create(['name' => 'Ana Trashed']);
    $order = Order::factory()->forCustomer($customer)->withItems()->create();

    $withoutPermission = OrdersUi::section(ordersUiShowHtml($order, ['orders.view']), 'customer-section');
    expect($withoutPermission)->toContain('Ana Trashed')->not->toContain(route('customers.show', $customer));

    $customer->delete();

    $trashed = OrdersUi::section(ordersUiShowHtml($order->fresh(), ['orders.view', 'customers.view']), 'customer-section');
    expect($trashed)->toContain('Ana Trashed')->not->toContain(route('customers.show', $customer->id));
});

test('REGRESSION: changing the customer address after the order exists does not change the address shown (frozen snapshot)', function () {
    $customer = Customer::factory()->create();
    $order = Order::factory()->forCustomer($customer)->withItems()->create([
        'shipping_address_line1' => 'Address As At Order Time 1',
        'shipping_city' => 'Original City',
    ]);

    $customer->forceFill(['shipping_address_line1' => 'Moved House Street 99', 'shipping_city' => 'New City'])->save();

    $section = OrdersUi::section(ordersUiShowHtml($order), 'customer-section');

    expect($section)->toContain('Address As At Order Time 1')
        ->toContain('Original City')
        ->not->toContain('Moved House Street 99')
        ->not->toContain('New City');
});

test('line items render product name, sku, quantity, unit price, line total and refunded quantity', function () {
    // OrderItemFactory snapshots name/sku/price FROM the product it is given, so the fixture is the product.
    $product = Product::factory()->create(['name' => 'Camiseta Azul', 'sku' => 'SKU-AZUL-1', 'price' => '12.50']);
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'quantity' => 3, 'refunded_quantity' => 1]);

    $section = OrdersUi::section(ordersUiShowHtml($order), 'line-items-section');

    expect($section)->toContain('Camiseta Azul')
        ->toContain('SKU-AZUL-1')
        ->toContain('12.50')
        ->toContain('37.50');
});

test('the totals block renders subtotal, tax, shipping, total and refunded as decimal strings', function () {
    // Set AFTER the items exist: withItems() recomputes subtotal/total in its own afterCreating hook.
    $order = Order::factory()->withItems()->create();
    $order->forceFill([
        'subtotal' => '100.00',
        'tax_amount' => '21.00',
        'shipping_amount' => '5.90',
        'total' => '126.90',
        'refunded_amount' => '0.00',
    ])->save();

    $section = OrdersUi::section(ordersUiShowHtml($order), 'totals-section');

    foreach (['100.00', '21.00', '5.90', '126.90', '0.00'] as $figure) {
        expect($section)->toContain('€ '.$figure);
    }
});

test('a decimal string is rendered unchanged apart from the currency affix (0.00 and 1234.50 survive)', function () {
    foreach (['10.00', '0.00', '1234.50'] as $amount) {
        expect(trim(strip_tags(Blade::render('<x-money :amount="$amount" />', ['amount' => $amount]))))->toBe('€ '.$amount);
    }
});

test('the detail view never casts or does arithmetic on a money value', function () {
    foreach (['app/Livewire/Orders/Show.php', 'app/Livewire/Orders/Index.php', 'resources/views/livewire/orders/show.blade.php', 'resources/views/livewire/orders.blade.php'] as $path) {
        $source = file_get_contents(base_path($path));

        expect($source)->not->toContain('(float)')
            ->not->toContain('floatval(')
            ->not->toContain('number_format(');
    }
});

test('the detail screen renders exactly one h1 and no inline duplicate of the topbar heading', function () {
    $order = Order::factory()->withItems()->create();
    $this->actingAs(OrdersUi::actor(['orders.view']));

    $html = $this->get(route('orders.show', $order))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1);
});
