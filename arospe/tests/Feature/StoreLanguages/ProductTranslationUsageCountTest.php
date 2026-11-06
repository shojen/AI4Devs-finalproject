<?php

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;

// Story 0076 (D-19, 0068 D8): product_translations is registered in config/store-languages.php, so
// the removal warning counts product content. Uses the SHIPPED registry, never a stand-in.

test('the shipped registry carries the product_translations entry', function () {
    expect(config('store-languages.translation_relations'))
        ->toContain(['table' => 'product_translations', 'column' => 'store_language_id']);
});

test('the registry holds only plain data, so it survives config:cache', function () {
    $config = config('store-languages');
    $hasClosure = false;

    array_walk_recursive($config, function ($value) use (&$hasClosure): void {
        $hasClosure = $hasClosure || $value instanceof Closure;
    });

    expect($config)->not->toBeEmpty()->and($hasClosure)->toBeFalse();
});

test('translationUsageCount counts the French product translations and only those', function () {
    $default = StoreLanguage::factory()->default()->create();
    $fr = StoreLanguage::factory()->create();
    $productA = Product::factory()->withoutTranslations()->create();
    $productB = Product::factory()->withoutTranslations()->create();

    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $productA->id]);
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $productB->id]);
    ProductTranslation::factory()->forLanguage($default)->create(['product_id' => $productA->id]);

    expect(StoreLanguage::translationUsageCount($fr->id))->toBe(2)
        ->and(StoreLanguage::translationUsageCount($default->id))->toBe(1);
});
