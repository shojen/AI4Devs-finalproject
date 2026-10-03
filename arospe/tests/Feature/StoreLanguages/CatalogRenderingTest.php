<?php

// Story 0069 -- RENDERING of the content languages section of App\Livewire\StoreLanguages\Index
// (resources/views/livewire/store-languages.blade.php). The two screen sections are two files on
// purpose (D-3): this one is the catalog, LocaleSettingsRenderingTest.php is the dashboard
// defaults, so the separation of the two i18n layers is structural in the suite and not only in
// the markup.
//
// Component logic, persistence and refusal wiring live in IndexTest.php; every domain invariant
// (cannot remove the default or the last active language, ...) is story 0068's, tested against
// its actions. Nothing here duplicates that: every test asserts against the rendered HTML.
//
// NO PAGE-GLOBAL ASSERTION ON A LANGUAGE NAME OR A CODE (R-3): story 0067's switcher renders
// "English" and "Español" in the chrome of this very page, and Spanish is always an active
// content language, so assertSee('Español') is ambiguous from the first test written. Every
// language-name assertion reads the row's own `language-name-{id}` cell; every control is
// reached through its D-14 data-test hook, present on BOTH the enabled and the disabled branch.
//
// Assumed lang keys (the first thing to adjust here if the real copy names them differently):
//   store-languages.index.already_default_tooltip     "Already the default"
//   store-languages.index.remove_default_tooltip      "Set another language as default first"
//   store-languages.index.remove_last_language_tooltip "Add another language before removing this one"
//   store-languages.index.action_not_allowed          the generic permission refusal
// Assumed hooks beyond D-14's table (the removal dialog's two buttons have none there):
//   confirm-remove-language, cancel-remove-language

