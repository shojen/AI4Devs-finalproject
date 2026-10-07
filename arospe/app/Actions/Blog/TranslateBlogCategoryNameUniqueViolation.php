<?php

namespace App\Actions\Blog;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Story 0072 (R-11): disambiguates a QueryException raised against `blog_category_translations`.
 * The table has two UNIQUE indexes and two foreign keys, so the parent's old blanket `23000`
 * catch would misreport an FK violation as "name taken". Only a MySQL 1062 on
 * `blog_category_translations_language_normalized_name_unique` -- a genuine name collision within
 * one store language -- becomes a clean `validation.unique` ValidationException; every other
 * QueryException is rethrown unchanged.
 *
 * Mirrors App\Actions\ProductCategories\TranslateProductCategoryNameUniqueViolation, including
 * its refusal to match against QueryException::getMessage(): that message embeds the interpolated
 * SQL, and so whatever the user typed as the name. Matching is driver-error-code first (1062),
 * then the index name against the native driver message (`errorInfo[2]`), anchored to its end.
 */
class TranslateBlogCategoryNameUniqueViolation
{
    /**
     * $errorKey is the field the caller reports on and $attributeLabel the human-readable name
     * interpolated into the message. Both default to `name`; they exist for story 0073's
     * per-language authoring action, which reports on a derived key. Neither decides WHETHER the
     * violation is a name collision.
     */
    public function __invoke(QueryException $e, string $errorKey = 'name', string $attributeLabel = 'name'): ValidationException
    {
        $isNameCollision = ($e->errorInfo[1] ?? null) === 1062
            && preg_match(
                "/for key '(?:[^']+\\.)?blog_category_translations_language_normalized_name_unique'$/",
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
