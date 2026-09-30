<?php

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\Dashboard\GetOrdersSeries;
use App\Actions\Dashboard\GetSalesSeries;
use App\Actions\Dashboard\ResolveSalesBuckets;
use App\Enums\OrderStatus;
use App\Enums\SalesGranularity;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Dashboard\DomainQueryLog;

// Story 0082 (D-6): ResolveSalesBuckets is the shared, NON-authorizing collaborator of the two
// series actions: range normalization, cap validation, status resolution and the zero-filled bucket
// list. Advisory hardening (security audit F1/F2): a non-null status list is normalized (non-enum
// entries dropped, duplicates removed, re-indexed to a list, empty-after-normalization refused) and
// a range whose first or last day falls outside years 1000..9998 is refused with range_invalid.

function bucketsDay(string $date, string $time = '00:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Madrid');
}

/**
 * @return array<string, array<int, string>>
 */
function bucketsErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('it normalizes the range to Madrid day boundaries with an exclusive end the day after', function () {
    $result = app(ResolveSalesBuckets::class)(
        SalesGranularity::Day,
        bucketsDay('2026-05-01', '15:30:00'),
        bucketsDay('2026-05-03', '09:00:00'),
        null,
    );

    expect($result['start']->format('Y-m-d H:i:s'))->toBe('2026-05-01 00:00:00')
        ->and($result['start']->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($result['endExclusive']->format('Y-m-d H:i:s'))->toBe('2026-05-04 00:00:00')
        ->and($result['endExclusive']->getTimezone()->getName())->toBe('Europe/Madrid');
});

test('it reads the from and to instants in the application timezone, not in the caller timezone', function () {
    // 22:00 UTC on 30 April is 00:00 on 1 May in Madrid (CEST, UTC+2).
    $from = CarbonImmutable::parse('2026-04-30 22:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-05-02 21:59:59', 'UTC'); // 23:59:59 on 2 May in Madrid

    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, $from, $to, null);

    expect($result['start']->format('Y-m-d H:i:s'))->toBe('2026-05-01 00:00:00')
        ->and($result['endExclusive']->format('Y-m-d H:i:s'))->toBe('2026-05-03 00:00:00')
        ->and(array_column($result['buckets'], 'bucket'))->toBe(['2026-05-01', '2026-05-02']);
});

test('the default status set is resolved when null is given and excludes cancelled', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-01'), bucketsDay('2026-05-01'), null);

    expect($result['statuses'])->toBe(OrderStatus::defaultDashboardSet())
        ->and($result['statuses'])->not->toContain(OrderStatus::Cancelled);
});

test('an explicit status list is returned as given, cancelled included when chosen', function () {
    $result = app(ResolveSalesBuckets::class)(
        SalesGranularity::Day,
        bucketsDay('2026-05-01'),
        bucketsDay('2026-05-01'),
        [OrderStatus::Cancelled, OrderStatus::Shipped],
    );

    expect($result['statuses'])->toEqualCanonicalizing([OrderStatus::Cancelled, OrderStatus::Shipped]);
});

test('daily buckets are listed ascending with the day as key and as label', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-01'), bucketsDay('2026-05-03'), null);

    expect(array_column($result['buckets'], 'bucket'))->toBe(['2026-05-01', '2026-05-02', '2026-05-03']);

    foreach ($result['buckets'] as $bucket) {
        expect($bucket['label'])->toBeInstanceOf(CarbonImmutable::class)
            ->and($bucket['label']->format('Y-m-d H:i:s'))->toBe($bucket['bucket'].' 00:00:00');
    }
});

test('monthly buckets are labelled with the first day of the month', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Month, bucketsDay('2026-11-20'), bucketsDay('2027-02-05'), null);

    expect(array_column($result['buckets'], 'bucket'))->toBe(['2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(array_map(fn (array $b): string => $b['label']->format('Y-m-d'), $result['buckets']))
        ->toBe(['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01']);
});

test('yearly buckets are labelled with 1 January', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Year, bucketsDay('2026-06-15'), bucketsDay('2028-02-10'), null);

    expect(array_column($result['buckets'], 'bucket'))->toBe(['2026', '2027', '2028'])
        ->and(array_map(fn (array $b): string => $b['label']->format('Y-m-d'), $result['buckets']))
        ->toBe(['2026-01-01', '2027-01-01', '2028-01-01']);
});

test('a single-day range yields exactly one bucket for every granularity', function (string $granularity, string $key) {
    $granularity = SalesGranularity::from($granularity);
    $result = app(ResolveSalesBuckets::class)($granularity, bucketsDay('2026-05-15'), bucketsDay('2026-05-15'), null);

    expect($result['buckets'])->toHaveCount(1)
        ->and($result['buckets'][0]['bucket'])->toBe($key);
})->with([
    'day' => ['day', '2026-05-15'],
    'month' => ['month', '2026-05'],
    'year' => ['year', '2026'],
]);

