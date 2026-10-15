<?php

namespace App\Actions\Blog;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Story 0074: disambiguates a QueryException raised against `blog_tag_translations`, which has two
 * UNIQUE indexes and two foreign keys, so a blanket `23000` catch would misreport an FK violation
 * as "name taken". Only a MySQL 1062 on the per-language normalized_name index becomes a
 * `validation.unique` ValidationException; everything else is rethrown unchanged.
 *
 * Matches the driver error code and the native driver message, never QueryException::getMessage(),
 * which embeds the interpolated SQL and so whatever the user typed.
 */
class TranslateBlogTagNameUniqueViolation
{
    public function __invoke(QueryException $e, string $errorKey = 'name', string $attributeLabel = 'name'): ValidationException
    {
        $isNameCollision = ($e->errorInfo[1] ?? null) === 1062
            && preg_match(
                "/for key '(?:[^']+\\.)?blog_tag_translations_language_normalized_name_unique'$/",
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
