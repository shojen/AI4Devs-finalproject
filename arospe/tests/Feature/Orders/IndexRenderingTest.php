<?php

// Story 0055 -- view-level rendering tests for App\Livewire\Orders\Index /
// resources/views/livewire/orders.blade.php (the FLAT path: Index-in-a-subfolder exception).
// Every assertion is against the RENDERED HTML, never against the collection the component built.

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Orders\Index;
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
function ordersUiRenderingActor(array $permissions = ['orders.view', 'customers.view']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('three orders render their number, customer name, both status labels, total and date', function () {
    $this->actingAs(ordersUiRenderingActor());

    $customer = Customer::factory()->create(['name' => 'Marta Ruiz']);
    $order = Order::factory()->forCustomer($customer)->create([
        'order_number' => 'ORD-2026-000042',
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Paid,
        'total' => '10.00',
        'created_at' => '2026-03-04 10:15:00',
    ]);
    Order::factory()->count(2)->create();

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('ORD-2026-000042')
        ->toContain('Marta Ruiz')
        ->toContain(__('orders.statuses.processing'))
        ->toContain(__('orders.payment_statuses.paid'))
        ->toContain('04/03/2026')
        ->and($order->id)->not->toBeEmpty();
});

test('orders render newest first, asserted on the rendered position of each order number', function () {
    $this->actingAs(ordersUiRenderingActor());

    Order::factory()->create(['order_number' => 'ORD-OLDEST', 'created_at' => '2026-01-01 09:00:00']);
    Order::factory()->create(['order_number' => 'ORD-NEWEST', 'created_at' => '2026-03-01 09:00:00']);
    Order::factory()->create(['order_number' => 'ORD-MIDDLE', 'created_at' => '2026-02-01 09:00:00']);

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect(strpos($html, 'ORD-NEWEST'))->toBeLessThan(strpos($html, 'ORD-MIDDLE'))
        ->and(strpos($html, 'ORD-MIDDLE'))->toBeLessThan(strpos($html, 'ORD-OLDEST'));
});

test('two orders sharing a created_at second render deterministically, UUID v7 descending', function () {
    $this->actingAs(ordersUiRenderingActor());

    $first = Order::factory()->create(['order_number' => 'ORD-TIE-A', 'created_at' => '2026-03-01 09:00:00']);
    $second = Order::factory()->create(['order_number' => 'ORD-TIE-B', 'created_at' => '2026-03-01 09:00:00']);

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    // UUID v7 is time-ordered: the later-created row has the greater id and therefore sorts first.
    [$higher, $lower] = strcmp($first->id, $second->id) > 0
        ? ['ORD-TIE-A', 'ORD-TIE-B']
        : ['ORD-TIE-B', 'ORD-TIE-A'];

    expect(strpos($html, $higher))->toBeLessThan(strpos($html, $lower));
});

test('zero orders renders the explicit empty state and no table', function () {
    $this->actingAs(ordersUiRenderingActor());

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain(__('orders.index.empty'))
        ->not->toContain('data-flux-table');
});

test('each row carries a view-order hook whose href is the order detail route', function () {
    $this->actingAs(ordersUiRenderingActor());

    $order = Order::factory()->create();

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toMatch('/data-test="view-order-'.$order->id.'"/')
        ->and($html)->toContain('href="'.route('orders.show', $order).'"');
});

test('a flagged order renders the needs-attention marker and an unflagged one does not', function () {
    $this->actingAs(ordersUiRenderingActor());

    $flagged = Order::factory()->create(['flagged_for_review' => true, 'flag_reason' => 'billing_ip_country_mismatch']);
    $plain = Order::factory()->create();

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain('data-test="order-flagged-'.$flagged->id.'"')
        ->not->toContain('data-test="order-flagged-'.$plain->id.'"');
});

test('the marker explains why: the recorded reason label is offered with it', function () {
    $this->actingAs(ordersUiRenderingActor());

    Order::factory()->create(['flagged_for_review' => true, 'flag_reason' => 'billing_ip_country_mismatch']);

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain(e(__('orders.flag_reasons.billing_ip_country_mismatch')));
});

test('a flagged order whose flag_reason is NULL still renders the marker, with the generic copy', function () {
    $this->actingAs(ordersUiRenderingActor());

    // The 0053-flagged / 0054-unreasoned combination: reachable today and the MAJORITY of flagged
    // orders (0053 sets the flag without a reason in six cases) -- D-15.
    $order = Order::factory()->create(['flagged_for_review' => true, 'flag_reason' => null]);

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain('data-test="order-flagged-'.$order->id.'"')
        ->toContain(e(__('orders.index.flagged_generic')));
});

test('the marker copy reads as needs-attention, never as an error or invalid', function () {
    foreach (['en', 'es'] as $locale) {
        $copy = mb_strtolower(trans('orders.index.flagged', [], $locale).' '.trans('orders.index.flagged_generic', [], $locale));

        expect($copy)->not->toContain('error')->not->toContain('invalid')->not->toContain('inválid');
    }
});

test('the total renders as the stored decimal string', function () {
    $this->actingAs(ordersUiRenderingActor());

    Order::factory()->create(['total' => '10.00']);

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain('10.00');
});

test('the customer cell links to the customer for an actor holding customers.view', function () {
    $this->actingAs(ordersUiRenderingActor(['orders.view', 'customers.view']));

    $customer = Customer::factory()->create();
    Order::factory()->forCustomer($customer)->create();

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('customers.show', $customer).'"');
});

test('the customer cell is plain text for an actor without customers.view (amendment 9)', function () {
    $this->actingAs(ordersUiRenderingActor(['orders.view']));

    $customer = Customer::factory()->create(['name' => 'Plain Text Customer']);
    Order::factory()->forCustomer($customer)->create();

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain('Plain Text Customer')
        ->not->toContain(route('customers.show', $customer));
});

test('an order whose customer was soft-deleted still lists, with the name as plain text (amendment 8)', function () {
    $this->actingAs(ordersUiRenderingActor(['orders.view', 'customers.view']));

    $customer = Customer::factory()->create(['name' => 'Gone Customer']);
    $order = Order::factory()->forCustomer($customer)->create(['order_number' => 'ORD-GONE']);
    $customer->delete();

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->toContain('ORD-GONE')
        ->toContain('Gone Customer')
        ->not->toContain(route('customers.show', $customer->id))
        ->and($order->id)->not->toBeEmpty();
});

test('the summary counts orders through a plural-aware translation', function () {
    $this->actingAs(ordersUiRenderingActor());

    Order::factory()->count(2)->create();

    Livewire::test(Index::class)->assertSee(trans_choice('orders.index.summary', 2, ['count' => 2]));
});

test('the list view resolves to the flat livewire/orders path, never orders/index', function () {
    expect(file_exists(resource_path('views/livewire/orders.blade.php')))->toBeTrue()
        ->and(file_exists(resource_path('views/livewire/orders/index.blade.php')))->toBeFalse();
});

test('the list carries no create control: it is read-only (D-13)', function () {
    $this->actingAs(ordersUiRenderingActor());

    $html = $this->get(route('orders.index'))->assertOk()->getContent();

    expect($html)->not->toContain('data-test="create-order"');
});

test('rendering the list loads the order book once, not once per computed read', function () {
    $this->actingAs(ordersUiRenderingActor());
    Order::factory()->count(3)->create();

    $listReads = 0;
    DB::listen(function ($query) use (&$listReads): void {
        if (preg_match('/from [`"]orders[`"] order by/i', $query->sql)) {
            $listReads++;
        }
    });

    Livewire::test(Index::class);

    expect($listReads)->toBe(1);
});