test('the Gherkin period example yields 366 days, 13 months and 2 years', function (string $granularity, int $count) {
    $granularity = SalesGranularity::from($granularity);
    $result = app(ResolveSalesBuckets::class)($granularity, bucketsDay('2026-04-30'), bucketsDay('2027-04-30'), null);

    $keys = array_column($result['buckets'], 'bucket');

    expect($keys)->toHaveCount($count)
        ->and(array_unique($keys))->toHaveCount($count);
})->with([
    'day' => ['day', 366],
    'month' => ['month', 13],
    'year' => ['year', 2],
]);

test('bucket keys are strictly ascending across the DST changes of 2026', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-03-28'), bucketsDay('2026-03-30'), null);
    $fall = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-10-24'), bucketsDay('2026-10-26'), null);

    expect(array_column($result['buckets'], 'bucket'))->toBe(['2026-03-28', '2026-03-29', '2026-03-30'])
        ->and(array_column($fall['buckets'], 'bucket'))->toBe(['2026-10-24', '2026-10-25', '2026-10-26']);
});

test('the leap day 2028-02-29 is its own daily bucket', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2028-02-01'), bucketsDay('2028-02-29'), null);

    expect($result['buckets'])->toHaveCount(29)
        ->and(end($result['buckets'])['bucket'])->toBe('2028-02-29');
});

test('a range at exactly the cap is accepted and one bucket more is refused', function (string $granularity, string $to, int $cap, string $tooLong) {
    $granularity = SalesGranularity::from($granularity);
    $resolve = app(ResolveSalesBuckets::class);
    $from = bucketsDay('2020-01-01');

    $ok = $resolve($granularity, $from, bucketsDay($to), null);
    $errors = bucketsErrors(fn () => $resolve($granularity, $from, bucketsDay($tooLong), null));

    expect($ok['buckets'])->toHaveCount($cap)
        ->and($granularity->maxBuckets())->toBe($cap)
        ->and($errors)->toHaveKey('range')
        ->and($errors)->not->toHaveKey('statuses')
        ->and($errors['range'][0])->toBe(__('dashboard.errors.range_too_long', ['max' => $cap]))
        ->and($errors['range'][0])->not->toBe('dashboard.errors.range_too_long')
        ->and($errors['range'][0])->toContain((string) $cap);
})->with([
    'day 366/367' => ['day', '2020-12-31', 366, '2021-01-01'],
    'month 120/121' => ['month', '2029-12-31', 120, '2030-01-01'],
    'year 50/51' => ['year', '2069-06-01', 50, '2070-01-01'],
]);

test('the cap counts buckets, not days: a month range of 120 partial months is accepted', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Month, bucketsDay('2020-01-31'), bucketsDay('2029-12-01'), null);

    expect($result['buckets'])->toHaveCount(120);
});

test('from after to is refused with the translated range_invalid message on the range key only', function () {
    $errors = bucketsErrors(fn () => app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-05'), bucketsDay('2026-05-01'), null));

    expect(array_keys($errors))->toBe(['range'])
        ->and($errors['range'][0])->toBe(__('dashboard.errors.range_invalid'))
        ->and($errors['range'][0])->not->toBe('dashboard.errors.range_invalid');
});

test('from and to on the same day is a valid range, not an inverted one', function () {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-05', '23:00:00'), bucketsDay('2026-05-05', '01:00:00'), null);

    expect($result['buckets'])->toHaveCount(1);
});

test('an empty status list is refused on the statuses key only', function () {
    $errors = bucketsErrors(fn () => app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-01'), bucketsDay('2026-05-02'), []));

    expect(array_keys($errors))->toBe(['statuses'])
        ->and($errors['statuses'][0])->toBe(__('dashboard.errors.statuses_required'))
        ->and($errors['statuses'][0])->not->toBe('dashboard.errors.statuses_required');
});

test('an invalid range and an empty status list raise ONE exception carrying both keys', function (string $to) {
    $exceptions = [];

    try {
        app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-05'), bucketsDay($to), []);
    } catch (ValidationException $e) {
        $exceptions[] = $e;
    }

    expect($exceptions)->toHaveCount(1)
        ->and(array_keys($exceptions[0]->errors()))->toEqualCanonicalizing(['range', 'statuses']);
})->with([
    'inverted range' => '2026-05-01',
    'too long range' => '2028-05-01',
]);

test('ResolveSalesBuckets is constructor-injected into both series actions', function (string $action) {
    $constructor = (new ReflectionClass($action))->getConstructor();

    $types = array_map(fn (ReflectionParameter $p): string => (string) $p->getType(), $constructor?->getParameters() ?? []);

    expect($types)->toContain(ResolveSalesBuckets::class);
})->with([GetSalesSeries::class, GetOrdersSeries::class]);

test('ResolveSalesBuckets authorizes nothing: it takes no privileged-attempt logger and works for a guest', function () {
    $constructor = (new ReflectionClass(ResolveSalesBuckets::class))->getConstructor();
    $types = array_map(fn (ReflectionParameter $p): string => (string) $p->getType(), $constructor?->getParameters() ?? []);

    $result = app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-01'), bucketsDay('2026-05-01'), null);

    expect($types)->not->toContain(LogRefusedPrivilegedAttempt::class)
        ->and(auth()->check())->toBeFalse()
        ->and($result['buckets'])->toHaveCount(1);
});

test('only the two series actions reference ResolveSalesBuckets anywhere in app/', function () {
    $referencing = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'ResolveSalesBuckets')) {
            $referencing[] = str_replace(base_path('app').'/', '', $file->getPathname());
        }
    }

    sort($referencing);

    expect($referencing)->toBe([
        'Actions/Dashboard/GetOrdersSeries.php',
        'Actions/Dashboard/GetSalesSeries.php',
        'Actions/Dashboard/ResolveSalesBuckets.php',
    ]);
});

