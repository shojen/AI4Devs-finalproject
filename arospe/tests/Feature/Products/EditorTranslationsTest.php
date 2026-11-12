<?php

// Save composition for App\Livewire\Products\Editor (story 0077, D-3/D-4/D-5/D-8): the default
// language is written through CreateProduct/UpdateProduct, every engaged non-default language
// through SetProductTranslation, in one transaction. Written at TDD Phase 3 step 1 (red).

use App\Actions\Products\SetProductTranslation;
use App\Actions\Products\SyncProductSalesRegions;
use App\Livewire\Products\Editor;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTranslation;
use App\Models\SalesRegion;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->spanish = StoreLanguage::factory()->default()->create(['name' => 'Mmm default']);
    $this->french = StoreLanguage::factory()->create(['name' => 'Aaa second']);
    $this->german = StoreLanguage::factory()->create(['name' => 'Zzz third']);
    $this->retired = StoreLanguage::factory()->inactive()->create(['name' => 'Qqq retired']);
});

/**
 * @param  array<int, string>  $permissions
 */
function editorTranslationsActor(array $permissions = ['products.view', 'products.create', 'products.edit']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/**
 * @param  array<string, array<string, string|null>>  $rowsByLanguageId
 */
function editorTranslationsProduct(array $rowsByLanguageId): Product
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
 * Fills the shared fields a create needs, leaving every translatable field to the test.
 */
function editorTranslationsFillSharedFields(mixed $component): mixed
{
    return $component
        ->set('sku', 'TRN-'.fake()->unique()->numerify('#####'))
        ->set('productCategoryId', ProductCategory::factory()->create()->id)
        ->set('type', 'physical')
        ->set('status', 'draft')
        ->set('price', '19.90')
        ->set('stock', '5');
}

function editorTranslationsRow(Product $product, StoreLanguage $language): ?object
{
    return DB::table('product_translations')
        ->where('product_id', $product->id)
        ->where('store_language_id', $language->id)
        ->first();
}

// =====================================================================
// Default vs non-default write split
// =====================================================================

test('translating a product into French stores a French row alongside the Spanish one', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTranslationsRow($product, $this->french)->name)->toBe('Chaussures')
        ->and(editorTranslationsRow($product, $this->spanish)->name)->toBe('Zapatillas');
});

test('saving with only the French title changed leaves the Spanish row exactly as it was', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([
        $this->spanish->id => ['name' => 'Zapatillas', 'slug' => 'zapatillas', 'meta_title' => 'Meta es'],
        $this->french->id => ['name' => 'Chaussures'],
    ]);
    $spanishBefore = editorTranslationsRow($product, $this->spanish);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Souliers')
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTranslationsRow($product, $this->french)->name)->toBe('Souliers')
        ->and(editorTranslationsRow($product, $this->spanish))->toEqual($spanishBefore);
});

test('the default language slug and SEO fields are written from the editor', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('slugs.'.$this->spanish->id, 'zapatillas-nuevas')
        ->set('metaTitles.'.$this->spanish->id, 'Titulo nuevo')
        ->set('metaDescriptions.'.$this->spanish->id, 'Descripcion nueva')
        ->call('save')
        ->assertHasNoErrors();

    $row = editorTranslationsRow($product, $this->spanish);

    expect($row->slug)->toBe('zapatillas-nuevas')
        ->and($row->meta_title)->toBe('Titulo nuevo')
        ->and($row->meta_description)->toBe('Descripcion nueva');
});

test('SetProductTranslation is called once per engaged non-default language and the default goes through the update action', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $this->mock(SetProductTranslation::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')->twice();
    });

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, 'Zapatillas Pro')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('names.'.$this->german->id, 'Schuhe')
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTranslationsRow($product, $this->spanish)->name)->toBe('Zapatillas Pro');
});

// =====================================================================
// The three blank-tab states (D-4)
// =====================================================================

test('an untouched non-default tab with no stored row writes no row and raises no error', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTranslationsRow($product, $this->french))->toBeNull()
        ->and(editorTranslationsRow($product, $this->german))->toBeNull();
});

test('an engaged non-default tab requires its title', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('slugs.'.$this->french->id, 'chaussures')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);

    expect(editorTranslationsRow($product, $this->french))->toBeNull();
});

test('a blanked tab that was previously translated is refused and its row stays intact', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([
        $this->spanish->id => ['name' => 'Zapatillas'],
        $this->french->id => ['name' => 'Chaussures', 'slug' => 'chaussures'],
    ]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, '')
        ->set('slugs.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);

    expect(editorTranslationsRow($product, $this->french)->name)->toBe('Chaussures');
});

