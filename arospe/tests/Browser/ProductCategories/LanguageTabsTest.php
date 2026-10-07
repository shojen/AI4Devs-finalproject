<?php

// Pest 4 browser tests for the language tabs of the product category modal, per
// ai-spec/tasks/done/0071-product-categories-language-tabs-ui.md's "Browser --
// LanguageTabsTest.php" section. Written at TDD Phase 3 step 1 (red), before the tabbed modal
// exists.
//
// Only what a real browser alone can prove lives here: that wire:model delivers text typed into a
// non-default tab, that the x-show markup keeps hidden panels mounted and keeps typed text across
// switches, that a real click on a tab header actually reveals its panel (a compiled wire:click
// that silently no-ops is invisible to Livewire::test()), and that a refused save lands the user
// on the offending tab. Persistence rules, validation matrices and authorization are
// tests/Feature/ProductCategories/LanguageTabs*Test.php's.
//
// SELECTORS: every tab, panel and field is reached through its data-test hook, keyed by language
// id, via Pest's "@" shorthand -- never by language name or code (D-11). Tab switching is a
// server round trip, so a bounded ->wait(1) follows each tab click and each save; the repo's own
// SalesRegionsIndexTest.php records why ->waitForEvent('networkidle') is not used (it does not
// settle reliably in this environment).

use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['products.view', 'products.create', 'products.edit', 'products.delete']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, string>  $namesByLanguageId
 */
function languageTabsBrowserCategory(array $namesByLanguageId): ProductCategory
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

// Scenario: Unsaved text on a hidden tab survives switching tabs
test('typing into the French tab, switching away and back, keeps the typed text', function () {
    // A regression guard on the x-show markup: an expression matching the wrong id, or an @if that
    // tears the panel down, loses the text -- and neither is visible to Livewire::test().
    visit('/product-categories')
        ->assertNoJavaScriptErrors()
        ->click('New category')
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@language-name-input-'.$this->french->id, 'Chaussures')
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertValue('@language-name-input-'.$this->french->id, 'Chaussures');
});

// Scenario: Every language panel stays mounted, hidden rather than removed
test('an inactive language panel is still in the document, merely hidden', function () {
    visit('/product-categories')
        ->assertNoJavaScriptErrors()
        ->click('New category')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->spanish->id)
        ->assertPresent('@language-panel-'.$this->french->id)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->assertNoJavaScriptErrors();
});

// Scenario: A real click on a tab shows that tab's panel
test('clicking a language tab reveals its panel and hides the previous one', function () {
    // The only level at which a compiled-wire:click no-op (D-8) is detectable.
    visit('/product-categories')
        ->assertNoJavaScriptErrors()
        ->click('New category')
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertMissing('@language-panel-'.$this->spanish->id);
});

// Scenario: A catalog administrator translates a category into an additional language
test('a real fill on a non-default tab followed by a real save persists that language', function () {
    $category = languageTabsBrowserCategory([$this->spanish->id => 'Calzado']);

    visit('/product-categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-product-category-'.$category->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@language-name-input-'.$this->french->id, 'Chaussures')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect(ProductCategoryTranslation::query()
        ->where('product_category_id', $category->id)
        ->where('store_language_id', $this->french->id)
        ->value('name'))->toBe('Chaussures')
        ->and(ProductCategoryTranslation::query()
            ->where('product_category_id', $category->id)
            ->where('store_language_id', $this->spanish->id)
            ->value('name'))->toBe('Calzado');
});

// Scenario: A refusal on a hidden tab brings that tab into view
test('a save refused because of a hidden tab brings that tab into view and marks it', function () {
    languageTabsBrowserCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);
    $target = languageTabsBrowserCategory([$this->spanish->id => 'Botas']);

    visit('/product-categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-product-category-'.$target->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@language-name-input-'.$this->french->id, 'Chaussures')
        // Look away, so the refusal belongs to a tab nobody is viewing when Save is pressed.
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertVisible('@language-name-error-'.$this->french->id)
        ->assertVisible('@language-tab-error-'.$this->french->id);
});

// Scenario: A refusal does not survive closing the modal
test('a refused save error does not survive cancelling and reopening against another category', function () {
    // The resetValidation() regression story 0018 shipped, now across N error keys.
    languageTabsBrowserCategory([$this->spanish->id => 'Calzado', $this->french->id => 'Chaussures']);
    $target = languageTabsBrowserCategory([$this->spanish->id => 'Botas']);
    $other = languageTabsBrowserCategory([$this->spanish->id => 'Gorros']);

    visit('/product-categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-product-category-'.$target->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@language-name-input-'.$this->french->id, 'Chaussures')
        ->click('Save')
        ->wait(1)
        ->assertVisible('@language-name-error-'.$this->french->id)
        ->click('Cancel')
        ->wait(1)
        ->click('@edit-product-category-'.$other->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertNotPresent('@language-name-error-'.$this->french->id)
        ->assertNotPresent('@language-tab-error-'.$this->french->id)
        ->assertValue('@language-name-input-'.$this->spanish->id, 'Gorros');
});
