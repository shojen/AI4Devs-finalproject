<?php

// Story 0069 -- the DASHBOARD DEFAULTS section of App\Livewire\StoreLanguages\Index, and the
// separation between it and the content languages section (CatalogRenderingTest.php).
//
// The screen's central product risk is conflating two different i18n layers: a content language
// may be ANY ISO 639-1 language and is about the store's content, while a dashboard default is
// `en`/`es` only (App\Enums\UiLocale) and is about the admin interface. The same string -- "English",
// `en` -- is legitimately available in both, with different meanings. A test asserting merely that
// both sections render would pass against a screen that wired one list into the other, so the
// first test below is the DELIBERATE COLLISION: the content default is English while the dashboard
// default is Spanish.
//
// A native <select> rendered by Livewire carries no server-rendered `selected` attribute (wire:model
// sets it client-side), so "which option is selected" is asserted on the component's own bound
// property plus the exact option set, and on a real browser only where a click is the point.
//
// Assumed lang key (the first thing to adjust if the real copy names it differently):
//   localization.settings.description   the section description stating these are system-wide
//                                       defaults and not the administrator's personal preference
// Assumed lang key group:  localization.attributes.defaultUiLocale / defaultNotificationLocale.

use App\Enums\UiLocale;
use App\Livewire\StoreLanguages\Index;
use App\Models\LocaleSetting;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function storeLanguagesLocaleActor(array $permissions = ['store-languages.view', 'store-languages.create', 'store-languages.edit', 'store-languages.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * The slice of the page belonging to the dashboard defaults section.
 */
function storeLanguagesLocaleSectionHtml(string $html): string
{
    $start = strpos($html, 'data-test="locale-settings-section"');

    expect($start)->not->toBeFalse('Expected the dashboard defaults section wrapper on the page.');

    $other = strpos($html, 'data-test="store-languages-section"');

    if ($other !== false && $other > $start) {
        return substr($html, $start, $other - $start);
    }

    return substr($html, $start);
}

/**
 * The option values of the native <select> carrying `data-test="$hook"`, in order.
 *
 * @return array<int, string>
 */
function storeLanguagesSelectOptionValues(string $html, string $hook): array
{
    $quoted = preg_quote($hook, '/');

    expect(preg_match('/<select[^>]*data-test="'.$quoted.'"[^>]*>(.*?)<\/select>/is', $html, $select))
        ->toBe(1, "Expected a native <select> carrying the hook [{$hook}].");

    preg_match_all('/<option[^>]*\bvalue="([^"]*)"/i', $select[1], $values);

    return $values[1];
}

/**
 * Does the tag carrying `data-test="$dataTest"` also carry `disabled="disabled"`?
 */
function storeLanguagesLocaleControlDisabled(string $html, string $dataTest): bool
{
    $quoted = preg_quote($dataTest, '/');

    return (bool) preg_match(
        '/<[a-z0-9-]+(?=[^>]*\bdata-test="'.$quoted.'")(?=[^>]*\sdisabled="disabled")[^>]*>/is',
        $html
    );
}

// =====================================================================
// The two layers, kept apart
// =====================================================================

test('the deliberate collision: English as the content default and Spanish as the dashboard default stay on their own side of the screen', function () {
    $spanish = StoreLanguage::factory()->create(['code' => 'es', 'name' => 'Español', 'is_default' => false]);
    $english = StoreLanguage::factory()->default()->create(['code' => 'en', 'name' => 'English']);
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'es']);
    $this->actingAs(storeLanguagesLocaleActor());

    $component = Livewire::test(Index::class);
    $html = $component->html();

    // Content side: the marker sits on the English ROW, and only there.
    expect($html)->toContain('data-test="default-badge-language-'.$english->id.'"')
        ->and($html)->not->toContain('data-test="default-badge-language-'.$spanish->id.'"');

    // Interface side: the dashboard default is Spanish, and English is not what it holds.
    $component->assertSet('defaultUiLocale', 'es')
        ->assertSet('defaultNotificationLocale', 'es');

    expect(storeLanguagesLocaleSectionHtml($html))->not->toContain('default-badge-language-')
        ->and(storeLanguagesLocaleSectionHtml($html))->not->toContain('language-row-');
});

