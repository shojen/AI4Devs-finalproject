<?php

// Component-level language-tab behaviour for App\Livewire\BlogCategories\Index, per
// ai-spec/tasks/in-progress/0073-blog-categories-language-tabs-ui.md's "Feature --
// BlogCategoryLanguageTabsTest.php" section and its Phase 2 amendments (A-1..A-17). Written at TDD
// Phase 3 step 1 (red), before the tabbed component, the action or the markup exist.
//
// Calibration: this file does NOT re-run 0070's/0072's suites one layer up. It asserts only that
// the SCREEN routes into those rules and renders their outcome. The action is proven by direct
// calls in SetBlogCategoryTranslationTest.php, independent of any component.
//
// D-11: no assertion matches a language name or a two-letter code. Every language is addressed by
// its id; category names ("Guias", "Guides", ...) never collide with generated language names.
//
// 0058 D-13: the actions authorize BEFORE they validate, so every negative-validation test below
// acts as an actor holding blog.edit (the helper default).

use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\RenameBlogCategory;
use App\Actions\Blog\SetBlogCategoryTranslation;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Livewire\BlogCategories\Index;
use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // Names chosen so the by-name tab order is default, then french, then german, and so the store
    // default is NOT alphabetically first.
    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);
});

/**
 * @param  array<int, string>  $permissions
 */
function blogLanguageTabsActor(array $permissions = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/**
 * A category holding exactly the given names, keyed by store language id. No other translation row
 * exists, so "untranslated" languages are genuinely absent.
 *
 * @param  array<string, string>  $namesByLanguageId
 */
function blogLanguageTabsCategory(array $namesByLanguageId): BlogCategory
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

function blogLanguageTabsStoredName(BlogCategory $category, StoreLanguage $language): ?string
{
    return BlogCategoryTranslation::query()
        ->where('blog_category_id', $category->id)
        ->where('store_language_id', $language->id)
        ->value('name');
}

// =====================================================================
// Happy path
// =====================================================================

test('saving a new category calls CreateBlogCategory with the default name and SetBlogCategoryTranslation with the second language, as two separate calls', function () {
    $this->actingAs(blogLanguageTabsActor());
    $created = BlogCategory::factory()->withoutTranslations()->create();
    $frenchId = $this->french->id;

    $this->mock(CreateBlogCategory::class, function (MockInterface $mock) use ($created): void {
        $mock->shouldReceive('__invoke')->once()->with('Guias')->andReturn($created);
    });
    $this->mock(SetBlogCategoryTranslation::class, function (MockInterface $mock) use ($created, $frenchId): void {
        $mock->shouldReceive('__invoke')
            ->once()
            ->withArgs(fn (BlogCategory $category, StoreLanguage $language, string $name): bool => $category->is($created)
                && $language->id === $frenchId
                && $name === 'Guides')
            ->andReturn(new BlogCategoryTranslation);
    });

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Guias')
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);
});

test('saving a new category with the default tab and one other language filled stores both names', function () {
    $this->actingAs(blogLanguageTabsActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Guias')
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $category = BlogCategory::query()->sole();

    expect(BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->count())->toBe(2)
        ->and(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias')
        ->and(blogLanguageTabsStoredName($category, $this->french))->toBe('Guides');
});

test('editing only the French tab stores French and never calls RenameBlogCategory', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    // Risk if missing: the retrofit collapses back to "always rewrite the default row".
    $this->mock(RenameBlogCategory::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(blogLanguageTabsStoredName($category, $this->french))->toBe('Guides')
        ->and(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias');
});

test('correcting a name in one language leaves the other language untouched', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, "Guides d'achat")
        ->call('save')
        ->assertHasNoErrors();

    expect(blogLanguageTabsStoredName($category, $this->french))->toBe("Guides d'achat")
        ->and(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias');
});

// 0071 D-13: SetTranslation is 0070's deliberately-unguarded primitive, reachable from no
// component. One expect() per namespace -- never expect([...]), which is disjunctive.
arch('the blog categories component never reaches the unguarded SetTranslation primitive')
    ->expect('App\Livewire\BlogCategories')
    ->not->toUse('App\Actions\Translations\SetTranslation');

test('the tab set equals the active store languages and excludes an inactive one', function () {
    $this->actingAs(blogLanguageTabsActor());

    $names = Livewire::test(Index::class)->call('openCreateModal')->get('names');

    expect($names)->toHaveCount(3)
        ->and(array_keys($names))->toEqualCanonicalizing([$this->spanish->id, $this->french->id, $this->german->id])
        ->and($names)->not->toHaveKey($this->retired->id);
});

test('the active tab is the store default after opening the create modal and after opening the edit modal', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->call('closeModal')
        ->call('openEditModal', $category->id)
        ->assertSet('activeLanguageId', $this->spanish->id);
});

test('the component exposes the active languages as a computed collection, default first then by name (A-2)', function () {
    $this->actingAs(blogLanguageTabsActor());

    $languages = Livewire::test(Index::class)->call('openCreateModal')->instance()->languages;

    expect($languages->pluck('id')->all())->toBe([$this->spanish->id, $this->french->id, $this->german->id]);
});

// =====================================================================
// Edge cases
// =====================================================================

test('the fallback does not leak into the edit field of an untranslated language', function () {
    // The sharpest bug this story can ship (0071 D-6): translated()'s fallback bound into the
    // French input would silently create a French row identical to the Spanish one on an
    // untouched save.
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('names')[$this->french->id])->toBe('')
        ->and($component->get('names')[$this->spanish->id])->toBe('Guias');
});

