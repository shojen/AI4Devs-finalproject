<?php

use App\Actions\Dashboard\GetOrdersSeries;
use App\Actions\Dashboard\GetSalesSeries;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;

// Story 0082 (D-6): the money series (Sales + Real income). GetSalesSeries does not exist yet --
// red until implemented. Sibling files: GetSalesSeriesGranularityTest (periods) and
// GetSalesSeriesTimezoneTest (Madrid wall time, midnight, DST, leap day).
//
// Helpers are global functions guarded by function_exists: the three GetSalesSeries* files and
// GetOrdersSeriesTest declare the same small fixtures under the `series` prefix.

if (! function_exists('seriesActor')) {
    /** A NON-Super-Admin actor holding only orders.view. */
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

    /**
     * An order with an explicit Madrid wall-time created_at (status/payment_status/totals are not
     * mass-assignable, so they go through the factory state, which is unguarded).
     */
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

// --- Shape -----------------------------------------------------------------------------------

test('the money series returns the documented shape with money as decimal strings', function () {
    seriesOrder('2026-05-02 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect(array_keys($series))->toEqualCanonicalizing(['granularity', 'from', 'to', 'statuses', 'totalSales', 'totalIncome', 'points'])
        ->and($series['granularity'])->toBe(SalesGranularity::Day)
        ->and($series['from']->format('Y-m-d'))->toBe('2026-05-01')
        ->and($series['to']->format('Y-m-d'))->toBe('2026-05-03')
        ->and($series['to']->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($series['statuses'])->toBe(OrderStatus::defaultDashboardSet())
        ->and($series['totalSales'])->toBe('100.00')
        ->and($series['totalIncome'])->toBe('100.00')
        ->and($series['points'])->toHaveCount(3)
        ->and(array_keys($series['points'][1]))->toEqualCanonicalizing(['bucket', 'label', 'sales', 'income'])
        ->and($series['points'][1]['bucket'])->toBe('2026-05-02')
        ->and($series['points'][1]['label']->format('Y-m-d'))->toBe('2026-05-02')
        ->and($series['points'][1]['sales'])->toBe('100.00')
        ->and($series['points'][1]['income'])->toBe('100.00');
});

test('the normalized to is the inclusive end day, whatever time of day the caller passed', function () {
    $series = app(GetSalesSeries::class)(
        SalesGranularity::Day,
        seriesDay('2026-05-01 14:00:00'),
        seriesDay('2026-05-03 09:30:00'),
    );

    expect($series['from']->format('Y-m-d'))->toBe('2026-05-01')
        ->and($series['to']->format('Y-m-d'))->toBe('2026-05-03')
        ->and($series['points'])->toHaveCount(3);
});

test('an empty shop yields zero-filled points and zero totals', function () {
    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect($series['points'])->toHaveCount(3)
        ->and($series['totalSales'])->toBe('0.00')
        ->and($series['totalIncome'])->toBe('0.00');

    foreach ($series['points'] as $point) {
        expect($point['sales'])->toBe('0.00')->and($point['income'])->toBe('0.00');
    }
});

test('days without orders show zero between days that have orders', function () {
    seriesOrder('2026-05-01 09:00:00', '10.00');
    seriesOrder('2026-05-03 09:00:00', '30.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect(array_column($series['points'], 'sales'))->toBe(['10.00', '0.00', '30.00']);
});

test('orders outside the range are excluded from points and totals', function () {
    seriesOrder('2026-04-30 23:59:59', '1000.00');
    seriesOrder('2026-05-02 12:00:00', '5.00');
    seriesOrder('2026-05-04 00:00:00', '2000.00');

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03'));

    expect($series['totalSales'])->toBe('5.00')
        ->and(array_column($series['points'], 'sales'))->toBe(['0.00', '5.00', '0.00']);
});

test('the money and orders series share identical bucket keys and labels for the same input', function (string $granularity, string $from, string $to) {
    $granularity = SalesGranularity::from($granularity);
    seriesOrder('2026-05-02 10:00:00', '10.00');

    $money = app(GetSalesSeries::class)($granularity, seriesDay($from), seriesDay($to));
    $orders = app(GetOrdersSeries::class)($granularity, seriesDay($from), seriesDay($to));

    expect(array_column($money['points'], 'bucket'))->toBe(array_column($orders['points'], 'bucket'))
        ->and(array_map(fn (array $p): string => $p['label']->format('c'), $money['points']))
        ->toBe(array_map(fn (array $p): string => $p['label']->format('c'), $orders['points']))
        ->and($money['to']->format('c'))->toBe($orders['to']->format('c'));
})->with([
    'day' => ['day', '2026-04-28', '2026-05-05'],
    'month' => ['month', '2025-12-10', '2026-06-01'],
    'year' => ['year', '2024-01-01', '2026-12-31'],
]);

// --- Definitions (D-6 table) ------------------------------------------------------------------

test('Sales and Real income follow the definitions table', function (
    PaymentStatus $payment,
    OrderStatus $status,
    string $total,
    string $refunded,
    string $sales,
    string $income,
) {
    seriesOrder('2026-05-01 10:00:00', $total, $status, $payment, $refunded);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), OrderStatus::cases());

    expect($series['points'][0]['sales'])->toBe($sales)
        ->and($series['points'][0]['income'])->toBe($income)
        ->and($series['totalSales'])->toBe($sales)
        ->and($series['totalIncome'])->toBe($income);
})->with([
    'unpaid counts in sales, not in income' => [PaymentStatus::PendingPayment, OrderStatus::Pending, '60.00', '0.00', '60.00', '0.00'],
    'paid counts in both' => [PaymentStatus::Paid, OrderStatus::Delivered, '100.00', '0.00', '100.00', '100.00'],
    'paid with a refunded amount nets it out of income only' => [PaymentStatus::Paid, OrderStatus::Delivered, '100.00', '10.00', '100.00', '90.00'],
    'partially refunded: total minus refund' => [PaymentStatus::PartiallyRefunded, OrderStatus::Delivered, '100.00', '30.00', '100.00', '70.00'],
    'refunded payment status yields no income' => [PaymentStatus::Refunded, OrderStatus::Delivered, '100.00', '100.00', '100.00', '0.00'],
    'fully refunded and auto-cancelled yields no income' => [PaymentStatus::Refunded, OrderStatus::Cancelled, '100.00', '100.00', '100.00', '0.00'],
    'paid but cancelled never counts as income even when selected' => [PaymentStatus::Paid, OrderStatus::Cancelled, '80.00', '0.00', '80.00', '0.00'],
    'partially refunded but cancelled never counts as income' => [PaymentStatus::PartiallyRefunded, OrderStatus::Cancelled, '80.00', '20.00', '80.00', '0.00'],
    'refunded amount above the total is not clamped' => [PaymentStatus::Paid, OrderStatus::Delivered, '50.00', '70.00', '50.00', '-20.00'],
]);

test('exact decimal sums: 0.10 plus 0.20 is 0.30 in sales and in income', function () {
    seriesOrder('2026-05-01 09:00:00', '0.10', OrderStatus::Delivered, PaymentStatus::Paid);
    seriesOrder('2026-05-01 10:00:00', '0.20', OrderStatus::Delivered, PaymentStatus::Paid);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['points'][0]['sales'])->toBe('0.30')
        ->and($series['points'][0]['income'])->toBe('0.30')
        ->and($series['totalSales'])->toBe('0.30')
        ->and($series['totalIncome'])->toBe('0.30');
});

test('refunds are netted out of income on the order creation day, not out of sales', function () {
    seriesOrder('2026-05-01 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::PartiallyRefunded, '30.00');
    seriesOrder('2026-05-02 10:00:00', '40.00', OrderStatus::Delivered, PaymentStatus::Paid);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-02'));

    expect(array_column($series['points'], 'sales'))->toBe(['100.00', '40.00'])
        ->and(array_column($series['points'], 'income'))->toBe(['70.00', '40.00'])
        ->and($series['totalSales'])->toBe('140.00')
        ->and($series['totalIncome'])->toBe('110.00');
});

test('several orders of one day are summed into one point', function () {
    seriesOrder('2026-05-01 08:00:00', '10.50', OrderStatus::Pending, PaymentStatus::PendingPayment);
    seriesOrder('2026-05-01 18:00:00', '20.25', OrderStatus::Shipped, PaymentStatus::Paid);
    seriesOrder('2026-05-01 23:00:00', '1.25', OrderStatus::Delivered, PaymentStatus::Paid);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['points'][0]['sales'])->toBe('32.00')
        ->and($series['points'][0]['income'])->toBe('21.50');
});

// --- Status filter ----------------------------------------------------------------------------

test('the status filter narrows Sales and Real income, with cancelled excluded by default and included when chosen', function (array|string|null $statuses, string $sales, string $income) {
    $paid = PaymentStatus::Paid;
    seriesOrder('2026-05-01 10:00:00', '10.00', OrderStatus::Pending, $paid);
    seriesOrder('2026-05-01 10:00:00', '20.00', OrderStatus::Processing, $paid);
    seriesOrder('2026-05-01 10:00:00', '30.00', OrderStatus::Shipped, $paid);
    seriesOrder('2026-05-01 10:00:00', '40.00', OrderStatus::Delivered, $paid);
    seriesOrder('2026-05-01 10:00:00', '50.00', OrderStatus::Cancelled, $paid);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), seriesStatuses($statuses));

    expect($series['totalSales'])->toBe($sales)
        ->and($series['totalIncome'])->toBe($income);
})->with([
    'null = default set, cancelled out' => [null, '100.00', '100.00'],
    'explicit default set' => ['default', '100.00', '100.00'],
    'only cancelled: sales yes, income never' => [[OrderStatus::Cancelled], '50.00', '0.00'],
    'all five statuses: income still excludes cancelled' => ['all', '150.00', '100.00'],
    'delivered and shipped' => [[OrderStatus::Delivered, OrderStatus::Shipped], '70.00', '70.00'],
    'pending only' => [[OrderStatus::Pending], '10.00', '10.00'],
    'processing only' => [[OrderStatus::Processing], '20.00', '20.00'],
]);

