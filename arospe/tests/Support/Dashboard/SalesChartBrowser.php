<?php

namespace Tests\Support\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser-test fixtures and in-page probes for the story 0086 charts (SalesChartTest, SalesChartThemeTest).
 *
 * Everything is relative to "now" in the application timezone and every expected label/figure is
 * computed here, in PHP, by an independent bucketing of the seeded orders -- the browser tests never
 * assert "today" in JavaScript. The hooks are the testability contract of the story:
 * `money-chart-canvas` / `orders-chart-canvas` (`data-chart-ready="true"`, `canvas.chartInstance`),
 * `window.__salesChartCreations`, and the sr-only tables described in SalesOverviewUi.
 */
final class SalesChartBrowser
{
    public const string MONEY = 'money-chart-canvas';

    public const string ORDERS = 'orders-chart-canvas';

    /**
     * Offsets are days before today. Chosen so that the default range (last 30 days, cancelled off)
     * holds 3 counted orders, the cancelled one only shows when the chip is on, and one order falls
     * outside the day range but inside the month/year ranges.
     *
     * @var list<array{offset: int, status: OrderStatus, payment: PaymentStatus, total: string}>
     */
    public const array ORDERS_SPEC = [
        ['offset' => 3, 'status' => OrderStatus::Delivered, 'payment' => PaymentStatus::Paid, 'total' => '100.00'],
        ['offset' => 3, 'status' => OrderStatus::Pending, 'payment' => PaymentStatus::PendingPayment, 'total' => '60.00'],
        ['offset' => 1, 'status' => OrderStatus::Shipped, 'payment' => PaymentStatus::Paid, 'total' => '50.00'],
        ['offset' => 1, 'status' => OrderStatus::Cancelled, 'payment' => PaymentStatus::PendingPayment, 'total' => '40.00'],
        ['offset' => 75, 'status' => OrderStatus::Delivered, 'payment' => PaymentStatus::Paid, 'total' => '500.00'],
    ];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /**
     * A signed-in actor who may see orders (and therefore the sales overview card).
     */
    public static function actor(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        test()->seed(RolePermissionSeeder::class);

        $actor = User::factory()->create();
        $actor->givePermissionTo(['orders.view']);

        test()->actingAs($actor);

        return $actor;
    }

    /**
     * Seeds ORDERS_SPEC at noon of each day.
     */
    public static function seedOrders(): void
    {
        foreach (self::ORDERS_SPEC as $spec) {
            SalesOverviewUi::order(
                self::today()->subDays($spec['offset'])->setTime(12, 0)->format('Y-m-d H:i:s'),
                $spec['total'],
                $spec['status'],
                $spec['payment'],
            );
        }
    }

    /**
     * Bucket starts of a range, as the chart labels them.
     *
     * @return list<CarbonImmutable>
     */
    public static function buckets(string $granularity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = match ($granularity) {
            'day' => $from->startOfDay(),
            'month' => $from->startOfMonth(),
            'year' => $from->startOfYear(),
        };
        $buckets = [];

        for ($cursor = $start; $cursor <= $to; $cursor = match ($granularity) {
            'day' => $cursor->addDay(),
            'month' => $cursor->addMonth(),
            'year' => $cursor->addYear(),
        }) {
            $buckets[] = $cursor;
        }

        return $buckets;
    }

