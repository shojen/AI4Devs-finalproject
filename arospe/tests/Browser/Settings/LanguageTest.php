<?php

// Story 0067 -- Layer 1 admin UI language switcher, SETTINGS surface (Settings > Language tab).
//
// The chrome switcher CO-RENDERS on every settings page, so every locator here is scoped to the
// settings-surface hooks (`settings-language-switcher` / `settings-language-option-{en,es}`) and
// the "exactly two options" check is a scoped COUNT, never a bare value-set comparison -- a
// duplicated pair still reduces to {en, es} (D-20).
//
// "Selected" contract the markup must honour: the option's own hook element (or a descendant)
// carries aria-checked="true" / data-checked / aria-current="true" / aria-pressed="true".
//
// No ->waitForEvent('networkidle'); the polling assertScript() is the wait after a click.

use App\Models\LocaleSetting;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

const SETTINGS_SWITCHER = '[data-test="settings-language-switcher"]';

function settingsLanguageActor(?string $uiLocale): User
{
    $user = User::factory()->create(['ui_locale' => $uiLocale]);
    $user->givePermissionTo('sales-regions.view');

    return $user;
}

function settingsNavlistTextJs(): string
{
    return "(document.querySelector('[data-test=\"settings-navlist\"]')?.textContent ?? '').replace(/\\s+/g, ' ').trim()";
}

function settingsOptionValuesJs(): string
{
    return "Array.from(document.querySelectorAll('".SETTINGS_SWITCHER." [data-test^=\"settings-language-option-\"]')).map(e => e.dataset.test.replace('settings-language-option-', '')).sort().join(',')";
}

function settingsCheckedOptionsJs(): string
{
    return "Array.from(document.querySelectorAll('".SETTINGS_SWITCHER." [data-test^=\"settings-language-option-\"]')).filter(e => [e, ...e.querySelectorAll('*')].some(n => n.getAttribute('aria-checked') === 'true' || n.hasAttribute('data-checked') || n.getAttribute('aria-current') === 'true' || n.getAttribute('aria-pressed') === 'true')).map(e => e.dataset.test.replace('settings-language-option-', '')).join(',')";
}

// Scenario: An administrator switches the interface language from the Settings area
test('an administrator can switch the interface language from the Settings Language tab', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'en', 'default_notification_locale' => 'en']);
    $user = settingsLanguageActor('en');
    $this->actingAs($user);

    visit('/settings/language')
        ->assertScript(settingsNavlistTextJs(), 'Profile Security Appearance Language')
        ->click('@settings-language-option-es')
        // The polling assertion is the wait: it retries until the redirect has landed.
        ->assertScript(settingsNavlistTextJs(), 'Perfil Seguridad Apariencia Idioma')
        // The tab stays on itself rather than bouncing to the dashboard.
        ->assertPathIs('/settings/language')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->ui_locale)->toBe('es');
});

// Scenario: Each surface offers only Spanish and English (settings markup family, D-20)
test('the Settings Language tab offers exactly Spanish and English and no store content language', function () {
    StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'French']);
    $this->actingAs(settingsLanguageActor('es'));

    visit('/settings/language')
        ->assertScript('document.querySelectorAll(\''.SETTINGS_SWITCHER.'\').length', 1)
        ->assertScript('document.querySelectorAll(\''.SETTINGS_SWITCHER.' [data-test^="settings-language-option-"]\').length', 2)
        // Page-wide too: no duplicated settings option anywhere (the chrome uses different hooks).
        ->assertScript('document.querySelectorAll(\'[data-test^="settings-language-option-"]\').length', 2)
        ->assertScript(settingsOptionValuesJs(), 'en,es')
        ->assertScript('document.querySelectorAll(\'[data-test$="language-option-fr"]\').length', 0)
        ->assertNoJavaScriptErrors();
});

// The active option is read through the shared currentLocale(): a view hardcoding the selection
// would fail one of these rows.
test('the Settings Language tab marks the effective interface language as the selected option', function (?string $stored, string $storeDefault, string $expected) {
    LocaleSetting::factory()->create(['default_ui_locale' => $storeDefault, 'default_notification_locale' => 'en']);
    $this->actingAs(settingsLanguageActor($stored));

    visit('/settings/language')
        ->assertScript(settingsCheckedOptionsJs(), $expected)
        ->assertNoJavaScriptErrors();
})->with([
    'stored es over default en' => ['es', 'en', 'es'],
    'stored en over default es' => ['en', 'es', 'en'],
    'never chosen, default es' => [null, 'es', 'es'],
]);

// Scenario: A choice made in the account menu is reflected on the Settings tab
test('a choice made in the account menu is shown as selected on the Settings Language tab', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'en', 'default_notification_locale' => 'en']);
    $this->actingAs(settingsLanguageActor('en'));

    visit('/dashboard')
        ->click('@sidebar-menu-button')
        ->click('[data-test="sidebar"] [data-test="language-option-es"]')
        ->assertScript("(document.querySelector('[data-test=\"sidebar-link-sales_regions\"]')?.textContent ?? '').trim()", 'Regiones de venta');

    visit('/settings/language')
        ->assertScript(settingsCheckedOptionsJs(), 'es')
        ->assertNoJavaScriptErrors();
});
