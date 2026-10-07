<?php

// Component-level language-tab behaviour for App\Livewire\ProductCategories\Index, per
// ai-spec/tasks/done/0071-product-categories-language-tabs-ui.md's "Feature --
// LanguageTabsTest.php" section. Written at TDD Phase 3 step 1 (red), before the tabbed
// component, the strip, or App\Actions\ProductCategories\SetProductCategoryTranslation exist.
//
// Calibration (inherited from 0025): this file does NOT re-run 0070's suite one layer up -- the
// fallback chain and the normalised uniqueness fold are 0070's. It asserts only that the SCREEN
// routes into those rules and renders their outcome. The action itself is proven by direct calls
// in SetProductCategoryTranslationTest.php (backend-qa), independent of any component.
//
// D-11: no assertion here matches a language name or a two-letter code. Every language is
// addressed by its id; language names are generated words nobody asserts on, and category names
// ("Calzado", "Chaussures", ...) never collide with them.

use App\Actions\ProductCategories\RenameProductCategory;
use App\Actions\ProductCategories\SetProductCategoryTranslation;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Livewire\ProductCategories\Index;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // Names chosen so the by-name tab order is default, then french, then german (D-14), and so
    // the store default is NOT alphabetically first.
    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);
});

/**
 * @param  array<int, string>  $permissions
 */
function languageTabsActor(array $permissions = ['products.view', 'products.create', 'products.edit', 'products.delete']): User
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
function languageTabsCategory(array $namesByLanguageId): ProductCategory
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

function languageTabsStoredName(ProductCategory $category, StoreLanguage $language): ?string
{
    return ProductCategoryTranslation::query()
        ->where('product_category_id', $category->id)
        ->where('store_language_id', $language->id)
        ->value('name');
}

// =====================================================================
// Happy path
// =====================================================================

test('saving a new category with the default tab and one other language filled stores both names as distinct rows', function () {
    $this->actingAs(languageTabsActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Calzado')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $category = ProductCategory::query()->sole();

    expect(ProductCategoryTranslation::query()->where('product_category_id', $category->id)->count())->toBe(2)
        ->and(languageTabsStoredName($category, $this->spanish))->toBe('Calzado')
        ->and(languageTabsStoredName($category, $this->french))->toBe('Chaussures');
});

test('editing only the French tab stores French and never calls RenameProductCategory', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    // Risk if missing: the retrofit collapses back to "always rewrite the default row".
    $this->mock(RenameProductCategory::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(languageTabsStoredName($category, $this->french))->toBe('Chaussures')
        ->and(languageTabsStoredName($category, $this->spanish))->toBe('Calzado');
});

test('correcting a name in one language leaves the other language untouched', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Souliers')
        ->call('save')
        ->assertHasNoErrors();

    expect(languageTabsStoredName($category, $this->french))->toBe('Souliers')
        ->and(languageTabsStoredName($category, $this->spanish))->toBe('Calzado');
});

// D-4: SetTranslation is 0070's deliberately-unguarded primitive, reachable from no component.
// One expect() per namespace -- never expect([...]), which is disjunctive.
arch('the product categories component never reaches the unguarded SetTranslation primitive')
    ->expect('App\Livewire\ProductCategories')
    ->not->toUse('App\Actions\Translations\SetTranslation');

test('the tab set equals the active store languages and excludes an inactive one', function () {
    $this->actingAs(languageTabsActor());

    $names = Livewire::test(Index::class)->call('openCreateModal')->get('names');

    expect($names)->toHaveCount(3)
        ->and(array_keys($names))->toEqualCanonicalizing([$this->spanish->id, $this->french->id, $this->german->id])
        ->and($names)->not->toHaveKey($this->retired->id);
});

test('the active tab is the store default after opening the create modal and after opening the edit modal', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->call('closeModal')
        ->call('openEditModal', $category->id)
        ->assertSet('activeLanguageId', $this->spanish->id);
});

// =====================================================================
// Edge cases
// =====================================================================

test('the fallback does not leak into the edit field of an untranslated language', function () {
    // The sharpest bug this story can ship (D-6): translated()'s fallback bound into the French
    // input would silently create a French row identical to the Spanish one on an untouched save.
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('names')[$this->french->id])->toBe('')
        ->and($component->get('names')[$this->spanish->id])->toBe('Calzado');
});

test('saving an edit without touching an untranslated tab writes no row for that language', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, 'Zapatos')
        ->call('save')
        ->assertHasNoErrors();

    expect(languageTabsStoredName($category, $this->spanish))->toBe('Zapatos')
        ->and(languageTabsStoredName($category, $this->french))->toBeNull()
        ->and(ProductCategoryTranslation::query()->where('product_category_id', $category->id)->count())->toBe(1);
});

