<?php

namespace App\Concerns;

use App\Rules\Iban;
use Illuminate\Contracts\Validation\ValidationRule;

trait PaymentMethodValidationRules
{
    /**
     * Get the validation rules used to validate a payment method IBAN.
     *
     * Assumes the value has already been normalised (uppercase, no spaces)
     * by the caller before this runs -- see App\Rules\Iban's own docblock.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function ibanRules(): array
    {
        return ['required', 'string', 'max:34', new Iban];
    }
}
