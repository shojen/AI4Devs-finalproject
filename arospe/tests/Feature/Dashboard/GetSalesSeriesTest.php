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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboard\DomainQueryLog;

// Story 0082 (D-6): the money series (Sales + Real income). GetSalesSeries does not exist yet --
// red until implemented. Sibling files: GetSalesSeriesGranularityTest (periods, caps) and
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

test('bucket keys are ascending, unique and as many as the periods of the range', function (string $granularity, string $from, string $to, int $count) {
    $granularity = SalesGranularity::from($granularity);
    $series = app(GetSalesSeries::class)($granularity, seriesDay($from), seriesDay($to));
    $keys = array_column($series['points'], 'bucket');

    $sorted = $keys;
    sort($sorted);

    expect($keys)->toHaveCount($count)
        ->and($keys)->toBe($sorted)
        ->and(array_unique($keys))->toHaveCount($count);
})->with([
    'days' => ['day', '2026-01-15', '2026-03-15', 60],
    'months' => ['month', '2025-11-01', '2026-02-28', 4],
    'years' => ['year', '2024-06-01', '2027-06-01', 4],
]);

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

test('the status filter example: delivered paid 100, shipped paid 50, pending unpaid 20', function (array|string|null $statuses, string $sales, string $income) {
    seriesOrder('2026-05-01 10:00:00', '100.00', OrderStatus::Delivered, PaymentStatus::Paid);
    seriesOrder('2026-05-01 10:00:00', '50.00', OrderStatus::Shipped, PaymentStatus::Paid);
    seriesOrder('2026-05-01 10:00:00', '20.00', OrderStatus::Pending, PaymentStatus::PendingPayment);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), seriesStatuses($statuses));

    expect($series['totalSales'])->toBe($sales)->and($series['totalIncome'])->toBe($income);
})->with([
    'delivered' => [[OrderStatus::Delivered], '100.00', '100.00'],
    'delivered, shipped' => [[OrderStatus::Delivered, OrderStatus::Shipped], '150.00', '150.00'],
    'pending' => [[OrderStatus::Pending], '20.00', '0.00'],
    'all except cancelled' => ['default', '170.00', '150.00'],
]);

test('cancelled orders count in sales only when included (one delivered 100 and one cancelled 40)', function (array|string|null $statuses, string $sales) {
    seriesOrder('2026-05-01 10:00:00', '100.00', OrderStatus::Delivered);
    seriesOrder('2026-05-01 11:00:00', '40.00', OrderStatus::Cancelled);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), seriesStatuses($statuses));

    expect($series['points'][0]['sales'])->toBe($sales);
})->with([
    'without cancelled' => [null, '100.00'],
    'including cancelled' => ['all', '140.00'],
]);

test('the returned statuses echo the set that was applied', function () {
    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'), [OrderStatus::Cancelled]);

    expect($series['statuses'])->toBe([OrderStatus::Cancelled]);
});

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

test('from after to is refused on the range key with the translated message', function () {
    try {
        app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'));
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['range'])
            ->and($e->errors()['range'][0])->toBe(__('dashboard.errors.range_invalid'))
            ->and($e->errors()['range'][0])->not->toBe('dashboard.errors.range_invalid');
    }
});

test('an invalid range together with an empty status list reports both keys in one exception', function () {
    try {
        app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'), []);
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['range', 'statuses']);
    }
});

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

// --- Authorization -----------------------------------------------------------------------------

test('an actor without orders.view is refused, the refusal is logged and no orders query runs', function () {
    Log::spy();
    $actor = User::factory()->create();
    test()->actingAs($actor);

    $counts = DomainQueryLog::capture(function (): void {
        try {
            app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-02'));
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

    expect(fn () => app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-05'), seriesDay('2026-05-01'), []))
        ->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Privileged action refused')->once();
});

test('a Super Admin passes through Gate::before', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    test()->actingAs($admin);

    $series = app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    expect($series['points'])->toHaveCount(1);
});

test('a permitted actor is not logged as refused', function () {
    Log::spy();

    app(GetSalesSeries::class)(SalesGranularity::Day, seriesDay('2026-05-01'), seriesDay('2026-05-01'));

    Log::shouldNotHaveReceived('warning');
});