test('saving an edit without touching an untranslated tab writes no row for that language', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, 'Novedades')
        ->call('save')
        ->assertHasNoErrors();

    expect(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Novedades')
        ->and(blogLanguageTabsStoredName($category, $this->french))->toBeNull()
        ->and(BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->count())->toBe(1);
});

test('blank-because-untranslated is distinguishable from blank-because-cleared', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->german->id => 'Ratgeber']);

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('originalTranslatedLanguageIds'))
        ->toContain($this->german->id)
        ->not->toContain($this->french->id);

    // Clearing a translated tab does not move it into "untranslated".
    $component->set('names.'.$this->german->id, '');

    expect($component->get('originalTranslatedLanguageIds'))->toContain($this->german->id);
});

test('promoting another language to store default under an existing catalog renders the old copy blank and reorders the tabs', function () {
    // 0070 R-2: a Spanish-only category, French promoted to default, modal reopened.
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);
    app(SetDefaultStoreLanguage::class)($this->french);
    StoreLanguage::flushDefaultStoreLanguage();

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('names')[$this->french->id])->toBe('')
        ->and($component->get('names')[$this->spanish->id])->toBe('Guias');

    $component->assertSet('activeLanguageId', $this->french->id);
});

test('a translation in a since-removed language gets no tab and the list still renders a name', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

    $this->french->forceFill(['is_active' => false])->save();

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('names'))->toBeArray()
        ->toHaveKey($this->spanish->id)
        ->not->toHaveKey($this->french->id);

    $row = collect(Livewire::test(Index::class)->get('categories'))->firstWhere('id', $category->id);

    expect($row['name'])->toBe('Guias')
        ->and(blogLanguageTabsStoredName($category, $this->french))->toBe('Guides');
});

test('switching tabs preserves unsaved input typed on another language', function () {
    $this->actingAs(blogLanguageTabsActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->french->id, 'Guides')
        ->call('setActiveLanguageTab', $this->spanish->id)
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->call('setActiveLanguageTab', $this->french->id)
        ->assertSet('activeLanguageId', $this->french->id)
        ->assertSet('names.'.$this->french->id, 'Guides');
});

test('a whitespace-only entry on an untranslated tab is a no-op that writes nothing and never calls the translation action', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    $this->mock(SetBlogCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, '   ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(blogLanguageTabsStoredName($category, $this->french))->toBeNull();
});

test('re-saving an unchanged category creates no second translation row', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

    Livewire::test(Index::class)->call('openEditModal', $category->id)->call('save')->assertHasNoErrors();

    expect(BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->count())->toBe(2);
});

test('closing the modal after a refused save clears every piece of tab state and error', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $other = blogLanguageTabsCategory([$this->spanish->id => 'Novedades']);

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, '')
        ->set('names.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id, 'names.'.$this->french->id])
        ->call('closeModal')
        ->assertHasNoErrors()
        ->assertSet('names', [])
        ->assertSet('originalTranslatedLanguageIds', [])
        ->assertSet('activeLanguageId', '');

    // Reopening against a different category shows none of the previous state.
    $component->call('openEditModal', $other->id)->assertHasNoErrors();

    expect($component->get('names')[$this->spanish->id])->toBe('Novedades')
        ->and($component->get('names')[$this->french->id])->toBe('');
});

