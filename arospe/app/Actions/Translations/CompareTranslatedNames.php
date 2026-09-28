<?php

namespace App\Actions\Translations;

use App\Actions\NormalizeForSearch;

/**
 * Story 0070 (Phase 5 round-1 finding 2): the single collation-aware comparator every screen that
 * sorts a translatable entity's resolved name reuses, replacing the byte comparison (`$a <=> $b`)
 * that `App\Livewire\ProductCategories\Index` and `App\Livewire\Products\Editor` each wrote
 * inline once `product_categories.name` moved off the column and its sort moved from
 * `orderBy('name')` -- running under the column's `utf8mb4_unicode_ci` collation -- into PHP.
 * Byte order put "Zapatos" before "bolsos" and every accented name after every ASCII one, a real
 * behaviour change the story's own D-15 promised not to make.
 *
 * Reuses the already-shared App\Actions\NormalizeForSearch fold (trim, lowercase, then
 * `Str::ascii()` accent-stripping) rather than introducing PHP's intl `Collator`: this codebase
 * already has ONE tested, injected convention for "compare two strings case- and
 * accent-insensitively" (see `ProductCategoryValidationRules`' own uniqueness rule), and reusing
 * it keeps a single fold implementation instead of two competing ones. `Collator` would track
 * `utf8mb4_unicode_ci` more precisely (real Unicode DUCET tailoring), but nothing in this
 * pilot's Gherkin needs more than case- and accent-insensitive ordering, and NormalizeForSearch
 * already delivers exactly that with no new extension dependency beyond what the app already
 * assumes elsewhere.
 *
 * A null name sorts LAST, never first; two null names, or two names that fold identically, break
 * the tie on `id` -- the exact fallback the two inline comparators this class replaces already
 * used, now written once so 0072/0074/0076/0078 do not each re-derive or re-duplicate it.
 */
class CompareTranslatedNames
{
    public function __construct(
        private readonly NormalizeForSearch $normalizeForSearch,
    ) {}

    /**
     * Compare two already-resolved translated names, each paired with the id of the record it
     * belongs to for a deterministic tiebreak. Takes plain strings rather than models so it needs
     * no knowledge of `App\Concerns\Translatable` and stays trivially unit-testable.
     */
    public function __invoke(?string $nameA, string $idA, ?string $nameB, string $idB): int
    {
        if ($nameA === null && $nameB === null) {
            return $idA <=> $idB;
        }

        if ($nameA === null) {
            return 1;
        }

        if ($nameB === null) {
            return -1;
        }

        $foldedA = ($this->normalizeForSearch)($nameA);
        $foldedB = ($this->normalizeForSearch)($nameB);

        return $foldedA <=> $foldedB ?: $idA <=> $idB;
    }
}
