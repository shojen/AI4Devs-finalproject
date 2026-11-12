<?php

// Pest 4 browser tests for the language tabs of App\Livewire\Products\Editor, per
// ai-spec/tasks/in-progress/0077-product-editor-language-tabs-ui.md's "Browser --
// EditorLanguageTabsTest.php" section (B-1 .. B-6; D-13: the mirrored tests/Browser/Products/
// folder). Written at TDD Phase 3 step 1 (red), before the tabbed editor exists.
//
// Only what a real browser alone can prove lives here: a compiled wire:click on a tab, the
// wire:ignore'd rich-text region of each language, text typed inside the WYSIWYG's 400 ms debounce
// surviving a tab switch, a refusal on a hidden tab becoming visible, and the real blur that
// triggers the slug pre-fill. Persistence, validation and authorization are the Feature tests'.
//
// SELECTORS: every tab, panel and field is reached through its data-test hook keyed by language id
// (D-11), never by language name or code. Tab switching and blur handlers are server round trips,
// so a bounded ->wait(1) follows each; ->waitForEvent('networkidle') is not used (it does not
// settle reliably here -- see tests/Browser/SalesRegionsIndexTest.php).

use App\Models\Product;
use App\Models\ProductTranslation;
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
    $actor->givePermissionTo(['products.view', 'products.create', 'products.edit', 'media.view']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function editorTabsBrowserProduct(array $rowsByLanguageId): Product
{
    $product = Product::factory()->withoutTranslations()->create();

    foreach ($rowsByLanguageId as $languageId => $attributes) {
        ProductTranslation::factory()->create(array_merge([
            'product_id' => $product->id,
            'store_language_id' => $languageId,
            'description' => null,
            'slug' => null,
            'meta_title' => null,
            'meta_description' => null,
        ], $attributes));
    }

    return $product;
}

function editorTabsBrowserDescriptionRegion(string $languageId): string
{
    return '[data-test="product-description-editor-'.$languageId.'"] [data-test="wysiwyg-editor-region"]';
}

/**
 * Types into a language's rich-text region the way a keyboard would: focus, then insertText, which
 * fires the same `input` event the editor's debounce listens to.
 */
function editorTabsBrowserTypeScript(string $regionSelector, string $text): string
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

// B-1 -- Scenario: Switching tabs shows the target language's content
test('a real click on a language tab reveals its panel and hides the previous one', function () {
    $product = editorTabsBrowserProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    visit(route('products.edit', $product))
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->spanish->id)
        ->assertMissing('@language-panel-'.$this->french->id)
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@language-panel-'.$this->french->id)
        ->assertMissing('@language-panel-'.$this->spanish->id);
});

// B-2 -- Scenario: Switching tabs shows the target language's description
test('the rich-text region of the French tab shows the French description and not the Spanish one', function () {
    $product = editorTabsBrowserProduct([
        $this->spanish->id => ['name' => 'Zapatillas', 'description' => '<p>Descripcion solo en espanol</p>'],
        $this->french->id => ['name' => 'Chaussures', 'description' => '<p>Description seulement en francais</p>'],
    ]);

    visit(route('products.edit', $product))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->assertSeeIn(editorTabsBrowserDescriptionRegion($this->french->id), 'Description seulement en francais')
        ->assertDontSeeIn(editorTabsBrowserDescriptionRegion($this->french->id), 'Descripcion solo en espanol')
        ->assertNoJavaScriptErrors();
});

// B-3 -- Scenario: Switching tabs keeps unsaved work in the tab being left
test('an unsaved Spanish title survives switching to French and back, read off the DOM', function () {
    $product = editorTabsBrowserProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    visit(route('products.edit', $product))
        ->assertNoJavaScriptErrors()
        ->fill('@product-name-input-'.$this->spanish->id, 'Zapatillas editadas sin guardar')
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertValue('@product-name-input-'.$this->spanish->id, 'Zapatillas editadas sin guardar');
});

// B-4 -- Scenario: Switching tabs keeps a description typed moments before
test('text typed into the Spanish description survives a switch made inside the 400 ms debounce', function () {
    $product = editorTabsBrowserProduct([
        $this->spanish->id => ['name' => 'Zapatillas', 'description' => '<p>Base</p>'],
    ]);

    $page = visit(route('products.edit', $product))->assertNoJavaScriptErrors();

    $page->script(editorTabsBrowserTypeScript(editorTabsBrowserDescriptionRegion($this->spanish->id), ' tecleado ahora'));

    // Switched immediately: the WYSIWYG debounces its write-back by 400 ms (0021 D9), so this click
    // lands inside that window. The bounded waits only let the two round trips settle.
    $page->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->click('@language-tab-'.$this->spanish->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSeeIn(editorTabsBrowserDescriptionRegion($this->spanish->id), 'tecleado ahora');
});

// B-5 -- Scenario: A refusal in a language the administrator is not looking at is surfaced
test('a refusal on a non-active tab is visible after Save without any further click', function () {
    $product = editorTabsBrowserProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    visit(route('products.edit', $product))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@product-slug-input-'.$this->french->id, 'slug-sin-titulo')
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

// B-6 -- Scenario: The slug pre-fill fires on a real blur and never overwrites a slug
test('leaving the French title pre-fills an empty French slug and a later title change leaves it alone', function () {
    $product = editorTabsBrowserProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    visit(route('products.edit', $product))
        ->assertNoJavaScriptErrors()
        ->click('@language-tab-'.$this->french->id)
        ->wait(1)
        ->fill('@product-name-input-'.$this->french->id, 'Chaussures de course')
        ->click('@product-meta-title-input-'.$this->french->id)
        ->wait(1)
        ->assertValue('@product-slug-input-'.$this->french->id, 'chaussures-de-course')
        ->fill('@product-name-input-'.$this->french->id, 'Souliers legers')
        ->click('@product-meta-title-input-'.$this->french->id)
        ->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertValue('@product-slug-input-'.$this->french->id, 'chaussures-de-course');
});