use App\Livewire\StoreLanguages\Index;
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
function storeLanguagesCatalogActor(array $permissions = ['store-languages.view', 'store-languages.create', 'store-languages.edit', 'store-languages.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * Does the tag carrying `data-test="$dataTest"` also carry `disabled="disabled"` -- the exact
 * format Laravel's ComponentAttributeBag renders a bare boolean `disabled` prop as. A bare
 * `\sdisabled` substring would false-match flux:button's own always-present `disabled:opacity-75`
 * utility class on every button, enabled or not.
 */
function storeLanguagesControlDisabled(string $html, string $dataTest): bool
{
    $quoted = preg_quote($dataTest, '/');

    return (bool) preg_match(
        '/<[a-z0-9-]+(?=[^>]*\bdata-test="'.$quoted.'")(?=[^>]*\sdisabled="disabled")[^>]*>/is',
        $html
    );
}

/**
 * Is a control with this hook on the page at all (either branch)?
 */
function storeLanguagesControlPresent(string $html, string $dataTest): bool
{
    return str_contains($html, 'data-test="'.$dataTest.'"');
}

/**
 * Is the control wrapped in an explicit <flux:tooltip> (compiled to <ui-tooltip>) -- the Blaze
 * trap docs/errors-log.md records, which a conditionally-bound :tooltip prop silently falls into.
 */
function storeLanguagesControlWrappedInTooltip(string $html, string $dataTest): bool
{
    $quoted = preg_quote($dataTest, '/');

    return (bool) preg_match(
        '/<ui-tooltip[^>]*>\s*<[a-z0-9-]+[^>]*\bdata-test="'.$quoted.'"/is',
        $html
    );
}

/**
 * The copy of the tooltip wrapping the control carrying `$dataTest`, isolated to that one
 * <ui-tooltip> block so a sibling tooltip's identical copy cannot satisfy the assertion.
 */
function storeLanguagesControlTooltipContent(string $html, string $dataTest): ?string
{
    $quoted = preg_quote($dataTest, '/');

    if (! preg_match(
        '/<ui-tooltip[^>]*>((?:(?!<\/ui-tooltip>).)*?data-test="'.$quoted.'"(?:(?!<\/ui-tooltip>).)*?)<\/ui-tooltip>/is',
        $html,
        $block
    )) {
        return null;
    }

    if (! preg_match('/data-flux-tooltip-content[^>]*>\s*([^<]*)/is', $block[1], $content)) {
        return null;
    }

    return trim(html_entity_decode($content[1], ENT_QUOTES));
}

/**
 * The rendered text of one row's name cell (`language-name-{id}`) -- the only safe place to read a
 * language name on this page.
 */
function storeLanguagesRowName(string $html, string $languageId): string
{
    $quoted = preg_quote($languageId, '/');

    // The cell may nest the name and the code in separate inline elements, so read from the hook
    // up to the end of that cell's own block, bounded so it can never reach the next row.
    if (! preg_match('/data-test="language-name-'.$quoted.'"[^>]*>((?:(?!data-test="language-row-).){0,300})/s', $html, $matches)) {
        return '';
    }

    $text = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES);

    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/**
 * The slice of the page belonging to ONE section wrapper: from its hook to the other section's
 * hook (or the end of the page), whichever order the two sit in.
 */
function storeLanguagesSectionHtml(string $html, string $hook): string
{
    $start = strpos($html, 'data-test="'.$hook.'"');

    expect($start)->not->toBeFalse("Expected the section wrapper [{$hook}] on the page.");

    $other = $hook === 'store-languages-section' ? 'locale-settings-section' : 'store-languages-section';
    $otherStart = strpos($html, 'data-test="'.$other.'"');

    if ($otherStart === false || $otherStart < $start) {
        return substr($html, $start);
    }

    return substr($html, $start, $otherStart - $start);
}

// =====================================================================
// The fresh installation
// =====================================================================

test('a fresh installation lists Spanish as the only content language, marked as the store default', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->html();
    $catalog = storeLanguagesSectionHtml($html, 'store-languages-section');

    expect(preg_match_all('/data-test="language-row-/', $catalog))->toBe(1)
        ->and($catalog)->toContain('data-test="language-row-'.$spanish->id.'"')
        ->and(storeLanguagesRowName($catalog, $spanish->id))->toContain('Español')
        ->and($catalog)->toContain('data-test="default-badge-language-'.$spanish->id.'"');
});

test('the single default Spanish row renders both row controls disabled and the Remove tooltip names exactly one reason, the more specific one', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->html();

    expect(storeLanguagesControlDisabled($html, 'set-default-language-'.$spanish->id))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$spanish->id))->toBeTrue()
        ->and(storeLanguagesControlWrappedInTooltip($html, 'remove-language-'.$spanish->id))->toBeTrue()
        ->and(storeLanguagesControlTooltipContent($html, 'remove-language-'.$spanish->id))
        ->toBe(__('store-languages.index.remove_last_language_tooltip'))
        ->not->toBe(__('store-languages.index.remove_default_tooltip'))
        ->not->toBe(__('store-languages.index.action_not_allowed'));
});

// =====================================================================
// Row structure and the two kinds of "no"
// =====================================================================

test('every active language is a row with its endonym and code, the default carries the marker, and a removed language is not rendered at all', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $removed = StoreLanguage::factory()->inactive()->create(['code' => 'de', 'name' => 'Deutsch']);
    $this->actingAs(storeLanguagesCatalogActor());

    $catalog = storeLanguagesSectionHtml(Livewire::test(Index::class)->html(), 'store-languages-section');

    expect(storeLanguagesRowName($catalog, $french->id))->toContain('Français')->toMatch('/\bfr\b/')
        ->and(storeLanguagesRowName($catalog, $spanish->id))->toContain('Español')->toMatch('/\bes\b/')
        ->and($catalog)->toContain('data-test="default-badge-language-'.$spanish->id.'"')
        ->and($catalog)->not->toContain('data-test="default-badge-language-'.$french->id.'"')
        ->and($catalog)->not->toContain('data-test="language-row-'.$removed->id.'"');
});

