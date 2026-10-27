<?php

// DOM-level rendering tests for the language tabs in resources/views/livewire/blog-tags.blade.php
// (consuming resources/views/components/language-tab-strip.blade.php), story 0075.
//
// D-10 / 0071 D-11: no assertion matches a language name or a two-letter code -- every tab, panel,
// input, hint and error marker is reached through its data-test hook, keyed by the language's id.
// Component state and persistence are BlogTagLanguageTabsTest.php's; nothing here duplicates that.
//
// Hooks: the tab/panel/untranslated/error hooks keep the shared strip's generic `language-*` names;
// the input is `blog-tag-name-input-{id}` on the edit form, while the create form keeps its bare
// `blog-tag-name-input`.

use App\Livewire\BlogTags\Index;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
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

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($this->actor);
});

/**
 * The opening tag of the element carrying the given data-test hook, or null when absent.
 */
function blogTagTabsRenderingTag(string $html, string $hook, string $element = '[a-z-]+'): ?string
{
    return preg_match('/<'.$element.'\b[^>]*data-test="'.preg_quote($hook, '/').'"[^>]*>/', $html, $matches) === 1
        ? $matches[0]
        : null;
}

test('the create form offers no language tabs and a single name field', function () {
    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect($html)->not->toContain('data-test="language-tab-strip"')
        ->and($html)->not->toContain('data-test="language-panel-')
        ->and(substr_count($html, '<input'))->toBe(1)
        ->and(blogTagTabsRenderingTag($html, 'blog-tag-name-input', 'input'))->not->toBeNull();
});

test('one tab control renders per active language, the default first and the rest by name', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Index::class)->call('openEditModal', $tag->id)->html();

    preg_match_all('/data-test="language-tab-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $html, $matches);

    expect($matches[1])->toBe([$this->spanish->id, $this->french->id, $this->german->id]);
});

test('an inactive language has neither a tab nor an input', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->retired)->create(['blog_tag_id' => $tag->id, 'name' => 'Laufen']);

    $html = Livewire::test(Index::class)->call('openEditModal', $tag->id)->html();

    expect($html)->not->toContain($this->retired->id)
        ->and(substr_count($html, 'data-test="language-panel-'))->toBe(3)
        ->and(substr_count($html, '<input'))->toBe(3)
        ->and($html)->not->toContain('<select');
});

test('every per-language input is present in the DOM whichever tab is active, hidden by x-show not omitted', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Index::class)->call('openEditModal', $tag->id)->html();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        expect(blogTagTabsRenderingTag($html, 'language-panel-'.$language->id))->toContain('x-show')
            ->and(blogTagTabsRenderingTag($html, 'blog-tag-name-input-'.$language->id, 'input'))->not->toBeNull();
    }
});

test('the active tab is marked structurally with aria-selected', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $component = Livewire::test(Index::class)->call('openEditModal', $tag->id);
    $html = $component->html();

    expect(blogTagTabsRenderingTag($html, 'language-tab-'.$this->spanish->id))->toContain('aria-selected="true"')
        ->and(blogTagTabsRenderingTag($html, 'language-tab-'.$this->french->id))->toContain('aria-selected="false"');

    $html = $component->call('setActiveLanguageTab', $this->french->id)->html();

    expect(blogTagTabsRenderingTag($html, 'language-tab-'.$this->french->id))->toContain('aria-selected="true"')
        ->and(blogTagTabsRenderingTag($html, 'language-tab-'.$this->spanish->id))->toContain('aria-selected="false"');
});

test('an untranslated tab renders an empty field with the default name as a placeholder, never as its value', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Index::class)->call('openEditModal', $tag->id)->html();

    $french = blogTagTabsRenderingTag($html, 'blog-tag-name-input-'.$this->french->id, 'input');
    $spanish = blogTagTabsRenderingTag($html, 'blog-tag-name-input-'.$this->spanish->id, 'input');

    expect($french)->toContain('placeholder="'.__('blog-tags.index.tabs.default_guidance', ['name' => 'running']).'"')
        ->and($french)->not->toContain('value="running"')
        ->and($spanish)->not->toContain('placeholder=')
        ->and($html)->toContain('data-test="language-untranslated-'.$this->french->id.'"')
        ->and($html)->not->toContain('data-test="language-untranslated-'.$this->spanish->id.'"');
});

test('a translated tab renders its stored name as the value and carries no untranslated hint', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tag->id, 'name' => 'course à pied']);

    $html = Livewire::test(Index::class)->call('openEditModal', $tag->id)->html();

    expect($html)->not->toContain('data-test="language-untranslated-'.$this->french->id.'"');
    expect(Livewire::test(Index::class)->call('openEditModal', $tag->id)->get('names.'.$this->french->id))->toBe('course à pied');
});

test('a validation error renders against the failing language\'s own field and marks its tab', function () {
    $other = BlogTag::factory()->named('autre')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $other->id, 'name' => 'trail']);
    $tag = BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->french->id, 'trail')
        ->call('save')
        ->html();

    expect($html)->toContain('data-test="language-name-error-'.$this->french->id.'"')
        ->and($html)->toContain('data-test="language-tab-error-'.$this->french->id.'"')
        ->and($html)->not->toContain('data-test="language-name-error-'.$this->spanish->id.'"')
        ->and(blogTagTabsRenderingTag($html, 'language-tab-'.$this->french->id))->toContain('aria-selected="true"');
});

test('the list row hooks and the delete modal are unchanged by the tabs', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('data-test="edit-blog-tag-'.$tag->id.'"')
        ->and($html)->toContain('data-test="delete-blog-tag-'.$tag->id.'"');

    $deleteHtml = Livewire::test(Index::class)->call('confirmDelete', $tag->id)->html();

    expect($deleteHtml)->toContain('data-test="confirm-delete-blog-tag"')
        ->and($deleteHtml)->not->toContain('data-test="language-tab-strip"');
});
