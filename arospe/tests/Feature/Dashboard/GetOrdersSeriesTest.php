<?php

use App\Actions\Dashboard\GetOrdersSeries;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;

// Story 0082 (D-6): the orders series (COUNT per bucket plus a per-status breakdown).
// GetOrdersSeries does not exist yet -- red until implemented. Shared fixtures: see
// GetSalesSeriesTest (function_exists-guarded global helpers under the `series` prefix).

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

test('the orders series returns the documented shape', function () {
    seriesOrder('2026-05-02 10:00:00', '10.00', OrderStatus::Pending);

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect(array_keys($series))->toEqualCanonicalizing(['granularity', 'from', 'to', 'statuses', 'totalOrders', 'points'])
        ->and($series['granularity'])->toBe(SalesGranularity::Day)
        ->and($series['from']->format('Y-m-d'))->toBe('2026-05-01')
        ->and($series['to']->format('Y-m-d'))->toBe('2026-05-03')
        ->and($series['to']->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($series['statuses'])->toBe(OrderStatus::defaultDashboardSet())
        ->and($series['totalOrders'])->toBe(1)
        ->and($series['points'])->toHaveCount(3)
        ->and(array_keys($series['points'][1]))->toEqualCanonicalizing(['bucket', 'label', 'total', 'byStatus'])
        ->and($series['points'][1]['bucket'])->toBe('2026-05-02')
        ->and($series['points'][1]['label']->format('Y-m-d'))->toBe('2026-05-02')
        ->and($series['points'][1]['total'])->toBe(1);
});

test('the orders chart counts orders per day: 3 on 1 May, none on 2 May, 1 on 3 May', function () {
    foreach (['08:00:00', '12:00:00', '20:00:00'] as $time) {
        seriesOrder("2026-05-01 {$time}");
    }
    seriesOrder('2026-05-03 09:00:00');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect(array_column($series['points'], 'total'))->toBe([3, 0, 1])
        ->and($series['totalOrders'])->toBe(4);
});

test('each period is broken down by status, with every other selected status at zero', function () {
    seriesOrder('2026-05-01 08:00:00', '1.00', OrderStatus::Pending);
    seriesOrder('2026-05-01 09:00:00', '1.00', OrderStatus::Pending);
    seriesOrder('2026-05-01 10:00:00', '1.00', OrderStatus::Shipped);

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['points'][0]['total'])->toBe(3)
        ->and($series['points'][0]['byStatus'])->toEqual([
            'pending' => 2,
            'processing' => 0,
            'shipped' => 1,
            'delivered' => 0,
        ])
        ->and($series['points'][0]['byStatus']['cancelled'] ?? null)->toBeNull();
});

test('byStatus has a zero-filled key for every selected status on an empty bucket', function (array|string|null $statuses, array $expectedKeys) {
    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-02'), seriesStatuses($statuses));

    foreach ($series['points'] as $point) {
        expect($point['total'])->toBe(0)
            ->and(array_keys($point['byStatus']))->toEqualCanonicalizing($expectedKeys)
            ->and(array_sum($point['byStatus']))->toBe(0);
    }

    expect($series['totalOrders'])->toBe(0);
})->with([
    'default set' => [null, ['pending', 'processing', 'shipped', 'delivered']],
    'all five statuses' => ['all', ['pending', 'processing', 'shipped', 'delivered', 'cancelled']],
    'cancelled only' => [[OrderStatus::Cancelled], ['cancelled']],
]);

test('the status filter narrows the count, with cancelled excluded by default and included when chosen', function (array|string|null $statuses, int $orders, array $byStatus) {
    seriesOrder('2026-05-01 10:00:00', '10.00', OrderStatus::Pending);
    seriesOrder('2026-05-01 10:00:00', '20.00', OrderStatus::Processing);
    seriesOrder('2026-05-01 10:00:00', '30.00', OrderStatus::Shipped);
    seriesOrder('2026-05-01 10:00:00', '40.00', OrderStatus::Delivered);
    seriesOrder('2026-05-01 10:00:00', '50.00', OrderStatus::Cancelled);

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), seriesStatuses($statuses));

    expect($series['totalOrders'])->toBe($orders)
        ->and($series['points'][0]['total'])->toBe($orders)
        ->and($series['points'][0]['byStatus'])->toEqual($byStatus);
})->with([
    'null = default set' => [null, 4, ['pending' => 1, 'processing' => 1, 'shipped' => 1, 'delivered' => 1]],
    'only cancelled' => [[OrderStatus::Cancelled], 1, ['cancelled' => 1]],
    'all five' => ['all', 5, ['pending' => 1, 'processing' => 1, 'shipped' => 1, 'delivered' => 1, 'cancelled' => 1]],
    'delivered and shipped' => [[OrderStatus::Delivered, OrderStatus::Shipped], 2, ['delivered' => 1, 'shipped' => 1]],
    'pending' => [[OrderStatus::Pending], 1, ['pending' => 1]],
]);

