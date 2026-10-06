<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;

/**
 * The bucket size of a dashboard sales series (story 0082, D-6): one point per day, month or year.
 *
 * Every per-granularity constant lives here so the dashboard series actions and their bucket
 * collaborator never branch on the case. `sqlFormat()` is interpolated into SQL by the series
 * actions: it is a closed constant returned from this enum, never caller input.
 */
enum SalesGranularity: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';

    /**
     * The MySQL `DATE_FORMAT` pattern that renders a `created_at` as this granularity's bucket key.
     *
     * @return literal-string a closed constant, so it is safe to interpolate into raw SQL
     */
    public function sqlFormat(): string
    {
        return match ($this) {
            self::Day => '%Y-%m-%d',
            self::Month => '%Y-%m',
            self::Year => '%Y',
        };
    }

    /**
     * The PHP `date()` pattern that renders a date as the same bucket key `sqlFormat()` produces.
     */
    public function keyFormat(): string
    {
        return match ($this) {
            self::Day => 'Y-m-d',
            self::Month => 'Y-m',
            self::Year => 'Y',
        };
    }

    /**
     * The interval from one bucket start to the next. Only ever added to a value returned by
     * `startOf()`, so a month-end date can never overflow into the following month.
     */
    public function step(): CarbonInterval
    {
        return match ($this) {
            self::Day => CarbonInterval::day(),
            self::Month => CarbonInterval::month(),
            self::Year => CarbonInterval::year(),
        };
    }

    /**
     * The most buckets a series may span, checked before any query runs.
     */
    public function maxBuckets(): int
    {
        return match ($this) {
            self::Day => 366,
            self::Month => 120,
            self::Year => 50,
        };
    }

    /**
     * The first instant of the bucket the given date falls in, keeping its timezone.
     */
    public function startOf(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);

        return match ($this) {
            self::Day => $date->startOfDay(),
            self::Month => $date->startOfMonth(),
            self::Year => $date->startOfYear(),
        };
    }
}