test('the dashboard selects offer exactly two options each, counted, and never a content language', function () {
    $this->seed(StoreLanguageSeeder::class);
    StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    StoreLanguage::factory()->create(['code' => 'de', 'name' => 'Deutsch']);
    $this->actingAs(storeLanguagesLocaleActor());

    $html = Livewire::test(Index::class)->html();

    foreach (['default-ui-locale-select', 'default-notification-locale-select'] as $hook) {
        $values = storeLanguagesSelectOptionValues($html, $hook);

        expect($values)->toHaveCount(2)
            ->and($values)->toEqualCanonicalizing(array_column(UiLocale::cases(), 'value'))
            ->and($values)->not->toContain('fr')
            ->and($values)->not->toContain('de');
    }
});

test('the picker offers the bundled list while the dashboard selects offer two values, so the two option lists are structurally different', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesLocaleActor());

    $html = Livewire::test(Index::class)->call('openAddLanguageModal')->html();

    expect(preg_match_all('/data-test="language-option-[a-z]{2}"/', $html))->toBeGreaterThan(100)
        ->and(storeLanguagesSelectOptionValues($html, 'default-ui-locale-select'))->toHaveCount(2);
});

test('the component state shapes differ: the content default is a store language id, the locale properties are UiLocale backing values', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    LocaleSetting::factory()->create(['default_ui_locale' => 'en', 'default_notification_locale' => 'es']);
    $this->actingAs(storeLanguagesLocaleActor());

    $component = Livewire::test(Index::class);

    $defaultRow = collect($component->get('languages'))->firstWhere('isDefault', true);

    expect($defaultRow['id'])->toBe($spanish->id)
        ->and(UiLocale::tryFrom($defaultRow['id']))->toBeNull()
        ->and(UiLocale::tryFrom($component->get('defaultUiLocale')))->toBe(UiLocale::English)
        ->and(UiLocale::tryFrom($component->get('defaultNotificationLocale')))->toBe(UiLocale::Spanish);
});

test('the two selects start from the stored defaults, and from the resolved fallback on an unseeded settings table', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesLocaleActor());

    $unseeded = Livewire::test(Index::class);

    expect(UiLocale::tryFrom($unseeded->get('defaultUiLocale')))->toBe(LocaleSetting::defaultUiLocale())
        ->and(UiLocale::tryFrom($unseeded->get('defaultNotificationLocale')))->toBe(LocaleSetting::defaultNotificationLocale());

    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'en']);

    Livewire::test(Index::class)
        ->assertSet('defaultUiLocale', 'es')
        ->assertSet('defaultNotificationLocale', 'en');
});

// =====================================================================
// Independence by mutation, in both directions
// =====================================================================

test('saving the dashboard default leaves the notification default and the content default untouched, on the same render', function () {
    $french = StoreLanguage::factory()->default()->create(['code' => 'fr', 'name' => 'Français']);
    StoreLanguage::factory()->create(['code' => 'es', 'name' => 'Español']);
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'es']);
    $this->actingAs(storeLanguagesLocaleActor());

    $component = Livewire::test(Index::class)
        ->set('defaultUiLocale', 'en')
        ->call('saveDefaultUiLocale')
        ->assertHasNoErrors();

    $html = $component->html();

    expect(LocaleSetting::defaultUiLocale())->toBe(UiLocale::English)
        ->and(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::Spanish)
        ->and($component->get('defaultNotificationLocale'))->toBe('es')
        ->and($french->fresh()->is_default)->toBeTrue()
        ->and($html)->toContain('data-test="default-badge-language-'.$french->id.'"');
});

test('saving the notification default leaves the dashboard default untouched', function () {
    $this->seed(StoreLanguageSeeder::class);
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'es']);
    $this->actingAs(storeLanguagesLocaleActor());

    $component = Livewire::test(Index::class)
        ->set('defaultNotificationLocale', 'en')
        ->call('saveDefaultNotificationLocale')
        ->assertHasNoErrors();

    expect(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::English)
        ->and(LocaleSetting::defaultUiLocale())->toBe(UiLocale::Spanish)
        ->and($component->get('defaultUiLocale'))->toBe('es');
});

