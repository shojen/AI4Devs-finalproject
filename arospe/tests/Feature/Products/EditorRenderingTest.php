<?php

// Option-set and embedded-component markup guards for App\Livewire\Products\Editor /
// resources/views/livewire/products/editor.blade.php, per
// ai-spec/tasks/in-progress/0027-products-list-and-editor-ui.md's "Tests to perform" section.
// WRITTEN AGAINST THE ORIGINAL (pre-0077) contract: ONE wysiwyg-editor bound to `description`,
// not N per-language panels.
//
// Written at TDD Phase 3 step 1 (red), before the real component/view exist.
//
// The three embedded-component assertions (distinct wire:keys, literal select-event names, the
// searchable-multi-select's option-resolver/field) read the compiled BLADE VIEW SOURCE directly
// via file_get_contents(), not the rendered HTML output -- a Livewire child component's own
// attributes (select-event, option-resolver, wire:key) are consumed by the child's mount/render
// and are NOT preserved as literal text in the parent's rendered output. This is the only way to
// pin an "exact static attribute" regression guard for a Livewire component TAG, and it fails red
// right now for the correct reason: the view file does not exist yet.

use App\Livewire\Products\Editor;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function productsEditorRenderingActor(array $permissions = ['products.view', 'products.create', 'products.edit', 'media.view']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

function productsEditorViewSource(): string
{
    $path = resource_path('views/livewire/products/editor.blade.php');

    return file_exists($path) ? (string) file_get_contents($path) : '';
}

// =====================================================================
// Status control (D-6)
// =====================================================================

test('the status selects option set is exactly ProductStatus::cases()', function () {
    $actor = productsEditorRenderingActor();
    $this->actingAs($actor);

    $html = Livewire::test(Editor::class)->html();

    expect($html)->toContain(__('products.statuses.active'))
        ->and($html)->toContain(__('products.statuses.draft'));

    // Regression guard against anyone feeding it ProductDisplayStatus::cases() instead.
    expect(mb_strtolower($html))->not->toContain('agotado')
        ->and($html)->not->toContain(__('products.display_statuses.out_of_stock'));
});

// =====================================================================
// Type control (D-5)
// =====================================================================

test('the type control renders a disabled placeholder with value empty and no real option pre-selected', function () {
    $actor = productsEditorRenderingActor();
    $this->actingAs($actor);

    $html = Livewire::test(Editor::class)->html();

    // D-5/D-16: the placeholder must be disabled AND selected, with value="".
    expect($html)->toMatch('/<option[^>]*value=""[^>]*disabled[^>]*>/')
        ->toMatch('/<option[^>]*value=""[^>]*selected[^>]*>|<option[^>]*selected[^>]*value=""[^>]*>/');

    // Neither real option carries `selected` on a fresh create form.
    expect($html)->not->toMatch('/<option[^>]*value="physical"[^>]*selected/')
        ->not->toMatch('/<option[^>]*value="virtual"[^>]*selected/');
});

// =====================================================================
// Category control
// =====================================================================

test('the category select is fed from real product_categories rows, ordered by name', function () {
    $actor = productsEditorRenderingActor();
    $this->actingAs($actor);

    // Story 0070 (D-15): `name` no longer lives on `product_categories` -- the factory's
    // `named()` state writes it into the default store language's translation row instead.
    ProductCategory::factory()->named('Zapatos')->create();
    ProductCategory::factory()->named('Abrigos')->create();

    $html = Livewire::test(Editor::class)->html();

    $posAbrigos = mb_strpos($html, 'Abrigos');
    $posZapatos = mb_strpos($html, 'Zapatos');

    expect($posAbrigos)->not->toBeFalse()
        ->and($posZapatos)->not->toBeFalse()
        ->and($posAbrigos)->toBeLessThan($posZapatos);
});

// =====================================================================
// Embedded components -- exact static attributes (D-8, D-10)
// =====================================================================

test('the two direct gallery embeds carry distinct wire keys and the literal select-event names', function () {
    $source = productsEditorViewSource();

    expect($source)->not->toBe('');

    expect($source)->toContain('wire:key="featured-image-gallery"')
        ->and($source)->toContain('wire:key="product-gallery-picker"')
        ->and($source)->toContain('select-event="featured-image-selected"')
        ->and($source)->toContain('select-event="product-images-added"');
});

test('each language panel binds its own wysiwyg editor to its own descriptions entry', function () {
    $source = productsEditorViewSource();

    expect($source)->not->toBe('');
    expect($source)->toContain('components.wysiwyg-editor')
        ->and($source)->toContain('wire:model="descriptions.')
        ->and($source)->not->toContain('wire:model="description"');
});

test('the searchable multi-select is bound to regionIds with SearchSalesRegions as its option resolver', function () {
    $source = productsEditorViewSource();

    expect($source)->not->toBe('');
    expect($source)->toContain('components.searchable-multi-select')
        ->and($source)->toContain('SearchSalesRegions::class')
        ->and($source)->toContain('field="regionIds"')
        ->and($source)->toContain('wire:model="regionIds"');
});

test('both direct gallery embeds sit inside a can viewAny Media branch, and the page still renders without it', function () {
    $source = productsEditorViewSource();

    expect($source)->not->toBe('');
    expect($source)->toContain("@can('viewAny', \\App\\Models\\Media::class)");

    $actorWithoutMediaView = User::factory()->create();
    $actorWithoutMediaView->givePermissionTo(['products.view', 'products.create']);
    $this->actingAs($actorWithoutMediaView);

    Livewire::test(Editor::class)->assertOk();
});

// =====================================================================
// Lossy-sanitization notice (D-13)
// =====================================================================

test('the description field carries the static lossy-sanitization notice', function () {
    $actor = productsEditorRenderingActor();
    $this->actingAs($actor);

    $html = Livewire::test(Editor::class)->html();

    expect($html)->toContain('data-test="description-sanitization-notice"');
});

// =====================================================================
// No default store language at all (story 0070, D-15)
// =====================================================================

// Phase 5 review round 1, finding 3(d): categoryOptions()'s own docblock promises this fallback
// (translated()/withTranslationsFor() never throw with no default language) but it was only ever
// proved at the model layer -- never through this screen's own Livewire component.
// withoutTranslations() skips provisioning a default store language, so store_languages stays
// genuinely empty (this file's beforeEach never creates one either).
test('with no default store language at all, the editor still renders without throwing', function () {
    $actor = productsEditorRenderingActor();
    $this->actingAs($actor);

    ProductCategory::factory()->withoutTranslations()->create();

    expect(StoreLanguage::query()->count())->toBe(0);

    Livewire::test(Editor::class)
        ->assertOk()
        ->assertSee('—');
});

// =====================================================================
// Story 0077: language panels (D-1, D-11, D-14)
// =====================================================================

function productsEditorXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    return new DOMXPath($document);
}