test('a forged list of originally translated languages cannot turn a blanked tab into an untouched one', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([
        $this->spanish->id => ['name' => 'Zapatillas'],
        $this->french->id => ['name' => 'Chaussures'],
    ]);

    $component = Livewire::test(Editor::class, ['product' => $product]);

    expect(fn () => $component->set('originalTranslatedLanguageIds', []))->toThrow(CannotUpdateLockedPropertyException::class);

    $component->set('names.'.$this->french->id, '')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);

    expect(editorTranslationsRow($product, $this->french)->name)->toBe('Chaussures');
});

// =====================================================================
// Optional fields, sanitisation
// =====================================================================

test('blank optional fields reach the database as NULL and two products may both save without a French slug', function () {
    $this->actingAs(editorTranslationsActor());
    $first = editorTranslationsProduct([$this->spanish->id => ['name' => 'Uno']]);
    $second = editorTranslationsProduct([$this->spanish->id => ['name' => 'Dos']]);

    foreach ([$first, $second] as $product) {
        Livewire::test(Editor::class, ['product' => $product])
            ->set('names.'.$this->french->id, 'Titre '.$product->id)
            ->call('save')
            ->assertHasNoErrors();

        $row = editorTranslationsRow($product, $this->french);

        expect($row->slug)->toBeNull()
            ->and($row->meta_title)->toBeNull()
            ->and($row->meta_description)->toBeNull();
    }
});

test('a script tag in the French description is stored sanitised', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('descriptions.'.$this->french->id, '<p>Bonjour</p><script>alert(1)</script>')
        ->call('save')
        ->assertHasNoErrors();

    $stored = (string) editorTranslationsRow($product, $this->french)->description;

    expect($stored)->not->toContain('<script')->and($stored)->toContain('Bonjour');
});

test('a typed slug is stored canonical but stays as typed in the bound array, and a slug that canonicalises to nothing is stored NULL', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $component = Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('slugs.'.$this->french->id, '---')
        ->set('slugs.'.$this->spanish->id, '  Zapatillas Nuevas  ')
        ->call('save')
        ->assertHasNoErrors();

    expect(editorTranslationsRow($product, $this->french)->slug)->toBeNull()
        ->and(editorTranslationsRow($product, $this->spanish)->slug)->toBe('zapatillas-nuevas');
});

test('after a refusal elsewhere the typed slugs are still the typed strings, never null', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    Livewire::test(Editor::class, ['product' => $product])
        ->set('slugs.'.$this->french->id, '---')
        ->set('slugs.'.$this->spanish->id, '  Zapatillas Nuevas  ')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id])
        ->assertSet('slugs.'.$this->french->id, '---')
        ->assertSet('slugs.'.$this->spanish->id, '  Zapatillas Nuevas  ');
});

// =====================================================================
// One transaction (R-3)
// =====================================================================

test('a refusal from SetProductTranslation for a later language rolls back the default update, the region sync and earlier languages, and keeps typed values', function () {
    $this->actingAs(editorTranslationsActor());
    $region = SalesRegion::factory()->create();
    $newRegion = SalesRegion::factory()->create();
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);
    $product->salesRegions()->sync([$region->id]);

    $real = app(SetProductTranslation::class);
    $this->mock(SetProductTranslation::class, function (MockInterface $mock) use ($real): void {
        $mock->shouldReceive('__invoke')->andReturnUsing(function (Product $product, StoreLanguage $language, string $name, ...$rest) use ($real) {
            if ($language->id === $this->german->id) {
                throw ValidationException::withMessages(['slugs.'.$language->id => 'Simulated slug race.']);
            }

            return $real($product, $language, $name, ...$rest);
        });
    });

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->spanish->id, 'Renamed default')
        ->set('regionIds', [$region->id, $newRegion->id])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('names.'.$this->german->id, 'Schuhe')
        ->call('save')
        ->assertHasErrors(['slugs.'.$this->german->id])
        ->assertSet('names.'.$this->french->id, 'Chaussures')
        ->assertSet('names.'.$this->german->id, 'Schuhe');

    expect(editorTranslationsRow($product, $this->spanish)->name)->toBe('Zapatillas')
        ->and(editorTranslationsRow($product, $this->french))->toBeNull()
        ->and(editorTranslationsRow($product, $this->german))->toBeNull()
        ->and($product->fresh()->salesRegions->pluck('id')->all())->toBe([$region->id]);
});

