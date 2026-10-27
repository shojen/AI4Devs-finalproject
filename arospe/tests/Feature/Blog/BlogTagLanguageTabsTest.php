<?php

// Story 0075 -- the per-store-language names on App\Livewire\BlogTags\Index (language tabs on the
// edit form). Component behaviour only: the rendered markup lives in
// BlogTagLanguageTabsRenderingTest.php, the action's own contract in SetBlogTagTranslationTest.php,
// and the real-browser round trips in tests/Browser/BlogTags/LanguageTabsTest.php.
//
// Tabs are edit-only (D-4); the create form keeps its single `name` field.

use App\Livewire\BlogTags\Index;
use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
    $this->english = StoreLanguage::factory()->create();

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($this->actor);
});

function blogTagTabsStoredName(BlogTag $tag, StoreLanguage $language): ?string
{
    return BlogTagTranslation::query()
        ->where('blog_tag_id', $tag->id)
        ->where('store_language_id', $language->id)
        ->value('name');
}

test('openEditModal() populates $names from the raw translation rows, never from the translated() fallback', function () {
    $tag = BlogTag::factory()->named('running')->create();

    $names = Livewire::test(Index::class)->call('openEditModal', $tag->id)->get('names');

    expect($names[$this->french->id])->toBe('')
        ->and($names[$this->spanish->id])->toBe('running');
});

test('$names holds a string key for every active language and none for an inactive one', function () {
    $german = StoreLanguage::factory()->inactive()->create();
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($german)->create(['blog_tag_id' => $tag->id, 'name' => 'Laufen']);

    $names = Livewire::test(Index::class)->call('openEditModal', $tag->id)->get('names');

    expect(array_keys($names))->toEqualCanonicalizing([$this->spanish->id, $this->french->id, $this->english->id])
        ->and(array_filter($names, fn (mixed $value): bool => ! is_string($value)))->toBe([]);
});

test('the default language tab is active first and a tab switch only accepts an active language', function () {
    $german = StoreLanguage::factory()->inactive()->create();
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->call('setActiveLanguageTab', $this->french->id)
        ->assertSet('activeLanguageId', $this->french->id);

    $this->withoutExceptionHandling();
    $component = Livewire::test(Index::class)->call('openEditModal', $tag->id);

    expect(fn () => $component->call('setActiveLanguageTab', $german->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('setActiveLanguageTab', 'not-a-language'))->toThrow(ModelNotFoundException::class);
    $component->assertSet('activeLanguageId', $this->spanish->id);
});

test('the tab state a client could forge is #[Locked]', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->named('running')->create();

    $component = Livewire::test(Index::class)->call('openEditModal', $tag->id);

    expect(fn () => $component->set('activeLanguageId', $this->french->id))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $component->set('originalTranslatedLanguageIds', []))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('saving a French name stores it next to the default one, and several languages are written in one save', function () {
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->french->id, 'course à pied')
        ->set('names.'.$this->english->id, 'jogging')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(blogTagTabsStoredName($tag, $this->french))->toBe('course à pied')
        ->and(blogTagTabsStoredName($tag, $this->english))->toBe('jogging')
        ->and(blogTagTabsStoredName($tag, $this->spanish))->toBe('running');
});

test('re-translating replaces the existing row for that language and never adds a second one', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tag->id, 'name' => 'course à pied']);

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->french->id, 'trail')
        ->call('save')
        ->assertHasNoErrors();

    expect(blogTagTabsStoredName($tag, $this->french))->toBe('trail')
        ->and(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->where('store_language_id', $this->french->id)->count())->toBe(1);
});

test('saving with an untranslated tab left empty creates no translation row for it', function () {
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->spanish->id, 'jogging')
        ->call('save')
        ->assertHasNoErrors();

    expect(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->count())->toBe(1)
        ->and(blogTagTabsStoredName($tag, $this->french))->toBeNull()
        ->and(blogTagTabsStoredName($tag, $this->spanish))->toBe('jogging');
});

test('an untouched save copies the default language\'s name into no other language', function () {
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)->call('openEditModal', $tag->id)->call('save')->assertHasNoErrors();

    expect(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->count())->toBe(1);
});

