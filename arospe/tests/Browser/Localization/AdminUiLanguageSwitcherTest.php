<?php

// Story 0067 -- Layer 1 admin UI language switcher, CHROME surface (the account menu).
//
// Livewire::test() cannot prove any of this: it runs with the whole middleware stack disabled, so
// SetUiLocale never runs and "the UI is now Spanish" would be vacuously green. Everything here is
// therefore driven through a real browser against the real `web` middleware group.
//
// Hooks: the chrome switcher renders TWICE per page (desktop sidebar menu + mobile topbar menu, both
// always in the DOM, only CSS-hidden), so every locator is scoped to ONE of the two menus through
// a literal CSS selector -- see docs/testing/frontend/playwright-setup/waiting-rules.md
// ("A page embedding the same component twice duplicates every data-test hook").
//
// "Selected" contract the markup must honour: the option's own hook element (or a descendant)
// carries aria-checked="true" / data-checked / aria-current="true" / aria-pressed="true".
//
// Waiting: no ->waitForEvent('networkidle') anywhere. After a click the polling assertScript() IS
// the wait -- it is retried until the post-redirect page carries the new language.
//
// Language strings: assertions use navigation.items.sales_regions ('Sales Regions' / 'Regiones de
// venta' -- no overlap), read from the sidebar link's own hook, never 'Dashboard'/'Roles'.

use App\Models\LocaleSetting;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

const DESKTOP_SWITCHER = '[data-test="sidebar"] [data-test="language-switcher"]';
const MOBILE_SWITCHER = '[data-test="topbar-mobile-profile"] [data-test="language-switcher"]';

/**
 * A user able to see the Sales Regions sidebar link (the translated string under test).
 */
function languageSwitcherActor(?string $uiLocale): User
{
    $user = User::factory()->create(['ui_locale' => $uiLocale]);
    $user->givePermissionTo('sales-regions.view');

    return $user;
}

function salesRegionsSidebarLabelJs(): string
{
    return "(document.querySelector('[data-test=\"sidebar-link-sales_regions\"]')?.textContent ?? '').trim()";
}

/**
 * The locale codes of the options inside the scope, sorted and joined ("en,es"). Equality against
 * "en,es" pins the value set AND (a duplicated pair reads "en,en,es,es") the count.
 */
function languageOptionValuesJs(string $scope): string
{
    return "Array.from(document.querySelectorAll('{$scope} [data-test^=\"language-option-\"]')).map(e => e.dataset.test.replace('language-option-', '')).sort().join(',')";
}

/**
 * The locale codes of the options inside the scope that are marked as the current choice.
 */
function checkedLanguageOptionsJs(string $scope): string
{
    return "Array.from(document.querySelectorAll('{$scope} [data-test^=\"language-option-\"]')).filter(e => [e, ...e.querySelectorAll('*')].some(n => n.getAttribute('aria-checked') === 'true' || n.hasAttribute('data-checked') || n.getAttribute('aria-current') === 'true' || n.getAttribute('aria-pressed') === 'true')).map(e => e.dataset.test.replace('language-option-', '')).join(',')";
}

// Scenarios: switch to English / back to Spanish from the account menu (one round trip, both directions)
test('an administrator can switch the interface language from Spanish to English and back from the account menu', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'en', 'default_notification_locale' => 'en']);
    $user = languageSwitcherActor('es');
    $this->actingAs($user);

    visit('/dashboard')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Regiones de venta')
        ->click('@sidebar-menu-button')
        ->click(DESKTOP_SWITCHER.' [data-test="language-option-en"]')
        // The polling assertion is the wait: it retries until the redirect has landed.
        ->assertScript(salesRegionsSidebarLabelJs(), 'Sales Regions');
    expect($user->fresh()->ui_locale)->toBe('en');

    // Force a genuinely fresh page load -- the preference must come from the stored row.
    visit('/dashboard')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Sales Regions')
        ->click('@sidebar-menu-button')
        ->click(DESKTOP_SWITCHER.' [data-test="language-option-es"]')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Regiones de venta');
    expect($user->fresh()->ui_locale)->toBe('es');

    visit('/dashboard')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Regiones de venta')
        ->assertNoJavaScriptErrors();
});

