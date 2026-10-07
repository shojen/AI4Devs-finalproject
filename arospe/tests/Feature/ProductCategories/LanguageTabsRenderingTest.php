<?php

// DOM-level rendering tests for the language tabs in
// resources/views/livewire/product-categories.blade.php and the extracted
// resources/views/components/language-tab-strip.blade.php, per
// ai-spec/tasks/done/0071-product-categories-language-tabs-ui.md's "Feature --
// LanguageTabsRenderingTest.php" section. Written at TDD Phase 3 step 1 (red), before the tabbed
// markup exists.
//
// D-11: no assertion matches a language name or a two-letter code -- every tab, panel, input,
// hint and error marker is reached through its data-test hook, keyed by the language's id.
// Component state and persistence are LanguageTabsTest.php's; nothing here duplicates that.

use App\Livewire\ProductCategories\Index;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // The default sits alphabetically BETWEEN the other two, so a by-name-only order would put it
    // in the middle: D-14 requires default first, then the rest by name.
    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);
});

/**
 * @param  array<int, string>  $permissions
 */
function languageTabsRenderingActor(array $permissions = ['products.view', 'products.create', 'products.edit', 'products.delete']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/**
 * @param  array<string, string>  $namesByLanguageId
 */
function languageTabsRenderingCategory(array $namesByLanguageId): ProductCategory
{
    $category = ProductCategory::factory()->withoutTranslations()->create();

    foreach ($namesByLanguageId as $languageId => $name) {
        ProductCategoryTranslation::factory()->create([
            'product_category_id' => $category->id,
            'store_language_id' => $languageId,
            'name' => $name,
        ]);
    }

    return $category;
}

/**
 * The opening tag of the element carrying the given data-test hook, or null when absent.
 */
function languageTabsRenderingTag(string $html, string $hook, string $element = '[a-z-]+'): ?string
{
    return preg_match('/<'.$element.'\b[^>]*data-test="'.preg_quote($hook, '/').'"[^>]*>/', $html, $matches) === 1
        ? $matches[0]
        : null;
}

/**
 * The visible text inside the element carrying the given data-test hook, or null when absent.
 */
function languageTabsRenderingText(string $html, string $hook): ?string
{
    if (preg_match('/data-test="'.preg_quote($hook, '/').'"[^>]*>(.*?)<\/(?:div|p|span|li)>/s', $html, $matches) !== 1) {
        return null;
    }

    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($matches[1]))) ?? '');
}

test('one tab control renders per active language, the default first and the rest by name', function () {
    $this->actingAs(languageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    preg_match_all('/data-test="language-tab-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $html, $matches);

    // Asserted by id, never by name (D-11): inactive language absent, default first, then by name.
    expect($matches[1])->toBe([$this->spanish->id, $this->french->id, $this->german->id]);
});

test('one panel renders per active language, each holding one name input, hidden by x-show rather than omitted', function () {
    $this->actingAs(languageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $panel = languageTabsRenderingTag($html, 'language-panel-'.$language->id);
        $input = languageTabsRenderingTag($html, 'language-name-input-'.$language->id, 'input');

        expect($panel)->not->toBeNull()
            ->and($panel)->toContain('x-show')
            ->and($input)->not->toBeNull();
    }

    expect($html)->not->toContain('language-panel-'.$this->retired->id)
        ->and(substr_count($html, 'data-test="language-panel-'))->toBe(3);
});

test('an untranslated tab renders its not-yet-translated hint while a translated one and the default do not', function () {
    $this->actingAs(languageTabsRenderingActor());
    $category = languageTabsRenderingCategory([
        $this->spanish->id => 'Calzado',
        $this->german->id => 'Schuhe',
    ]);

    $html = Livewire::test(Index::class)->call('openEditModal', $category->id)->html();

    expect($html)->toContain('data-test="language-untranslated-'.$this->french->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$this->german->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$this->spanish->id.'"');
});

test('a tab carrying an error renders its marker on the tab header and only on that header', function () {
    $this->actingAs(languageTabsRenderingActor());
    $category = languageTabsRenderingCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);

    $html = Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, '')
        ->call('save')
        ->call('setActiveLanguageTab', $this->spanish->id)
        ->html();

    // Still marked after navigating away, and not marked on the languages that carry no error.
    expect($html)->toContain('data-test="language-tab-error-'.$this->french->id.'"')
        ->and($html)->not->toContain('data-test="language-tab-error-'.$this->spanish->id.'"')
        ->and($html)->not->toContain('data-test="language-tab-error-'.$this->german->id.'"');
});

