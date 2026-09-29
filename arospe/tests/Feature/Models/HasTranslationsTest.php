<?php

use App\Actions\ProductCategories\CreateProductCategory;
use App\Actions\ProductCategories\RenameProductCategory;
use App\Actions\StoreLanguages\SetDefaultStoreLanguage;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Story 0070 (D-5, D-6, D-10, R-4, R-6), Phase 3 TDD "red" step: App\Concerns\HasTranslations does
// not exist yet, and App\Models\ProductCategory does not `use` it -- every test below that calls
// ->translated()/->withTranslationsFor() is expected to fail with an undefined-method error until
// backend-expert implements the trait and wires it into ProductCategory (plus, for the
// "no default store language" write-side cases, until CreateProductCategory/RenameProductCategory
// gain their own "no default language" refusal). That is the correct, intended "red" outcome.
//
// Feature, never Unit (per the story's own suite-placement rule, round-3 Phase 2 finding 2): every
// case here resolves the store default through App\Models\StoreLanguage::defaultStoreLanguage(),
// which queries store_languages, and tests/Pest.php binds RefreshDatabase only to Feature/Browser.
//
// Fixtures use ProductCategory::factory()->withoutTranslations() throughout (already shipped by
// database-expert) so every translation row in a test is arranged explicitly and visibly, rather
// than relying on the factory's own default-language auto-provisioning.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function hasTranslationsActor(array $permissions): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

// =====================================================================
// Fallback resolution
// =====================================================================

test('a translation present for the requested language is returned', function () {
    $spanish = StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($spanish)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);
    ProductCategoryTranslation::factory()->forLanguage($french)->create([
        'product_category_id' => $category->id,
        'name' => 'Chaussures',
    ]);

    expect($category->translated('name', $french->id))->toBe('Chaussures');
});

// The distinction that matters: a non-null assertion alone would pass even if the resolver
// silently picked the first translation alphabetically rather than the actual default.
test('a translation missing for the requested language falls back to the default language\'s exact value', function () {
    $spanish = StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($spanish)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);

    expect($category->translated('name', $french->id))->toBe('Calzado');
});

test('a category with no translation in any language resolves to null without throwing', function () {
    StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();

    expect(fn () => $category->translated('name', $french->id))->not->toThrow(Throwable::class)
        ->and($category->translated('name', $french->id))->toBeNull();
});

// D-5: fallback resolves per FIELD, not per row -- invisible on this pilot (a category has one
// field) and the whole point on 0076/0078. Fixture shape pinned by the story: real store-language
// rows (the default must resolve through the memo), but the two-field translations are unsaved
// model instances attached via setRelation() -- no DDL, no *_translations table with two columns.
test('fallback resolves per field independently, not per translation row', function () {
    $default = StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();

    $defaultTranslation = (new ProductCategoryTranslation)->forceFill([
        'store_language_id' => $default->id,
        'title' => 'Default Title',
        'description' => 'Default Description',
    ]);

    $frenchTranslation = (new ProductCategoryTranslation)->forceFill([
        'store_language_id' => $french->id,
        'title' => 'Titre Français',
        'description' => null,
    ]);

    $category->setRelation('translations', new Collection([$defaultTranslation, $frenchTranslation]));

    expect($category->translated('title', $french->id))->toBe('Titre Français')
        ->and($category->translated('description', $french->id))->toBe('Default Description');
});

test('a translation authored into a now-inactive store language still resolves when requested', function () {
    StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->inactive()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($french)->create([
        'product_category_id' => $category->id,
        'name' => 'Chaussures',
    ]);

    expect($category->translated('name', $french->id))->toBe('Chaussures');
});

// Combines both Gherkin scenarios ("re-points the fallback" / "resolves to nothing after a default
// change"): the fallback LEVEL moves to French, and since this category is translated only into
// the OLD default (Spanish), it has nothing at the new fallback level either.
test('changing the store default under a catalog translated only into the old default resolves to null afterward', function () {
    $spanish = StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($spanish)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);

    expect($category->translated('name'))->toBe('Calzado');

    $this->actingAs(hasTranslationsActor(['store-languages.view', 'store-languages.edit']));
    app(SetDefaultStoreLanguage::class)($french);

    expect($category->translated('name'))->toBeNull();
});

// =====================================================================
// No default store language at all (D-6c)
// =====================================================================

test('with no default store language row at all, translated() returns null without throwing', function () {
    $language = StoreLanguage::factory()->default()->create();
    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($language)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);

    // A raw query-builder update fires no `saved` event, so the memo must be flushed explicitly --
    // otherwise this test would silently pass through the stale default the factory provisioned.
    DB::table('store_languages')->update(['is_default' => false]);
    StoreLanguage::flushDefaultStoreLanguage();

    expect(fn () => $category->translated('name'))->not->toThrow(Throwable::class)
        ->and($category->translated('name'))->toBeNull();
});

test('with no default store language row at all, withTranslationsFor() renders an empty catalog without throwing', function () {
    $language = StoreLanguage::factory()->default()->create();
    ProductCategory::factory()->withoutTranslations()->create();

    DB::table('store_languages')->update(['is_default' => false]);
    StoreLanguage::flushDefaultStoreLanguage();

    expect(fn () => ProductCategory::query()->withTranslationsFor()->get())->not->toThrow(Throwable::class);
});

