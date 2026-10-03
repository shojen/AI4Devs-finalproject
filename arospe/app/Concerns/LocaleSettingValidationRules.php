<?php

namespace App\Concerns;

use App\Enums\UiLocale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules for the two dashboard-default locale settings, consumed by
 * App\Livewire\StoreLanguages\Index (story 0069).
 *
 * The locale actions (App\Actions\Localization\SetDefaultUiLocale / SetDefaultNotificationLocale)
 * take an already-typed UiLocale and validate nothing, so the component -- the only layer holding
 * the raw, client-writable string -- validates it here before converting it with UiLocale::from().
 * A new trait rather than a method on UserValidationRules, which is named for the User model.
 */
trait LocaleSettingValidationRules
{
    /**
     * Get the validation rules used to validate the default dashboard language.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function defaultUiLocaleRules(): array
    {
        return ['required', 'string', Rule::enum(UiLocale::class)];
    }

    /**
     * Get the validation rules used to validate the default notification email language.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function defaultNotificationLocaleRules(): array
    {
        return ['required', 'string', Rule::enum(UiLocale::class)];
    }
}
