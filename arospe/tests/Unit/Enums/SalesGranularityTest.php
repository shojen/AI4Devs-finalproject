<?php

use App\Enums\SalesGranularity;
use Carbon\CarbonImmutable;

// Story 0082 (D-6): the closed, per-granularity constants the dashboard series actions rely on, so
// the actions never need a `switch`. SalesGranularity does not exist yet -- red until implemented.

test('sqlFormat is the MySQL DATE_FORMAT pattern of the bucket key', function (string $granularity, string $format) {
    $granularity = SalesGranularity::from($granularity);
    expect($granularity->sqlFormat())->toBe($format);
})->with([
    'day' => ['day', '%Y-%m-%d'],
    'month' => ['month', '%Y-%m'],
    'year' => ['year', '%Y'],
]);

test('keyFormat renders a date as the same bucket key the SQL produces', function (string $granularity, string $key) {
    $granularity = SalesGranularity::from($granularity);
    $date = CarbonImmutable::parse('2026-05-17 13:45:10', 'Europe/Madrid');

    expect($date->format($granularity->keyFormat()))->toBe($key);
})->with([
    'day' => ['day', '2026-05-17'],
    'month' => ['month', '2026-05'],
    'year' => ['year', '2026'],
]);

test('startOf returns the first instant of the bucket, keeping the timezone', function (string $granularity, string $expected) {
    $granularity = SalesGranularity::from($granularity);
    $date = CarbonImmutable::parse('2026-05-17 13:45:10', 'Europe/Madrid');

    $start = $granularity->startOf($date);

    expect($start->format('Y-m-d H:i:s'))->toBe($expected)
        ->and($start->getTimezone()->getName())->toBe('Europe/Madrid');
})->with([
    'day' => ['day', '2026-05-17 00:00:00'],
    'month' => ['month', '2026-05-01 00:00:00'],
    'year' => ['year', '2026-01-01 00:00:00'],
]);

test('step moves one bucket start to the next bucket start', function (string $granularity, string $next) {
    $granularity = SalesGranularity::from($granularity);
    $start = $granularity->startOf(CarbonImmutable::parse('2026-12-31 10:00:00', 'Europe/Madrid'));

    expect($start->add($granularity->step())->format('Y-m-d H:i:s'))->toBe($next);
})->with([
    'day' => ['day', '2027-01-01 00:00:00'],
    'month' => ['month', '2027-01-01 00:00:00'],
    'year' => ['year', '2027-01-01 00:00:00'],
]);

test('startOf does not mutate the date it was given', function () {
    $date = CarbonImmutable::parse('2026-05-17 13:45:10', 'Europe/Madrid');

    SalesGranularity::Month->startOf($date);

    expect($date->format('Y-m-d H:i:s'))->toBe('2026-05-17 13:45:10');
});
