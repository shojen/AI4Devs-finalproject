<?php

// View-level rendering tests for App\Livewire\ProductCategories\Index /
// resources/views/livewire/product-categories.blade.php, per
// ai-spec/tasks/in-progress/0025-product-categories-ui.md's "Tests to perform" section, WRITTEN
// AGAINST THE ORIGINAL (pre-0070/0071) design -- a single `public string $name` field, no
// language tabs. Every "⚠️ Correction, 2026-08-30" block in the story file describes 0070/0071's
// later contract and is deliberately not applied here.
//
// Written at TDD Phase 3 step 1 (red), before the real component/view existed. Component logic,
// persistence, validation-rule enforcement and both authorization layers are covered by
// tests/Feature/ProductCategories/IndexTest.php -- nothing here duplicates that. Every test below
// asserts against the RENDERED HTML (assertSee/assertDontSee/->html()), which that file never
// does directly (its own trans_choice() assertSee() calls are the one exception, kept there
// because they are inseparable from the "surfaces an error" assertion they sit beside).
//
// Mirrors tests/Feature/SalesRegions/IndexRenderingTest.php's shape. The two hooks named
// explicitly in the story's own Acceptance Criteria -- data-test="edit-product-category-{id}" /
// data-test="delete-product-category-{id}" -- are authoritative and used as-is; the empty-state
// hook below is this file's own contract, now satisfied by the shipped Blade view.

use App\Actions\ProductCategories\CreateProductCategory;
use App\Livewire\ProductCategories\Index;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    // Story 0070: CreateProductCategory now writes the default-language translation, so every
    // fixture category set up below needs a default store language to write into.
    StoreLanguage::factory()->default()->create();
});

/**
 * @param  array<int, string>  $permissions
 */
function productCategoriesIndexRenderingActor(array $permissions = ['products.view', 'products.create', 'products.edit', 'products.delete']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

test('the list renders each categorys name and its product count', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    $category = app(CreateProductCategory::class)('Footwear');
    Product::factory()->count(4)->create(['product_category_id' => $category->id]);

    Livewire::test(Index::class)
        ->assertSee('Footwear')
        ->assertSee('4');
});

test('the empty state renders when the catalog holds no categories', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    expect(ProductCategory::count())->toBe(0);

    $html = Livewire::test(Index::class)->html();

    // This file's own contract for the not-yet-built markup: a data-test hook, so the empty
    // state is selectable without depending on a specific translated string.
    expect($html)->toContain('data-test="product-categories-empty-state"');
});

test('the create and edit modal contains exactly one text input and no select markup', function () {
    // A cheap guard against a stray element copy-pasted in from the Users view, which has both
    // a role AND a status <select>. This story's modal has a single name field and no <select>
    // anywhere (per the story's own "runtime traps" section, trap 4).
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect(substr_count($html, '<input'))->toBe(1)
        ->and($html)->not->toContain('<select');
});

test('the blocked-delete message renders in the DOM with the correct singular digit', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    $category = app(CreateProductCategory::class)('Calzado');
    Product::factory()->create(['product_category_id' => $category->id]);

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteProductCategory')
        ->assertSee(trans_choice('products.categories.delete_blocked', 1, ['count' => 1]));
});

test('the blocked-delete message renders in the DOM with the correct plural digit', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    $category = app(CreateProductCategory::class)('Calzado');
    Product::factory()->count(12)->create(['product_category_id' => $category->id]);

    Livewire::test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteProductCategory')
        ->assertSee(trans_choice('products.categories.delete_blocked', 12, ['count' => 12]));
});

test('a validation message appears next to the name field and the modal stays open', function () {
    // A test asserting only assertHasErrors() never proves the human actually sees the
    // sentence -- this asserts the rendered message text AND that the modal is still open.
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', '')
        ->call('save')
        ->assertSee(__('validation.required', ['attribute' => 'name']))
        ->assertSet('showModal', true);
});

// D-9's second pin, alongside ArchitectureTest.php's namespace-scope arch() rule: the screen
// shows only product categories, with no link or reference to any blog taxonomy. Scoped to the
// component's own ->html(), never the full page -- the shared sidebar may legitimately gain a
// "Blog" entry once Epic 4 lands, which has nothing to do with this screen.
test('the product category screen references no blog taxonomy', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    app(CreateProductCategory::class)('Footwear');

    Livewire::test(Index::class)->assertDontSee('blog');
});

// Phase 4 audit finding N-3: closeModal() must clear the 'name' validation error, or a refused
// save's inline message leaks into the next time the create/edit modal opens -- Livewire persists
// the error bag across round trips, and this modal's flux:input renders the 'name' error whenever
// one is present in the bag, regardless of which category (or none) the modal is now open for.
test('closing the modal after a refused save clears the stale validation error', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors('name')
        ->call('closeModal')
        ->call('openCreateModal')
        ->assertHasNoErrors();
});

// Phase 5 review round 1, finding 3(a): Index::loadProductCategories()'s own docblock promises a
// category with no default-language translation renders the em dash "—" and sorts last, rather
// than throwing -- untested until now. withoutTranslations() skips writing any translation row
// for the category (but this file's own beforeEach above already provisioned the default store
// language, so `translated('name')` resolves against a real default id and legitimately finds
// nothing, rather than hitting the separate "no default language at all" branch covered below).
test('a category with no default-language translation renders an em dash instead of throwing', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    ProductCategory::factory()->withoutTranslations()->create();

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('—');
});

// Phase 5 review round 1, finding 3(d): D-15's "no default store language at all" fallback
// (App\Concerns\HasTranslations::translated()/scopeWithTranslationsFor(), which never throw) was
// only ever proved at the model layer -- never through this screen's own Livewire component.
// withoutTranslations() also skips provisioning a default store language, so deleting the one
// this file's beforeEach created above leaves store_languages genuinely empty.
test('with no default store language at all, the list still renders without throwing', function () {
    $actor = productCategoriesIndexRenderingActor();
    $this->actingAs($actor);

    StoreLanguage::query()->delete();
    StoreLanguage::flushDefaultStoreLanguage();
    ProductCategory::factory()->withoutTranslations()->create();

    expect(StoreLanguage::query()->count())->toBe(0);

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('—');
});
