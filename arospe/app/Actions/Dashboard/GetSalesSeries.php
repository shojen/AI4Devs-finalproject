<?php

namespace App\Actions\Dashboard;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Orders\ToNumericString;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesGranularity;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Story 0082 (D-6) -- the money series of the dashboard's sales overview: per bucket, Sales and
 * Real income. `LogRefusedPrivilegedAttempt` and `ResolveSalesBuckets` are constructor-injected
 * because `__invoke()`'s parameter list is the caller's contract (docs/conventions/code-style.md).
 *
 * Order of operations: authorize (`viewAny` on Order, i.e. `orders.view`; a refusal is logged and
 * wins over any validation error) -> validate (ResolveSalesBuckets) -> query. A caller should check
 * the ability itself and call this only when permitted.
 *
 * - Sales: `SUM(total)` of the orders whose status is in `$statuses` -- gross (tax and shipping
 *   included, refunds NOT netted), unpaid orders counted.
 * - Real income: `SUM(total - refunded_amount)` of those orders that are also `paid` or
 *   `partially_refunded` and not cancelled -- money actually collected, net of refunds. A cancelled
 *   order never counts, even when `cancelled` is among the statuses.
 *
 * Both are bucketed by `orders.created_at`, so income is attributed to the order's creation day
 * even though `order_payments.paid_at` (story 0084) now records when the payment was made; switching
 * the bucketing to it is a separate follow-up. Known limitations, accepted: (a) `refunded_amount` is
 * merchandise-only, so a partially refunded order would overstate income by the tax/shipping share
 * of the refund -- unobservable today, tax and shipping being 0.00 on every order; (b) `payment_status`
 * only becomes `paid` through `App\Actions\Orders\MarkOrderAsPaid`, so Real income reads 0 until an
 * administrator marks an order as paid.
 *
 * One aggregate query, grouped by `DATE_FORMAT(created_at, <format>)`; the format string and the
 * enum values are interpolated from closed enums, never from input. Money stays decimal strings
 * and is never clamped. Buckets with no orders are zero-filled in PHP.
 */
class GetSalesSeries
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly ResolveSalesBuckets $resolveSalesBuckets,
        private readonly ToNumericString $toNumericString,
    ) {}

    /**
     * @param  list<OrderStatus>|null  $statuses  null means OrderStatus::defaultDashboardSet(); [] is refused
     * @return array{granularity: SalesGranularity, from: CarbonImmutable, to: CarbonImmutable, statuses: list<OrderStatus>, totalSales: string, totalIncome: string, points: list<array{bucket: string, label: CarbonImmutable, sales: string, income: string}>}
     */
    public function __invoke(SalesGranularity $granularity, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null): array
    {
        $this->logRefusedPrivilegedAttempt->authorize('viewAny', Order::class, targetType: 'order');

        $resolved = ($this->resolveSalesBuckets)($granularity, $from, $to, $statuses);

        $format = $granularity->sqlFormat();
        $paid = PaymentStatus::Paid->value;
        $partiallyRefunded = PaymentStatus::PartiallyRefunded->value;
        $cancelled = OrderStatus::Cancelled->value;

        $rows = Order::query()
            ->toBase()
            ->select([
                DB::raw("DATE_FORMAT(created_at, '{$format}') AS bucket"),
                DB::raw('SUM(total) AS sales'),
                DB::raw("SUM(CASE WHEN payment_status IN ('{$paid}', '{$partiallyRefunded}') AND status <> '{$cancelled}' THEN total - refunded_amount ELSE 0 END) AS income"),
            ])
            ->whereIn('status', array_map(fn (OrderStatus $status): string => $status->value, $resolved['statuses']))
            ->where('created_at', '>=', $resolved['start'])
            ->where('created_at', '<', $resolved['endExclusive'])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];
        $totalSales = '0.00';
        $totalIncome = '0.00';

        foreach ($resolved['buckets'] as $bucket) {
            $row = $rows->get($bucket['bucket']);
            $sales = bcadd(($this->toNumericString)((string) ($row->sales ?? '0')), '0', 2);
            $income = bcadd(($this->toNumericString)((string) ($row->income ?? '0')), '0', 2);

            $totalSales = bcadd($totalSales, $sales, 2);
            $totalIncome = bcadd($totalIncome, $income, 2);

            $points[] = [
                'bucket' => $bucket['bucket'],
                'label' => $bucket['label'],
                'sales' => $sales,
                'income' => $income,
            ];
        }

        return [
            'granularity' => $granularity,
            'from' => $resolved['start'],
            'to' => $resolved['endExclusive']->subDay()->startOfDay(),
            'statuses' => $resolved['statuses'],
            'totalSales' => $totalSales,
            'totalIncome' => $totalIncome,
            'points' => $points,
        ];
    }
}
