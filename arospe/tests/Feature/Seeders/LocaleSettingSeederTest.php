<?php

// Story 0068 -- Database\Seeders\LocaleSettingSeeder (D22): a one-time bootstrap from
// config('app.locale'), failing loudly when that value is not a UiLocale case. Every test here
// sets config(['app.locale' => ...]) explicitly, per the task file's own callout: unlike
// StoreLanguageSeeder (which reads no config at all), this seeder branches on one, so the
// ambient-config hazard (docs/errors-log's SUPER_ADMIN_EMAIL entry) is live here.

use App\Models\LocaleSetting;
use App\Models\User;
use Database\Seeders\LocaleSettingSeeder;
use Database\Seeders\ProductionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// --- Fresh install ---

test('seeding an empty table creates exactly one row, both columns matching config(app.locale)', function () {
    config(['app.locale' => 'es']);

    $this->seed(LocaleSettingSeeder::class);

    expect(LocaleSetting::count())->toBe(1);

    $settings = LocaleSetting::current();

    expect($settings->default_ui_locale)->toBe('es')
        ->and($settings->default_notification_locale)->toBe('es')
        ->and($settings->id)->toBe(LocaleSetting::SINGLETON_ID);
});

test('seeding an empty table with app.locale set to en creates a row matching en', function () {
    config(['app.locale' => 'en']);

    $this->seed(LocaleSettingSeeder::class);

    $settings = LocaleSetting::current();

    expect($settings->default_ui_locale)->toBe('en')
        ->and($settings->default_notification_locale)->toBe('en');
});

// --- Fail loudly ---

test('seeding with an app.locale outside the offered pair throws and writes no row', function () {
    config(['app.locale' => 'fr']);

    expect(fn () => (new LocaleSettingSeeder)->run())->toThrow(Throwable::class);

    expect(LocaleSetting::count())->toBe(0);
});

// --- Idempotency ---

test('seeding twice creates no second row', function () {
    config(['app.locale' => 'es']);

    $this->seed(LocaleSettingSeeder::class);
    $this->seed(LocaleSettingSeeder::class);

    expect(LocaleSetting::count())->toBe(1);
});

// --- D22: the bootstrap only writes an EMPTY table, never a resync ---

test('re-seeding after an administrator has changed either default leaves both untouched', function () {
    config(['app.locale' => 'es']);

    $this->seed(LocaleSettingSeeder::class);

    $settings = LocaleSetting::current();
    $settings->forceFill(['default_ui_locale' => 'en'])->save();

    $this->seed(LocaleSettingSeeder::class);

    expect(LocaleSetting::current()->default_ui_locale)->toBe('en')
        ->and(LocaleSetting::count())->toBe(1);
});

// --- ProductionSeeder composition ---

test('ProductionSeeder reaches LocaleSettingSeeder', function () {
    config(['app.locale' => 'es']);
    app()->instance('env', 'production');
    config(['auth.super_admin.email' => null]);

    (new ProductionSeeder)();

    expect(LocaleSetting::count())->toBe(1)
        ->and(LocaleSetting::current()->default_ui_locale)->toBe('es');
});

// --- Isolation ---

test('running the Locale Setting seeder alone creates no users, roles or permissions', function () {
    config(['app.locale' => 'es']);

    $this->seed(LocaleSettingSeeder::class);

    expect(User::count())->toBe(0)
        ->and(Role::count())->toBe(0)
        ->and(Permission::count())->toBe(0);
});
