<?php

namespace App\Actions\Products;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Story 0076a (D-31, R-22): attributes a 1062 on a product-owned unique index to its form field by
 * INDEX NAME, matched on errorInfo[2] with the anchored pattern from
 * docs/security/constraint-violation-discrimination.md -- never on getMessage().
 */
class TranslateProductUniqueViolation
{
    /**
     * Story 0076b (D-4): a non-null $storeLanguageId re-keys the slug and natural-key indexes to the
     * nested "slugs.{id}" / "names.{id}" form; null keeps the bare keys.
     *
     * @throws QueryException $e unchanged when it is not a 1062, or names any other index
     */
    public function __invoke(QueryException $e, ?string $storeLanguageId = null): ValidationException
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            $message = (string) ($e->errorInfo[2] ?? '');

            if ($this->names('products_sku_unique', $message)) {
                return ValidationException::withMessages([
                    'sku' => trans('validation.unique', ['attribute' => 'sku']),
                ]);
            }

            if ($this->names('product_translations_language_slug_unique', $message)) {
                return ValidationException::withMessages([
                    ($storeLanguageId === null ? 'slug' : "slugs.{$storeLanguageId}") => trans('validation.unique', ['attribute' => trans('products.attributes.slug')]),
                ]);
            }

            if ($this->names('product_translations_product_language_unique', $message)) {
                return ValidationException::withMessages([
                    ($storeLanguageId === null ? 'name' : "names.{$storeLanguageId}") => trans('products.editor.concurrent_save_retry'),
                ]);
            }
        }

        throw $e;
    }

    private function names(string $index, string $driverMessage): bool
    {
        return preg_match("/for key '(?:[^']+\\.)?".$index."'$/", $driverMessage) === 1;
    }
}
