<?php

// Story 0082 (D-1): lang/{en,es}/dashboard.php were created by that story with an `errors` group;
// story 0083 extends the same files with new top-level groups (hero, counters, blog, stock, orders,
// ...), which leaves the pinned `errors` assertions below untouched.

/**
 * @return array<string, mixed>
 */
function dashboardLang(string $locale): array
{
    $path = base_path("lang/{$locale}/dashboard.php");

    expect(file_exists($path))->toBeTrue("lang/{$locale}/dashboard.php must exist");

    return require $path;
}

/**
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function dashboardFlattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            array_push($keys, ...dashboardFlattenKeys($value, $full));
        } else {
            $keys[] = $full;
        }
    }

    sort($keys);

    return $keys;
}

/**
 * @return list<string>
 */
function dashboardPlaceholders(string $text): array
{
    preg_match_all('/:([A-Za-z_]+)/', $text, $matches);

    $found = array_unique($matches[1]);
    sort($found);

    return array_values($found);
}

test('the errors group holds exactly range_invalid, range_too_long and statuses_required in both locales', function (string $locale) {
    $errors = dashboardLang($locale)['errors'] ?? null;

    expect($errors)->toBeArray()
        ->and(array_keys($errors))->toEqualCanonicalizing(['range_invalid', 'range_too_long', 'statuses_required']);
})->with(['en', 'es']);

test('every errors message is a non-empty string in both locales', function (string $locale) {
    foreach (dashboardLang($locale)['errors'] as $key => $message) {
        expect($message)->toBeString()->not->toBe('', "errors.{$key} ({$locale}) must not be empty");
    }
})->with(['en', 'es']);

test('English and Spanish dashboard files define identical key sets', function () {
    expect(dashboardFlattenKeys(dashboardLang('es')))->toBe(dashboardFlattenKeys(dashboardLang('en')));
});

test('each errors message carries the same placeholders in English and Spanish', function (string $key) {
    $en = dashboardLang('en')['errors'][$key];
    $es = dashboardLang('es')['errors'][$key];

    expect(dashboardPlaceholders($es))->toBe(dashboardPlaceholders($en));
})->with(['range_invalid', 'range_too_long', 'statuses_required']);

test('range_too_long exposes the :max placeholder in both locales', function (string $locale) {
    expect(dashboardLang($locale)['errors']['range_too_long'])->toContain(':max');
})->with(['en', 'es']);

test('range_invalid and statuses_required take no placeholder', function (string $key) {
    expect(dashboardPlaceholders(dashboardLang('en')['errors'][$key]))->toBe([]);
})->with(['range_invalid', 'statuses_required']);

// Story 0086 (D-5, Phase 2 finding 4): the `sales` group. Placeholder parity is asserted per key over
// the whole group, in a loop (the group does not exist before the story, so a dataset built from it at
// collection time would be empty). The `errors` pin above is left exactly as it is.

/**
 * @return array<string, string> flattened `sales.*` leaves, keyed by their dotted path
 */
function dashboardSalesLeaves(string $locale): array
{
    $sales = dashboardLang($locale)['sales'] ?? null;

    expect($sales)->toBeArray("lang/{$locale}/dashboard.php must define a `sales` group");

    $leaves = [];

    foreach (dashboardFlattenKeys($sales) as $key) {
        $leaves[$key] = (string) data_get($sales, $key);
    }

    return $leaves;
}

test('the sales group exists with the same keys in both locales and no empty strings', function () {
    $en = dashboardSalesLeaves('en');
    $es = dashboardSalesLeaves('es');

    expect(array_keys($es))->toBe(array_keys($en))
        ->and($en)->not->toBeEmpty();

    foreach (['en' => $en, 'es' => $es] as $locale => $leaves) {
        foreach ($leaves as $key => $message) {
            expect($message)->not->toBe('', "sales.{$key} ({$locale}) must not be empty");
        }
    }
});

test('every sales message carries the same placeholders in English and Spanish', function () {
    $en = dashboardSalesLeaves('en');
    $es = dashboardSalesLeaves('es');

    foreach ($en as $key => $message) {
        expect(dashboardPlaceholders($es[$key] ?? ''))->toBe(dashboardPlaceholders($message), "sales.{$key} placeholders differ between en and es");
    }
});

test('the sales group defines the documented placeholders in both locales', function (string $locale) {
    $leaves = dashboardSalesLeaves($locale);

    expect(dashboardPlaceholders($leaves['kpi.collected'] ?? ''))->toBe(['percent'])
        ->and(dashboardPlaceholders($leaves['range_year_window'] ?? ''))->toBe(['max', 'min']);
})->with(['en', 'es']);

test('the sales group defines every key the card needs in both locales', function (string $locale) {
    $leaves = dashboardSalesLeaves($locale);

    foreach ([
        'title',
        'kpi.collected',
        'kpi.income_hint',
        'granularity.day', 'granularity.month', 'granularity.year',
        'presets.last_7_days', 'presets.last_30_days', 'presets.this_month', 'presets.this_year',
        'chart_a_title', 'chart_b_title', 'legend_help', 'empty',
        'range_year_window', 'date_invalid', 'statuses_max', 'reset', 'loading',
    ] as $key) {
        expect($leaves)->toHaveKey($key);
    }
})->with(['en', 'es']);

test('the sales group never duplicates the backend range and status refusals', function () {
    expect(dashboardSalesLeaves('en'))->not->toHaveKey('range_error')
        ->not->toHaveKey('range_invalid')
        ->not->toHaveKey('statuses_required');
});

test('the Spanish sales strings really differ from the English ones', function () {
    $en = dashboardSalesLeaves('en');
    $es = dashboardSalesLeaves('es');

    // A few words can legitimately coincide (a brand, "Total"); the group as a whole must be translated.
    $identical = array_keys(array_filter($en, fn (string $message, string $key): bool => ($es[$key] ?? null) === $message, ARRAY_FILTER_USE_BOTH));

    expect(count($identical))->toBeLessThan((int) ceil(count($en) / 4), 'too many sales strings are untranslated: '.implode(', ', $identical));
});
