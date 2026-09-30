<?php

namespace App\Actions\Dashboard;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\OrderStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Story 0082 (D-6) -- the orders series of the dashboard's sales overview: per bucket, the number
 * of orders and its breakdown by status. `LogRefusedPrivilegedAttempt` and `ResolveSalesBuckets` are
 * constructor-injected because `__invoke()`'s parameter list is the caller's contract
 * (docs/conventions/code-style.md).
 *
 * Same arguments, buckets, status filter, bucketing and order of operations as GetSalesSeries
 * (authorize -> validate -> query), so the two charts always share identical bucket keys. One
 * query, `GROUP BY bucket, status`, pivoted in PHP: `byStatus` holds a key for EVERY selected status
 * (zero-filled), keyed by the OrderStatus value. An order counts once, never once per line.
 */
class GetOrdersSeries
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly ResolveSalesBuckets $resolveSalesBuckets,
    ) {}

    /**
     * @param  list<OrderStatus>|null  $statuses  null means OrderStatus::defaultDashboardSet(); [] is refused
     * @return array{granularity: SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, statuses: list<OrderStatus>, totalOrders: int, points: list<array{bucket: string, label: CarbonImmutable, total: int, byStatus: array<string, int>}>}
     */
    public function __invoke(SalesGranularity $granularity, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null): array
    {
        $this->logRefusedPrivilegedAttempt->authorize('viewAny', Order::class, targetType: 'order');

        $resolved = ($this->resolveSalesBuckets)($granularity, $from, $to, $statuses);

        $format = $granularity->sqlFormat();

        $rows = Order::query()
            ->toBase()
            ->select([
                DB::raw("DATE_FORMAT(created_at, '{$format}') AS bucket"),
                'status',
                DB::raw('COUNT(*) AS orders_count'),
            ])
            ->whereIn('status', array_map(fn (OrderStatus $status): string => $status->value, $resolved['statuses']))
            ->where('created_at', '>=', $resolved['start'])
            ->where('created_at', '<', $resolved['endExclusive'])
            ->groupBy('bucket', 'status')
            ->get();

        /** @var array<string, array<string, int>> $counts bucket key => status value => count */
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->bucket][(string) $row->status] = (int) $row->orders_count;
        }

        $points = [];
        $totalOrders = 0;

        foreach ($resolved['buckets'] as $bucket) {
            $byStatus = [];

            foreach ($resolved['statuses'] as $status) {
                $byStatus[$status->value] = $counts[$bucket['bucket']][$status->value] ?? 0;
            }

            $total = array_sum($byStatus);
            $totalOrders += $total;

            $points[] = [
                'bucket' => $bucket['bucket'],
                'label' => $bucket['label'],
                'total' => $total,
                'byStatus' => $byStatus,
            ];
        }

        return [
            'granularity' => $granularity,
            'from' => $resolved['start'],
            'to' => $resolved['endExclusive']->subDay()->startOfDay(),
            'statuses' => $resolved['statuses'],
            'totalOrders' => $totalOrders,
            'points' => $points,
        ];
    }
}
