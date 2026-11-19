<?php

namespace App\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shared field rules of issuing an invoice (story 0101b, FD-11), used by
 * App\Actions\Invoices\IssueInvoice and the screens that build on it.
 */
trait InvoiceValidationRules
{
    /**
     * The amount of an invoice: a dot-decimal string with at most two decimals and at most eight
     * integer digits (the decimal(10,2) ceiling 99999999.99), at least 0.01. Comma decimals,
     * exponents, surrounding spaces, `INF` and non-strings are refused.
     *
     * @return array<int, ValidationRule|Closure|string>
     */
    protected function invoiceAmountRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'regex:/^\d{1,8}(\.\d{1,2})?$/D',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && is_numeric($value) && bccomp($value, '0.01', 2) < 0) {
                    $fail(__('invoices.errors.amount_minimum'));
                }
            },
        ];
    }

    /**
     * The address an invoice is mailed to (story 0104 FD-3): a syntactically valid address with a
     * dotted domain, no whitespace and at most 254 characters. No DNS lookup.
     *
     * @return array<int, ValidationRule|Closure|string>
     */
    protected function invoiceRecipientEmailRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'max:254',
            'regex:/^[^@\s]+@[^@\s]+\.[^@\s.]+$/D',
            'email:rfc',
        ];
    }

    /**
     * Messages of the amount rules, keyed by `amount.<rule>`.
     *
     * @return array<string, string>
     */
    protected function invoiceAmountMessages(): array
    {
        return [
            'amount.required' => __('invoices.errors.amount_format'),
            'amount.string' => __('invoices.errors.amount_format'),
            'amount.regex' => __('invoices.errors.amount_format'),
        ];
    }
}