test('clearing a previously translated language is refused, keeps the translation and brings that tab forward', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tag->id, 'name' => 'course à pied']);

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id)
        ->assertSet('showModal', true);

    expect(blogTagTabsStoredName($tag, $this->french))->toBe('course à pied');
});

test('a refused default-language edit renders on the default tab\'s key, not on the bare name key', function () {
    BlogTag::factory()->named('running')->create();
    $tag = BlogTag::factory()->named('invierno')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->call('setActiveLanguageTab', $this->french->id)
        ->set('names.'.$this->spanish->id, 'running')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertHasNoErrors(['name'])
        ->assertSet('activeLanguageId', $this->spanish->id);

    expect(blogTagTabsStoredName($tag, $this->spanish))->toBe('invierno');
});

test('a blank default-language name is refused on the default tab and changes nothing', function () {
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->spanish->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id]);

    expect(blogTagTabsStoredName($tag, $this->spanish))->toBe('running');
});

test('a refusal in one language discards every language\'s edit', function () {
    $other = BlogTag::factory()->named('autre')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $other->id, 'name' => 'course à pied']);
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->spanish->id, 'jogging')
        ->set('names.'.$this->french->id, 'Course à pied')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id)
        ->assertSet('names.'.$this->spanish->id, 'jogging');

    expect(blogTagTabsStoredName($tag, $this->spanish))->toBe('running')
        ->and(blogTagTabsStoredName($tag, $this->french))->toBeNull();
});

test('the same name in two different languages is accepted', function () {
    BlogTag::factory()->named('trail')->create();
    $tag = BlogTag::factory()->named('correr')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->french->id, 'trail')
        ->call('save')
        ->assertHasNoErrors();

    expect(blogTagTabsStoredName($tag, $this->french))->toBe('trail');
});

test('save() authorizes the whole batch before any action runs', function () {
    $this->withoutExceptionHandling();
    $tag = BlogTag::factory()->named('running')->create();

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->spanish->id, 'jogging')
        ->set('names.'.$this->french->id, 'course à pied');

    $this->actor->revokePermissionTo('blog.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect(blogTagTabsStoredName($tag, $this->spanish))->toBe('running')
        ->and(blogTagTabsStoredName($tag, $this->french))->toBeNull();
});

test('a forged $names key for an inactive language is ignored and never written', function () {
    $german = StoreLanguage::factory()->inactive()->create();
    $tag = BlogTag::factory()->named('running')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$german->id, 'Laufen')
        ->call('save');

    expect(blogTagTabsStoredName($tag, $german))->toBeNull();
});

test('closeModal() resets the tab state, the whole $names array and every validation error', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tag->id, 'name' => 'trail']);

    Livewire::test(Index::class)
        ->call('openEditModal', $tag->id)
        ->set('names.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors()
        ->call('closeModal')
        ->assertHasNoErrors()
        ->assertSet('names', [])
        ->assertSet('activeLanguageId', '')
        ->assertSet('originalTranslatedLanguageIds', []);
});

test('the list shows the store default language\'s name and a placeholder for a tag untranslated there', function () {
    $tag = BlogTag::factory()->named('running')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $tag->id, 'name' => 'course à pied']);
    $frenchOnly = BlogTag::factory()->withoutTranslations()->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $frenchOnly->id, 'name' => 'seulement']);

    $names = collect(Livewire::test(Index::class)->get('tags'))->pluck('name')->all();

    expect($names)->toContain('running')
        ->toContain('—')
        ->not->toContain('course à pied')
        ->not->toContain('seulement');
});

test('create is unchanged: one field, one translation in the default language, no tab state', function () {
    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->assertSet('names', [])
        ->assertSet('activeLanguageId', '')
        ->set('name', 'running')
        ->call('save')
        ->assertHasNoErrors();

    $tag = BlogTag::query()->sole();

    expect(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->pluck('store_language_id')->all())->toBe([$this->spanish->id]);
});

test('a refused create is still keyed on the bare name key', function () {
    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);
});