test('the status filter example: delivered, delivered + shipped, pending and all except cancelled', function (array|string|null $statuses, int $orders) {
    seriesOrder('2026-05-01 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    seriesOrder('2026-05-01 10:00:00', '50.00', OrderStatus::Shipped, PaymentStatus::Paid);
    seriesOrder('2026-05-01 10:00:00', '20.00', OrderStatus::Pending, PaymentStatus::PendingPayment);

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), seriesStatuses($statuses));

    expect($series['totalOrders'])->toBe($orders);
})->with([
    'delivered' => [[OrderStatus::Delivered], 1],
    'delivered, shipped' => [[OrderStatus::Delivered, OrderStatus::Shipped], 2],
    'pending' => [[OrderStatus::Pending], 1],
    'all except cancelled' => ['default', 3],
]);

test('the orders count ignores payment state and amounts', function () {
    seriesOrder('2026-05-01 10:00:00', '0.00', OrderStatus::Pending, PaymentStatus::PendingPayment);
    seriesOrder('2026-05-01 10:00:00', '99.99', OrderStatus::Delivered, PaymentStatus::Refunded, '99.99');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['totalOrders'])->toBe(2);
});

test('totals equal the sum of the points and every point equals the sum of its byStatus', function () {
    seriesOrder('2026-05-01 10:00:00', '1.00', OrderStatus::Pending);
    seriesOrder('2026-05-01 11:00:00', '1.00', OrderStatus::Delivered);
    seriesOrder('2026-05-02 11:00:00', '1.00', OrderStatus::Cancelled);
    seriesOrder('2026-05-03 11:00:00', '1.00', OrderStatus::Shipped);

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'), OrderStatus::cases());

    foreach ($series['points'] as $point) {
        expect($point['total'])->toBe(array_sum($point['byStatus']));
    }

    expect($series['totalOrders'])->toBe(4)
        ->and(array_sum(array_column($series['points'], 'total')))->toBe(4);
});

test('orders outside the range are excluded', function () {
    seriesOrder('2026-04-30 23:59:59');
    seriesOrder('2026-05-02 12:00:00');
    seriesOrder('2026-05-04 00:00:00');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect($series['totalOrders'])->toBe(1)
        ->and(array_column($series['points'], 'total'))->toBe([0, 1, 0]);
});

test('orders are grouped per day, month and year over 30 April 2026 to 30 April 2027', function (string $granularity, int $count, array $expected) {
    $granularity = SalesGranularity::from($granularity);
    seriesOrder('2026-04-30 10:00:00');
    seriesOrder('2026-05-01 10:00:00');
    seriesOrder('2026-05-01 11:00:00');
    seriesOrder('2027-04-30 10:00:00');

    $series = app(GetOrdersSeries::class)($granularity, seriesDay('2026-04-30'), seriesDay('2027-04-30'));

    $nonZero = array_filter(array_column($series['points'], 'total', 'bucket'), fn (int $total): bool => $total !== 0);

    expect($series['points'])->toHaveCount($count)
        ->and($nonZero)->toBe($expected)
        ->and($series['totalOrders'])->toBe(4);
})->with([
    'per day' => ['day', 366, ['2026-04-30' => 1, '2026-05-01' => 2, '2027-04-30' => 1]],
    'per month' => ['month', 13, ['2026-04' => 1, '2026-05' => 2, '2027-04' => 1]],
    'per year' => ['year', 2, ['2026' => 3, '2027' => 1]],
]);

test('Madrid day boundaries apply: 00:00:00 and 00:30 belong to the new day, 23:59:59 to the old one', function () {
    seriesOrder('2026-04-30 23:59:59');
    seriesOrder('2026-05-01 00:00:00');
    seriesOrder('2026-05-01 00:30:00');
    seriesOrder('2026-05-01 23:59:59');
    seriesOrder('2026-05-02 00:00:00');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-04-30'), seriesDay('2026-05-02'));

    expect(array_column($series['points'], 'total'))->toBe([1, 3, 1]);
});