function productsEditorHookCount(DOMXPath $xpath, string $hook, string $scope = ''): int
{
    return $xpath->query($scope.'//*[@data-test="'.$hook.'"]')->length;
}

/**
 * Three store languages (default first by tab order) and a product translated into each.
 *
 * @return array{0: Product, 1: list<StoreLanguage>}
 */
function productsEditorThreeLanguageProduct(): array
{
    $default = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $second = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $third = StoreLanguage::factory()->create(['name' => 'Zzz third']);

    $product = Product::factory()->withoutTranslations()->create();

    foreach ([$default, $second, $third] as $language) {
        ProductTranslation::factory()->forLanguage($language)->create([
            'product_id' => $product->id,
            'name' => 'Name '.$language->id,
        ]);
    }

    return [$product, [$default, $second, $third]];
}

test('every active language panel is in the DOM at once, never swapped by an if branch', function () {
    $this->actingAs(productsEditorRenderingActor());
    [$product, $languages] = productsEditorThreeLanguageProduct();

    $xpath = productsEditorXPath(Livewire::test(Editor::class, ['product' => $product])->html());

    foreach ($languages as $language) {
        expect(productsEditorHookCount($xpath, 'language-panel-'.$language->id))->toBe(1);
    }

    expect($xpath->query('//*[starts-with(@data-test, "language-panel-")]')->length)->toBe(count($languages));
});

test('each translatable hook sits inside its own language panel exactly once', function () {
    $this->actingAs(productsEditorRenderingActor());
    [$product, $languages] = productsEditorThreeLanguageProduct();

    $xpath = productsEditorXPath(Livewire::test(Editor::class, ['product' => $product])->html());

    foreach ($languages as $language) {
        $panel = '//*[@data-test="language-panel-'.$language->id.'"]';

        foreach (['product-name-input', 'product-slug-input', 'product-meta-title-input', 'product-meta-description-input', 'product-description-editor'] as $hook) {
            expect(productsEditorHookCount($xpath, $hook.'-'.$language->id, $panel))->toBe(1)
                ->and(productsEditorHookCount($xpath, $hook.'-'.$language->id))->toBe(1);
        }
    }
});

