<?php

// DOM-level rendering tests for the language tabs in resources/views/livewire/blog-categories.blade.php
// (consuming resources/views/components/language-tab-strip.blade.php), per
// ai-spec/tasks/in-progress/0073-blog-categories-language-tabs-ui.md's "Feature --
// BlogCategoryLanguageTabsRenderingTest.php" section and amendments A-10, A-12, A-14, A-16.
// Written at TDD Phase 3 step 1 (red), before the tabbed markup exists.
//
// D-11: no assertion matches a language name or a two-letter code -- every tab, panel, input, hint
// and error marker is reached through its data-test hook, keyed by the language's id. Component
// state and persistence are BlogCategoryLanguageTabsTest.php's; nothing here duplicates that.
//
// Hook names (A-16): the tab/panel/untranslated/error hooks keep 0071's `language-*` names (they
// belong to the shared strip contract); the input is `blog-category-name-input-{id}`, keeping
// 0062's established prefix.

use App\Livewire\BlogCategories\Index;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\BlogPost;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // The default sits alphabetically BETWEEN the other two, so a by-name-only order would put it
    // in the middle: the strip order is default first, then the rest by name.
    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogLanguageTabsRenderingActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/**
 * @param  array<string, string>  $namesByLanguageId
 */
function blogLanguageTabsRenderingCategory(array $namesByLanguageId): BlogCategory
{
    $category = BlogCategory::factory()->withoutTranslations()->create();

    foreach ($namesByLanguageId as $languageId => $name) {
        BlogCategoryTranslation::factory()->create([
            'blog_category_id' => $category->id,
            'store_language_id' => $languageId,
            'name' => $name,
        ]);
    }

    return $category;
}

/**
 * The opening tag of the element carrying the given data-test hook, or null when absent.
 */
function blogLanguageTabsRenderingTag(string $html, string $hook, string $element = '[a-z-]+'): ?string
{
    return preg_match('/<'.$element.'\b[^>]*data-test="'.preg_quote($hook, '/').'"[^>]*>/', $html, $matches) === 1
        ? $matches[0]
        : null;
}

/**
 * The visible text inside the element carrying the given data-test hook, or null when absent.
 */
function blogLanguageTabsRenderingText(string $html, string $hook): ?string
{
    if (preg_match('/data-test="'.preg_quote($hook, '/').'"[^>]*>(.*?)<\/(?:div|p|span|li)>/s', $html, $matches) !== 1) {
        return null;
    }

    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($matches[1]))) ?? '');
}

test('one tab control renders per active language, the default first and the rest by name', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    preg_match_all('/data-test="language-tab-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $html, $matches);

    // Asserted by id, never by name: inactive language absent, default first, then by name.
    expect($matches[1])->toBe([$this->spanish->id, $this->french->id, $this->german->id]);
});

test('one panel renders per active language, each holding one plain text input, hidden by x-show rather than omitted', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $panel = blogLanguageTabsRenderingTag($html, 'language-panel-'.$language->id);
        $input = blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$language->id, 'input');

        expect($panel)->not->toBeNull()
            ->and($panel)->toContain('x-show')
            ->and($input)->not->toBeNull();
    }

    expect($html)->not->toContain('language-panel-'.$this->retired->id)
        ->and(substr_count($html, 'data-test="language-panel-'))->toBe(3)
        ->and(substr_count($html, '<input'))->toBe(3)
        ->and($html)->not->toContain('<select');
});

test('an inactive panel is present in the DOM and merely hidden, bound to the server-side active language (A-10)', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->call('setActiveLanguageTab', $this->spanish->id)
        ->html();

    // The French tab is not active, yet its panel and its input are both rendered (an @if panel
    // would drop them and discard typed text on every tab switch).
    $frenchPanel = blogLanguageTabsRenderingTag($html, 'language-panel-'.$this->french->id);

    expect($frenchPanel)->not->toBeNull()
        ->and($frenchPanel)->toContain('x-show')
        ->and($frenchPanel)->toContain('$wire.activeLanguageId')
        ->and($frenchPanel)->toContain('x-cloak')
        ->and(blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$this->french->id, 'input'))->not->toBeNull();
});

test('an untranslated tab renders its not-yet-translated hint while a translated one and the default do not', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());
    $category = blogLanguageTabsRenderingCategory([
        $this->spanish->id => 'Guias',
        $this->german->id => 'Ratgeber',
    ]);

    $html = Livewire::test(Index::class)->call('openEditModal', $category->id)->html();

    expect($html)->toContain('data-test="language-untranslated-'.$this->french->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$this->german->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$this->spanish->id.'"');
});

