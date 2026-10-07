<?php

namespace App\Actions\ProductCategories;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Story 0070 (D-7 (ii)): disambiguates a QueryException raised against
 * `product_category_translations` between its two unique indexes --
 * `product_category_translations_store_language_id_name_unique` (a genuine name collision within
 * one store language) and `product_category_translations_category_language_unique` (the
 * `(product_category_id, store_language_id)` natural-key index, unreachable through
 * App\Actions\ProductCategories\CreateProductCategory / RenameProductCategory, which always pass
 * the id of an existing default language) -- plus a 1452 FK violation on `store_language_id`.
 * Only the first maps to a clean `validation.unique` ValidationException; every other
 * QueryException is rethrown unchanged.
 *
 * Loosely follows the shipped App\Actions\Products\TranslateProductVariantUniqueViolation
 * precedent (stateless, container-resolved, ending in `throw $e`), with one deliberate
 * divergence beyond the parameter type: the discrimination never matches against
 * `QueryException::getMessage()`. That message embeds the already-interpolated SQL --
 * including whatever the user typed as the category name, per the framework's own
 * `formatMessage()` (vendor/laravel/framework/src/Illuminate/Database/QueryException.php) --
 * so a category renamed to text that happens to contain the index name would otherwise be
 * misreported as a name collision even when the real failure is unrelated (the natural-key
 * unique index, a 1452 FK violation, a 1213 deadlock, a 1205 lock-wait-timeout, ...). Instead,
 * matching is driver-error-code first: only a MySQL 1062 (`$e->errorInfo[1]`) is even
 * considered, and only THEN is the index name matched against `$e->errorInfo[2]` (the native
 * driver message, not `getMessage()`), anchored to the end of the string. The `(?:[^']+\.)?`
 * prefix is optional because MySQL 8.0.19+ qualifies the key name with the table name in this
 * message form.
 */
class TranslateProductCategoryNameUniqueViolation
{
    /**
     * $errorKey is the field the caller reports on. It is derived by the caller from its own
     * arguments (this story's two actions pass 'name'; story 0071's
     * SetProductCategoryTranslation passes "names.{$language->id}"). It never decides WHETHER
     * the violation is a name collision. $attributeLabel is the human-readable field name
     * interpolated into the message, so a derived key is never shown to the user.
     */
    public function __invoke(QueryException $e, string $errorKey = 'name', string $attributeLabel = 'name'): ValidationException
    {
        $isNameCollision = ($e->errorInfo[1] ?? null) === 1062
            && preg_match(
                "/for key '(?:[^']+\\.)?product_category_translations_store_language_id_name_unique'$/",
                (string) ($e->errorInfo[2] ?? ''),
            ) === 1;

        if ($isNameCollision) {
            return ValidationException::withMessages([
                $errorKey => trans('validation.unique', ['attribute' => $attributeLabel]),
            ]);
        }

        throw $e;
    }
}