test('the shared fields render exactly once and outside every language panel', function () {
    $this->actingAs(productsEditorRenderingActor());
    [$product] = productsEditorThreeLanguageProduct();

    $xpath = productsEditorXPath(Livewire::test(Editor::class, ['product' => $product])->html());

    foreach (['product-sku-input', 'product-price-input', 'product-stock-input', 'product-status-select', 'product-type-select', 'product-category-select'] as $hook) {
        expect(productsEditorHookCount($xpath, $hook))->toBe(1)
            ->and($xpath->query('//*[starts-with(@data-test, "language-panel-")]//*[@data-test="'.$hook.'"]')->length)->toBe(0);
    }
});

test('the error marker appears only on the tab of a language that carries an error', function () {
    $this->actingAs(productsEditorRenderingActor());
    [$product, $languages] = productsEditorThreeLanguageProduct();

    $cleanXpath = productsEditorXPath(Livewire::test(Editor::class, ['product' => $product])->html());

    expect($cleanXpath->query('//*[starts-with(@data-test, "language-tab-error-")]')->length)->toBe(0);

    $failed = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$languages[1]->id, '')
        ->set('slugs.'.$languages[1]->id, 'a-slug')
        ->call('save')
        ->assertHasErrors(['names.'.$languages[1]->id]);
    $failedXpath = productsEditorXPath($failed->html());

    expect(productsEditorHookCount($failedXpath, 'language-tab-error-'.$languages[1]->id))->toBe(1)
        ->and($failedXpath->query('//*[starts-with(@data-test, "language-tab-error-")]')->length)->toBe(1);
});

test('an untranslated language panel shows its untranslated notice and a translated one does not', function () {
    $this->actingAs(productsEditorRenderingActor());
    $default = StoreLanguage::factory()->default()->create();
    $second = StoreLanguage::factory()->create();
    $product = Product::factory()->withoutTranslations()->create();
    ProductTranslation::factory()->forLanguage($default)->create(['product_id' => $product->id]);

    $xpath = productsEditorXPath(Livewire::test(Editor::class, ['product' => $product])->html());

    expect(productsEditorHookCount($xpath, 'language-untranslated-'.$second->id, '//*[@data-test="language-panel-'.$second->id.'"]'))->toBe(1)
        ->and(productsEditorHookCount($xpath, 'language-untranslated-'.$default->id))->toBe(0);
});

test('a create-only actor sees a lock notice on non-default panels and none on the default one', function () {
    $this->actingAs(productsEditorRenderingActor(['products.view', 'products.create']));
    $default = StoreLanguage::factory()->default()->create();
    $second = StoreLanguage::factory()->create();

    $xpath = productsEditorXPath(Livewire::test(Editor::class)->html());

    expect(productsEditorHookCount($xpath, 'language-translation-locked-'.$second->id, '//*[@data-test="language-panel-'.$second->id.'"]'))->toBe(1)
        ->and(productsEditorHookCount($xpath, 'language-translation-locked-'.$default->id))->toBe(0);
});

test('a create-only actor gets a disabled wysiwyg on non-default panels and an enabled one on the default panel', function () {
    $this->actingAs(productsEditorRenderingActor(['products.view', 'products.create']));
    $default = StoreLanguage::factory()->default()->create();
    $second = StoreLanguage::factory()->create();

    $xpath = productsEditorXPath(Livewire::test(Editor::class)->html());
    $boldButton = fn (string $languageId, string $extra): int => $xpath->query(
        '//*[@data-test="product-description-editor-'.$languageId.'"]//*[@data-test="wysiwyg-bold"]'.$extra
    )->length;

    expect($boldButton($second->id, ''))->toBe(1)
        ->and($boldButton($second->id, '[@disabled]'))->toBe(1)
        ->and($boldButton($default->id, ''))->toBe(1)
        ->and($boldButton($default->id, '[@disabled]'))->toBe(0);
});

test('an actor who may also edit sees no lock notice on a create', function () {
    $this->actingAs(productsEditorRenderingActor(['products.view', 'products.create', 'products.edit']));
    StoreLanguage::factory()->default()->create();
    $second = StoreLanguage::factory()->create();

    $xpath = productsEditorXPath(Livewire::test(Editor::class)->html());

    expect(productsEditorHookCount($xpath, 'language-translation-locked-'.$second->id))->toBe(0);
});