test('an empty status list is refused with a ValidationException on statuses', function () {
    $call = fn () => app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-02'), []);

    expect($call)->toThrow(ValidationException::class);

    try {
        $call();
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['statuses'])
            ->and($e->errors()['statuses'][0])->toBe(__('dashboard.errors.statuses_required'));
    }
});

// --- Validation --------------------------------------------------------------------------------

test('a refused range runs no orders query', function () {
    $counts = DomainQueryLog::capture(function (): void {
        try {
            app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'));
        } catch (ValidationException) {
            //
        }
    });

    expect($counts['orders'])->toBe(0);
});

// --- Queries -----------------------------------------------------------------------------------

test('the money series runs exactly one aggregate query on orders, however many orders exist', function (int $orders) {
    foreach (range(1, $orders) as $i) {
        seriesOrder('2026-05-0'.(($i % 3) + 1).' 10:00:00', '10.00', OrderStatus::Delivered, PaymentStatus::Paid);
    }

    $counts = DomainQueryLog::capture(
        fn () => app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-03')),
    );

    expect($counts['orders'])->toBe(1)
        ->and(DomainQueryLog::total($counts))->toBe(1);
})->with([1, 9]);

test('the money series runs one orders query even when the shop is empty', function () {
    $counts = DomainQueryLog::capture(
        fn () => app(GetSalesSeries::class)(SalesGranularity::Month, seriesDay('2026-01-01'), seriesDay('2026-12-31')),
    );

    expect($counts['orders'])->toBe(1);
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
        $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), array_fill(0, 200_000, OrderStatus::Delivered));
    });

    expect($selects)->toHaveCount(1)
        ->and(count($selects[0]))->toBeLessThanOrEqual(1 + 2)
        ->and($series['statuses'])->toBe([OrderStatus::Delivered]);
});

test('a status list with every status repeated is bound at most once per distinct status', function () {
    $selects = seriesOrdersSelects(
        fn () => app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), array_merge(...array_fill(0, 1000, OrderStatus::cases()))),
    );

    expect($selects)->toHaveCount(1)
        ->and(count($selects[0]))->toBeLessThanOrEqual(count(OrderStatus::cases()) + 2);
});

test('a refused extreme range runs no orders query', function (string $from, string $to) {
    $counts = DomainQueryLog::capture(function () use ($from, $to, &$errors): void {
        try {
            app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay($from), seriesDay($to));
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

test('the returned statuses are the normalized list, garbage and duplicates removed', function () {
    $series = app(GetSalesSeries::class)(
        SalesGranularity::Day,
        seriesDay('2026-05-01'),
        seriesDay('2026-05-01'),
        ['x' => OrderStatus::Shipped, 'y' => 'shipped', 'z' => OrderStatus::Shipped],
    );

    expect($series['statuses'])->toBe([OrderStatus::Shipped]);
});
