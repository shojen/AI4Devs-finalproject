<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shared tax-ID normalisation and rules (story 0100, FD-6): free text, whitespace removed,
 * upper-cased, at most 32 characters of letters, digits and `- . /`. Reused by the customer tax
 * ID story (0100a).
 */
trait TaxIdValidationRules
{
    /**
     * Strip all whitespace and upper-case; blank becomes null.
     */
    protected function normalizeTaxId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Invalid UTF-8 makes preg_replace return null: keep the raw value so the regex rule refuses it.
        $normalized = strtoupper(preg_replace('/\s+/u', '', $value) ?? $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Rules for an already-normalised tax ID.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function taxIdRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:32',
            'regex:/^[A-Za-z0-9\-.\/]+$/D',
        ];
    }
}