test('an untranslated tab renders an empty input beside its hint and never the fallback name', function () {
    // The DOM counterpart of the no-leak test: the property can be '' while the Blade still
    // interpolates a stray translated('name') left over from 0062's single-field markup.
    $this->actingAs(blogLanguageTabsRenderingActor());
    $category = blogLanguageTabsRenderingCategory([$this->spanish->id => 'Guias']);

    $html = Livewire::test(Index::class)->call('openEditModal', $category->id)->html();

    $frenchInput = blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$this->french->id, 'input');
    $spanishInput = blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$this->spanish->id, 'input');

    expect($frenchInput)->not->toBeNull()
        ->and($frenchInput)->not->toContain('Guias')
        ->and($html)->toContain('data-test="language-untranslated-'.$this->french->id.'"')
        ->and($spanishInput)->not->toBeNull();
});

test('the create modal renders no untranslated hint on any tab', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect($html)->not->toContain('data-test="language-untranslated-');
});

test('a tab carrying an error renders its marker on the tab header and only on that header', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());
    $category = blogLanguageTabsRenderingCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

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

test('a required-field refusal renders the localized attribute beside its field and never the internal key', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, '')
        ->call('save')
        ->html();

    $message = blogLanguageTabsRenderingText($html, 'language-name-error-'.$this->spanish->id);

    // Scoped to the error element: the surrounding markup legitimately contains wire:model="names.{id}".
    expect($message)->not->toBeNull()
        ->and($message)->toContain(__('validation.required', ['attribute' => __('blog.categories.index.tabs.name_attribute')]))
        ->and($message)->not->toContain('names.');
});

test('a per-language duplicate refusal renders the translated message with no internal key', function (string $locale) {
    app()->setLocale($locale);

    $this->actingAs(blogLanguageTabsRenderingActor());
    blogLanguageTabsRenderingCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $target = blogLanguageTabsRenderingCategory([$this->spanish->id => 'Novedades']);

    $html = Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->html();

    $message = blogLanguageTabsRenderingText($html, 'language-name-error-'.$this->french->id);
    $expected = __('validation.unique', ['attribute' => __('blog.categories.index.tabs.name_attribute')]);

    expect($message)->toBe($expected)
        ->and($message)->not->toContain('names.')
        ->and($message)->not->toContain($this->french->id);
})->with(['english' => ['en'], 'spanish' => ['es']]);

test('each tab header carries a wire:click bound to its own literal language id', function () {
    // A tab whose wire:click silently stringifies is a no-op that Livewire::test()->call() can
    // never detect, so the rendered attribute is read directly.
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $tab = blogLanguageTabsRenderingTag($html, 'language-tab-'.$language->id);

        expect($tab)->not->toBeNull()
            ->and($tab)->toMatch('/wire:click="[^"]*setActiveLanguageTab[^"]*'.preg_quote($language->id, '/').'[^"]*"/')
            ->and($tab)->not->toContain('[object')
            ->and($tab)->not->toContain('undefined');
    }
});

test('for a create-only administrator every non-default name input is disabled with the requires-edit hint and the default is not', function () {
    // B-1: blog.view + blog.create, no blog.edit.
    $this->actingAs(blogLanguageTabsRenderingActor(['blog.view', 'blog.create']));

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    $default = blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$this->spanish->id, 'input');

    expect($default)->not->toBeNull()
        ->and($default)->not->toMatch('/\sdisabled(\s|=|>|\/)/');

    foreach ([$this->french, $this->german] as $other) {
        $input = blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$other->id, 'input');

        expect($input)->not->toBeNull()
            ->and($input)->toMatch('/\sdisabled(\s|=|>|\/)/');
    }

    expect(substr_count($html, e(__('blog.categories.index.tabs.translation_requires_edit'))))->toBe(2);
});

test('for an administrator holding blog.edit no name input is disabled and no requires-edit hint renders', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $input = blogLanguageTabsRenderingTag($html, 'blog-category-name-input-'.$language->id, 'input');

        expect($input)->not->toBeNull()
            ->and($input)->not->toMatch('/\sdisabled(\s|=|>|\/)/');
    }

    expect($html)->not->toContain(e(__('blog.categories.index.tabs.translation_requires_edit')));
});

test('the list name cell renders the resolved default-language name, and an em dash when no name resolves (A-14)', function () {
    $this->actingAs(blogLanguageTabsRenderingActor());
    $named = blogLanguageTabsRenderingCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $bare = BlogCategory::factory()->withoutTranslations()->create();

    $component = Livewire::test(Index::class);
    $html = $component->html();
    $rows = collect($component->get('categories'))->keyBy('id');

    // The list has no tabs: it shows the store default's name, not the French one.
    expect($rows[$named->id]['name'])->toBe('Guias')
        ->and($rows[$bare->id]['name'])->toBe('—')
        ->and($html)->toContain('Guias')
        ->and($html)->toContain('—');
});

test('the blocked-delete refusal still renders unchanged now that this file\'s view was rewritten', function () {
    $category = BlogCategory::factory()->named('Guias')->create();
    BlogPost::factory()->count(2)->create(['blog_category_id' => $category->id]);
    $this->actingAs(blogLanguageTabsRenderingActor());

    $html = Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->html();

    expect($html)->toContain('data-test="blog-category-delete-blocked"')
        ->and($html)->toContain(e('This category is used by 2 posts — reassign them before deleting.'));
});
