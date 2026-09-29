<?php

use App\Actions\Translations\SetTranslation;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;

// Story 0070 (D-9, D-12), Phase 3 TDD "red" step: App\Actions\Translations\SetTranslation does not
// exist yet -- every test below is expected to fail (class not found) until backend-expert
// implements it in app/Actions/Translations/, a new cross-cutting-concern folder (D-8).
//
// Every case calls the primitive DIRECTLY (`app(SetTranslation::class)(...)`), never through a
// caller action -- SetTranslation deliberately neither authorizes (D-9) nor validates, by design,
// and has no caller in this story at all (D-12): CreateProductCategory/RenameProductCategory only
// ever write the DEFAULT language, so this file is SetTranslation's only test coverage. Guarded,
// validating authoring of a non-default language is story 0071's (D-17) -- not asserted here.

beforeEach(function () {
    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
    $this->category = ProductCategory::factory()->withoutTranslations()->create();
});

test('storing a translation in an additional language leaves both rows intact, each with its own value', function () {
    ProductCategoryTranslation::factory()->forLanguage($this->spanish)->create([
        'product_category_id' => $this->category->id,
        'name' => 'Calzado',
    ]);

    app(SetTranslation::class)($this->category, $this->french, ['name' => 'Chaussures']);

    expect(
        ProductCategoryTranslation::query()
            ->where('product_category_id', $this->category->id)
            ->where('store_language_id', $this->spanish->id)
            ->value('name')
    )->toBe('Calzado')
        ->and(
            ProductCategoryTranslation::query()
                ->where('product_category_id', $this->category->id)
                ->where('store_language_id', $this->french->id)
                ->value('name')
        )->toBe('Chaussures')
        ->and(ProductCategoryTranslation::where('product_category_id', $this->category->id)->count())->toBe(2);
});

// This is what makes a re-translation REPLACE rather than duplicate -- asserted as both the new
// value and a row count of one, not either alone.
test('storing a translation twice for the same language replaces it, rather than duplicating it', function () {
    app(SetTranslation::class)($this->category, $this->french, ['name' => 'Chaussures']);
    app(SetTranslation::class)($this->category, $this->french, ['name' => 'Souliers']);

    expect(
        ProductCategoryTranslation::query()
            ->where('product_category_id', $this->category->id)
            ->where('store_language_id', $this->french->id)
            ->count()
    )->toBe(1)
        ->and(
            ProductCategoryTranslation::query()
                ->where('product_category_id', $this->category->id)
                ->where('store_language_id', $this->french->id)
                ->value('name')
        )->toBe('Souliers');
});

test('SetTranslation returns the persisted translation model', function () {
    $result = app(SetTranslation::class)($this->category, $this->french, ['name' => 'Chaussures']);

    expect($result)->toBeInstanceOf(ProductCategoryTranslation::class)
        ->and($result->name)->toBe('Chaussures')
        ->and($result->store_language_id)->toBe($this->french->id)
        ->and($result->product_category_id)->toBe($this->category->id);
});