// =====================================================================
// Negative cases
// =====================================================================

test('a duplicate name on a hidden tab is refused on that tab and brings it into view', function () {
    // The single highest-value test in this story: an error keyed to a tab nobody is looking at.
    $this->actingAs(blogLanguageTabsActor());
    blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $target = blogLanguageTabsCategory([$this->spanish->id => 'Novedades']);

    Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id)
        ->assertSet('showModal', true);

    expect(blogLanguageTabsStoredName($target, $this->french))->toBeNull();
});

test('two tabs failing at once land the active tab on the first in strip order', function () {
    $this->actingAs(blogLanguageTabsActor());
    blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides', $this->german->id => 'Ratgeber']);
    $target = blogLanguageTabsCategory([$this->spanish->id => 'Novedades']);

    // German is typed last but French is earlier in strip order (default, then by name).
    Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->call('setActiveLanguageTab', $this->german->id)
        ->set('names.'.$this->german->id, 'Ratgeber')
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id, 'names.'.$this->german->id])
        ->assertSet('activeLanguageId', $this->french->id);
});

test('the default language tab left blank is refused on that tab unconditionally', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertSet('activeLanguageId', $this->spanish->id);

    expect(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias');
});

test('blanking a previously translated tab is refused while leaving an untranslated one blank is accepted', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id);

    expect(blogLanguageTabsStoredName($category, $this->french))->toBe('Guides');

    // German was never translated: blank is accepted and writes nothing.
    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(blogLanguageTabsStoredName($category, $this->german))->toBeNull();
});

test('the same name in two different languages is accepted and in one language is refused', function () {
    $this->actingAs(blogLanguageTabsActor());
    blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

    // Same string, a different language than the one holding it: accepted.
    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Guides')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    // Same string, same language, second category: refused.
    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Novedades')
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('showModal', true);
});

test('an accent-only variant within one language is refused (one canary; the fold itself is 0072\'s)', function () {
    $this->actingAs(blogLanguageTabsActor());
    blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guías']);
    $target = blogLanguageTabsCategory([$this->spanish->id => 'Novedades']);

    Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->set('names.'.$this->french->id, 'Guias')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);
});

test('re-saving a category under its own unchanged name is accepted, unchanged on disk, and a free name still saves (the ignore() trap)', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(blogLanguageTabsStoredName($category, $this->french))->toBe('Guides')
        ->and(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias');

    // Control: a genuinely free name in the same language is still accepted.
    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Ratgeber')
        ->call('save')
        ->assertHasNoErrors();

    expect(blogLanguageTabsStoredName($category, $this->french))->toBe('Ratgeber');
});

test('a forged tab switch to an unknown or inactive language fails and leaves the active tab unchanged', function (string $which) {
    $this->withoutExceptionHandling();
    $this->actingAs(blogLanguageTabsActor());

    $forgedId = $which === 'inactive' ? $this->retired->id : '0199aaaa-0000-7000-8000-000000000000';

    $component = Livewire::test(Index::class)->call('openCreateModal');

    expect(fn () => $component->call('setActiveLanguageTab', $forgedId))
        ->toThrow(ModelNotFoundException::class);

    $component->assertSet('activeLanguageId', $this->spanish->id);
})->with(['an unknown language id' => ['unknown'], 'an inactive language id' => ['inactive']]);

