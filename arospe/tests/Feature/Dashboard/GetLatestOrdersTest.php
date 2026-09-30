<?php

use App\Actions\Dashboard\GetLatestOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;
use Tests\Support\Orders\OrdersUi;

// Story 0082 (D-5), Phase 3 TDD red step: App\Actions\Dashboard\GetLatestOrders does not exist yet.
// Every order gets an explicit created_at -- factory rows share timestamps.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Europe/Madrid'));
    test()->actingAs(OrdersUi::actor(['orders.view']));
});

afterEach(function () {
    Model::preventLazyLoading(false);
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function orderCreatedAgo(int $minutesAgo, array $attributes = []): Order
{
    return Order::factory()->create(array_merge([
        'created_at' => Carbon::now()->subMinutes($minutesAgo),
    ], $attributes));
}

it('lists exactly the 5 newest of 7 orders, newest first', function () {
    $orders = collect(range(1, 7))->map(fn (int $minutes): Order => orderCreatedAgo($minutes));

    $listed = app(GetLatestOrders::class)();

    expect(array_column($listed, 'id'))->toBe($orders->take(5)->pluck('id')->all());
});

it('returns fewer than 5 when fewer orders exist and an empty list for an empty shop', function () {
    expect(app(GetLatestOrders::class)())->toBe([]);

    orderCreatedAgo(1);
    orderCreatedAgo(2);

    expect(app(GetLatestOrders::class)())->toHaveCount(2);
});

it('returns the documented shape per order with money as a decimal string', function () {
    $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);
    $order = orderCreatedAgo(1, [
        'customer_id' => $customer->id,
        'order_number' => 'ORD-2026-000042',
        'total' => '123.40',
        'status' => OrderStatus::Shipped,
        'payment_status' => PaymentStatus::Paid,
    ]);

    $row = app(GetLatestOrders::class)()[0];

    expect(array_keys($row))->toBe(['id', 'orderNumber', 'customerName', 'total', 'status', 'paymentStatus', 'createdAt'])
        ->and($row['id'])->toBe($order->id)
        ->and($row['orderNumber'])->toBe('ORD-2026-000042')
        ->and($row['customerName'])->toBe('Ada Lovelace')
        ->and($row['total'])->toBe('123.40')
        ->and($row['status'])->toBe(OrderStatus::Shipped)
        ->and($row['paymentStatus'])->toBe(PaymentStatus::Paid)
        ->and($row['createdAt'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($row['createdAt']->equalTo(Carbon::now()->subMinute()))->toBeTrue();
});

it('returns plain arrays, never Eloquent models', function () {
    orderCreatedAgo(1);

    $listed = app(GetLatestOrders::class)();

    expect($listed)->toBeList()
        ->and($listed[0])->toBeArray();
});

it('includes cancelled orders', function () {
    $cancelled = orderCreatedAgo(1, ['status' => OrderStatus::Cancelled]);

    $row = app(GetLatestOrders::class)()[0];

    expect($row['id'])->toBe($cancelled->id)
        ->and($row['status'])->toBe(OrderStatus::Cancelled);
});

it('breaks created_at ties by id descending and lists the same 5 on every call', function () {
    $sameSecond = Carbon::now()->subHour();
    $ids = collect(range(1, 6))
        ->map(fn (): string => Order::factory()->create(['created_at' => $sameSecond])->id)
        ->all();

    rsort($ids);
    $expected = array_slice($ids, 0, 5);

    expect(array_column(app(GetLatestOrders::class)(), 'id'))->toBe($expected)
        ->and(array_column(app(GetLatestOrders::class)(), 'id'))->toBe($expected);
});

it('still lists an order whose customer was soft-deleted, with that customer name', function () {
    $customer = Customer::factory()->trashed()->create(['name' => 'Grace Hopper']);
    $order = orderCreatedAgo(1, ['customer_id' => $customer->id]);

    $listed = app(GetLatestOrders::class)();

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['id'])->toBe($order->id)
        ->and($listed[0]['customerName'])->toBe('Grace Hopper');
});

it('triggers no lazy loading when lazy loading is prevented', function () {
    foreach (range(1, 5) as $minutes) {
        orderCreatedAgo($minutes, ['customer_id' => Customer::factory()->create()->id]);
    }
    orderCreatedAgo(6, ['customer_id' => Customer::factory()->trashed()->create()->id]);

    Model::preventLazyLoading();

    expect(app(GetLatestOrders::class)())->toHaveCount(5);
});

it('runs exactly 2 domain-table queries (orders and customers), independent of how many orders exist', function (int $orderCount) {
    foreach (range(1, $orderCount) as $minutes) {
        orderCreatedAgo($minutes);
    }

    $queries = DomainQueryLog::capture(fn () => app(GetLatestOrders::class)());

    expect($queries['orders'])->toBe(1)
        ->and($queries['customers'])->toBe(1)
        ->and(DomainQueryLog::total($queries))->toBe(2);
})->with([3, 12]);
