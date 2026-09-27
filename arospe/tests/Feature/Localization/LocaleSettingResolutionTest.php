<?php

// Story 0068 -- App\Models\LocaleSetting::defaultUiLocale() / defaultNotificationLocale(), the
// highest-value block in this story per its own task file. The three-tier resolution chain
// (persisted row -> config('app.locale') -> UiLocale::English) MUST use tryFrom() at both the
// persisted and the config tier, never from() -- this runs on the fallback branch of every web
// request, guest requests included, so a \ValueError here would 500 the entire application.
//
// Filed under tests/Feature/ rather than tests/Unit/, despite the task file's own "Unit:" label
// on these items: every case below needs the real database (a persisted LocaleSetting row, or an
// explicitly-empty table), and this repo's own tests/Unit/ convention
// (docs/testing/backend/unit-tests.md) is unambiguous that a test needing the database "isn't a
// unit test in this codebase". The task file uses "Unit" here to describe test GRANULARITY (one
// method's behaviour), not the tests/Unit/ folder -- the same way its Authorization section
// labels tests/Feature/Policies/StoreLanguagePolicyTest.php itself "Unit".
//
// Every case sets config(['app.locale' => ...]) explicitly, per the task file's own callout: a
// test depending on a config key must set it, including to the value it assumes, never relying on
// the ambient .env value (the SUPER_ADMIN_EMAIL hazard this project's errors log already records).

use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Illuminate\Support\Facades\DB;

// =====================================================================
// The persisted tier
// =====================================================================

test('with a persisted row, defaultUiLocale returns the stored case', function () {
    config(['app.locale' => 'es']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'en',
    ]);

    expect(LocaleSetting::defaultUiLocale())->toBe(UiLocale::English);
});

test('with a persisted row, defaultNotificationLocale returns the stored case', function () {
    config(['app.locale' => 'es']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'en',
    ]);

    expect(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::English);
});

// defaultNotificationLocale() must not read default_ui_locale, and vice versa.
test('each accessor reads only its own column, never the other', function () {
    config(['app.locale' => 'es']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'es',
    ]);

    expect(LocaleSetting::defaultUiLocale())->toBe(UiLocale::English)
        ->and(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::Spanish);
});

// =====================================================================
// The config fallback tier -- no row at all
// =====================================================================

test('with no row at all, defaultUiLocale falls back to config(app.locale) mapped through UiLocale', function () {
    config(['app.locale' => 'en']);

    expect(LocaleSetting::count())->toBe(0)
        ->and(LocaleSetting::defaultUiLocale())->toBe(UiLocale::English);
});

test('with no row at all, defaultNotificationLocale falls back to config(app.locale) mapped through UiLocale', function () {
    config(['app.locale' => 'es']);

    expect(LocaleSetting::count())->toBe(0)
        ->and(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::Spanish);
});

// =====================================================================
// The last-resort tier -- the assertion standing between the app and a 500 on every request,
// including guest requests. config('app.locale') is an arbitrary Laravel locale string with no
// guarantee of being a UiLocale case (APP_LOCALE=fr is a legal deployment), and UiLocale::from()
// would raise \ValueError on it. tryFrom() must be used instead, falling through to
// UiLocale::English rather than throwing.
// =====================================================================

test('with no row and an unmapped config(app.locale), defaultUiLocale returns English and does not throw', function () {
    config(['app.locale' => 'fr']);

    expect(LocaleSetting::count())->toBe(0);

    $resolved = LocaleSetting::defaultUiLocale();

    expect($resolved)->toBe(UiLocale::English);
});

test('with no row and an unmapped config(app.locale), defaultNotificationLocale returns English and does not throw', function () {
    config(['app.locale' => 'fr']);

    expect(LocaleSetting::count())->toBe(0);

    $resolved = LocaleSetting::defaultNotificationLocale();

    expect($resolved)->toBe(UiLocale::English);
});

// =====================================================================
// A stored value outside the enum -- written by bypassing the model (DB::table()->update()) so
// no cast or mutator can intervene, simulating a value that predates a narrower enum or was
// written by a direct SQL statement. Neither column is enum-cast (D21) specifically so this
// falls through to the config tier instead of throwing on hydration.
// =====================================================================

test('a stored default_ui_locale value outside the enum falls through to the config tier, not throwing', function () {
    config(['app.locale' => 'es']);
    $settings = LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'en',
    ]);

    DB::table('locale_settings')->where('id', $settings->id)->update(['default_ui_locale' => 'fr']);

    expect(LocaleSetting::defaultUiLocale())->toBe(UiLocale::Spanish);
});

test('a stored default_notification_locale value outside the enum falls through to the config tier, not throwing', function () {
    config(['app.locale' => 'es']);
    $settings = LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'en',
    ]);

    DB::table('locale_settings')->where('id', $settings->id)->update(['default_notification_locale' => 'de']);

    expect(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::Spanish);
});

// =====================================================================
// Type -- both accessors return a UiLocale instance, never a string. The middleware's ->value
// call site and story 0069's select both depend on the type.
// =====================================================================

test('both accessors return UiLocale instances, not strings', function () {
    config(['app.locale' => 'en']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'en',
    ]);

    expect(LocaleSetting::defaultUiLocale())->toBeInstanceOf(UiLocale::class)
        ->and(LocaleSetting::defaultNotificationLocale())->toBeInstanceOf(UiLocale::class);
});

// =====================================================================
// current() -- the one resolution path (D20), find(SINGLETON_ID).
// =====================================================================

test('current returns the singleton row by its fixed id', function () {
    config(['app.locale' => 'en']);
    $settings = LocaleSetting::factory()->create();

    expect(LocaleSetting::current()->id)->toBe($settings->id)
        ->and(LocaleSetting::current()->id)->toBe(LocaleSetting::SINGLETON_ID);
});
