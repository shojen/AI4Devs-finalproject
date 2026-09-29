<?php

namespace App\Concerns;

use App\Enums\UiLocale;
use App\Enums\UserStatus;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait UserValidationRules
{
    /**
     * Get the validation rules used to validate a user's assigned role.
     *
     * The Super Admin role is excluded server-side, in the rule itself — not
     * merely omitted from the dropdown — so a forged submission cannot
     * assign it. Resolved via Role::superAdminName() (story 0008) rather
     * than the literal 'Super Admin', so this exclusion always matches
     * whichever role config('auth.super_admin.role') actually grants the
     * Gate::before bypass to — otherwise an overridden config value would
     * make this rule exclude an ordinary role while permitting assignment
     * of the real Super Admin role.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function roleRules(): array
    {
        return [
            'required',
            Rule::exists('roles', 'id')->where('guard_name', 'web')->whereNot('name', Role::superAdminName()),
        ];
    }

    /**
     * Get the validation rules used to validate a user's status.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function statusRules(): array
    {
        return ['required', Rule::enum(UserStatus::class)];
    }

    /**
     * Get the validation rules used to validate a user's chosen admin UI locale.
     *
     * Rule::enum() resolves with tryFrom(), so a forged/unsupported value fails
     * validation rather than throwing (story 0066, D-5).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function uiLocaleRules(): array
    {
        return ['required', 'string', Rule::enum(UiLocale::class)];
    }
}
