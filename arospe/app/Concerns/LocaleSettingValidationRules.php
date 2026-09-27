<?php

namespace App\Concerns;

use App\Enums\UiLocale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules for the default-locale settings area (story 0068), consumed by story
 * 0069's settings form.
 *
 * A NEW trait, deliberately not a reuse of story 0066's App\Concerns\UserValidationRules (D26,
 * R-18): that trait is named for the model whose input it validates, a User's own `ui_locale`
 * preference is a different concept from this singleton's store-wide defaults, and this project's
 * naming convention forbids one trait `use`-ing another -- so the one-line duplication of a
 * `Rule::enum(UiLocale::class)` array is the accepted cost.
 */
trait LocaleSettingValidationRules
{
    /**
     * Get the validation rules used to validate a submitted default dashboard language.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function defaultUiLocaleRules(): array
    {
        return ['required', 'string', Rule::enum(UiLocale::class)];
    }

    /**
     * Get the validation rules used to validate a submitted default notification-email language.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function defaultNotificationLocaleRules(): array
    {
        return ['required', 'string', Rule::enum(UiLocale::class)];
    }
}