test('a domain-state disablement carries its own specific tooltip, different from the permission refusal and from the other domain state', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->html();

    // Default row, other languages active: "Set default" is "already the default", and Remove
    // asks for another default first.
    expect(storeLanguagesControlDisabled($html, 'set-default-language-'.$spanish->id))->toBeTrue()
        ->and(storeLanguagesControlTooltipContent($html, 'set-default-language-'.$spanish->id))
        ->toBe(__('store-languages.index.already_default_tooltip'))
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$spanish->id))->toBeTrue()
        ->and(storeLanguagesControlTooltipContent($html, 'remove-language-'.$spanish->id))
        ->toBe(__('store-languages.index.remove_default_tooltip'))
        // The ordinary row is fully enabled.
        ->and(storeLanguagesControlDisabled($html, 'set-default-language-'.$french->id))->toBeFalse()
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$french->id))->toBeFalse()
        ->and(storeLanguagesControlPresent($html, 'set-default-language-'.$french->id))->toBeTrue()
        ->and(storeLanguagesControlPresent($html, 'remove-language-'.$french->id))->toBeTrue();

    expect(__('store-languages.index.already_default_tooltip'))
        ->not->toBe(__('store-languages.index.action_not_allowed'))
        ->not->toBe(__('store-languages.index.remove_default_tooltip'))
        ->not->toBe(__('store-languages.index.remove_last_language_tooltip'));
});

test('an actor holding only store-languages.view sees the catalog read-only: every control disabled with the generic refusal, hooks present on the disabled branch', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesCatalogActor(['store-languages.view']));

    $html = Livewire::test(Index::class)->html();

    expect(storeLanguagesRowName($html, $french->id))->toContain('Français')
        ->and(storeLanguagesControlDisabled($html, 'add-language-button'))->toBeTrue()
        ->and(storeLanguagesControlWrappedInTooltip($html, 'add-language-button'))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'set-default-language-'.$french->id))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$french->id))->toBeTrue()
        ->and(storeLanguagesControlWrappedInTooltip($html, 'set-default-language-'.$french->id))->toBeTrue()
        ->and(storeLanguagesControlWrappedInTooltip($html, 'remove-language-'.$french->id))->toBeTrue()
        ->and(storeLanguagesControlTooltipContent($html, 'set-default-language-'.$french->id))
        ->toBe(__('store-languages.index.action_not_allowed'))
        ->and(storeLanguagesControlTooltipContent($html, 'remove-language-'.$french->id))
        ->toBe(__('store-languages.index.action_not_allowed'))
        ->and(storeLanguagesControlDisabled($html, 'set-default-language-'.$spanish->id))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$spanish->id))->toBeTrue();
});

test('an actor holding view and edit but neither create nor delete can promote a language but not add or remove one', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesCatalogActor(['store-languages.view', 'store-languages.edit']));

    $html = Livewire::test(Index::class)->html();

    expect(storeLanguagesControlDisabled($html, 'add-language-button'))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'set-default-language-'.$french->id))->toBeFalse()
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$french->id))->toBeTrue();
});

test('a Super Admin holding zero store-languages permission rows sees every control enabled', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $html = Livewire::test(Index::class)->html();

    expect(storeLanguagesControlPresent($html, 'add-language-button'))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'add-language-button'))->toBeFalse()
        ->and(storeLanguagesControlDisabled($html, 'set-default-language-'.$french->id))->toBeFalse()
        ->and(storeLanguagesControlDisabled($html, 'remove-language-'.$french->id))->toBeFalse();
});

// =====================================================================
// The picker
// =====================================================================

test('the picker is searched client-side: the search field is bound to no Livewire property, and every option is an act-now button', function () {
    $this->seed(StoreLanguageSeeder::class);
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->call('openAddLanguageModal')->html();

    preg_match('/<[a-z0-9-]+[^>]*data-test="language-picker-search"[^>]*>/is', $html, $search);
    preg_match('/<[a-z0-9-]+[^>]*data-test="language-option-fr"[^>]*>/is', $html, $option);

    expect($search)->not->toBeEmpty()
        ->and($search[0])->not->toContain('wire:model')
        ->and($option)->not->toBeEmpty()
        ->and($option[0])->toContain('wire:click')
        ->and($option[0])->toContain('addLanguage');
});

