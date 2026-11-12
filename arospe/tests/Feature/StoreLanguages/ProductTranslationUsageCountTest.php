<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTranslation;
use App\Models\StoreLanguage;

// Story 0076 (D-19, 0068 D8): product_translations is registered in config/store-languages.php, so
// the removal warning counts product content. Uses the SHIPPED registry, never a stand-in.

test('translationUsageCount counts the French product translations and only those', function () {
    $default = StoreLanguage::factory()->default()->create();
    $fr = StoreLanguage::factory()->create();
    $category = ProductCategory::factory()->withoutTranslations()->create();
    $productA = Product::factory()->withoutTranslations()->create(['product_category_id' => $category->id]);
    $productB = Product::factory()->withoutTranslations()->create(['product_category_id' => $category->id]);

    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $productA->id]);
    ProductTranslation::factory()->forLanguage($fr)->create(['product_id' => $productB->id]);
    ProductTranslation::factory()->forLanguage($default)->create(['product_id' => $productA->id]);

    expect(StoreLanguage::translationUsageCount($fr->id))->toBe(2)
        ->and(StoreLanguage::translationUsageCount($default->id))->toBe(1);
});