test('with no default store language row at all, CreateProductCategory refuses legibly and writes nothing', function () {
    $language = StoreLanguage::factory()->default()->create();

    DB::table('store_languages')->update(['is_default' => false]);
    StoreLanguage::flushDefaultStoreLanguage();

    $this->actingAs(hasTranslationsActor(['products.create']));

    $caught = null;
    try {
        app(CreateProductCategory::class)('Calzado');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and(ProductCategory::count())->toBe(0);
});

test('with no default store language row at all, RenameProductCategory refuses legibly and writes nothing', function () {
    $language = StoreLanguage::factory()->default()->create();
    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($language)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);

    DB::table('store_languages')->update(['is_default' => false]);
    StoreLanguage::flushDefaultStoreLanguage();

    $this->actingAs(hasTranslationsActor(['products.edit']));

    $caught = null;
    try {
        app(RenameProductCategory::class)($category, 'Zapatería');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and(ProductCategoryTranslation::where('product_category_id', $category->id)->value('name'))->toBe('Calzado');
});

// =====================================================================
// The default-language memo (D-10)
// =====================================================================

test('the default-language memo is flushed by StoreLanguage\'s own saved hook, so a promotion takes effect within the same request', function () {
    StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($french)->create([
        'product_category_id' => $category->id,
        'name' => 'Chaussures',
    ]);

    // Prime the memo against the OLD default (Spanish) before promoting -- without the `saved`
    // hook flush, translated() below would keep resolving through the stale Spanish default and
    // never find this category's only (French) translation.
    expect($category->translated('name'))->toBeNull();

    $this->actingAs(hasTranslationsActor(['store-languages.view', 'store-languages.edit']));
    app(SetDefaultStoreLanguage::class)($french);

    expect($category->translated('name'))->toBe('Chaussures');
});

test('a freshly created default language resolves correctly in this test, independent of any other test\'s default (isolation A)', function () {
    $default = StoreLanguage::factory()->default()->create();
    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($default)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);

    expect($category->translated('name'))->toBe('Calzado');
});

test('a different test\'s own default language never leaks into this one (isolation B)', function () {
    $default = StoreLanguage::factory()->default()->create();
    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($default)->create([
        'product_category_id' => $category->id,
        'name' => 'Bolsos',
    ]);

    expect($category->translated('name'))->toBe('Bolsos');
});

test('the "no default" answer is memoised across a whole list render, not re-queried per row', function () {
    $categories = ProductCategory::factory()->withoutTranslations()->count(3)->create();

    DB::enableQueryLog();

    $loaded = ProductCategory::query()->whereIn('id', $categories->pluck('id'))->withTranslationsFor()->get();
    foreach ($loaded as $category) {
        $category->translated('name');
    }

    $storeLanguageQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'store_languages'))
        ->count();

    DB::disableQueryLog();

    expect($storeLanguageQueries)->toBe(1);
});

// =====================================================================
// Query shape (R-4)
// =====================================================================

test('rendering N categories through withTranslationsFor() issues a bounded, N-independent number of queries', function () {
    $default = StoreLanguage::factory()->default()->create();

    $few = ProductCategory::factory()->withoutTranslations()->count(2)->create();
    $many = ProductCategory::factory()->withoutTranslations()->count(6)->create();

    foreach ($few->concat($many) as $category) {
        ProductCategoryTranslation::factory()->forLanguage($default)->create(['product_category_id' => $category->id]);
    }

    // Warm the default-language memo first, so both measurements below are apples-to-apples
    // (the very first call after a `saved` flush costs one extra query the memo absorbs after).
    StoreLanguage::defaultStoreLanguage();

    DB::enableQueryLog();
    ProductCategory::query()->whereIn('id', $few->pluck('id'))->withTranslationsFor()->get();
    $queriesForFew = count(DB::getQueryLog());
    DB::flushQueryLog();

    ProductCategory::query()->whereIn('id', $many->pluck('id'))->withTranslationsFor()->get();
    $queriesForMany = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesForFew)->toBe($queriesForMany)
        ->and($queriesForFew)->toBeLessThanOrEqual(2);
});

// R-4's subtle N+1 shape: $model->translations() (the relation METHOD) always re-queries;
// $model->translations (the property) reads the hydrated collection. They differ by one
// character and only the second respects eager loading.
test('translated() reads the already-loaded relation and issues no additional query per call', function () {
    $default = StoreLanguage::factory()->default()->create();
    $french = StoreLanguage::factory()->create();

    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($default)->create([
        'product_category_id' => $category->id,
        'name' => 'Calzado',
    ]);
    ProductCategoryTranslation::factory()->forLanguage($french)->create([
        'product_category_id' => $category->id,
        'name' => 'Chaussures',
    ]);

    $loaded = ProductCategory::query()->whereKey($category->id)->withTranslationsFor($french->id)->firstOrFail();

    DB::enableQueryLog();
    $loaded->translated('name');
    $loaded->translated('name', $french->id);
    $loaded->translated('name');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});
