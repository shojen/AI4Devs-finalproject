<?php

// Pest 4 browser tests for the language tabs of App\Livewire\BlogPosts\Editor, per
// ai-spec/tasks/in-progress/0079-blog-post-editor-language-tabs-ui.md's "Browser --
// EditorLanguageTabsTest.php" section (B-1 .. B-7, plus the Q-2(a) read-only slug display).
// Written at TDD Phase 3 step 1 (red), before the tabbed blog post editor exists.
//
// Only what a real browser alone can prove lives here: a compiled wire:click on a tab, the
// wire:ignore'd rich-text region of each language, text typed inside the WYSIWYG's 400 ms debounce
// surviving a tab switch, a refusal on a hidden tab becoming visible, and a real fill + Save on a
// non-default tab. Validation rules and authorization are the Feature tests'.
//
// SELECTORS: every tab, panel and field is reached through its data-test hook keyed by language id
// (D-15), never by language name or code. Tab switches and Save are server round trips, so a
// bounded ->wait(1) follows each; ->waitForEvent('networkidle') is not used.

use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
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
    $actor->givePermissionTo(['blog.view', 'blog.create', 'blog.edit', 'media.view']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function blogEditorTabsBrowserPost(array $rowsByLanguageId): BlogPost
{
    $post = BlogPost::factory()->withoutTranslations()->draft()->create();

    foreach ($rowsByLanguageId as $languageId => $attributes) {
        BlogPostTranslation::factory()->create(array_merge([
            'blog_post_id' => $post->id,
            'store_language_id' => $languageId,
            'body' => null,
        ], $attributes));
    }

    return $post;
}

function blogEditorTabsBrowserBodyRegion(string $languageId): string
{
    return '[data-test="blog-post-body-editor-'.$languageId.'"] [data-test="wysiwyg-editor-region"]';
}

/**
 * Types into a language's rich-text region the way a keyboard would: focus, then insertText, which
 * fires the same `input` event the editor's debounce listens to.
 */
function blogEditorTabsBrowserTypeScript(string $regionSelector, string $text): string
{
    $jsonText = json_encode($text);

    return <<<JS
        (function() {
            const region = document.querySelector('{$regionSelector}');
            region.focus();
            const range = document.createRange();
            range.selectNodeContents(region);
            range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('insertText', false, {$jsonText});
        })()
    JS;
}

// B-1 + B-2 -- Scenario: Switching tabs shows the target language's title and body, and only those
test('a real click on the French tab swaps the wire:ignore body to the French one and drops the default one', function () {
    $post = blogEditorTabsBrowserPost([
        $this->spanish->id => ['title' => 'Botas de invierno', 'body' => '<p>Cuerpo solo en espanol</p>'],
        $this->french->id => ['title' => 'Bottes d hiver', 'body' => '<p>Corps seulement en francais</p>'],
    ]);

    visit(route('blog-posts.edit', $post))
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->spanish->id)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertMissing('@language-panel-'.$this->spanish->id)
        ->assertSeeIn(blogEditorTabsBrowserBodyRegion($this->french->id), 'Corps seulement en francais')
        ->assertDontSeeIn(blogEditorTabsBrowserBodyRegion($this->french->id), 'Cuerpo solo en espanol')
        ->assertNoJavaScriptErrors();
});

// B-3 -- Scenario: A field absent in one language opens empty while its sibling opens filled
test('a tab with a title but no body renders an empty body region, never the default body', function () {
    $third = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $post = blogEditorTabsBrowserPost([
        $this->spanish->id => ['title' => 'Botas de invierno', 'body' => '<p>Cuerpo solo en espanol</p>'],
        $third->id => ['title' => 'Winter boots', 'body' => null],
    ]);

    visit(route('blog-posts.edit', $post))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$third->id)
        ->wait(1)
        ->assertValue('@blog-post-title-input-'.$third->id, 'Winter boots')
        ->assertDontSeeIn(blogEditorTabsBrowserBodyRegion($third->id), 'Cuerpo solo en espanol')
        ->assertNoJavaScriptErrors();
});

