<?php

namespace App\Concerns;

use App\Actions\NormalizeForSearch;
use App\Models\BlogTag;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait BlogTagValidationRules
{
    /**
     * Format only -- no uniqueness. The rule set FindOrCreateBlogTag uses, where an existing name
     * is a HIT, never an error (D-9). Reaching for nameRules() there would refuse the action's own
     * primary use case, and would look like a validation bug rather than a design error.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function nameFormatRules(NormalizeForSearch $normalizeForSearch): array
    {
        return [
            // First, so a name that is too long reports the length once, not once per length rule.
            'bail',
            'required',
            'string',
            'max:'.BlogTag::NAME_MAX_LENGTH,
            $this->foldedNameFits($normalizeForSearch),
        ];
    }

    /**
     * Format PLUS uniqueness, compared against normalized_name. The rule set CreateBlogTag and
     * RenameBlogTag use, where an existing name is a refusal (D-9).
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function nameRules(NormalizeForSearch $normalizeForSearch, ?string $blogTagId = null): array
    {
        return [
            ...$this->nameFormatRules($normalizeForSearch),
            $this->uniqueNormalisedName($normalizeForSearch, $blogTagId),
        ];
    }

    /**
     * Trim the way a human means it: PHP's trim() leaves a non-breaking space or a zero-width
     * space in place, and the shared normaliser then folds those to a plain space (its own trim()
     * has already run), so "\u{00A0}running" would fold to " running" and slip past the uniqueness
     * check as a visually identical duplicate. Strips every Unicode whitespace, separator and
     * format character from both ends. Applied BEFORE validation so `max` sees the stored value,
     * and so a whitespace-only name is refused rather than looked up (R-2).
     *
     * The trailing branch is guarded by a lookbehind so it only starts at the beginning of a run:
     * without it, every space of an interior run rescans the rest of the run looking for the end of
     * the string, which is quadratic (measured at 29 s for 50,000 characters with PCRE's JIT off,
     * and still 9 s with possessive quantifiers alone). This runs on the raw input, before `max:`
     * can refuse it.
     * Invalid UTF-8 makes `preg_replace()` return null; that becomes an empty name, which
     * validation refuses, rather than letting the untrimmed bytes through.
     */
    protected function trimName(string $name): string
    {
        return preg_replace('/\A[\s\p{Z}\p{Cf}]++|(?<![\s\p{Z}\p{Cf}])[\s\p{Z}\p{Cf}]++\z/u', '', $name) ?? '';
    }

    /**
     * Refuses a name whose FOLDED form would not fit `normalized_name` (R-4), or that folds to
     * nothing or to something with edge whitespace -- an invisible or unlookup-able key.
     *
     * `max:100` bounds what an editor sees, but App\Actions\NormalizeForSearch ends in an ASCII
     * transliteration, which can make a string longer (`ß` -> `ss`, and up to 5 characters for a
     * single code point -- measured across every code point). No column width up to 255 is a
     * guarantee for 100 raw characters, so this bounds the FOLDED value instead: an in-policy name
     * can never reach a raw 22001 or a truncated uniqueness key.
     */
    protected function foldedNameFits(NormalizeForSearch $normalizeForSearch): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($normalizeForSearch): void {
            $folded = $normalizeForSearch((string) $value);

            if ($folded === '' || $folded !== trim($folded)) {
                $fail(trans('validation.required', ['attribute' => $attribute]));

                return;
            }

            if (mb_strlen($folded) > BlogTag::NORMALIZED_NAME_MAX_LENGTH) {
                $fail(trans('validation.max.string', [
                    'attribute' => $attribute,
                    'max' => BlogTag::NAME_MAX_LENGTH,
                ]));
            }
        };
    }

    /**
     * A closure-based rule, not a bare Rule::unique() (D-3): the value compared must be the
     * candidate's NORMALISED form, produced by the same shared App\Actions\NormalizeForSearch call
     * BlogTag's saving hook uses to write the column, against the `normalized_name` column the
     * UNIQUE index guards -- so the pre-flight check and the constraint can never disagree. A bare
     * `Rule::unique('blog_tags', 'name')` would compare the RAW submitted string against the wrong
     * column.
     *
     * $blogTagId excludes that row from the comparison, which is what makes saving a tag under its
     * own unchanged name succeed (R-1). It must stay server-authoritative when a component feeds it
     * (docs/security/livewire-authorization.md).
     */
    protected function uniqueNormalisedName(NormalizeForSearch $normalizeForSearch, ?string $blogTagId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($normalizeForSearch, $blogTagId): void {
            $taken = BlogTag::query()
                ->where('normalized_name', $normalizeForSearch((string) $value))
                ->when($blogTagId !== null, fn ($query) => $query->whereKeyNot($blogTagId))
                ->exists();

            if ($taken) {
                $fail(trans('validation.unique', ['attribute' => $attribute]));
            }
        };
    }
}
