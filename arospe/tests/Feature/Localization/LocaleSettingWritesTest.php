<?php

// Story 0068 -- App\Actions\Localization\SetDefaultUiLocale / SetDefaultNotificationLocale (D23:
// two narrow single-column actions, never one taking both, to avoid a read-modify-write clobbering
// a concurrent change to the column the caller never meant to touch). Both firstOrCreate the
// singleton row if the seeder has not run, write with forceFill() (neither column is fillable),
// and need no DB::transaction() -- one column, one row, no multi-row invariant (unlike the
// store_languages default swap).

use App\Actions\Localization\SetDefaultNotificationLocale;
use App\Actions\Localization\SetDefaultUiLocale;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    config(['app.locale' => 'es']);
});

function localeSettingWriteActor(array $permissions = ['store-languages.view', 'store-languages.edit']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

// =====================================================================
// Independence -- each action writes only its own column.
// =====================================================================

test('setting the default dashboard language persists it and leaves the notification column untouched', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'es',
    ]);

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultUiLocale::class)(UiLocale::English);

    $settings = LocaleSetting::current();

    expect($settings->default_ui_locale)->toBe('en')
        ->and($settings->default_notification_locale)->toBe('es');
});

test('setting the default notification language persists it and leaves the dashboard column untouched', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'es',
    ]);

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultNotificationLocale::class)(UiLocale::English);

    $settings = LocaleSetting::current();

    expect($settings->default_notification_locale)->toBe('en')
        ->and($settings->default_ui_locale)->toBe('en');
});

// The independence Gherkin scenario, spelled out precisely: a dashboard default of English,
// unaffected by a subsequent notification-only change to Spanish.
test('the two defaults are independent of each other', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'en',
    ]);

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultNotificationLocale::class)(UiLocale::Spanish);

    expect(LocaleSetting::current()->default_ui_locale)->toBe('en');
});

// =====================================================================
// Singleton creation -- either action against an empty table creates the row, and neither action
// ever creates a second row.
// =====================================================================

test('SetDefaultUiLocale against an empty table creates the singleton row rather than failing', function () {
    expect(LocaleSetting::count())->toBe(0);

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultUiLocale::class)(UiLocale::English);

    expect(LocaleSetting::count())->toBe(1)
        ->and(LocaleSetting::current()->default_ui_locale)->toBe('en')
        ->and(LocaleSetting::current()->id)->toBe(LocaleSetting::SINGLETON_ID);
});

test('SetDefaultNotificationLocale against an empty table creates the singleton row rather than failing', function () {
    expect(LocaleSetting::count())->toBe(0);

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultNotificationLocale::class)(UiLocale::English);

    expect(LocaleSetting::count())->toBe(1)
        ->and(LocaleSetting::current()->default_notification_locale)->toBe('en');
});

test('neither action ever creates a second row, on an empty table or a populated one', function () {
    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultUiLocale::class)(UiLocale::English);
    expect(LocaleSetting::count())->toBe(1);

    app(SetDefaultUiLocale::class)(UiLocale::Spanish);
    app(SetDefaultNotificationLocale::class)(UiLocale::English);

    expect(LocaleSetting::count())->toBe(1);
});

// =====================================================================
// Phase-4-style finding (docs/security/model-instance-trust.md) -- a caller-dirtied attribute on
// a fetched LocaleSetting must not persist through either action.
// =====================================================================

test('a caller-dirtied attribute on a fetched LocaleSetting is not persisted by SetDefaultUiLocale', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'es',
    ]);

    $fetched = LocaleSetting::current();
    $fetched->default_notification_locale = 'en';

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultUiLocale::class)(UiLocale::English);

    expect(LocaleSetting::current()->default_ui_locale)->toBe('en')
        ->and(LocaleSetting::current()->default_notification_locale)->toBe('es');
});

test('a caller-dirtied attribute on a fetched LocaleSetting is not persisted by SetDefaultNotificationLocale', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'es',
    ]);

    $fetched = LocaleSetting::current();
    $fetched->default_ui_locale = 'en';

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultNotificationLocale::class)(UiLocale::English);

    expect(LocaleSetting::current()->default_notification_locale)->toBe('en')
        ->and(LocaleSetting::current()->default_ui_locale)->toBe('es');
});

// =====================================================================
// Return value -- each action returns the refreshed LocaleSetting row.
// =====================================================================

// =====================================================================
// Independence from the content-authoring layer (PRD assumption 14 / D18) -- the two i18n
// layers must never bleed into each other. Setting the dashboard default must not touch
// store_languages.is_default, the content-authoring layer's own, entirely separate default.
// =====================================================================

test('the content-authoring default is untouched by a dashboard default change', function () {
    StoreLanguage::factory()->create(); // the Spanish-equivalent stand-in
    $frenchContentDefault = StoreLanguage::factory()->default()->create();

    $this->actingAs(localeSettingWriteActor());

    app(SetDefaultUiLocale::class)(UiLocale::English);

    expect($frenchContentDefault->fresh()->is_default)->toBeTrue();
});

test('SetDefaultUiLocale returns the refreshed LocaleSetting row', function () {
    $this->actingAs(localeSettingWriteActor());

    $result = app(SetDefaultUiLocale::class)(UiLocale::English);

    expect($result)->toBeInstanceOf(LocaleSetting::class)
        ->and($result->default_ui_locale)->toBe('en');
});
