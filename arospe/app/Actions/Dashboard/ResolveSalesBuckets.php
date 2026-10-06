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
 *
 * Two defensive rules bound the work and keep every query valid (security audit F1/F2):
 * - The range's first and last day must fall in the years 1000..9998, so `endExclusive` never passes
 *   year 9999 and always fits a MySQL DATETIME; otherwise `range_invalid`, before any CarbonPeriod
 *   is built or any query is issued.
 * - A non-null status list is normalized before validation (non-OrderStatus entries dropped,
 *   duplicates removed keeping the first, re-indexed), so its size is bounded by the enum's cases
 *   and a huge or garbage input cannot inflate the query; an empty result is refused.
 */
class ResolveSalesBuckets
{
    private const int MIN_YEAR = 1000;

    private const int MAX_YEAR = 9998;

    /**
     * @param  list<OrderStatus>|null  $statuses  null means OrderStatus::defaultDashboardSet(); an empty
     *                                            list, or one with no OrderStatus in it, is refused. Typed as a
     *                                            list, but the method is defensive about what a Livewire-hydrated
     *                                            caller may pass (any keys, non-enum entries, duplicates).
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

        if ($statuses !== null) {
            $statuses = $this->normalizeStatuses($statuses);
        }

        if ($start->greaterThan($lastDay) || ! $this->isSupportedYear($start) || ! $this->isSupportedYear($lastDay)) {
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

        if ($statuses !== null && $statuses === []) {
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

    private function isSupportedYear(CarbonImmutable $day): bool
    {
        return $day->year >= self::MIN_YEAR && $day->year <= self::MAX_YEAR;
    }

    /**
     * @param  array<array-key, mixed>  $statuses
     * @return list<OrderStatus>
     */
    private function normalizeStatuses(array $statuses): array
    {
        $seen = [];

        foreach ($statuses as $status) {
            if ($status instanceof OrderStatus && ! isset($seen[$status->value])) {
                $seen[$status->value] = $status;
            }
        }

        return array_values($seen);
    }
}