test('blank-because-untranslated is distinguishable from blank-because-cleared', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado', $this->german->id => 'Schuhe']);

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('originalTranslatedLanguageIds'))
        ->toContain($this->german->id)
        ->not->toContain($this->french->id);

    // Clearing a translated tab does not move it into "untranslated": the locked list still says
    // it held a translation when the modal opened.
    $component->set('names.'.$this->german->id, '');

    expect($component->get('originalTranslatedLanguageIds'))->toContain($this->german->id);
});

test('promoting another language to store default under an existing catalog renders the old copy blank and reorders the tabs', function () {
    // 0070 R-2: a Spanish-only category, French promoted to default, modal reopened.
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);
    app(SetDefaultStoreLanguage::class)($this->french);
    StoreLanguage::flushDefaultStoreLanguage();

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('names')[$this->french->id])->toBe('')
        ->and($component->get('names')[$this->spanish->id])->toBe('Calzado');

    $component->assertSet('activeLanguageId', $this->french->id);
});

test('a translation in a since-removed language gets no tab and the list still renders a name', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);

    $this->french->forceFill(['is_active' => false])->save();

    $component = Livewire::test(Index::class)->call('openEditModal', $category->id);

    expect($component->get('names'))->toBeArray()
        ->toHaveKey($this->spanish->id)
        ->not->toHaveKey($this->french->id);

    $row = collect(Livewire::test(Index::class)->get('productCategories'))->firstWhere('id', $category->id);

    expect($row['name'])->toBe('Calzado')
        ->and(languageTabsStoredName($category, $this->french))->toBe('Chaussures');
});

test('switching tabs preserves unsaved input typed on another language', function () {
    $this->actingAs(languageTabsActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('setActiveLanguageTab', $this->spanish->id)
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->call('setActiveLanguageTab', $this->french->id)
        ->assertSet('activeLanguageId', $this->french->id)
        ->assertSet('names.'.$this->french->id, 'Chaussures');
});

test('a whitespace-only entry on an untranslated tab is a no-op that writes nothing and never calls the translation action', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    $this->mock(SetProductCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, '   ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(languageTabsStoredName($category, $this->french))->toBeNull();
});

test('closing the modal after a refused save clears every piece of tab state and error', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);
    $other = languageTabsCategory([$this->spanish->id => 'Botas']);

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

    expect($component->get('names')[$this->spanish->id])->toBe('Botas')
        ->and($component->get('names')[$this->french->id])->toBe('');
});

// =====================================================================
// Negative cases
// =====================================================================

test('a duplicate name on a hidden tab is refused on that tab and brings it into view', function () {
    // The single highest-value test in this story: an error keyed to a tab nobody is looking at.
    $this->actingAs(languageTabsActor());
    languageTabsCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);
    $target = languageTabsCategory([$this->spanish->id => 'Botas']);

    Livewire::test(Index::class)
        ->call('openEditModal', $target->id)
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id)
        ->assertSet('showModal', true);

    expect(languageTabsStoredName($target, $this->french))->toBeNull();
});

test('the default language tab left blank is refused on that tab unconditionally', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertSet('activeLanguageId', $this->spanish->id);

    expect(languageTabsStoredName($category, $this->spanish))->toBe('Calzado');
});

test('blanking a previously translated tab is refused while leaving an untranslated one blank is accepted', function () {
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('activeLanguageId', $this->french->id);

    expect(languageTabsStoredName($category, $this->french))->toBe('Chaussures');

    // German was never translated: blank is accepted and writes nothing.
    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(languageTabsStoredName($category, $this->german))->toBeNull();
});

test('the same name in two different languages is accepted and in one language is refused', function () {
    $this->actingAs(languageTabsActor());
    languageTabsCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);

    // Same string, a different language than the one holding it: accepted.
    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Chaussures')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    // Same string, same language, second category: refused.
    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Botas')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('showModal', true);
});

test('a forged tab switch to an unknown or inactive language fails and leaves the active tab unchanged', function (string $which) {
    $this->withoutExceptionHandling();
    $this->actingAs(languageTabsActor());

    $forgedId = $which === 'inactive' ? $this->retired->id : '0199aaaa-0000-7000-8000-000000000000';

    $component = Livewire::test(Index::class)->call('openCreateModal');

    expect(fn () => $component->call('setActiveLanguageTab', $forgedId))
        ->toThrow(ModelNotFoundException::class);

    $component->assertSet('activeLanguageId', $this->spanish->id);
})->with(['an unknown language id' => ['unknown'], 'an inactive language id' => ['inactive']]);

