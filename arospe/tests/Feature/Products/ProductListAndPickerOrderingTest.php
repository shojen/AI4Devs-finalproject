<?php

use App\Livewire\Orders\Show;
use App\Livewire\Products\Editor;
use App\Livewire\Products\Index;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Story 0076 (D-14, D-20): the existing readers of the moved columns keep their behaviour against
// the translation table -- the products list, its delete modal, the order product picker and the
// product editor's mount.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
    StoreLanguage::flushDefaultStoreLanguage();
});

function p76ActAs(array $permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);
    test()->actingAs($actor);

    return $actor;
}

// --- Products\Index ------------------------------------------------------------------------

test('the products list shows and orders products by their default-language title', function () {
    p76ActAs(['products.view']);
    Product::factory()->named('Botas')->create();
    Product::factory()->named('Abrigo')->create();

    $names = collect(Livewire::test(Index::class)->get('products')->items())->pluck('name')->all();

    expect($names)->toBe(['Abrigo', 'Botas']);
});

test('the list orders by the default-language title even when a French row would sort differently', function () {
    p76ActAs(['products.view']);
    $fr = StoreLanguage::factory()->create(['code' => 'fr']);
    $botas = Product::factory()->named('Botas')->create();
    Product::factory()->named('Abrigo')->create();
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $botas->id, 'name' => 'Aaa']);

    $names = collect(Livewire::test(Index::class)->get('products')->items())->pluck('name')->all();

    expect($names)->toBe(['Abrigo', 'Botas']);
});

test('a product with no default-language title is listed with a placeholder and raises no error', function () {
    p76ActAs(['products.view']);
    $fr = StoreLanguage::factory()->create(['code' => 'fr']);
    $product = Product::factory()->withoutTranslations()->create();
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $product->id, 'name' => 'Seulement francais']);

    $items = collect(Livewire::test(Index::class)->get('products')->items());

    expect($items)->toHaveCount(1)
        ->and($items[0]['name'])->toBe('—');
});

test('two products sharing a title never reshuffle between pages', function () {
    p76ActAs(['products.view']);
    $products = Product::factory()->count(30)->create();

    foreach ($products as $product) {
        DB::table('product_translations')->where('product_id', $product->id)->update(['name' => 'Camiseta']);
    }

    $component = Livewire::test(Index::class);
    $pageOne = collect($component->get('products')->items())->pluck('id')->all();
    $pageTwo = collect($component->call('gotoPage', 2)->get('products')->items())->pluck('id')->all();

    expect([...$pageOne, ...$pageTwo])->toBe($products->pluck('id')->sort()->values()->all());
});

test('the list issues the same number of product queries for few and for many products and never selects description', function () {
    p76ActAs(['products.view']);

    $measure = function (): array {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        Livewire::test(Index::class)->get('products');

        return collect($queries)->filter(fn (string $sql) => str_contains($sql, 'products') || str_contains($sql, 'product_translations'))->values()->all();
    };

    Product::factory()->count(3)->create();
    $few = $measure();

    Product::factory()->count(10)->create();
    $many = $measure();

    expect($many)->toHaveCount(count($few));

    foreach ($many as $sql) {
        expect($sql)->not->toContain('description');
    }
});

test('confirmDelete() names the product by its default-language title, or the placeholder when it has none', function () {
    p76ActAs(['products.view', 'products.delete']);
    $named = Product::factory()->named('Runner Pro')->create();
    $nameless = Product::factory()->withoutTranslations()->create();

    Livewire::test(Index::class)
        ->call('confirmDelete', $named->id)
        ->assertSet('deletingProductName', 'Runner Pro');

    Livewire::test(Index::class)
        ->call('confirmDelete', $nameless->id)
        ->assertSet('deletingProductName', '—');
});

// --- Orders\Show::productOptions() ---------------------------------------------------------

test('the picker returns the first PRODUCT_PICKER_LIMIT products in translated-name order, not an arbitrary window sorted afterwards', function () {
    p76ActAs(['orders.view', 'orders.edit']);
    $total = Show::PRODUCT_PICKER_LIMIT + 5;

    // Created in DESCENDING name order, so the first rows by id are the alphabetically LAST ones: a
    // PHP-side sort after the SQL limit would return a different set.
    for ($i = $total; $i >= 1; $i--) {
        Product::factory()->active()->named(sprintf('P%03d', $i))->create();
    }

    $order = Order::factory()->withItems(1)->create();
    $names = collect(Livewire::test(Show::class, ['order' => $order])->instance()->productOptions())->pluck('name')->all();

    $expected = [];
    for ($i = 1; $i <= Show::PRODUCT_PICKER_LIMIT; $i++) {
        $expected[] = sprintf('P%03d', $i);
    }

    expect($names)->toBe($expected);
});

test('the picker lists each product once with its id, default-language name and sku', function () {
    p76ActAs(['orders.view', 'orders.edit']);
    $fr = StoreLanguage::factory()->create(['code' => 'fr']);
    $product = Product::factory()->active()->named('Zeta Active')->create();
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $product->id, 'name' => 'Alpha FR']);

    $order = Order::factory()->withItems(1)->create();
    $options = Livewire::test(Show::class, ['order' => $order])->instance()->productOptions();

    expect($options)->toBe([['id' => $product->id, 'name' => 'Zeta Active', 'sku' => $product->sku]]);
});

// --- Products\Editor::mount() --------------------------------------------------------------

test('the editor opens a product with the raw default-language name and description', function () {
    p76ActAs(['products.view', 'products.edit']);
    $product = Product::factory()->named('Zapatillas Running', '<p>Ligeras</p>')->create();

    Livewire::test(Editor::class, ['product' => $product])
        ->assertSet('names.'.StoreLanguage::defaultStoreLanguage()->id, 'Zapatillas Running')
        ->assertSet('descriptions.'.StoreLanguage::defaultStoreLanguage()->id, '<p>Ligeras</p>');
});

test('a product whose only translation is French opens with an empty name, never the French one', function () {
    p76ActAs(['products.view', 'products.edit']);
    $fr = StoreLanguage::factory()->create(['code' => 'fr']);
    $product = Product::factory()->withoutTranslations()->create();
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $product->id, 'name' => 'Chaussures', 'description' => '<p>Legeres</p>']);

    Livewire::test(Editor::class, ['product' => $product])
        ->assertSet('names.'.StoreLanguage::defaultStoreLanguage()->id, '')
        ->assertSet('descriptions.'.StoreLanguage::defaultStoreLanguage()->id, '');
});
