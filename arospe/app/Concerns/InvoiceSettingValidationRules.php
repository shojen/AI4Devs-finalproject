<?php

namespace App\Concerns;

use App\Enums\InvoiceNumberYear;
use App\Enums\InvoiceTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Shared normalisation and field rules of the invoice settings (story 0100, FD-6, FD-13), used by
 * App\Actions\Invoices\SaveInvoiceSettings and the settings screen (0103).
 */
trait InvoiceSettingValidationRules
{
    use TaxIdValidationRules;

    /**
     * Normalise the keys present in the payload (FD-6): trim, blank to null, colours and country
     * upper-cased, tax ID through normalizeTaxId(). Prefixes and separators are never trimmed:
     * stray whitespace in them must be refused, not silently dropped. Absent keys stay absent.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function normalizeInvoiceSettingAttributes(array $attributes): array
    {
        $text = [
            'issuer_legal_name', 'issuer_address_line1', 'issuer_address_line2',
            'issuer_city', 'issuer_postal_code', 'issuer_province',
        ];

        foreach ($text as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $trimmed = trim($attributes[$key]);
                $attributes[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        foreach (['issuer_country', 'primary_color', 'accent_color'] as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $trimmed = strtoupper(trim($attributes[$key]));
                $attributes[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        if (array_key_exists('issuer_tax_id', $attributes) && is_string($attributes['issuer_tax_id'])) {
            $attributes['issuer_tax_id'] = $this->normalizeTaxId($attributes['issuer_tax_id']);
        }

        return $attributes;
    }

    /**
     * Field rules for an already-normalised payload, keyed by field; every key must be present.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function invoiceSettingRules(): array
    {
        $rules = [
            'issuer_legal_name' => ['present', 'nullable', 'string', 'max:255'],
            'issuer_tax_id' => ['present', ...$this->taxIdRules()],
            'issuer_address_line1' => ['present', 'nullable', 'string', 'max:255'],
            'issuer_address_line2' => ['present', 'nullable', 'string', 'max:255'],
            'issuer_city' => ['present', 'nullable', 'string', 'max:100'],
            'issuer_postal_code' => ['present', 'nullable', 'string', 'max:20'],
            'issuer_province' => ['present', 'nullable', 'string', 'max:100'],
            'issuer_country' => ['present', 'nullable', 'string', 'regex:/^[A-Z]{2}$/D'],
            'logo_media_id' => ['present', 'nullable', 'uuid', 'exists:media,id'],
            'primary_color' => ['present', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/D'],
            'accent_color' => ['present', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/D'],
            'template' => ['required', 'string', Rule::enum(InvoiceTemplate::class)],
            'reset_numbering_yearly' => ['required', 'boolean'],
            'auto_issue_on_order' => ['required', 'boolean'],
        ];

        foreach (['standard', 'corrective'] as $series) {
            $rules["{$series}_prefix"] = ['present', 'string', $this->implicitPattern('/^[A-Za-z0-9\-\/]{0,10}$/D')];
            $rules["{$series}_year_format"] = ['required', 'string', Rule::enum(InvoiceNumberYear::class)];
            $rules["{$series}_separator"] = ['present', 'string', $this->implicitPattern('/^[\-\/.]{0,3}$/D')];
            $rules["{$series}_padding"] = ['required', 'integer', 'between:1,10'];
        }

        return $rules;
    }

    /**
     * A pattern rule that also runs on blank strings. Laravel skips plain `regex` on a blank or
     * whitespace-only string, which would let ' ' or "\n" through as a prefix or separator.
     */
    private function implicitPattern(string $pattern): ValidationRule
    {
        return new class($pattern) implements ValidationRule
        {
            public bool $implicit = true;

            public function __construct(private readonly string $pattern) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (is_string($value) && preg_match($this->pattern, $value) !== 1) {
                    $fail('validation.regex')->translate();
                }
            }
        };
    }
}