test('promoting a content language leaves both dashboard selects and the stored defaults unchanged', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'en']);
    $this->actingAs(storeLanguagesLocaleActor());

    $component = Livewire::test(Index::class)
        ->call('setDefaultLanguage', $french->id)
        ->assertHasNoErrors();

    expect($french->fresh()->is_default)->toBeTrue()
        ->and($component->get('defaultUiLocale'))->toBe('es')
        ->and($component->get('defaultNotificationLocale'))->toBe('en')
        ->and(LocaleSetting::defaultUiLocale())->toBe(UiLocale::Spanish)
        ->and(LocaleSetting::defaultNotificationLocale())->toBe(UiLocale::English);
});

// =====================================================================
// Read-only, copy and structure
// =====================================================================

test('an actor holding only store-languages.view sees both settings with their values and neither can be changed', function () {
    $this->seed(StoreLanguageSeeder::class);
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'en']);
    $this->actingAs(storeLanguagesLocaleActor(['store-languages.view']));

    $component = Livewire::test(Index::class)
        ->assertSet('defaultUiLocale', 'es')
        ->assertSet('defaultNotificationLocale', 'en');
    $html = $component->html();

    foreach (['default-ui-locale-select', 'default-notification-locale-select', 'save-default-ui-locale', 'save-default-notification-locale'] as $hook) {
        expect(str_contains($html, 'data-test="'.$hook.'"'))->toBeTrue("Hook [{$hook}] must be present on the disabled branch.")
            ->and(storeLanguagesLocaleControlDisabled($html, $hook))->toBeTrue("Hook [{$hook}] must render disabled.");
    }
});

test('an actor holding store-languages.edit sees both selects and both Save buttons enabled', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesLocaleActor(['store-languages.view', 'store-languages.edit']));

    $html = Livewire::test(Index::class)->html();

    foreach (['default-ui-locale-select', 'default-notification-locale-select', 'save-default-ui-locale', 'save-default-notification-locale'] as $hook) {
        expect(str_contains($html, 'data-test="'.$hook.'"'))->toBeTrue()
            ->and(storeLanguagesLocaleControlDisabled($html, $hook))->toBeFalse("Hook [{$hook}] must render enabled.");
    }
});

test('a Super Admin holding zero store-languages permission rows sees both selects enabled', function () {
    $this->seed(StoreLanguageSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    $html = Livewire::test(Index::class)->html();

    expect(storeLanguagesLocaleControlDisabled($html, 'default-ui-locale-select'))->toBeFalse()
        ->and(storeLanguagesLocaleControlDisabled($html, 'default-notification-locale-select'))->toBeFalse()
        ->and(str_contains($html, 'data-test="save-default-ui-locale"'))->toBeTrue();
});

test('the section states in its own description that the defaults are system-wide and not a personal preference', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesLocaleActor());

    $section = storeLanguagesLocaleSectionHtml(Livewire::test(Index::class)->html());

    expect(__('localization.settings.description'))->not->toBe('localization.settings.description')
        ->and($section)->toContain(e(__('localization.settings.description')));
});

test('the section description reads differently from the personal language switcher heading, in both locales', function () {
    foreach (['en', 'es'] as $locale) {
        expect(trans('localization.settings.description', [], $locale))
            ->not->toBe('localization.settings.description')
            ->not->toBe(trans('localization.switcher.heading', [], $locale));
    }
});

test('every localization copy key the screen relies on exists in both locales', function (string $key) {
    foreach (['en', 'es'] as $locale) {
        expect(trans($key, [], $locale))->not->toBe($key, "[{$key}] is missing from lang/{$locale}/localization.php");
    }
})->with([
    'localization.settings.description',
    'localization.attributes.defaultUiLocale',
    'localization.attributes.defaultNotificationLocale',
]);

test('lang/en/localization.php and lang/es/localization.php are key-for-key identical', function () {
    $english = array_keys(Arr::dot(require lang_path('en/localization.php')));
    $spanish = array_keys(Arr::dot(require lang_path('es/localization.php')));

    expect($spanish)->toEqualCanonicalizing($english);
});
