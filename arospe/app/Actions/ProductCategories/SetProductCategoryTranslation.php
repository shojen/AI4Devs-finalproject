<?php

namespace App\Actions\ProductCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\ProductCategoryTranslation;
use App\Models\StoreLanguage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Story 0071: the backend layer of the defence-in-depth decision (2026-08-30). Self-authorizing
 * (and refusal-logging) and self-validating wrapper over story 0070's deliberately unguarded
 * App\Actions\Translations\SetTranslation primitive, for one product category name in one
 * store language.
 *
 * Constructor injection for the same reason as RenameProductCategory: __invoke()'s three domain
 * arguments are this action's whole public signature.
 */
class SetProductCategoryTranslation
{
    use ProductCategoryValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateProductCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Authorize, validate and persist one product category's name in one store language.
     *
     * @throws AuthorizationException when the actor lacks products.edit (logged first)
     * @throws ValidationException keyed "names.{$language->id}" -- blank, over-length, or
     *                             duplicate within that store language (incl. the 1062 race)
     * @throws LogicException when the primitive returns an unexpected model
     */
    public function __invoke(
        ProductCategory $productCategory,
        StoreLanguage $language,
        string $name,
    ): ProductCategoryTranslation {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $productCategory,
            targetType: 'product_category',
            targetId: $productCategory->id,
        );

        $name = trim($name);
        $errorKey = "names.{$language->id}";
        $attributeLabel = __('products.categories.index.tabs.name_attribute');

        // Nested data with a dotted rule key: a flat ["names.{id}" => $name] data key is escaped
        // by Validator::parseData() and never reaches the rule.
        Validator::make(
            ['names' => [$language->id => $name]],
            [$errorKey => $this->nameRules($this->normalizeForSearch, $language->id, $productCategory->id)],
            [],
            ['names.*' => $attributeLabel],
        )->validate();

        try {
            $translation = ($this->setTranslation)($productCategory, $language, ['name' => $name]);
        } catch (QueryException $e) {
            throw ($this->translateNameUniqueViolation)($e, $errorKey, $attributeLabel);
        }

        if (! $translation instanceof ProductCategoryTranslation) {
            throw new LogicException('SetTranslation returned an unexpected model for a product category.');
        }

        return $translation;
    }
}
