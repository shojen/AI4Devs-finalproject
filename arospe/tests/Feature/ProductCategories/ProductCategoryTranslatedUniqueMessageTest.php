<?php

use App\Actions\NormalizeForSearch;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;

// Story 0071 (Q-4), Phase 3 TDD "red" step. The story files this case under
// tests/Unit/Concerns, but that directory is deliberately
// DB-free and un-refreshed (tests/Pest.php) while a duplicate refusal needs a real
// product_category_translations row -- so the case lives here, in tests/Feature, beside the sibling
// per-language rules test.
//
// Red reason today: uniqueNormalisedName() calls $fail(trans('validation.unique', ['attribute' =>
// $attribute])), which bakes the RAW attribute ("names.{id}") into the message and bypasses the
// caller's custom attributes. Q-4 changes it to $fail('validation.unique')->translate().

function productCategoryTranslatedMessageHarness(): object
{
    return new class
    {
        use ProductCategoryValidationRules;

        /**
         * @return array<int, mixed>
         */
        public function exposedNameRules(NormalizeForSearch $normalizeForSearch, string $storeLanguageId): array
        {
            return $this->nameRules($normalizeForSearch, $storeLanguageId);
        }
    };
}

/**
 * Validates "Chaussures" under either the flat `name` key or the nested `names.{id}` key.
 *
 * @param  array<string, string>  $attributes
 */
function duplicateNameValidator(string $key, StoreLanguage $language, array $attributes = []): ValidatorContract
{
    $data = $key === 'name' ? ['name' => 'Chaussures'] : ['names' => [$language->id => 'Chaussures']];

    return Validator::make(
        $data,
        [$key => productCategoryTranslatedMessageHarness()->exposedNameRules(app(NormalizeForSearch::class), $language->id)],
        [],
        $attributes,
    );
}

beforeEach(function () {
    StoreLanguage::factory()->default()->create();
    $this->french = StoreLanguage::factory()->create();

    $other = ProductCategory::factory()->withoutTranslations()->create();
    ProductCategoryTranslation::factory()->forLanguage($this->french)->create([
        'product_category_id' => $other->id,
        'name' => 'Chaussures',
    ]);
});

test('a duplicate refused under the name key reads The name has already been taken in en', function () {
    app()->setLocale('en');

    $validator = duplicateNameValidator('name', $this->french);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('name'))->toBe('The name has already been taken.');
});

test('a duplicate refused under the name key reads El nombre ya está en uso in es', function () {
    app()->setLocale('es');

    $validator = duplicateNameValidator('name', $this->french);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('name'))->toBe('El nombre ya está en uso.');
});