// Scenario: Each surface offers only Spanish and English (and no store content language)
test('the account menu offers exactly Spanish and English and no store content language', function () {
    StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'French']);
    $this->actingAs(languageSwitcherActor('es'));

    visit('/dashboard')
        ->click('@sidebar-menu-button')
        ->assertScript(languageOptionValuesJs(DESKTOP_SWITCHER), 'en,es')
        ->assertScript('document.querySelectorAll(\''.DESKTOP_SWITCHER.' [data-test^="language-option-"]\').length', 2)
        ->assertScript('document.querySelectorAll(\'[data-test="language-option-fr"]\').length', 0)
        ->assertNoJavaScriptErrors();
});

// Scenario: The switcher indicates the language currently in use
test('the account menu marks the stored interface language as the selected option', function (string $stored) {
    // The store default differs from the stored choice, so a control reading the default fails.
    LocaleSetting::factory()->create([
        'default_ui_locale' => $stored === 'es' ? 'en' : 'es',
        'default_notification_locale' => 'en',
    ]);
    $this->actingAs(languageSwitcherActor($stored));

    visit('/dashboard')
        ->click('@sidebar-menu-button')
        ->assertScript(checkedLanguageOptionsJs(DESKTOP_SWITCHER), $stored)
        ->assertNoJavaScriptErrors();
})->with(['es', 'en']);

// Scenario: An administrator who has never chosen a language sees the store default selected
test('an account with no stored interface language renders a working switcher with the store default selected', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'en']);
    $this->actingAs(languageSwitcherActor(null));

    visit('/dashboard')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Regiones de venta')
        ->click('@sidebar-menu-button')
        ->assertScript(languageOptionValuesJs(DESKTOP_SWITCHER), 'en,es')
        ->assertScript(checkedLanguageOptionsJs(DESKTOP_SWITCHER), 'es')
        ->assertNoJavaScriptErrors();
});

// Scenario: The switcher is available to an administrator holding no module permissions
test('the switcher is available to an administrator holding no module permissions', function () {
    $roleless = User::factory()->create(['ui_locale' => 'es']);
    expect($roleless->getAllPermissions())->toBeEmpty();
    $this->actingAs($roleless);

    visit('/dashboard')
        ->click('@sidebar-menu-button')
        ->assertScript(languageOptionValuesJs(DESKTOP_SWITCHER), 'en,es')
        ->assertNoJavaScriptErrors();
});

// Scenario: The switcher is available on a narrow viewport
test('the switcher is available in the mobile account menu on a narrow viewport', function () {
    $this->actingAs(languageSwitcherActor('es'));

    visit('/dashboard')
        ->resize(390, 800)
        ->click('@mobile-menu-button')
        ->assertScript(languageOptionValuesJs(MOBILE_SWITCHER), 'en,es')
        ->assertVisible(MOBILE_SWITCHER.' [data-test="language-option-en"]')
        ->assertNoJavaScriptErrors();
});

// Scenario: The choice outlives the session it was made in (the one accepted full journey, D-15)
test('an administrator who chose Spanish still sees Spanish after signing out and signing in again', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'en', 'default_notification_locale' => 'en']);
    $user = languageSwitcherActor(null);

    visit('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Sales Regions')
        ->click('@sidebar-menu-button')
        ->click(DESKTOP_SWITCHER.' [data-test="language-option-es"]')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Regiones de venta')
        // Both account menus carry data-test="logout-button" and both are in the DOM: scope the
        // click to the desktop menu or the locator is ambiguous.
        ->click('@sidebar-menu-button')
        ->click('[data-test="sidebar"] [data-test="logout-button"]')
        // Logout redirects to the public welcome page, which renders no "Log in" text; the polling
        // assertion is the wait for the sign-out to land (the sidebar no longer exists).
        ->assertScript("document.querySelectorAll('[data-test=\"sidebar\"]').length", 0);

    visit('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertScript(salesRegionsSidebarLabelJs(), 'Regiones de venta')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->ui_locale)->toBe('es');
});