// B-4 -- Scenario: Unsaved text on a hidden tab survives switching tabs
test('an unsaved French title survives switching to Spanish and back, read off the DOM', function () {
    $post = blogEditorTabsBrowserPost([$this->spanish->id => ['title' => 'Botas de invierno']]);

    visit(route('blog-posts.edit', $post))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-post-title-input-'.$this->french->id, 'Bottes d hiver non enregistrees')
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertValue('@blog-post-title-input-'.$this->french->id, 'Bottes d hiver non enregistrees');
});

// B-5 -- Scenario: a body typed moments before a switch is not lost
test('text typed into the Spanish body survives a switch made inside the 400 ms debounce', function () {
    $post = blogEditorTabsBrowserPost([
        $this->spanish->id => ['title' => 'Botas de invierno', 'body' => '<p>Base</p>'],
    ]);

    $page = visit(route('blog-posts.edit', $post))->assertNoJavaScriptErrors();

    $page->script(blogEditorTabsBrowserTypeScript(blogEditorTabsBrowserBodyRegion($this->spanish->id), ' tecleado ahora'));

    // Switched immediately: the WYSIWYG debounces its write-back by 400 ms (0021 D9), so this click
    // lands inside that window. The bounded waits only let the two round trips settle.
    $page->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSeeIn(blogEditorTabsBrowserBodyRegion($this->spanish->id), 'tecleado ahora');
});

// B-6 -- Scenario: A refusal in a language the editor is not looking at is surfaced
test('a refusal on a non-active tab is visible after Save without any further click', function () {
    $post = blogEditorTabsBrowserPost([$this->spanish->id => ['title' => 'Botas de invierno']]);

    $page = visit(route('blog-posts.edit', $post))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1);

    // A French body with no French title is refused on the French title.
    $page->script(blogEditorTabsBrowserTypeScript(blogEditorTabsBrowserBodyRegion($this->french->id), 'Corps sans titre'));

    // Bounded wait: lets the WYSIWYG's 400 ms debounce (0021 D9) write the body back before the switch.
    $page->wait(1)
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->click('@blog-post-save')
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertVisible('@language-tab-error-'.$this->french->id);
});

// B-7 -- Scenario: A blog editor translates a post into an additional language
test('a real fill on the French tab followed by Save persists the French translation', function () {
    $post = blogEditorTabsBrowserPost([$this->spanish->id => ['title' => 'Botas de invierno', 'body' => '<p>Cuerpo</p>']]);

    $page = visit(route('blog-posts.edit', $post))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@blog-post-title-input-'.$this->french->id, 'Bottes d\'hiver');

    $page->script(blogEditorTabsBrowserTypeScript(blogEditorTabsBrowserBodyRegion($this->french->id), 'Corps en francais'));

    // Bounded wait: the WYSIWYG debounces its write-back by 400 ms (0021 D9) before Save may read it.
    $page->wait(1)
        ->click('@blog-post-save')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $french = BlogPostTranslation::query()
        ->where('blog_post_id', $post->id)
        ->where('store_language_id', $this->french->id)
        ->first();

    expect($french)->not->toBeNull()
        ->and($french->title)->toBe('Bottes d\'hiver')
        ->and($french->body)->toContain('Corps en francais');
});

// Q-2(a) -- Scenarios: a translated tab shows its saved slug read-only; an untranslated tab shows none;
// a just-typed title does not change it
test('the French tab shows its persisted slug as text, unchanged by typing, and an untranslated tab shows none', function () {
    $third = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $post = blogEditorTabsBrowserPost([
        $this->spanish->id => ['title' => 'Botas de invierno'],
        $this->french->id => ['title' => 'Bottes d hiver'],
    ]);

    visit(route('blog-posts.edit', $post))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertSeeIn('@blog-post-slug-'.$this->french->id, 'bottes-d-hiver')
        ->fill('@blog-post-title-input-'.$this->french->id, 'Chaussures de neige')
        ->wait(1)
        ->assertSeeIn('@blog-post-slug-'.$this->french->id, 'bottes-d-hiver')
        ->assertDontSeeIn('@blog-post-slug-'.$this->french->id, 'chaussures-de-neige')
        ->click('@language-tab-'.$third->id)
        ->wait(1)
        ->assertMissing('@blog-post-slug-'.$third->id)
        ->assertNoJavaScriptErrors();
});