test('the region sync is not reached when a later language is refused', function () {
    $this->actingAs(editorTranslationsActor());
    $product = editorTranslationsProduct([$this->spanish->id => ['name' => 'Zapatillas']]);

    $this->mock(SetProductTranslation::class, function (MockInterface $mock): void {
        $mock->shouldReceive('__invoke')->andThrow(ValidationException::withMessages(['names.'.$this->french->id => 'Refused.']));
    });
    $this->mock(SyncProductSalesRegions::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    Livewire::test(Editor::class, ['product' => $product])
        ->set('names.'.$this->french->id, 'Chaussures')
        ->call('save')
        ->assertHasErrors(['names.'.$this->french->id]);
});

// =====================================================================
// Create path (D-8)
// =====================================================================

test('creating a product filling only the default tab stores exactly one translation in the default language', function () {
    $this->actingAs(editorTranslationsActor(['products.view', 'products.create']));

    $component = Livewire::test(Editor::class)->set('names.'.$this->spanish->id, 'Zapatillas');
    editorTranslationsFillSharedFields($component)->call('save')->assertHasNoErrors();

    $product = Product::query()->sole();

    expect(DB::table('product_translations')->where('product_id', $product->id)->pluck('store_language_id')->all())
        ->toBe([$this->spanish->id]);
});

test('creating the default language writes its slug and SEO fields instead of null', function () {
    $this->actingAs(editorTranslationsActor(['products.view', 'products.create']));

    $component = Livewire::test(Editor::class)
        ->set('names.'.$this->spanish->id, 'Zapatillas')
        ->set('slugs.'.$this->spanish->id, 'zapatillas')
        ->set('metaTitles.'.$this->spanish->id, 'Meta es');
    editorTranslationsFillSharedFields($component)->call('save')->assertHasNoErrors();

    $row = editorTranslationsRow(Product::query()->sole(), $this->spanish);

    expect($row->slug)->toBe('zapatillas')->and($row->meta_title)->toBe('Meta es');
});

test('a holder of create and edit creates a product with a Spanish and a French translation', function () {
    $this->actingAs(editorTranslationsActor());

    $component = Livewire::test(Editor::class)
        ->set('names.'.$this->spanish->id, 'Zapatillas')
        ->set('names.'.$this->french->id, 'Chaussures');
    editorTranslationsFillSharedFields($component)->call('save')->assertHasNoErrors();

    $product = Product::query()->sole();

    expect(DB::table('product_translations')->where('product_id', $product->id)->pluck('store_language_id')->sort()->values()->all())
        ->toBe(collect([$this->spanish->id, $this->french->id])->sort()->values()->all());
});

test('a create-only holder with a non-blank French field is refused before validation, logged, and nothing is created', function () {
    $this->withoutExceptionHandling();
    Log::spy();
    $actor = editorTranslationsActor(['products.view', 'products.create']);
    $this->actingAs($actor);

    $this->mock(SetProductTranslation::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('__invoke');
    });

    $component = Livewire::test(Editor::class)
        ->set('names.'.$this->spanish->id, 'Zapatillas')
        ->set('names.'.$this->french->id, 'Chaussures');
    editorTranslationsFillSharedFields($component);

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id)
        ->once();

    expect(Product::count())->toBe(0)->and(DB::table('product_translations')->count())->toBe(0);
});

test('the create-only refusal fires before validation, so an invalid core field does not mask it', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(editorTranslationsActor(['products.view', 'products.create']));

    $component = Livewire::test(Editor::class)
        ->set('names.'.$this->spanish->id, 'Zapatillas')
        ->set('names.'.$this->french->id, 'Chaussures')
        ->set('price', 'not-a-price');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
});

test('a create-only holder with blank French fields creates normally', function () {
    $this->actingAs(editorTranslationsActor(['products.view', 'products.create']));

    $component = Livewire::test(Editor::class)->set('names.'.$this->spanish->id, 'Zapatillas');
    editorTranslationsFillSharedFields($component)->call('save')->assertHasNoErrors();

    expect(Product::count())->toBe(1);
});

test('a create-only holder whose payload names a deactivated language creates normally with no refusal logged and no row for it', function () {
    Log::spy();
    $this->actingAs(editorTranslationsActor(['products.view', 'products.create']));

    $component = Livewire::test(Editor::class)
        ->set('names.'.$this->spanish->id, 'Zapatillas')
        ->set('names.'.$this->retired->id, 'Smuggled retired')
        ->set('slugs.'.$this->retired->id, 'smuggled-retired');
    editorTranslationsFillSharedFields($component)->call('save')->assertHasNoErrors();

    $product = Product::query()->sole();

    Log::shouldNotHaveReceived('warning');
    expect(DB::table('product_translations')->where('product_id', $product->id)->pluck('store_language_id')->all())
        ->toBe([$this->spanish->id]);
});

test('the create-only editor exposes the locked-tab hint as a false translation-authoring flag', function () {
    $this->actingAs(editorTranslationsActor(['products.view', 'products.create']));

    Livewire::test(Editor::class)->assertSet('canAuthorTranslations', false);

    $this->actingAs(editorTranslationsActor());

    Livewire::test(Editor::class)->assertSet('canAuthorTranslations', true);
});
