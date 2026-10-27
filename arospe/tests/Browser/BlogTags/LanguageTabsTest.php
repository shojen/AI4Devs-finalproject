<?php

// Pest 4 browser tests for the language tabs of the blog tag edit modal (route blog-tags.index,
// GET /blog/tags), story 0075.
//
// Only what a real browser alone can prove lives here: that wire:model on a dynamic array key
// delivers text typed into a hidden tab (R-5), that the x-show markup keeps hidden panels mounted
// and keeps typed text across switches, that a real click on a tab header actually reveals its
// panel, and that a refused save lands the editor on the offending tab. Persistence rules,
// validation matrices and authorization are tests/Feature/Blog/BlogTagLanguageTabs*Test.php's.
//
// SELECTORS: every tab, panel and field is reached through its data-test hook, keyed by language
// id, via Pest's "@" shorthand -- never by language name or code. Tab switching is a server round
// trip, so a bounded ->wait(1) follows each tab click and each save (see
// docs/testing/frontend/playwright-setup/waiting-rules.md); ->waitForEvent('networkidle') is banned
// in this repo.

use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
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

// Scenario: Unsaved text on a hidden tab survives switching tabs
test('typing into the French tab, switching away and back, keeps the typed text', function () {
    $tag = BlogTag::factory()->named('running')->create();

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-tag-name-input-'.$this->french->id, 'course à pied')
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-tag-name-input-'.$this->french->id, 'course à pied');
});

// Scenario: A real click on a tab shows that tab's panel
test('clicking a language tab reveals its panel and hides the previous one', function () {
    $tag = BlogTag::factory()->named('running')->create();

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->assertVisible('@language-panel-'.$this->spanish->id)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertMissing('@language-panel-'.$this->spanish->id)
        // An untranslated tab shows the default's name as guidance only, never as its value (D-2).
        ->assertValue('@blog-tag-name-input-'.$this->french->id, '');
});

// Scenario: A blog editor translates a tag into an additional language
test('a real fill on a non-default tab followed by a real save persists that language and re-opens with it', function () {
    $tag = BlogTag::factory()->named('running')->create();

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-tag-name-input-'.$this->french->id, 'course à pied')
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->wait(1)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertValue('@blog-tag-name-input-'.$this->french->id, 'course à pied');

    expect(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->where('store_language_id', $this->french->id)->value('name'))->toBe('course à pied')
        ->and(BlogTagTranslation::query()->where('blog_tag_id', $tag->id)->where('store_language_id', $this->spanish->id)->value('name'))->toBe('running');
});

// Scenario: A refusal on a hidden tab brings that tab forward
test('a save refused because of a hidden tab brings that tab into view and marks it', function () {
    $other = BlogTag::factory()->named('autre')->create();
    BlogTagTranslation::factory()->forLanguage($this->french)->create(['blog_tag_id' => $other->id, 'name' => 'trail']);
    $target = BlogTag::factory()->named('running')->create();

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$target->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-tag-name-input-'.$this->french->id, 'trail')
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

// Scenario: One continuous pass over the edit modal raises no JavaScript error
test('opening the edit modal, switching tabs, saving and cancelling raises no JavaScript error', function () {
    $tag = BlogTag::factory()->named('running')->create();

    visit('/blog/tags')
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->click('Save')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->click('@edit-blog-tag-'.$tag->id)
        ->wait(1)
        ->click('Cancel')
        ->wait(1)
        ->assertNoJavaScriptErrors();
});
