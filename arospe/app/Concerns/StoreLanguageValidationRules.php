<?php

namespace App\Concerns;

use App\Models\StoreLanguage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules for the Store Languages area (story 0068), consumed by
 * App\Actions\StoreLanguages\AddStoreLanguage.
 *
 * `nameRules()` from the first design draft is deliberately absent, not left dormant (D15): with
 * a picker over the bundled fixture there is no independent "what should this be called" input
 * for a human to type, so a validation method nothing calls would be untested surface.
 */
trait StoreLanguageValidationRules
{
    /**
     * Get the validation rules used to validate a submitted store language code: required, a
     * member of the bundled ISO 639-1 fixture (App\Models\StoreLanguage::availableLanguages()),
     * and unique among ACTIVE rows only -- a code held by an INACTIVE row must pass this rule, so
     * AddStoreLanguage's find-or-create can reactivate it instead of being blocked by its own
     * uniqueness check (D5).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function codeRules(): array
    {
        return [
            'required',
            'string',
            Rule::in(array_keys(StoreLanguage::availableLanguages())),
            Rule::unique('store_languages', 'code')->where('is_active', true),
        ];
    }
}
