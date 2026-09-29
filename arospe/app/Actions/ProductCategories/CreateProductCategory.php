<?php

namespace App\Actions\ProductCategories;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Actions\NormalizeForSearch;
use App\Actions\Translations\SetTranslation;
use App\Concerns\ProductCategoryValidationRules;
use App\Models\ProductCategory;
use App\Models\StoreLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CreateProductCategory
{
    use ProductCategoryValidationRules;

    /**
     * Constructor injection, not method injection: __invoke()'s single
     * domain argument is this action's whole public signature, called that
     * way by every direct-call test -- so every collaborator is resolved
     * from the container without widening that signature. See
     * docs/conventions/code-style.md's constructor-injection exception.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private readonly NormalizeForSearch $normalizeForSearch,
        private readonly SetTranslation $setTranslation,
        private readonly TranslateProductCategoryNameUniqueViolation $translateNameUniqueViolation,
    ) {}

    /**
     * Create a new product category, writing its name into the store DEFAULT language only
     * (story 0070, D-12) -- guarded, per-language authoring of a NON-default language is story
     * 0071's SetProductCategoryTranslation.
     *
     * Authorizes `create` on `ProductCategory::class` as its own first
     * statement (story 0025, discharging the hand-off 0023 recorded --
     * see docs/database/schema-products/categories-and-products.md#product_categories and
     * docs/conventions/directory-structure.md#directory-structure) -- the
     * identical self-authorizing shape App\Actions\Products\CreateProduct
     * already uses, so a future Artisan command, queued job or REST
     * controller inherits the same refusal the dashboard gets.
     * `targetType: 'product_category'` is passed explicitly, since
     * LogRefusedPrivilegedAttempt::resolveTarget() auto-resolves only User
     * and Role instances/classes; there is no `targetId` yet, matching
     * CreateProduct's own `Product::class` create-time call.
     *
     * The default store language is resolved AFTER authorizing but BEFORE validating -- the
     * write-side fail-loud counterpart of App\Models\StoreLanguage::defaultStoreLanguage()'s
     * null-safe read side (D-6c): writing a "default-language" name with no default language
     * configured is meaningless, so this refuses legibly rather than validating against an id
     * that does not exist.
     *
     * The name is trimmed BEFORE validation, not after (R-6): Laravel's
     * `required` treats a string of spaces as present, so without this a
     * whitespace-only name would validate and persist.
     */
    public function __invoke(string $name): ProductCategory
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', ProductCategory::class, targetType: 'product_category');

        $defaultLanguage = StoreLanguage::defaultStoreLanguage();

        throw_if(
            $defaultLanguage === null,
            RuntimeException::class,
            'Cannot create a product category: no default store language is configured.',
        );

        $name = trim($name);

        Validator::make(
            ['name' => $name],
            $this->productCategoryRules($this->normalizeForSearch, $defaultLanguage->id),
        )->validate();

        try {
            return DB::transaction(function () use ($name, $defaultLanguage): ProductCategory {
                $productCategory = ProductCategory::create();

                ($this->setTranslation)($productCategory, $defaultLanguage, ['name' => $name]);

                return $productCategory;
            });
        } catch (QueryException $e) {
            // The unique index is the last-word RACE guard behind the normalised-comparison
            // validation rule above, not the primary defence -- see D-4/D-7/R-2.
            throw ($this->translateNameUniqueViolation)($e);
        }
    }
}
