<?php

namespace App\Concerns;

use App\Actions\NormalizeForSearch;
use App\Models\BlogCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait BlogCategoryValidationRules
{
    /**
     * Get the validation rules used to validate a blog category.
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    protected function blogCategoryRules(
        NormalizeForSearch $normalizeForSearch,
        ?string $blogCategoryId = null,
    ): array {
        return ['name' => $this->nameRules($normalizeForSearch, $blogCategoryId)];
    }

    /**
     * Get the validation rules used to validate a blog category name.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function nameRules(
        NormalizeForSearch $normalizeForSearch,
        ?string $blogCategoryId = null,
    ): array {
        return [
            // First, so a name that is too long reports the length once, not once per length rule.
            'bail',
            'required',
            'string',
            'max:'.BlogCategory::NAME_MAX_LENGTH,
            $this->foldedNameFits($normalizeForSearch),
            $this->uniqueNormalisedName($normalizeForSearch, $blogCategoryId),
        ];
    }

    /**
     * Trim the way a human means it: PHP's trim() leaves a non-breaking space or a zero-width
     * space in place, and the shared normaliser then folds those to a plain space (its own trim()
     * has already run), so "\u{00A0}Guías" would fold to " guias" and slip past the uniqueness
     * check as a visually identical duplicate. Strips every Unicode whitespace, separator and
     * format character from both ends. Applied BEFORE validation so `max` sees the stored value.
     */
    protected function trimName(string $name): string
    {
        return preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $name) ?? $name;
    }

    /**
     * Refuses a name whose FOLDED form would not fit `normalized_name` (R-4), or that folds to
     * nothing or to something with edge whitespace -- an invisible or unlookup-able key.
     *
     * `max:255` bounds what an editor sees, but App\Actions\NormalizeForSearch ends in an ASCII
     * transliteration, which can make a string longer (`ß` -> `ss`, `€` -> `EUR`,
     * up to 5 characters for a single character in some scripts -- measured across every
     * codepoint). Giving normalized_name headroom is not viable: utf8mb4 caps a unique key at 768
     * characters, under 255 x 5. Refusing here turns what would be a silently truncated key or a
     * raw 22001 into a clean validation error on `name`.
     */
    protected function foldedNameFits(NormalizeForSearch $normalizeForSearch): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($normalizeForSearch): void {
            $folded = $normalizeForSearch((string) $value);

            if ($folded === '' || $folded !== trim($folded)) {
                $fail(trans('validation.required', ['attribute' => $attribute]));

                return;
            }

            if (mb_strlen($folded) > BlogCategory::NAME_MAX_LENGTH) {
                $fail(trans('validation.max.string', [
                    'attribute' => $attribute,
                    'max' => BlogCategory::NAME_MAX_LENGTH,
                ]));
            }
        };
    }

    /**
     * A closure-based rule, not a bare Rule::unique() (D-4/D-12): the value compared must be the
     * candidate's NORMALISED form, produced by the same shared App\Actions\NormalizeForSearch call
     * BlogCategory's saving hook uses to write the column, against the `normalized_name` column the
     * UNIQUE index guards -- so the pre-flight check and the constraint can never disagree.
     *
     * $blogCategoryId excludes that row from the comparison, which is what makes saving a category
     * under its own unchanged name succeed (R-1). It must stay server-authoritative when a
     * component feeds it (docs/security/livewire-authorization.md).
     */
    protected function uniqueNormalisedName(NormalizeForSearch $normalizeForSearch, ?string $blogCategoryId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($normalizeForSearch, $blogCategoryId): void {
            $taken = BlogCategory::query()
                ->where('normalized_name', $normalizeForSearch((string) $value))
                ->when($blogCategoryId !== null, fn ($query) => $query->whereKeyNot($blogCategoryId))
                ->exists();

            if ($taken) {
                $fail(trans('validation.unique', ['attribute' => $attribute]));
            }
        };
    }
}
