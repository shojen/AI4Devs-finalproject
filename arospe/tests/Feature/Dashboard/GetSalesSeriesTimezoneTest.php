<?php

use App\Actions\Dashboard\GetSalesSeries;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

// Story 0082 (D-6): created_at is stored as Madrid wall time, so days are bucketed in SQL without
// CONVERT_TZ. Boundary instants are written as explicit wall-clock strings. DST days are asserted
// through the bucket they land in, never through the repeated 02:00-03:00 hour of 2026-10-25.

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

test('the application timezone the series relies on is Europe/Madrid', function () {
    expect(config('app.timezone'))->toBe('Europe/Madrid');
});

test('an order at 00:30 on 1 May lands on 1 May, not on 30 April', function () {
    seriesOrder('2026-05-01 00:30:00', '10.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-04-30'), seriesDay('2026-05-01'));

    expect(array_column($series['points'], 'bucket'))->toBe(['2026-04-30', '2026-05-01'])
        ->and(array_column($series['points'], 'sales'))->toBe(['0.00', '10.00']);
});

test('midnight boundaries split days exactly: 23:59:59 stays, 00:00:00 moves on', function () {
    seriesOrder('2026-04-30 23:59:59', '1.00');
    seriesOrder('2026-05-01 00:00:00', '2.00');
    seriesOrder('2026-05-01 23:59:59', '4.00');
    seriesOrder('2026-05-02 00:00:00', '8.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-04-30'), seriesDay('2026-05-02'));

    expect(array_column($series['points'], 'sales'))->toBe(['1.00', '6.00', '8.00']);
});

test('a range that ends on a day includes that whole day through 23:59:59 and excludes the next midnight', function () {
    seriesOrder('2026-05-01 23:59:59', '5.00');
    seriesOrder('2026-05-02 00:00:00', '500.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['points'])->toHaveCount(1)
        ->and($series['totalSales'])->toBe('5.00');
});

test('a range that starts on a day includes 00:00:00 of that day', function () {
    seriesOrder('2026-05-01 00:00:00', '3.00');
    seriesOrder('2026-04-30 23:59:59', '300.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['totalSales'])->toBe('3.00');
});

test('a caller in UTC is read in Madrid time: 22:00 UTC on 30 April starts 1 May', function () {
    seriesOrder('2026-04-30 23:30:00', '100.00'); // 30 April in Madrid
    seriesOrder('2026-05-01 00:15:00', '7.00'); // 1 May in Madrid

    $series = app(GetSalesSeries::class)(
        SalesGranularity::Day,
        CarbonImmutable::parse('2026-04-30 22:00:00', 'UTC'),
        CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC'),
    );

    expect(array_column($series['points'], 'bucket'))->toBe(['2026-05-01'])
        ->and($series['totalSales'])->toBe('7.00');
});

test('the spring-forward day 2026-03-29 is exactly one daily bucket', function () {
    seriesOrder('2026-03-29 00:00:00', '1.00');
    seriesOrder('2026-03-29 01:59:59', '2.00');
    seriesOrder('2026-03-29 03:00:00', '4.00'); // 02:xx does not exist that day
    seriesOrder('2026-03-29 23:59:59', '8.00');
    seriesOrder('2026-03-28 23:59:59', '16.00');
    seriesOrder('2026-03-30 00:00:00', '32.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-03-28'), seriesDay('2026-03-30'));

    expect(array_column($series['points'], 'bucket'))->toBe(['2026-03-28', '2026-03-29', '2026-03-30'])
        ->and(array_column($series['points'], 'sales'))->toBe(['16.00', '15.00', '32.00']);
});

test('the fall-back day 2026-10-25 is exactly one daily bucket', function () {
    seriesOrder('2026-10-25 00:30:00', '1.00');
    seriesOrder('2026-10-25 12:00:00', '2.00');
    seriesOrder('2026-10-25 23:59:59', '4.00');
    seriesOrder('2026-10-24 23:59:59', '8.00');
    seriesOrder('2026-10-26 00:00:00', '16.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-10-24'), seriesDay('2026-10-26'));

    expect(array_column($series['points'], 'bucket'))->toBe(['2026-10-24', '2026-10-25', '2026-10-26'])
        ->and(array_column($series['points'], 'sales'))->toBe(['8.00', '7.00', '16.00']);
});

test('a range that ends on a DST day still includes that whole day', function (string $day, string $lastInstant) {
    seriesOrder($lastInstant, '5.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay($day), seriesDay($day));

    expect($series['points'])->toHaveCount(1)
        ->and($series['totalSales'])->toBe('5.00');
})->with([
    'spring forward' => ['2026-03-29', '2026-03-29 23:59:59'],
    'fall back' => ['2026-10-25', '2026-10-25 23:59:59'],
]);

test('the leap day 2028-02-29 is its own day and February 2028 has 29 days', function () {
    seriesOrder('2028-02-28 23:59:59', '1.00');
    seriesOrder('2028-02-29 00:00:00', '2.00');
    seriesOrder('2028-02-29 23:59:59', '4.00');
    seriesOrder('2028-03-01 00:00:00', '8.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2028-02-01'), seriesDay('2028-02-29'));

    expect($series['points'])->toHaveCount(29)
        ->and($series['points'][27]['bucket'])->toBe('2028-02-28')
        ->and($series['points'][27]['sales'])->toBe('1.00')
        ->and($series['points'][28]['bucket'])->toBe('2028-02-29')
        ->and($series['points'][28]['sales'])->toBe('6.00')
        ->and($series['totalSales'])->toBe('7.00');
});

test('February 2027 has 28 days and February 2028 is one month bucket holding the leap day', function () {
    seriesOrder('2028-02-29 10:00:00', '9.00');

    $feb2027 = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2027-02-01'), seriesDay('2027-02-28'));
    $feb2028 = app(GetSalesSeries::class)(SalesGranularity::Month, seriesDay('2028-02-01'), seriesDay('2028-02-29'));

    expect($feb2027['points'])->toHaveCount(28)
        ->and($feb2028['points'])->toHaveCount(1)
        ->and($feb2028['points'][0]['bucket'])->toBe('2028-02')
        ->and($feb2028['points'][0]['sales'])->toBe('9.00');
});

test('year granularity puts the last second of a year in that year and the first second of the next in the next', function () {
    seriesOrder('2026-12-31 23:59:59', '1.00');
    seriesOrder('2027-01-01 00:00:00', '2.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Year, seriesDay('2026-01-01'), seriesDay('2027-12-31'));

    expect(array_column($series['points'], 'bucket'))->toBe(['2026', '2027'])
        ->and(array_column($series['points'], 'sales'))->toBe(['1.00', '2.00']);
});
