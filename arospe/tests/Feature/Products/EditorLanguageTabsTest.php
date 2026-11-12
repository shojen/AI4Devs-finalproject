<?php

// Component-level language-tab behaviour for App\Livewire\Products\Editor, per
// ai-spec/tasks/in-progress/0077-product-editor-language-tabs-ui.md's "Tests to perform" section.
// Written at TDD Phase 3 step 1 (red), before the tabbed Editor exists.
//
// D-11: no assertion matches a language name or a two-letter code; every language is addressed by
// its id. Language names are generated words nobody asserts on.

use App\Livewire\Products\Editor;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // By-name tab order is default, french, german (0071 D-14); the default is NOT alphabetically first.
    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);

    $this->actingAs(editorTabsActor());
});

function editorTabsActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['products.view', 'products.create', 'products.edit', 'products.delete']);

    return $actor;
}

/**
 * A product holding exactly the given rows, keyed by store language id; no other row exists.
 *
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function editorTabsProduct(array $rowsByLanguageId): Product
{
    $product = Product::factory()->withoutTranslations()->create();

    foreach ($rowsByLanguageId as $languageId => $attributes) {
        ProductTranslation::factory()->create(array_merge([
            'product_id' => $product->id,
            'store_language_id' => $languageId,
        ], $attributes));
    }

    return $product;
}

/**
 * @return array<string, array<string, string>>
 */
function editorTabsFullRow(string $suffix): array
{
    return [
        'name' => 'Name '.$suffix,
        'description' => '<p>Description '.$suffix.'</p>',
        'slug' => 'slug-'.$suffix,
        'meta_title' => 'Meta title '.$suffix,
        'meta_description' => 'Meta description '.$suffix,
    ];
}

/**
 * @return list<string>
 */
function editorTabsPersistedLanguageIds(Product $product): array
{
    return ProductTranslation::query()
        ->where('product_id', $product->id)
        ->pluck('store_language_id')
        ->sort()
        ->values()
        ->all();
}

// =====================================================================
// Tab set and order
// =====================================================================

test('the tab set equals the active languages, default first then by name', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    $languages = Livewire::test(Editor::class, ['product' => $product])->instance()->languages();

    expect($languages->pluck('id')->all())->toBe([$this->spanish->id, $this->french->id, $this->german->id]);
});

test('the default language is the active tab on load', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->assertSet('activeLanguageId', $this->spanish->id)
        ->assertSet('defaultLanguageId', $this->spanish->id);
});

test('an inactive language with a stored translation gets no tab and no hydrated value', function () {
    $product = editorTabsProduct([
        $this->spanish->id => editorTabsFullRow('es'),
        $this->retired->id => editorTabsFullRow('retired'),
    ]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    expect($component->instance()->languages()->pluck('id')->all())->not->toContain($this->retired->id);

    foreach (['names', 'descriptions', 'slugs', 'metaTitles', 'metaDescriptions'] as $property) {
        expect(array_keys($component->get($property)))->not->toContain($this->retired->id);
    }
});

test('a deactivated language renders no tab', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->assertSeeHtml('data-test="language-tab-'.$this->french->id.'"')
        ->assertDontSeeHtml('data-test="language-tab-'.$this->retired->id.'"');
});

// =====================================================================
// Hydration
// =====================================================================

test('loading hydrates every one of the five fields for every language from its own row', function () {
    $product = editorTabsProduct([
        $this->spanish->id => editorTabsFullRow('es'),
        $this->french->id => editorTabsFullRow('fr'),
        $this->german->id => editorTabsFullRow('de'),
    ]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    foreach (['es' => $this->spanish, 'fr' => $this->french, 'de' => $this->german] as $suffix => $language) {
        $component
            ->assertSet('names.'.$language->id, 'Name '.$suffix)
            ->assertSet('descriptions.'.$language->id, '<p>Description '.$suffix.'</p>')
            ->assertSet('slugs.'.$language->id, 'slug-'.$suffix)
            ->assertSet('metaTitles.'.$language->id, 'Meta title '.$suffix)
            ->assertSet('metaDescriptions.'.$language->id, 'Meta description '.$suffix);
    }
});

test('an untranslated language hydrates empty strings everywhere, never the default language fallback', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    foreach (['names', 'descriptions', 'slugs', 'metaTitles', 'metaDescriptions'] as $property) {
        $component->assertSet($property.'.'.$this->french->id, '');
    }
});

test('no hydrated value is null even when optional columns are NULL', function () {
    $product = editorTabsProduct([
        $this->spanish->id => ['name' => 'Only a name', 'description' => null, 'slug' => null, 'meta_title' => null, 'meta_description' => null],
    ]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    foreach (['names', 'descriptions', 'slugs', 'metaTitles', 'metaDescriptions'] as $property) {
        $values = $component->get($property);

        expect($values)->not->toBeEmpty();

        foreach ($values as $value) {
            expect($value)->toBeString();
        }
    }
});

test('a product translated in no language at all opens with empty fields and no error', function () {
    $product = editorTabsProduct([]);

    $component = Livewire::test(Editor::class, ['product' => $product])->assertOk();

    foreach ([$this->spanish, $this->french, $this->german] as $language) {
        $component->assertSet('names.'.$language->id, '');
    }
});

test('a store with a single active language renders one tab and saves', function () {
    $this->french->forceFill(['is_active' => false])->save();
    $this->german->forceFill(['is_active' => false])->save();
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    expect($component->instance()->languages())->toHaveCount(1);

    $component->set('names.'.$this->spanish->id, 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProductTranslation::query()->where('product_id', $product->id)->value('name'))->toBe('Renamed');
});

// =====================================================================
// Switching
// =====================================================================

test('setActiveLanguageTab switches to an active language id', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->call('setActiveLanguageTab', $this->french->id)
        ->assertSet('activeLanguageId', $this->french->id);
});