// =====================================================================
// The removal confirmation: three states, the more specific reason wins (D-10)
// =====================================================================

test('removing a language that is neither the default nor the last active one is a plain confirmation with no replacement select', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->call('confirmRemoveLanguage', $french->id)->html();

    expect(storeLanguagesControlPresent($html, 'remove-modal-replacement-select'))->toBeFalse()
        ->and(storeLanguagesControlPresent($html, 'confirm-remove-language'))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'confirm-remove-language'))->toBeFalse();
});

test('removing the default while others are active asks for a replacement and keeps Confirm disabled until one is chosen', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesCatalogActor());

    $component = Livewire::test(Index::class)->call('confirmRemoveLanguage', $spanish->id);
    $before = $component->html();

    expect(storeLanguagesControlPresent($before, 'remove-modal-replacement-select'))->toBeTrue()
        ->and(storeLanguagesControlDisabled($before, 'confirm-remove-language'))->toBeTrue();

    preg_match('/<select[^>]*data-test="remove-modal-replacement-select"[^>]*>(.*?)<\/select>/is', $before, $select);
    preg_match_all('/<option[^>]*value="([^"]*)"/i', $select[1] ?? '', $values);

    // The placeholder ('') plus exactly the other active languages -- never the target itself.
    expect($values[1])->toEqualCanonicalizing(['', $french->id]);

    $after = $component->set('replacementLanguageId', $french->id)->html();

    expect(storeLanguagesControlDisabled($after, 'confirm-remove-language'))->toBeFalse();
});

test('removing the default that is also the only active language renders no replacement select at all and a disabled Confirm', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->call('confirmRemoveLanguage', $spanish->id)->html();

    expect(storeLanguagesControlPresent($html, 'remove-modal-replacement-select'))->toBeFalse()
        ->and(storeLanguagesControlPresent($html, 'confirm-remove-language'))->toBeTrue()
        ->and(storeLanguagesControlDisabled($html, 'confirm-remove-language'))->toBeTrue();
});

test('the removal confirmation never states a zero-usage safety claim when no translation references the language', function () {
    $this->seed(StoreLanguageSeeder::class);
    $french = StoreLanguage::factory()->create(['code' => 'fr', 'name' => 'Français']);
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->call('confirmRemoveLanguage', $french->id)->html();

    expect(storeLanguagesControlPresent($html, 'remove-modal-usage-line'))->toBeFalse();
});

// =====================================================================
// Section structure and lang
// =====================================================================

test('the catalog rows live inside the content languages section and nowhere in the dashboard defaults section', function () {
    $this->seed(StoreLanguageSeeder::class);
    $spanish = StoreLanguage::query()->where('code', 'es')->firstOrFail();
    $this->actingAs(storeLanguagesCatalogActor());

    $html = Livewire::test(Index::class)->html();

    expect(storeLanguagesSectionHtml($html, 'store-languages-section'))->toContain('data-test="language-row-'.$spanish->id.'"')
        ->and(storeLanguagesSectionHtml($html, 'locale-settings-section'))->not->toContain('language-row-')
        ->and(storeLanguagesSectionHtml($html, 'locale-settings-section'))->not->toContain('add-language-button');
});

test('every store-languages.index copy key the screen relies on exists in both locales', function (string $key) {
    foreach (['en', 'es'] as $locale) {
        expect(trans($key, [], $locale))->not->toBe($key, "[{$key}] is missing from lang/{$locale}/store-languages.php");
    }
})->with([
    'store-languages.index.heading',
    'store-languages.index.already_default_tooltip',
    'store-languages.index.remove_default_tooltip',
    'store-languages.index.remove_last_language_tooltip',
    'store-languages.index.action_not_allowed',
]);

test('lang/en/store-languages.php and lang/es/store-languages.php are key-for-key identical', function () {
    $english = array_keys(Arr::dot(require lang_path('en/store-languages.php')));
    $spanish = array_keys(Arr::dot(require lang_path('es/store-languages.php')));

    expect($spanish)->toEqualCanonicalizing($english);
});
