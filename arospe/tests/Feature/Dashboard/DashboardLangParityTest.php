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