test('setActiveLanguageTab refuses an inactive, unknown or malformed id as not found and keeps the tab', function (string $kind) {
    $this->withoutExceptionHandling();
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);
    $badId = match ($kind) {
        'inactive' => $this->retired->id,
        'unknown' => (string) fake()->uuid(),
        'malformed' => "not-a-uuid'; DROP TABLE product_translations; --",
    };

    $component = Livewire::test(Editor::class, ['product' => $product]);
    $rowsBefore = DB::table('product_translations')->count();

    expect(fn () => $component->call('setActiveLanguageTab', $badId))->toThrow(ModelNotFoundException::class);

    $component->assertSet('activeLanguageId', $this->spanish->id);
    expect(DB::table('product_translations')->count())->toBe($rowsBefore);
})->with(['inactive', 'unknown', 'malformed']);

test('the tab state properties are locked against client writes', function (string $property, mixed $value) {
    $this->withoutExceptionHandling();
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    expect(fn () => $component->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'activeLanguageId' => ['activeLanguageId', 'anything'],
    'defaultLanguageId' => ['defaultLanguageId', 'anything'],
    'originalTranslatedLanguageIds' => ['originalTranslatedLanguageIds', []],
    'canAuthorTranslations' => ['canAuthorTranslations', true],
]);

test('switching tabs writes nothing: row set, count and updated_at are unchanged', function () {
    $product = editorTabsProduct([
        $this->spanish->id => editorTabsFullRow('es'),
        $this->french->id => editorTabsFullRow('fr'),
    ]);
    $productsBefore = Product::count();
    $rowsBefore = DB::table('product_translations')->orderBy('id')->get()->all();
    $updatedAtBefore = $product->fresh()->updated_at;

    Livewire::test(Editor::class, ['product' => $product])
        ->call('setActiveLanguageTab', $this->french->id)
        ->call('setActiveLanguageTab', $this->spanish->id);

    expect(Product::count())->toBe($productsBefore)
        ->and(DB::table('product_translations')->orderBy('id')->get()->all())->toEqual($rowsBefore)
        ->and($product->fresh()->updated_at->equalTo($updatedAtBefore))->toBeTrue();
});

test('the non-translatable properties are identical across a switch', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);
    $shared = ['sku', 'productCategoryId', 'type', 'status', 'price', 'stock', 'regionIds', 'featuredMediaId', 'galleryMediaIds'];

    $component = Livewire::test(Editor::class, ['product' => $product]);
    $before = collect($shared)->mapWithKeys(fn (string $property): array => [$property => $component->get($property)])->all();

    $component->call('setActiveLanguageTab', $this->french->id);

    $after = collect($shared)->mapWithKeys(fn (string $property): array => [$property => $component->get($property)])->all();

    expect($after)->toBe($before);
});

test('unsaved input in the tab being left is still there after switching away and back', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, 'Typed but unsaved')
        ->call('setActiveLanguageTab', $this->french->id)
        ->call('setActiveLanguageTab', $this->spanish->id)
        ->assertSet('names.'.$this->spanish->id, 'Typed but unsaved');
});

// =====================================================================
// Tamper
// =====================================================================

test('array entries for an inactive, nonexistent or never-rendered language write no row', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);
    $nonexistentId = (string) fake()->uuid();

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->retired->id, 'Smuggled retired')
        ->set('slugs.'.$this->retired->id, 'smuggled-retired')
        ->set('names.'.$nonexistentId, 'Smuggled ghost')
        ->set('descriptions.'.$nonexistentId, '<p>ghost</p>')
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTabsPersistedLanguageIds($product))->toBe([$this->spanish->id]);
});

test('an engaged active language is persisted alongside a smuggled inactive one without the inactive row', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);
    $expected = collect([$this->spanish->id, $this->french->id])->sort()->values()->all();

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Souliers')
        ->set('names.'.$this->retired->id, 'Smuggled retired')
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTabsPersistedLanguageIds($product))->toBe($expected);
});

test('a non-string value in any translatable array for an active non-default language raises no TypeError and writes no row for it', function (string $property) {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, 'Still saves')
        ->set($property.'.'.$this->french->id, ['x'])
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTabsPersistedLanguageIds($product))->toBe([$this->spanish->id])
        ->and(ProductTranslation::query()->where('product_id', $product->id)->where('store_language_id', $this->spanish->id)->value('name'))->toBe('Still saves');
})->with(['names', 'descriptions', 'slugs', 'metaTitles', 'metaDescriptions']);

test('a non-string title counts as blank, so an engaged tab refuses it on the title (D-4)', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, ['x'])
        ->set('metaTitles.'.$this->french->id, 'Engaged')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);

    expect(editorTabsPersistedLanguageIds($product))->toBe([$this->spanish->id]);
});

test('prefillSlug with a non-string title raises no TypeError and stores no slug', function () {
    $product = editorTabsProduct([$this->spanish->id => editorTabsFullRow('es')]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, ['x'])
        ->call('prefillSlug', $this->french->id)
        ->assertSet('slugs.'.$this->french->id, '');
});