// --- F1: status-list normalization ---------------------------------------------------------------

function bucketsResolve(?array $statuses): array
{
    return app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay('2026-05-01'), bucketsDay('2026-05-01'), $statuses);
}

test('a huge status list of duplicates collapses to one status', function () {
    $result = bucketsResolve(array_fill(0, 200_000, OrderStatus::Delivered));

    expect($result['statuses'])->toBe([OrderStatus::Delivered]);
});

test('a string-keyed status array yields a proper list', function () {
    $result = bucketsResolve(['x' => OrderStatus::Shipped]);

    expect($result['statuses'])->toBe([OrderStatus::Shipped])
        ->and(array_is_list($result['statuses']))->toBeTrue();
});

test('non-enum entries are dropped and duplicates removed from a mixed list', function () {
    $result = bucketsResolve([OrderStatus::Shipped, 'shipped', 5, null, OrderStatus::Shipped]);

    expect($result['statuses'])->toBe([OrderStatus::Shipped]);
});

test('a list with only garbage is refused on the statuses key only', function () {
    $errors = bucketsErrors(fn () => bucketsResolve(['garbage']));

    expect(array_keys($errors))->toBe(['statuses'])
        ->and($errors['statuses'][0])->toBe(__('dashboard.errors.statuses_required'));
});

test('duplicates of different statuses keep the first occurrence order', function () {
    $result = bucketsResolve([OrderStatus::Shipped, OrderStatus::Pending, OrderStatus::Shipped]);

    expect($result['statuses'])->toBe([OrderStatus::Shipped, OrderStatus::Pending]);
});

test('the normalized statuses never exceed the number of statuses that exist', function () {
    $result = bucketsResolve(array_merge(...array_fill(0, 50, OrderStatus::cases())));

    expect($result['statuses'])->toBe(OrderStatus::cases());
});

// --- F2: extreme dates --------------------------------------------------------------------------

test('a range touching years outside 1000..9998 is refused with range_invalid before any query', function (string $from, string $to) {
    $counts = DomainQueryLog::capture(function () use ($from, $to, &$errors): void {
        $errors = bucketsErrors(fn () => app(ResolveSalesBuckets::class)(SalesGranularity::Day, bucketsDay($from), bucketsDay($to), null));
    });

    expect(array_keys($errors))->toBe(['range'])
        ->and($errors['range'][0])->toBe(__('dashboard.errors.range_invalid'))
        ->and($errors['range'][0])->not->toBe('dashboard.errors.range_invalid')
        ->and($counts['orders'])->toBe(0);
})->with([
    'from year 999' => ['0999-12-31', '0999-12-31'],
    'from year 999 into year 1000' => ['0999-12-31', '1000-01-02'],
    'from year 1' => ['0001-01-01', '0001-01-02'],
    'to 9999-12-31' => ['9999-12-30', '9999-12-31'],
    'single day 9999-12-31' => ['9999-12-31', '9999-12-31'],
]);

test('a negative year is refused with range_invalid', function () {
    $from = CarbonImmutable::create(-5, 1, 1, 0, 0, 0, 'Europe/Madrid');
    $errors = bucketsErrors(fn () => app(ResolveSalesBuckets::class)(SalesGranularity::Day, $from, $from, null));

    expect(array_keys($errors))->toBe(['range'])
        ->and($errors['range'][0])->toBe(__('dashboard.errors.range_invalid'));
});

test('a year granularity range from year 999 is range_invalid, not range_too_long', function () {
    $errors = bucketsErrors(fn () => app(ResolveSalesBuckets::class)(SalesGranularity::Year, bucketsDay('0999-12-31'), bucketsDay('2026-01-01'), null));

    expect($errors['range'][0] ?? null)->toBe(__('dashboard.errors.range_invalid'));
});

test('the edges of the supported years are accepted', function (string $granularity, string $from, string $to, string $firstKey) {
    $result = app(ResolveSalesBuckets::class)(SalesGranularity::from($granularity), bucketsDay($from), bucketsDay($to), null);

    expect($result['buckets'][0]['bucket'])->toBe($firstKey)
        ->and($result['buckets'])->toHaveCount(1);
})->with([
    'day 1000-01-01' => ['day', '1000-01-01', '1000-01-01', '1000-01-01'],
    'day 9998-12-31' => ['day', '9998-12-31', '9998-12-31', '9998-12-31'],
    'year 9998' => ['year', '9998-12-31', '9998-12-31', '9998'],
    'year 1000' => ['year', '1000-01-01', '1000-01-01', '1000'],
]);