    /**
     * The default range of a granularity (today as the end), as the D-2 decision defines it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function defaultRange(string $granularity): array
    {
        $today = self::today();

        return match ($granularity) {
            'day' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth()->subMonths(11), $today],
            'year' => [$today->startOfYear()->subYears(4), $today],
        };
    }

    public static function label(CarbonImmutable $bucket, string $granularity): string
    {
        return $bucket->settings(['locale' => 'en'])->isoFormat(
            ['day' => 'D MMM', 'month' => 'MMM YYYY', 'year' => 'YYYY'][$granularity],
        );
    }

    /**
     * The bucket key a day falls in, per granularity.
     */
    private static function bucketKey(CarbonImmutable $day, string $granularity): string
    {
        return $day->format(['day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$granularity]);
    }

    /**
     * @param  list<OrderStatus>  $statuses
     * @return list<array{day: CarbonImmutable, status: OrderStatus, paid: bool, total: string}>
     */
    private static function ordersIn(CarbonImmutable $from, CarbonImmutable $to, array $statuses): array
    {
        $inRange = [];

        foreach (self::ORDERS_SPEC as $spec) {
            $day = self::today()->subDays($spec['offset']);

            if ($day < $from->startOfDay() || $day > $to || ! in_array($spec['status'], $statuses, true)) {
                continue;
            }

            $inRange[] = ['day' => $day, 'status' => $spec['status'], 'paid' => $spec['payment'] === PaymentStatus::Paid, 'total' => $spec['total']];
        }

        return $inRange;
    }

    /**
     * Chart A's data, as the instance must hold it.
     *
     * @param  list<OrderStatus>  $statuses
     * @return array{labels: list<string>, sales: list<float>, income: list<float>}
     */
    public static function expectedMoney(string $granularity, CarbonImmutable $from, CarbonImmutable $to, array $statuses): array
    {
        $labels = [];
        $sales = [];
        $income = [];
        $orders = self::ordersIn($from, $to, $statuses);

        foreach (self::buckets($granularity, $from, $to) as $bucket) {
            $key = self::bucketKey($bucket, $granularity);
            $mine = array_filter($orders, fn (array $order): bool => self::bucketKey($order['day'], $granularity) === $key);

            $labels[] = self::label($bucket, $granularity);
            $sales[] = (float) array_sum(array_map(fn (array $order): float => (float) $order['total'], $mine));
            $income[] = (float) array_sum(array_map(fn (array $order): float => $order['paid'] ? (float) $order['total'] : 0.0, $mine));
        }

        return ['labels' => $labels, 'sales' => $sales, 'income' => $income];
    }

    /**
     * Chart B's data: one dataset per selected status, in OrderStatus::cases() order.
     *
     * @param  list<OrderStatus>  $statuses
     * @return array{labels: list<string>, datasets: array<string, list<int>>}
     */
    public static function expectedOrders(string $granularity, CarbonImmutable $from, CarbonImmutable $to, array $statuses): array
    {
        $orders = self::ordersIn($from, $to, $statuses);
        $labels = [];
        $datasets = [];

        foreach (OrderStatus::cases() as $status) {
            if (in_array($status, $statuses, true)) {
                $datasets[$status->value] = [];
            }
        }

        foreach (self::buckets($granularity, $from, $to) as $bucket) {
            $key = self::bucketKey($bucket, $granularity);
            $labels[] = self::label($bucket, $granularity);

            foreach (array_keys($datasets) as $value) {
                $datasets[$value][] = count(array_filter(
                    $orders,
                    fn (array $order): bool => $order['status']->value === $value && self::bucketKey($order['day'], $granularity) === $key,
                ));
            }
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /**
     * @return list<OrderStatus>
     */
    public static function defaultStatuses(): array
    {
        return OrderStatus::defaultDashboardSet();
    }

    /**
     * JS expression: the Chart instance behind a canvas hook.
     */
    public static function chartJs(string $hook): string
    {
        return "document.querySelector('[data-test=\"{$hook}\"]').chartInstance";
    }

    /**
     * JS function (as a string) returning a JSON snapshot of a chart: labels, per-dataset data, status and visibility.
     */
    public static function snapshotJs(string $hook): string
    {
        $chart = self::chartJs($hook);

        return "() => { const c = {$chart}; return JSON.stringify({ labels: c.data.labels, datasets: c.data.datasets.map((d, i) => ({ label: d.label, status: d.status ?? null, data: d.data, visible: c.isDatasetVisible(i) })) }); }";
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, status: ?string, data: list<int|float>, visible: bool}>}
     */
    public static function snapshot(mixed $page, string $hook): array
    {
        /** @var string $json */
        $json = $page->script(self::snapshotJs($hook));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Polls, INSIDE the page (4 s ceiling), until a JS boolean expression is true. A bare PHP-side wait
     * is not a polling primitive; this is the one condition-based wait the charts tests use.
     */
    public static function waitUntil(mixed $page, string $condition): void
    {
        $ok = $page->script("async () => { const end = Date.now() + 4000; while (Date.now() < end) { try { if ({$condition}) { return true; } } catch (e) {} await new Promise((r) => setTimeout(r, 50)); } return false; }");

        expect($ok)->toBeTrue("Condition never became true within 4s: {$condition}");
    }

    /**
     * Waits until both charts have been created (the lazy card has loaded and Chart.js is ready).
     */
    public static function waitForCharts(mixed $page): void
    {
        self::waitUntil($page, "document.querySelector('[data-test=\"".self::MONEY."\"]')?.dataset.chartReady === 'true' && document.querySelector('[data-test=\"".self::ORDERS."\"]')?.dataset.chartReady === 'true'");
    }

    /**
     * JS statement clicking a granularity option (the Flux segmented radio group).
     */
    public static function clickGranularityJs(string $option): string
    {
        return "document.querySelector('[data-test=\"sales-granularity\"] [value=\"{$option}\"]').click()";
    }

    /**
     * JS statement clicking a status chip.
     */
    public static function clickChipJs(string $status): string
    {
        return "document.querySelector('[data-test=\"sales-chip-{$status}\"]').click()";
    }
}
