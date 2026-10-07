<?php

// Pest 4 browser tests for the language tabs of the blog category modal (route
// blog-categories.index, GET /blog/categories), per
// ai-spec/tasks/in-progress/0073-blog-categories-language-tabs-ui.md's "Browser --
// LanguageTabsTest.php" section. Written at TDD Phase 3 step 1 (red), before the tabbed modal exists.
//
// Only what a real browser alone can prove lives here: that wire:model delivers text typed into a
// non-default tab, that the x-show markup keeps hidden panels mounted and keeps typed text across
// switches, that a real click on a tab header actually reveals its panel (a compiled wire:click
// that silently no-ops is invisible to Livewire::test()), and that a refused save lands the user
// on the offending tab and does not leak past Cancel. Persistence rules, validation matrices and
// authorization are tests/Feature/Blog/BlogCategoryLanguageTabs*Test.php's.
//
// SELECTORS: every tab, panel and field is reached through its data-test hook, keyed by language
// id, via Pest's "@" shorthand -- never by language name or code. Tab switching is a server round
// trip, so a bounded ->wait(1) follows each tab click and each save (see
// docs/testing/frontend/playwright-setup/waiting-rules.md); ->waitForEvent('networkidle') is banned
// in this repo.

use App\Models\BlogCategory;
use App\Models\BlogCategoryTranslation;
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
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'blog.delete']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, string>  $namesByLanguageId
 */
function blogLanguageTabsBrowserCategory(array $namesByLanguageId): BlogCategory
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

// Scenario: Unsaved text on a hidden tab survives switching tabs
test('typing into the French tab, switching away and back, keeps the typed text', function () {
    // A regression guard on the x-show markup: an expression matching the wrong id, or an @if that
    // tears the panel down, loses the text -- and neither is visible to Livewire::test().
    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@create-blog-category-button')
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-category-name-input-'.$this->french->id, 'Guides')
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-category-name-input-'.$this->french->id, 'Guides');
});

// Scenario: A real click on a tab shows that tab's panel
test('clicking a language tab reveals its panel and hides the previous one', function () {
    // The only level at which a compiled-wire:click no-op is detectable.
    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@create-blog-category-button')
        ->assertVisible('@language-panel-'.$this->spanish->id)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertMissing('@language-panel-'.$this->spanish->id);
});

// Scenario: A blog editor translates a category into an additional language
test('a real fill on a non-default tab followed by a real save persists that language', function () {
    $category = blogLanguageTabsBrowserCategory([$this->spanish->id => 'Guias']);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-category-'.$category->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-category-name-input-'.$this->french->id, 'Guides')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect(BlogCategoryTranslation::query()
        ->where('blog_category_id', $category->id)
        ->where('store_language_id', $this->french->id)
        ->value('name'))->toBe('Guides')
        ->and(BlogCategoryTranslation::query()
            ->where('blog_category_id', $category->id)
            ->where('store_language_id', $this->spanish->id)
            ->value('name'))->toBe('Guias');
});

// Scenario: A refusal on a hidden tab brings that tab into view
test('a save refused because of a hidden tab brings that tab into view and marks it', function () {
    blogLanguageTabsBrowserCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $target = blogLanguageTabsBrowserCategory([$this->spanish->id => 'Novedades']);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-category-'.$target->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-category-name-input-'.$this->french->id, 'Guides')
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
    blogLanguageTabsBrowserCategory([$this->spanish->id => 'Guias', $this->french->id => 'Guides']);
    $target = blogLanguageTabsBrowserCategory([$this->spanish->id => 'Novedades']);
    $other = blogLanguageTabsBrowserCategory([$this->spanish->id => 'Ofertas']);

    visit('/blog/categories')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-category-'.$target->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-category-name-input-'.$this->french->id, 'Guides')
        ->click('Save')
        ->wait(1)
        ->assertVisible('@language-name-error-'.$this->french->id)
        ->click('Cancel')
        ->wait(1)
        ->click('@edit-blog-category-'.$other->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertNotPresent('@language-name-error-'.$this->french->id)
        ->assertNotPresent('@language-tab-error-'.$this->french->id)
        ->assertValue('@blog-category-name-input-'.$this->spanish->id, 'Ofertas');
});