test('a forged names key for an unknown or inactive language is ignored by save', function () {
    $this->actingAs(blogLanguageTabsActor());

    $this->mock(SetBlogCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Guias')
        ->set('names.'.$this->retired->id, 'Fantasma')
        ->set('names.0199aaaa-0000-7000-8000-000000000000', 'Fantasma')
        ->call('save')
        ->assertHasNoErrors();

    expect(BlogCategoryTranslation::query()->where('name', 'Fantasma')->exists())->toBeFalse()
        ->and(BlogCategory::query()->count())->toBe(1);
});

test('forging editingCategoryId between opening the modal and saving throws and never retargets a non-default write', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(blogLanguageTabsActor());
    $a = blogLanguageTabsCategory([$this->spanish->id => 'Alfa']);
    $b = blogLanguageTabsCategory([$this->spanish->id => 'Beta']);

    $component = Livewire::test(Index::class)->call('openEditModal', $a->id);

    expect(fn () => $component->set('editingCategoryId', $b->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $component->set('names.'.$this->french->id, 'Guides')->call('save');

    expect(blogLanguageTabsStoredName($a, $this->french))->toBe('Guides')
        ->and(blogLanguageTabsStoredName($b, $this->french))->toBeNull();
});

test('a default-language refusal thrown under the name key by Rename renders on the default tab', function () {
    // Livewire's SupportValidation::dehydrate() drops any error whose first key segment is not a
    // component property, so once $name is gone a `name`-keyed error would silently vanish.
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    $this->mock(RenameBlogCategory::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')->andThrow(ValidationException::withMessages(['name' => 'Refused by the double']));
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->call('setActiveLanguageTab', $this->french->id)
        ->set('names.'.$this->spanish->id, 'Novedades')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertHasNoErrors(['name'])
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->assertSet('showModal', true);
});

test('a default-language refusal thrown under the name key by Create renders on the default tab', function () {
    $this->actingAs(blogLanguageTabsActor());

    $this->mock(CreateBlogCategory::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')->andThrow(ValidationException::withMessages(['name' => 'Refused by the double']));
    });

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->call('setActiveLanguageTab', $this->french->id)
        ->set('names.'.$this->spanish->id, 'Guias')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->assertSet('showModal', true);
});

test('a refusal under a names.{uuid} key renders a message containing no names. text, on both closure rules and in en and es (A-1, A-17)', function (string $locale) {
    app()->setLocale($locale);
    $this->actingAs(blogLanguageTabsActor());
    blogLanguageTabsCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $target = blogLanguageTabsCategory([$this->spanish->id => 'Novedades']);
    $label = __('blog.categories.index.tabs.name_attribute');

    // uniqueNormalisedName() closure, then foldedNameFits() closure (200 sharp-s fold to 400).
    foreach (['Guides', str_repeat("\u{00DF}", 200)] as $candidate) {
        $errors = Livewire::test(Index::class)
            ->call('openEditModal', $target->id)
            ->set('names.'.$this->french->id, $candidate)
            ->call('save')
            ->assertHasErrors(['names.'.$this->french->id])
            ->errors();

        $message = $errors->first('names.'.$this->french->id);

        expect($message)->toBeString()
            ->not->toContain('names.')
            ->not->toContain($this->french->id)
            ->toContain($label);
    }
})->with(['en' => ['en'], 'es' => ['es']]);

test('an existing name-keyed Create refusal now names the attribute through the validator: Spanish says "nombre" (A-13)', function () {
    app()->setLocale('es');
    $this->actingAs(blogLanguageTabsActor());
    BlogCategory::factory()->named('Guias')->create();

    $caught = null;
    try {
        app(CreateBlogCategory::class)('Guias');
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors()['name'][0])->toContain('nombre');
});

// =====================================================================
// Authorization -- layer 1, and the seam between the layers
// =====================================================================

test('an actor holding only blog.view cannot write any tab through a forged save, and the refusal is logged exactly once', function () {
    $this->withoutExceptionHandling();
    Log::spy();

    $actor = blogLanguageTabsActor(['blog.view']);
    $this->actingAs($actor);

    $this->mock(SetBlogCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    expect(fn () => Livewire::test(Index::class)
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save'))->toThrow(AuthorizationException::class);

    // One click, one line: two layers authorize, but only the refusing one logs.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['target_type'] ?? null) === 'blog_category')
        ->once();

    expect(BlogCategoryTranslation::query()->where('name', 'Guides')->exists())->toBeFalse();
});

test('the edit-mode layer-1 refusal runs before the action: a view-only actor with a stale edit modal writes nothing and logs once', function () {
    $this->withoutExceptionHandling();
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);
    $actor = blogLanguageTabsActor();
    $this->actingAs($actor);

    $this->mock(SetBlogCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Guides');

    $actor->revokePermissionTo('blog.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Log::spy();

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['target_type'] ?? null) === 'blog_category'
            && ($context['target_id'] ?? null) === $category->id)
        ->once();

    expect(blogLanguageTabsStoredName($category, $this->french))->toBeNull();
});

test('layer 1 validates before layer 2: a blank default name errors on names.{defaultId} with the translation action never invoked', function () {
    $this->actingAs(blogLanguageTabsActor());

    $this->mock(SetBlogCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, '')
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertSet('showModal', true);

    expect(BlogCategory::query()->count())->toBe(0);
});

test('an actor with blog.edit and no store language permissions can translate a category', function () {
    $actor = blogLanguageTabsActor(['blog.view', 'blog.edit']);
    $this->actingAs($actor);

    expect($actor->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'store-languages.')))->toBeEmpty();

    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Guides')
        ->call('save')
        ->assertHasNoErrors();

    expect(blogLanguageTabsStoredName($category, $this->french))->toBe('Guides');
});

test('a create-only actor sees translation unavailable, and a correct default-only create is not refused (B-1)', function () {
    Log::spy();
    $this->actingAs(blogLanguageTabsActor(['blog.view', 'blog.create']));

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->assertSet('canAuthorTranslations', false)
        ->set('names.'.$this->spanish->id, 'Guias')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $category = BlogCategory::query()->sole();

    expect(BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->count())->toBe(1)
        ->and(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias');

    Log::shouldNotHaveReceived('warning');
});

test('an actor holding blog.edit sees translation available on the create modal and in edit mode (B-1)', function () {
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->assertSet('canAuthorTranslations', true)
        ->call('closeModal')
        ->call('openEditModal', $category->id)
        ->assertSet('canAuthorTranslations', true);
});

test('a create-only actor submitting a forged non-default value is refused and logged before anything is written (B-1)', function () {
    $this->withoutExceptionHandling();
    Log::spy();

    $actor = blogLanguageTabsActor(['blog.view', 'blog.create']);
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Guias')
        ->set('names.'.$this->french->id, 'Guides');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'blog_category'
            && array_key_exists('target_id', $context) && $context['target_id'] === null)
        ->once();

    expect(BlogCategory::query()->count())->toBe(0);
});

test('canAuthorTranslations is #[Locked] so a client cannot flip it', function () {
    $this->actingAs(blogLanguageTabsActor(['blog.view', 'blog.create']));

    $component = Livewire::test(Index::class)->call('openCreateModal');

    expect(fn () => $component->set('canAuthorTranslations', true))->toThrow(CannotUpdateLockedPropertyException::class);
});

// =====================================================================
// Atomicity (Q-5)
// =====================================================================

test('a refused later-language write rolls back every earlier write of the same save (edit)', function () {
    // German is the language whose write is refused; the default-language rename and French
    // (earlier in strip order) have already run by then and must be rolled back.
    $this->actingAs(blogLanguageTabsActor());
    $category = blogLanguageTabsCategory([$this->spanish->id => 'Guias']);
    $germanId = $this->german->id;

    // A real model-level refusal at write time, so the earlier French write is genuinely
    // persisted before the German one throws (a test double would not prove the rollback).
    BlogCategoryTranslation::creating(function (BlogCategoryTranslation $translation) use ($germanId): void {
        if ($translation->store_language_id === $germanId) {
            throw ValidationException::withMessages(['names.'.$germanId => 'Refused at write time']);
        }
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, 'Novedades')
        ->set('names.'.$this->french->id, 'Guides')
        ->set('names.'.$this->german->id, 'Ratgeber')
        ->call('save')
        ->assertHasErrors(['names.'.$this->german->id])
        ->assertSet('activeLanguageId', $this->german->id)
        ->assertSet('showModal', true)
        ->assertSet('names.'.$this->spanish->id, 'Novedades')
        ->assertSet('names.'.$this->french->id, 'Guides')
        ->assertSet('names.'.$this->german->id, 'Ratgeber');

    expect(blogLanguageTabsStoredName($category, $this->spanish))->toBe('Guias')
        ->and(blogLanguageTabsStoredName($category, $this->french))->toBeNull()
        ->and(blogLanguageTabsStoredName($category, $this->german))->toBeNull()
        ->and(BlogCategoryTranslation::query()->where('blog_category_id', $category->id)->count())->toBe(1);
});

test('a refused later-language write on create leaves no half-written category behind', function () {
    $this->actingAs(blogLanguageTabsActor());
    $germanId = $this->german->id;

    BlogCategoryTranslation::creating(function (BlogCategoryTranslation $translation) use ($germanId): void {
        if ($translation->store_language_id === $germanId) {
            throw ValidationException::withMessages(['names.'.$germanId => 'Refused at write time']);
        }
    });

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Guias')
        ->set('names.'.$this->french->id, 'Guides')
        ->set('names.'.$this->german->id, 'Ratgeber')
        ->call('save')
        ->assertHasErrors(['names.'.$this->german->id])
        ->assertSet('showModal', true);

    expect(BlogCategory::query()->count())->toBe(0)
        ->and(BlogCategoryTranslation::query()->count())->toBe(0);
});