test('the DST days are each one bucket', function (string $day, string $before, string $after) {
    seriesOrder($before);
    seriesOrder("{$day} 00:30:00");
    seriesOrder("{$day} 23:59:59");
    seriesOrder($after);

    $from = seriesDay($day)->subDay()->format('Y-m-d');
    $to = seriesDay($day)->addDay()->format('Y-m-d');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay($from), seriesDay($to));

    expect(array_column($series['points'], 'bucket'))->toBe([$from, $day, $to])
        ->and(array_column($series['points'], 'total'))->toBe([1, 2, 1]);
})->with([
    'spring forward' => ['2026-03-29', '2026-03-28 23:59:59', '2026-03-30 00:00:00'],
    'fall back' => ['2026-10-25', '2026-10-24 23:59:59', '2026-10-26 00:00:00'],
]);

test('the leap day 2028-02-29 is its own day', function () {
    seriesOrder('2028-02-29 12:00:00');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2028-02-01'), seriesDay('2028-02-29'));

    expect($series['points'])->toHaveCount(29)
        ->and($series['points'][28]['bucket'])->toBe('2028-02-29')
        ->and($series['points'][28]['total'])->toBe(1);
});

test('a month range across a year boundary is bucketed per calendar month', function () {
    seriesOrder('2026-12-31 23:59:59');
    seriesOrder('2027-01-01 00:00:00');

    $series = app(GetOrdersSeries::class)(SalesGranularity::Month, seriesDay('2026-11-20'), seriesDay('2027-02-05'));

    expect(array_column($series['points'], 'bucket'))->toBe(['2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(array_column($series['points'], 'total'))->toBe([0, 1, 1, 0]);
});

test('a single-day range yields one bucket for every granularity', function (string $granularity, string $key) {
    $granularity = SalesGranularity::from($granularity);
    seriesOrder('2026-05-15 12:00:00');

    $series = app(GetOrdersSeries::class)($granularity, seriesDay('2026-05-15'), seriesDay('2026-05-15'));

    expect($series['points'])->toHaveCount(1)
        ->and($series['points'][0]['bucket'])->toBe($key)
        ->and($series['points'][0]['total'])->toBe(1);
})->with([
    'day' => ['day', '2026-05-15'],
    'month' => ['month', '2026-05'],
    'year' => ['year', '2026'],
]);

test('bucket keys are ascending and unique', function () {
    $series = app(GetOrdersSeries::class)(SalesGranularity::Month, seriesDay('2025-01-01'), seriesDay('2026-12-31'));
    $keys = array_column($series['points'], 'bucket');
    $sorted = $keys;
    sort($sorted);

    expect($keys)->toHaveCount(24)->and($keys)->toBe($sorted)->and(array_unique($keys))->toHaveCount(24);
});

test('a range at exactly the cap is accepted and one bucket over is refused', function (string $granularity, string $ok, int $cap, string $over) {
    $granularity = SalesGranularity::from($granularity);
    $accepted = app(GetOrdersSeries::class)($granularity, seriesDay('2020-01-01'), seriesDay($ok));

    expect($accepted['points'])->toHaveCount($cap);

    try {
        app(GetOrdersSeries::class)($granularity, seriesDay('2020-01-01'), seriesDay($over));
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['range'])
            ->and($e->errors()['range'][0])->toBe(__('dashboard.errors.range_too_long', ['max' => $cap]));
    }
})->with([
    'day 366/367' => ['day', '2020-12-31', 366, '2021-01-01'],
    'month 120/121' => ['month', '2029-12-31', 120, '2030-01-01'],
    'year 50/51' => ['year', '2069-12-31', 50, '2070-01-01'],
]);

test('from after to is refused on range with the translated message', function () {
    try {
        app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'));
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['range'])
            ->and($e->errors()['range'][0])->toBe(__('dashboard.errors.range_invalid'))
            ->and($e->errors()['range'][0])->not->toBe('dashboard.errors.range_invalid');
    }
});

test('an empty status list is refused on statuses, and an invalid range adds the range key to the same exception', function () {
    try {
        app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-02'), []);
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['statuses'])
            ->and($e->errors()['statuses'][0])->toBe(__('dashboard.errors.statuses_required'));
    }

    try {
        app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'), []);
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['range', 'statuses']);
    }
});

test('the orders series runs exactly one aggregate query on orders, however many orders exist', function (int $orders) {
    foreach (range(1, $orders) as $i) {
        seriesOrder('2026-05-0'.(($i % 3) + 1).' 10:00:00', '1.00', OrderStatus::cases()[$i % 5]);
    }

    $counts = DomainQueryLog::capture(
        fn () => app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'), OrderStatus::cases()),
    );

    expect($counts['orders'])->toBe(1)
        ->and(DomainQueryLog::total($counts))->toBe(1);
})->with([1, 10]);

