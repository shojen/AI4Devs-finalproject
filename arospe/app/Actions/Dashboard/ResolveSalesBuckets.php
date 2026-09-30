<?php

namespace App\Actions\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\SalesGranularity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Story 0082 (D-6) -- the collaborator shared by GetSalesSeries and GetOrdersSeries: range
 * normalization, cap validation, status-set resolution and the zero-filled bucket list, so both
 * charts always show identical buckets.
 *
 * Authorizes nothing, deliberately: it is not an action. Only the two series actions call it, and
 * both authorize (`orders.view`) before they do, so it is container-resolved and constructor-injected
 * into them like App\Actions\Orders\CalculateTaxAmount (docs/conventions/code-style.md). A test pins
 * that no other class references it.
 *
 * The range is read in the application timezone (`config('app.timezone')`): `from` becomes the
 * start of its day and `to` the day after its own day, so the predicate a caller builds from
 * `start`/`endExclusive` is half-open and sargable (`created_at >= start AND created_at < endExclusive`).
 * `created_at` already holds that same wall time, so nothing is converted in SQL.
 *
 * The bucket cap is computed here in PHP, on the bucket COUNT (not the day span), before any query
 * is issued. A bucket's label is its first day. When both the range and the statuses are invalid,
 * ONE ValidationException carries both keys.
 */
class ResolveSalesBuckets
{
    /**
     * @param  list<OrderStatus>|null  $statuses  null means OrderStatus::defaultDashboardSet(); [] is refused
     * @return array{start: CarbonImmutable, endExclusive: CarbonImmutable, statuses: list<OrderStatus>, buckets: list<array{bucket: string, label: CarbonImmutable}>}
     *
     * @throws ValidationException
     */
    public function __invoke(SalesGranularity $granularity, CarbonInterface $from, CarbonInterface $to, ?array $statuses): array
    {
        $timezone = config('app.timezone');

        $start = $from->toImmutable()->setTimezone($timezone)->startOfDay();
        $lastDay = $to->toImmutable()->setTimezone($timezone)->startOfDay();
        $endExclusive = $lastDay->addDay()->startOfDay();

        $errors = [];
        $buckets = [];

        if ($start->greaterThan($lastDay)) {
            $errors['range'] = __('dashboard.errors.range_invalid');
        } else {
            $cap = $granularity->maxBuckets();
            $period = CarbonPeriod::create(
                $granularity->startOf($start),
                $granularity->step(),
                $granularity->startOf($lastDay),
            );

            foreach ($period as $bucketStart) {
                $bucketStart = CarbonImmutable::instance($bucketStart);
                $buckets[] = ['bucket' => $bucketStart->format($granularity->keyFormat()), 'label' => $bucketStart];

                if (count($buckets) > $cap) {
                    $errors['range'] = __('dashboard.errors.range_too_long', ['max' => $cap]);

                    break;
                }
            }
        }

        if ($statuses === []) {
            $errors['statuses'] = __('dashboard.errors.statuses_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'start' => $start,
            'endExclusive' => $endExclusive,
            'statuses' => $statuses ?? OrderStatus::defaultDashboardSet(),
            'buckets' => $buckets,
        ];
    }
}
