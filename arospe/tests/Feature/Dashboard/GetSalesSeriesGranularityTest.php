<?php

use App\Actions\Dashboard\GetSalesSeries;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0082 (D-6): day/month/year grouping and the bucket caps of the money series. See
// GetSalesSeriesTest for the shared-fixture note (function_exists-guarded global helpers).

if (! function_exists('seriesActor')) {
    function seriesActor(): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('orders.view');
        test()->actingAs($actor);

        return $actor;
    }

    /**
     * Datasets resolve at collection time, before the classes under test exist, so they carry plain
     * tokens ('default', 'all') that the test bodies turn into the real status lists.
     *
     * @param  array<int, OrderStatus>|string|null  $statuses
     * @return array<int, OrderStatus>|null
     */
    function seriesStatuses(array|string|null $statuses): ?array
    {
        return match (true) {
            $statuses === 'default' => OrderStatus::defaultDashboardSet(),
            $statuses === 'all' => OrderStatus::cases(),
            default => $statuses,
        };
    }

    function seriesDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Madrid');
    }

    function seriesOrder(
        string $createdAt,
        string $total = '100.00',
        OrderStatus $status = OrderStatus::Delivered,
        PaymentStatus $payment = PaymentStatus::PendingPayment,
        string $refunded = '0.00',
    ): Order {
        return Order::factory()->state([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'total' => $total,
            'status' => $status,
            'payment_status' => $payment,
            'refunded_amount' => $refunded,
        ])->create();
    }
}

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    seriesActor();
});

test('sales are grouped per day, per month and per year over 30 April 2026 to 30 April 2027', function (string $granularity, int $count, array $expected) {
    $granularity = SalesGranularity::from($granularity);
    seriesOrder('2026-04-30 10:00:00', '10.00');
    seriesOrder('2026-05-01 10:00:00', '20.00');
    seriesOrder('2027-04-30 10:00:00', '40.00');

    $series = app(GetSalesSeries::class)($granularity, seriesDay('2026-04-30'), seriesDay('2027-04-30'));

    $byBucket = array_column($series['points'], 'sales', 'bucket');
    $nonZero = array_filter($byBucket, fn (string $sales): bool => $sales !== '0.00');

    expect($series['points'])->toHaveCount($count)
        ->and($nonZero)->toBe($expected)
        ->and($series['totalSales'])->toBe('70.00');
})->with([
    'per day' => ['day', 366, ['2026-04-30' => '10.00', '2026-05-01' => '20.00', '2027-04-30' => '40.00']],
    'per month' => ['month', 13, ['2026-04' => '10.00', '2026-05' => '20.00', '2027-04' => '40.00']],
    'per year' => ['year', 2, ['2026' => '30.00', '2027' => '40.00']],
]);

test('income is bucketed the same way as sales for every granularity', function (string $granularity, string $key) {
    $granularity = SalesGranularity::from($granularity);
    seriesOrder('2026-05-10 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::PartiallyRefunded, '25.00');
    seriesOrder('2026-05-20 10:00:00', '50.00', OrderStatus::Shipped, PaymentStatus::Paid);

    $series = app(GetSalesSeries::class)($granularity, seriesDay('2026-05-01'), seriesDay('2026-05-31'));
    $point = collect($series['points'])->firstWhere('bucket', $key);

    expect($point['sales'])->toBe('150.00')
        ->and($point['income'])->toBe('125.00');
})->with([
    'month' => ['month', '2026-05'],
    'year' => ['year', '2026'],
]);

test('a range spanning a year boundary is split into the right months', function () {
    seriesOrder('2026-12-31 23:59:59', '1.00');
    seriesOrder('2027-01-01 00:00:00', '2.00');
    seriesOrder('2027-02-05 12:00:00', '4.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Month, seriesDay('2026-11-20'), seriesDay('2027-02-05'));

    expect(array_column($series['points'], 'bucket'))->toBe(['2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(array_column($series['points'], 'sales'))->toBe(['0.00', '1.00', '2.00', '4.00']);
});

test('a range that starts mid-month still includes the whole first and last month only by its days', function () {
    seriesOrder('2026-05-14 23:59:59', '5.00'); // before from
    seriesOrder('2026-05-15 00:00:00', '7.00'); // first day of the range
    seriesOrder('2026-06-10 23:59:59', '11.00'); // last day of the range
    seriesOrder('2026-06-11 00:00:00', '13.00'); // after to

    $series = app(GetSalesSeries::class)(SalesGranularity::Month, seriesDay('2026-05-15'), seriesDay('2026-06-10'));

    expect(array_column($series['points'], 'sales'))->toBe(['7.00', '11.00']);
});

test('a single-day range yields exactly one point for every granularity', function (string $granularity, string $key) {
    $granularity = SalesGranularity::from($granularity);
    seriesOrder('2026-05-15 12:00:00', '9.99');

    $series = app(GetSalesSeries::class)($granularity, seriesDay('2026-05-15'), seriesDay('2026-05-15'));

    expect($series['points'])->toHaveCount(1)
        ->and($series['points'][0]['bucket'])->toBe($key)
        ->and($series['points'][0]['sales'])->toBe('9.99');
})->with([
    'day' => ['day', '2026-05-15'],
    'month' => ['month', '2026-05'],
    'year' => ['year', '2026'],
]);

test('a range at exactly the bucket cap is accepted', function (string $granularity, string $to, int $cap) {
    $granularity = SalesGranularity::from($granularity);
    $series = app(GetSalesSeries::class)($granularity, seriesDay('2020-01-01'), seriesDay($to));

    expect($series['points'])->toHaveCount($cap);
})->with([
    'day 366' => ['day', '2020-12-31', 366],
    'month 120' => ['month', '2029-12-31', 120],
    'year 50' => ['year', '2069-12-31', 50],
]);

test('a range one bucket over the cap is refused on range and names the cap', function (string $granularity, string $to, int $cap) {
    $granularity = SalesGranularity::from($granularity);
    try {
        app(GetSalesSeries::class)($granularity, seriesDay('2020-01-01'), seriesDay($to));
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['range'])
            ->and($e->errors()['range'][0])->toBe(__('dashboard.errors.range_too_long', ['max' => $cap]))
            ->and($e->errors()['range'][0])->toContain((string) $cap);
    }
})->with([
    'day 367' => ['day', '2021-01-01', 366],
    'month 121' => ['month', '2030-01-01', 120],
    'year 51' => ['year', '2070-01-01', 50],
]);

test('the too-long message is translated per locale', function () {
    app()->setLocale('es');

    try {
        app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2020-01-01'), seriesDay('2021-01-01'));
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['range'][0])->toBe(__('dashboard.errors.range_too_long', ['max' => 366], 'es'))
            ->and($e->errors()['range'][0])->not->toBe('dashboard.errors.range_too_long');
    }
});
