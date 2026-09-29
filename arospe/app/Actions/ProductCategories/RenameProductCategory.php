<?php

namespace App\Actions\ProductCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class RenameProductCategory
{
    use ProductCategoryValidationRules;

    /**
     * Constructor injection for the same reason as CreateProductCategory:
     * __invoke()'s two domain arguments are this action's whole public
     * signature.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateProductCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Rename an existing product category, writing its name into the store DEFAULT language only
     * (story 0070, D-12) -- guarded, per-language authoring of a NON-default language is story
     * 0071's SetProductCategoryTranslation.
     *
     * Authorizes `update` on `$productCategory` as its own first statement
     * (story 0025), the identical self-authorizing shape
     * App\Actions\Products\UpdateProduct already uses -- see
     * CreateProductCategory's docblock for the full reasoning.
     *
     * The default store language is resolved AFTER authorizing but BEFORE validating -- see
     * CreateProductCategory's identical reasoning (D-6c).
     *
     * The uniqueness rule ignores the target's own translation row (R-1), which is what makes
     * saving a category under its own unchanged name succeed.
     */
    public function __invoke(ProductCategory $productCategory, string $name): ProductCategory
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $productCategory,
            targetType: 'product_category',
            targetId: $productCategory->id,
        );

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot rename a product category: no default store language is configured.',
        );

        $name = trim($name);

        Validator::make(
            ['name' => $name],
            $this->productCategoryRules($this->normalizeForSearch, $defaultLanguage->id, $productCategory->id),
        )->validate();

        try {
            ($this->setTranslation)($productCategory, $defaultLanguage, ['name' => $name]);
        } catch (QueryException $e) {
            // Last-word race guard behind the validation rule above -- see
            // CreateProductCategory's identical catch and D-4/D-7/R-2.
            throw ($this->translateNameUniqueViolation)($e);
        }

        return $productCategory;
    }
}
