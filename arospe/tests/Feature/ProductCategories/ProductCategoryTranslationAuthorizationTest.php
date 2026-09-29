<?php

use App\Actions\ProductCategories\CreateProductCategory;
use App\Actions\ProductCategories\RenameProductCategory;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0070 (D-9, D-13, D-17), Phase 3 TDD step: authorization for the two write actions this
// story ships, scoped to the DEFAULT store language only (D-12). Guarded authoring of a
// NON-default language -- its own authorization, blank refusal and "no row written" -- is story
// 0071's SetProductCategoryTranslationTest (D-17); not asserted here.
//
// Expected outcome per test, verified against the live tree before writing this file:
//   - The "without products.edit" case is expected to PASS already: RenameProductCategory already
//     self-authorizes as its own first statement (story 0025), so the AuthorizationException is
//     thrown before the (still column-based) write is ever attempted, and the fixture translation
//     row is arranged through the already-shipped ProductCategoryFactory::named() state.
//   - The other two cases are expected to fail RED today: RenameProductCategory/CreateProductCategory
//     still write to the dropped product_categories.name column, so both surface
//     SQLSTATE[42S22] (column not found) before reaching this file's own assertions. That is the
//     correct, intended "red" outcome until backend-expert migrates both actions onto the
//     translation mechanism.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    $this->defaultLanguage = StoreLanguage::factory()->default()->create();
});

function productCategoryTranslationAuthorizationActor(array $permissions): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('an actor without products.edit cannot rename a category, and its default-language name is unchanged', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();

    $this->actingAs(productCategoryTranslationAuthorizationActor([]));

    $caught = null;
    try {
        app(RenameProductCategory::class)($category, 'Zapatería');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AuthorizationException::class);

    expect(
        ProductCategoryTranslation::query()
            ->where('product_category_id', $category->id)
            ->where('store_language_id', $this->defaultLanguage->id)
            ->value('name')
    )->toBe('Calzado');
});

// Not vacuous: RenameProductCategory self-authorizes, so this test would fail the moment a
// `store-languages.*` check was ever added to that path (D-13 -- authoring content is not managing
// the language catalog).
test('an actor holding products.edit and zero store-languages permissions can still rename a category', function () {
    $category = ProductCategory::factory()->named('Calzado')->create();

    $this->actingAs(productCategoryTranslationAuthorizationActor(['products.edit']));

    app(RenameProductCategory::class)($category, 'Zapatería');

    expect(
        ProductCategoryTranslation::query()
            ->where('product_category_id', $category->id)
            ->where('store_language_id', $this->defaultLanguage->id)
            ->value('name')
    )->toBe('Zapatería');
});

// The exact bug D-9 exists to prevent: if SetTranslation ever self-authorized `update`, creating a
// category would wrongly require products.edit instead of products.create.
test('an actor holding products.create and not products.edit can create a category, including its default-language translation', function () {
    $this->actingAs(productCategoryTranslationAuthorizationActor(['products.create']));

    $category = app(CreateProductCategory::class)('Calzado');

    $translations = ProductCategoryTranslation::query()->where('product_category_id', $category->id)->get();

    expect($translations)->toHaveCount(1)
        ->and($translations->first()->store_language_id)->toBe($this->defaultLanguage->id)
        ->and($translations->first()->name)->toBe('Calzado');
});