test('an empty shop still costs one orders query', function () {
    $counts = DomainQueryLog::capture(
        fn () => app(GetOrdersSeries::class)(SalesGranularity::Year, seriesDay('2024-01-01'), seriesDay('2026-12-31')),
    );

    expect($counts['orders'])->toBe(1);
});

test('an actor without orders.view is refused, logged against the order target and no orders query runs', function () {
    Log::spy();
    $actor = User::factory()->create();
    test()->actingAs($actor);

    $counts = DomainQueryLog::capture(function (): void {
        try {
            app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-02'));
        } catch (AuthorizationException) {
            //
        }
    });

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny'
            && ($context['target_type'] ?? null) === 'order'
            && array_key_exists('target_id', $context) && $context['target_id'] === null)
        ->once();

    expect($counts['orders'])->toBe(0);
});

test('authorization wins over validation: a refused actor with an invalid range and no statuses gets AuthorizationException', function () {
    Log::spy();
    test()->actingAs(User::factory()->create());

    expect(fn () => app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'), []))
        ->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Privileged action refused')->once();
});

test('a Super Admin passes through Gate::before and a permitted actor is not logged', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    test()->actingAs($admin);

    Log::spy();

    $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['points'])->toHaveCount(1);
    Log::shouldNotHaveReceived('warning');
});

// --- Security audit F1/F2: hostile status lists and extreme dates --------------------------------

if (! function_exists('seriesOrdersSelects')) {
    /**
     * Run $callback and return the bindings of every SELECT that reads the orders table.
     *
     * @return list<array<int, mixed>>
     */
    function seriesOrdersSelects(callable $callback): array
    {
        $selects = [];
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$selects, &$recording): void {
            if ($recording && preg_match('/^\s*select\b.*\bfrom\s+[`"]?orders[`"]?/is', $query->sql)) {
                $selects[] = $query->bindings;
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $selects;
    }
}

test('a huge duplicated status list runs one query with at most one status binding plus the date bounds', function () {
    seriesOrder('2026-05-01 10:00:00', '10.00', OrderStatus::Delivered);
    $series = null;

    $selects = seriesOrdersSelects(function () use (&$series): void {
        $series = app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), array_fill(0, 200_000, OrderStatus::Delivered));
    });

    expect($selects)->toHaveCount(1)
        ->and(count($selects[0]))->toBeLessThanOrEqual(1 + 2)
        ->and($series['statuses'])->toBe([OrderStatus::Delivered]);
});

test('a status list with every status repeated is bound at most once per distinct status', function () {
    $selects = seriesOrdersSelects(
        fn () => app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), array_merge(...array_fill(0, 1000, OrderStatus::cases()))),
    );

    expect($selects)->toHaveCount(1)
        ->and(count($selects[0]))->toBeLessThanOrEqual(count(OrderStatus::cases()) + 2);
});

test('a refused extreme range runs no orders query', function (string $from, string $to) {
    $counts = DomainQueryLog::capture(function () use ($from, $to, &$errors): void {
        try {
            app(GetOrdersSeries::class)(SalesGranularity::Day, seriesDay($from), seriesDay($to));
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
    });

    expect(array_keys($errors ?? []))->toBe(['range'])
        ->and($errors['range'][0])->toBe(__('dashboard.errors.range_invalid'))
        ->and($counts['orders'])->toBe(0);
})->with([
    'from year 999' => ['0999-12-31', '0999-12-31'],
    'to 9999-12-31' => ['9999-12-30', '9999-12-31'],
]);

test('byStatus keys equal the distinct selected statuses however often they repeat', function () {
    seriesOrder('2026-05-01 10:00:00', '1.00', OrderStatus::Shipped);

    $series = app(GetOrdersSeries::class)(
        SalesGranularity::Day,
        seriesDay('2026-05-01'),
        seriesDay('2026-05-01'),
        [OrderStatus::Shipped, OrderStatus::Pending, OrderStatus::Shipped, 'garbage', null],
    );

    expect($series['statuses'])->toBe([OrderStatus::Shipped, OrderStatus::Pending])
        ->and(array_keys($series['points'][0]['byStatus']))->toBe(['shipped', 'pending'])
        ->and($series['points'][0]['byStatus'])->toBe(['shipped' => 1, 'pending' => 0]);
});