test('a required-field refusal renders the localized attribute and never the internal key', function () {
    $this->actingAs(languageTabsRenderingActor());

    $html = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, '')
        ->call('save')
        ->html();

    $message = languageTabsRenderingText($html, 'language-name-error-'.$this->spanish->id);

    // Scoped to the error element: the surrounding markup legitimately contains wire:model="names.{id}".
    expect($message)->not->toBeNull()
        ->and($message)->toContain(__('validation.required', ['attribute' => __('products.categories.index.tabs.name_attribute')]))
        ->and($message)->not->toContain('names.');
});

test('a per-language duplicate refusal renders the translated message with no internal key', function (string $locale) {
    app()->setLocale($locale);

    $this->actingAs(languageTabsRenderingActor());
    languageTabsRenderingCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);
    $target = languageTabsRenderingCategory([$this->spanish->id => 'Botas']);

    $html = Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->html();

    $message = languageTabsRenderingText($html, 'language-name-error-'.$this->french->id);
    $expected = __('validation.unique', ['attribute' => __('products.categories.index.tabs.name_attribute')]);

    expect($message)->toBe($expected)
        ->and($message)->not->toContain('names.')
        ->and($message)->not->toContain($this->french->id);
})->with(['english' => ['en'], 'spanish' => ['es']]);

test('each tab header carries a wire:click bound to its own literal language id', function () {
    // D-8's compiled-output check: a tab whose wire:click silently stringifies is a no-op that
    // Livewire::test()->call() can never detect, so the rendered attribute is read directly.
    $this->actingAs(languageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $tab = languageTabsRenderingTag($html, 'language-tab-'.$language->id);

        expect($tab)->not->toBeNull()
            ->and($tab)->toMatch('/wire:click="[^"]*setActiveLanguageTab[^"]*'.preg_quote($language->id, '/').'[^"]*"/')
            ->and($tab)->not->toContain('[object')
            ->and($tab)->not->toContain('undefined');
    }
});

test('for a create-only administrator every non-default name input is disabled with the requires-edit hint and the default is not', function () {
    // B-1: products.view + products.create, no products.edit. The component's own guard is the
    // enforcement; this is the UI hint that keeps a correct create from looking like it will fail.
    $this->actingAs(languageTabsRenderingActor(['products.view', 'products.create']));

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    $default = languageTabsRenderingTag($html, 'language-name-input-'.$this->spanish->id, 'input');

    expect($default)->not->toBeNull()
        ->and($default)->not->toMatch('/\sdisabled(\s|=|>|\/)/');

    foreach ([$this->french, $this->german] as $other) {
        $input = languageTabsRenderingTag($html, 'language-name-input-'.$other->id, 'input');

        expect($input)->not->toBeNull()
            ->and($input)->toMatch('/\sdisabled(\s|=|>|\/)/');
    }

    $hint = e(__('products.categories.index.tabs.translation_requires_edit'));

    expect(substr_count($html, $hint))->toBe(2);
});

test('for an administrator holding products.edit no name input is disabled and no requires-edit hint renders', function () {
    $this->actingAs(languageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $input = languageTabsRenderingTag($html, 'language-name-input-'.$language->id, 'input');

        expect($input)->not->toBeNull()
            ->and($input)->not->toMatch('/\sdisabled(\s|=|>|\/)/');
    }

    expect($html)->not->toContain(e(__('products.categories.index.tabs.translation_requires_edit')));
});
