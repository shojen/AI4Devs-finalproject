<?php

namespace App\Concerns;

use App\Actions\NormalizeForSearch;
use App\Models\ProductCategoryTranslation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait ProductCategoryValidationRules
{
    /**
     * Get the validation rules used to validate a product category.
     *
     * Story 0070 (D-7, D-17): widened to take the store language the name is being written in --
     * required, and inserted second (before the existing optional id, which cannot follow it).
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    protected function productCategoryRules(
        NormalizeForSearch $normalizeForSearch,
        string $storeLanguageId,
        ?string $productCategoryId = null,
    ): array {
        return ['name' => $this->nameRules($normalizeForSearch, $storeLanguageId, $productCategoryId)];
    }

    /**
     * Get the validation rules used to validate a product category name, in a given store
     * language (D-7).
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function nameRules(
        NormalizeForSearch $normalizeForSearch,
        string $storeLanguageId,
        ?string $productCategoryId = null,
    ): array {
        return [
            'required',
            'string',
            'max:255',
            // Uniqueness is compared against the SHARED normaliser's output in
            // PHP, never left to the connection's collation. utf8mb4_unicode_ci
            // WOULD reject a case-/accent-duplicate pair, but only as a raw
            // 23000 QueryException with no field-level message; the PHP
            // pre-check is what produces a clean ValidationException on `name`
            // before the database ever has to enforce it.
            // See D-4, D-7, D-12 and R-2.
            $this->uniqueNormalisedName($normalizeForSearch, $storeLanguageId, $productCategoryId),
        ];
    }

    /**
     * A closure-based rule, deliberately not a bare Rule::unique() (D-4/D-12):
     * a bare Rule::unique() compiles to `WHERE name = ?` and hands the
     * case/accent decision to the connection's utf8mb4_unicode_ci collation.
     * This rule instead normalises the candidate and every OTHER existing
     * category's name -- in the SAME store language (D-7) -- through the same
     * shared App\Actions\NormalizeForSearch instance and compares those
     * normalised forms directly, so a case-only or accent-only duplicate is
     * refused here -- cleanly, on the `name` field -- before the database's
     * own UNIQUE(store_language_id, name) index (the last-word race guard,
     * not the primary defence) is ever asked.
     *
     * $productCategoryId excludes that row's OWN translation from the
     * comparison, which is what makes saving a category under its own
     * unchanged name succeed (R-1).
     */
    protected function uniqueNormalisedName(
        NormalizeForSearch $normalizeForSearch,
        string $storeLanguageId,
        ?string $productCategoryId = null,
    ): Closure {
        return function (string $attribute, mixed $value, Closure $fail) use ($normalizeForSearch, $storeLanguageId, $productCategoryId): void {
            $normalisedCandidate = $normalizeForSearch((string) $value);

            $existingNames = ProductCategoryTranslation::query()
                ->where('store_language_id', $storeLanguageId)
                ->when($productCategoryId !== null, fn ($query) => $query->where('product_category_id', '!=', $productCategoryId))
                ->pluck('name');

            foreach ($existingNames as $existingName) {
                if ($normalizeForSearch($existingName) === $normalisedCandidate) {
                    $fail(trans('validation.unique', ['attribute' => $attribute]));

                    return;
                }
            }
        };
    }
}
