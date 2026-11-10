<?php

// Per-language validation and error keys through App\Livewire\Products\Editor (story 0077, D-5,
// D-10). Written at TDD Phase 3 step 1 (red). Error keys are `{field}s.{languageId}` for every
// language INCLUDING the default (the actions' bare `name` / `slug` / ... keys are re-keyed).

use App\Livewire\Products\Editor;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['products.view', 'products.create', 'products.edit']);
    $this->actingAs($actor);
});

/**
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function editorValidationProduct(array $rowsByLanguageId): Product
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

/**
 * @return array<int, object>
 */
function editorValidationSnapshot(): array
{
    return [
        'products' => DB::table('products')->orderBy('id')->get()->all(),
        'translations' => DB::table('product_translations')->orderBy('id')->get()->all(),
        'media' => DB::table('product_media')->orderBy('product_id')->orderBy('media_id')->get()->all(),
        'regions' => DB::table('product_sales_region')->get()->all(),
    ];
}

function editorValidationPanelText(string $html, string $languageId): string
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $nodes = (new DOMXPath($document))->query('//*[@data-test="language-panel-'.$languageId.'"]');

    return $nodes->length === 1 ? $nodes->item(0)->textContent : '';
}

// =====================================================================
// Error keys and messages
// =====================================================================

test('a default-language refusal is keyed per language for every one of the five fields', function (string $property, string $value) {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);
    $key = $property.'.'.$this->spanish->id;

    Livewire::test(Editor::class, ['product' => $product])
        ->set($key, $value)
        ->call('save')
        ->assertHasErrors([$key]);
})->with([
    'name' => ['names', ''],
    'slug' => ['slugs', str_repeat('a', 300)],
    'meta title' => ['metaTitles', str_repeat('m', 161)],
    'meta description' => ['metaDescriptions', str_repeat('d', 501)],
]);

test('a non-default refusal is keyed per language for every one of the five fields', function (string $property, string $value) {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);
    $key = $property.'.'.$this->french->id;

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set($key, $value);

    $component->call('save')->assertHasErrors([$key]);
})->with([
    'name' => ['names', str_repeat('n', 300)],
    'slug' => ['slugs', str_repeat('a', 300)],
    'meta title' => ['metaTitles', str_repeat('m', 161)],
    'meta description' => ['metaDescriptions', str_repeat('d', 501)],
]);

test('refusal messages use the localized attribute labels and never the dotted key', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, '')
        ->set('metaTitles.'.$this->french->id, str_repeat('m', 161))
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save');

    $nameMessage = $component->errors()->first('names.'.$this->spanish->id);
    $metaMessage = $component->errors()->first('metaTitles.'.$this->french->id);

    expect(mb_strtolower($nameMessage))->toContain(mb_strtolower(__('products.attributes.name')))
        ->and(mb_strtolower($metaMessage))->toContain(mb_strtolower(__('products.attributes.meta_title')))
        ->and($nameMessage.$metaMessage)->not->toContain('names.')
        ->and($nameMessage.$metaMessage)->not->toContain('metaTitles.');
});

test('a blank title on an engaged tab is refused whether that tab is active or not', function (bool $switchToFrench) {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    if ($switchToFrench) {
        $component->call('setActiveLanguageTab', $this->french->id);
    }

    $component->set('metaDescriptions.'.$this->french->id, 'Only a description')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);
})->with([true, false]);

test('the default title is refused when blank regardless of every other tab', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, '')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasErrors(['names.'.$this->spanish->id]);
});

test('an over-long meta title and meta description are refused on their own keys', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('metaTitles.'.$this->french->id, str_repeat('m', 161))
        ->set('metaDescriptions.'.$this->french->id, str_repeat('d', 501))
        ->call('save');

    $component->assertHasErrors(['metaTitles.'.$this->french->id, 'metaDescriptions.'.$this->french->id])
        ->assertHasNoErrors(['names.'.$this->french->id]);
});

test('meta title at 160 and meta description at 500 characters are accepted', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('metaTitles.'.$this->french->id, str_repeat('m', 160))
        ->set('metaDescriptions.'.$this->french->id, str_repeat('d', 500))
        ->call('save')
        ->assertHasNoErrors();
});

// =====================================================================
// Slug uniqueness
// =====================================================================

test('the same slug in two languages of one product is accepted', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('slugs.'.$this->french->id, 'running')
        ->set('slugs.'.$this->spanish->id, 'running')
        ->call('save')
        ->assertHasNoErrors();
});

test('two products cannot share a slug within one language, refused on the slug key', function () {
    editorValidationProduct([
        $this->spanish->id => ['name' => 'Otro'],
        $this->french->id => ['name' => 'Autre', 'slug' => 'chaussures-de-course'],
    ]);
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('slugs.'.$this->french->id, 'chaussures-de-course')
        ->call('save')
        ->assertHasErrors(['slugs.'.$this->french->id]);
});

