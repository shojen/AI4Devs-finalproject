<?php

use App\Actions\NormalizeForSearch;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;

// Story 0070 (D-7, D-17), Phase 3 TDD "red" step: App\Concerns\ProductCategoryValidationRules's
// widened three-parameter signature -- productCategoryRules(NormalizeForSearch, string
// $storeLanguageId, ?string $productCategoryId = null) -- does not exist yet. The trait's CURRENT
// methods take only (NormalizeForSearch, ?string), so every call below is expected to fail with an
// arguments-count TypeError until backend-expert widens the signature. That is the correct,
// intended "red" outcome.
//
// Feature, not Unit: uniqueNormalisedName() reads product_category_translations. Every case here
// passes a NON-default $storeLanguageId (French, with Spanish as the store default) -- per D-17,
// this story ships no guarded write path for a non-default language, so no test here claims
// anything about rows WRITTEN; it proves only the RULE, called directly through Validator::make(),
// exactly as tests/Unit/Concerns/ProductCategoryValidationRulesTest.php does for the unwidened
// trait today. Guarded non-default authoring (blank refusal, "no row written", `products.edit`
// refusal) is story 0071's SetProductCategoryTranslationTest.

function productCategoryNameRulesPerLanguageHarness(): object
{
    return new class
    {
        use ProductCategoryValidationRules;

        /**
         * @return array<string, array<int, mixed>>
         */
        public function exposedProductCategoryRules(NormalizeForSearch $normalizeForSearch, string $storeLanguageId, ?string $productCategoryId = null): array
        {
            return $this->productCategoryRules($normalizeForSearch, $storeLanguageId, $productCategoryId);
        }
    };
}

function validateProductCategoryNamePerLanguage(string $name, string $storeLanguageId, ?string $ignoreCategoryId = null): ValidatorContract
{
    $harness = productCategoryNameRulesPerLanguageHarness();

    return Validator::make(
        ['name' => $name],
        $harness->exposedProductCategoryRules(app(NormalizeForSearch::class), $storeLanguageId, $ignoreCategoryId),
    );
}

beforeEach(function () {
    $this->spanish = StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();
});

test('two categories with the same normalised name within the same store language fail validation', function () {
    $other = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $other->id,
        'name' => 'Chaussures',
    ]);

    $validator = validateProductCategoryNamePerLanguage('Chaussures', $this->french->id);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

// The one test that proves the scope moved from GLOBAL to PER-LANGUAGE uniqueness: the identical
// string, byte-for-byte, already exists in Spanish (the default) and is checked here in French.
test('the identical name already used in a different store language passes validation', function () {
    $other = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($this->spanish)->create([
        'product_category_id' => $other->id,
        'name' => 'Chaussures',
    ]);

    $validator = validateProductCategoryNamePerLanguage('Chaussures', $this->french->id);

    expect($validator->passes())->toBeTrue();
});

test('a category\'s own name, re-checked in the same language with its own id excluded, passes rather than refused as a duplicate', function () {
    $category = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $category->id,
        'name' => 'Chaussures',
    ]);

    $validator = validateProductCategoryNamePerLanguage('Chaussures', $this->french->id, $category->id);

    expect($validator->passes())->toBeTrue();
});

// Proves the comparison still routes through the shared NormalizeForSearch fold, now that it is
// scoped per language rather than global.
test('a case-only and accent-only duplicate within one language still collides', function () {
    $other = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $other->id,
        'name' => 'Nino',
    ]);

    $validator = validateProductCategoryNamePerLanguage('Niño', $this->french->id);

    expect($validator->fails())->toBeTrue();
});

test('the same accent-folded pair in different store languages does not collide', function () {
    $other = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($this->spanish)->create([
        'product_category_id' => $other->id,
        'name' => 'Nino',
    ]);

    $validator = validateProductCategoryNamePerLanguage('Niño', $this->french->id);

    expect($validator->passes())->toBeTrue();
});

test('an empty name fails on name for a non-default store language', function () {
    $validator = validateProductCategoryNamePerLanguage('', $this->french->id);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

test('a 256-character name fails on name for a non-default store language', function () {
    $validator = validateProductCategoryNamePerLanguage(str_repeat('a', 256), $this->french->id);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});