test('a forged names key for an unknown or inactive language is ignored by save', function () {
    $this->actingAs(languageTabsActor());

    $this->mock(SetProductCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Calzado')
        ->set('names.'.$this->retired->id, 'Fantasma')
        ->set('names.0199aaaa-0000-7000-8000-000000000000', 'Fantasma')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProductCategoryTranslation::query()->where('name', 'Fantasma')->exists())->toBeFalse()
        ->and(ProductCategory::query()->count())->toBe(1);
});

test('an actor holding only products.view cannot write any tab through a forged save, and the refusal is logged', function () {
    // Layer 1 at the component. The same refusal at the action is asserted by direct calls in
    // SetProductCategoryTranslationTest.php -- the pair proves the two layers are independent.
    $this->withoutExceptionHandling();
    Log::spy();

    $actor = languageTabsActor(['products.view']);
    $this->actingAs($actor);

    $this->mock(SetProductCategoryTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    expect(fn () => Livewire::test(Index::class)
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id)
        ->once();

    expect(ProductCategoryTranslation::query()->where('name', 'Chaussures')->exists())->toBeFalse();
});

test('a default-language refusal thrown under the name key by Rename renders on the default tab', function () {
    // Livewire's SupportValidation::dehydrate() drops any error whose first key segment is not a
    // component property, so once $name is gone a `name`-keyed error would silently vanish.
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    $this->mock(RenameProductCategory::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')->andThrow(ValidationException::withMessages(['name' => 'Refused by the double']));
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->call('setActiveLanguageTab', $this->french->id)
        ->set('names.'.$this->spanish->id, 'Zapatos')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id])
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->assertSet('showModal', true);
});

test('an actor with products.edit and no store language permissions can translate a category', function () {
    $actor = languageTabsActor(['products.view', 'products.edit']);
    $this->actingAs($actor);

    expect($actor->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'store-languages.')))->toBeEmpty();

    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasNoErrors();

    expect(languageTabsStoredName($category, $this->french))->toBe('Chaussures');
});

test('a create-only actor sees translation unavailable, and a correct default-only create is not refused', function () {
    // B-1: products.view + products.create, no products.edit.
    Log::spy();
    $this->actingAs(languageTabsActor(['products.view', 'products.create']));

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->assertSet('canAuthorTranslations', false)
        ->set('names.'.$this->spanish->id, 'Calzado')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $category = ProductCategory::query()->sole();

    expect(ProductCategoryTranslation::query()->where('product_category_id', $category->id)->count())->toBe(1)
        ->and(languageTabsStoredName($category, $this->spanish))->toBe('Calzado');

    Log::shouldNotHaveReceived('warning');
});

test('a create-only actor submitting a forged non-default value is refused and logged before anything is written', function () {
    $this->withoutExceptionHandling();
    Log::spy();

    $actor = languageTabsActor(['products.view', 'products.create']);
    $this->actingAs($actor);

    $component = Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('names.'.$this->spanish->id, 'Calzado')
        ->set('names.'.$this->french->id, 'Chaussures');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'update'
            && ($context['target_type'] ?? null) === 'product_category'
            && array_key_exists('target_id', $context) && $context['target_id'] === null)
        ->once();

    expect(ProductCategory::query()->count())->toBe(0);
});

test('a refused later-language write rolls back every earlier write of the same save', function () {
    // Q-5. German is the language whose write is refused; French (alphabetically earlier) and the
    // default-language rename have already run by then and must be rolled back.
    $this->actingAs(languageTabsActor());
    $category = languageTabsCategory([$this->spanish->id => 'Calzado']);

    // A real model-level refusal at write time, so the earlier French write is genuinely
    // persisted before the German one throws (a double would not prove the rollback).
    $germanId = $this->german->id;

    ProductCategoryTranslation::creating(function (ProductCategoryTranslation $translation) use ($germanId): void {
        if ($translation->store_language_id === $germanId) {
            throw ValidationException::withMessages(['names.'.$germanId => 'Refused at write time']);
        }
    });

    Livewire::test(Index::class)
        ->call('openEditModal', $category->id)
        ->set('names.'.$this->spanish->id, 'Zapatos')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('names.'.$this->german->id, 'Schuhe')
        ->call('save')
        ->assertHasErrors(['names.'.$this->german->id])
        ->assertSet('activeLanguageId', $this->german->id)
        ->assertSet('showModal', true)
        ->assertSet('names.'.$this->spanish->id, 'Zapatos')
        ->assertSet('names.'.$this->french->id, 'Chaussures')
        ->assertSet('names.'.$this->german->id, 'Schuhe');

    expect(languageTabsStoredName($category, $this->spanish))->toBe('Calzado')
        ->and(languageTabsStoredName($category, $this->french))->toBeNull()
        ->and(languageTabsStoredName($category, $this->german))->toBeNull()
        ->and(ProductCategoryTranslation::query()->where('product_category_id', $category->id)->count())->toBe(1);
});