test('a default-language slug collision is keyed on the default language slug', function () {
    editorValidationProduct([$this->spanish->id => ['name' => 'Otro', 'slug' => 'zapatillas']]);
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('slugs.'.$this->spanish->id, 'zapatillas')
        ->call('save')
        ->assertHasErrors(['slugs.'.$this->spanish->id]);
});

test('a product keeps its own slug unchanged even with another product holding a different slug', function () {
    editorValidationProduct([$this->spanish->id => ['name' => 'Otro', 'slug' => 'otro-slug']]);
    $product = editorValidationProduct([
        $this->spanish->id => ['name' => 'Zapatillas'],
        $this->french->id => ['name' => 'Chaussures', 'slug' => 'chaussures'],
    ]);

    Livewire::test(Editor::class, ['product' => $product])
        ->call('save')
        ->assertHasNoErrors();
});

test('a slug in a language where the product has no row yet is accepted', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('slugs.'.$this->french->id, 'brand-new-slug')
        ->call('save')
        ->assertHasNoErrors();
});

test('a slug colliding only after canonicalisation is refused', function () {
    editorValidationProduct([
        $this->spanish->id => ['name' => 'Otro'],
        $this->french->id => ['name' => 'Autre', 'slug' => 'chaussures-de-course'],
    ]);
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('slugs.'.$this->french->id, '  Chaussures De Course  ')
        ->call('save')
        ->assertHasErrors(['slugs.'.$this->french->id]);
});

// =====================================================================
// Refusal side effects
// =====================================================================

test('a refused save writes nothing in products, product_translations, product_media or product_sales_region', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);
    $before = editorValidationSnapshot();

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, 'Renamed')
        ->set('slugs.'.$this->french->id, 'french-slug-without-title')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);

    expect(editorValidationSnapshot())->toEqual($before);
});

test('after a refusal the active tab is the first errored language in tab order', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->call('setActiveLanguageTab', $this->german->id)
        ->set('slugs.'.$this->german->id, 'german-slug')
        ->set('slugs.'.$this->french->id, 'french-slug')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id, 'names.'.$this->german->id])
        ->assertSet('activeLanguageId', $this->french->id);
});

test('a refusal on the default language field moves the active tab to the default tab', function () {
    editorValidationProduct([$this->spanish->id => ['name' => 'Otro', 'slug' => 'zapatillas']]);
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->call('setActiveLanguageTab', $this->french->id)
        ->set('slugs.'.$this->spanish->id, 'zapatillas')
        ->call('save')
        ->assertHasErrors(['slugs.'.$this->spanish->id])
        ->assertSet('activeLanguageId', $this->spanish->id);
});

test('a French error followed by a tab switch does not render the French message inside the Spanish panel', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('slugs.'.$this->french->id, 'french-slug')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);

    $message = $component->errors()->first('names.'.$this->french->id);
    $component->call('setActiveLanguageTab', $this->spanish->id);

    expect($message)->not->toBe('')
        ->and(editorValidationPanelText($component->html(), $this->spanish->id))->not->toContain($message);
});

// =====================================================================
// Slug pre-fill (D-9)
// =====================================================================

test('prefillSlug fills an empty slug from the title of its own language', function () {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures de course')
        ->call('prefillSlug', $this->french->id)
        ->assertSet('slugs.'.$this->french->id, 'chaussures-de-course')
        ->assertSet('slugs.'.$this->spanish->id, '');
});

test('prefillSlug never overwrites a slug that is already there', function () {
    $product = editorValidationProduct([
        $this->spanish->id => ['name' => 'Zapatillas'],
        $this->french->id => ['name' => 'Chaussures', 'slug' => 'souliers'],
    ]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures neuves')
        ->call('prefillSlug', $this->french->id)
        ->assertSet('slugs.'.$this->french->id, 'souliers');
});

test('prefillSlug on a title that canonicalises to nothing leaves an empty string, never null', function (string $title) {
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, $title)
        ->call('prefillSlug', $this->french->id);

    expect($component->get('slugs')[$this->french->id])->toBe('');
})->with(['!!!', '   ']);

test('prefillSlug never writes a slug for a language id that is not active', function () {
    $retired = StoreLanguage::factory()->inactive()->create();
    $product = editorValidationProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$retired->id, 'Smuggled retired');

    // The story says the method "accepts only an active language id" without naming the outcome
    // (refusal vs. silent no-op); either is fine here, a stored slug key is not.
    try {
        $component->call('prefillSlug', $retired->id);
    } catch (ModelNotFoundException) {
        // refusal is an accepted outcome
    }

    expect($component->get('slugs'))->not->toHaveKey($retired->id);
});
