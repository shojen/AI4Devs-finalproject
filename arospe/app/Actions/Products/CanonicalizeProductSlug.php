<?php

namespace App\Actions\Products;

use Illuminate\Support\Str;

/**
 * Story 0076a (D-6, D-30): the single slug canonicaliser, shared by the action layer and the
 * ProductTranslation `saving` hook so the two cannot drift. Blank or slug-less input becomes null,
 * never '', because a stored '' would collide with every other '' under the unique index.
 */
class CanonicalizeProductSlug
{
    public function __invoke(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        return Str::slug($slug) ?: null;
    }
}
