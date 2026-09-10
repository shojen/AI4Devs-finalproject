<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validate IBAN structure (ISO 13616) and the mod-97 (ISO 7064) checksum.
 *
 * Assumes the value has already been normalised (uppercase, no whitespace) by
 * the caller -- this rule deliberately does NOT strip whitespace itself,
 * matching ProfileValidationRules::emailRules(), which likewise assumes
 * lowercasing has already happened. If normalisation is ever skipped
 * upstream, this rule fails loudly on the raw value instead of silently
 * accepting it.
 *
 * The structure regex is anchored with `\z`, never a bare `$` -- PCRE's `$`
 * matches before a trailing newline unless the `D` modifier is set, and the
 * mod-97 checksum does not catch it either (PHP's `(int)` cast tolerates
 * trailing whitespace inside a chunk), so a bare `$` would silently accept
 * "ES9121000418450200051332\n" as a valid IBAN and persist the newline
 * verbatim (Phase 4 security audit finding F-2).
 */
class Iban implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}\z/', $value)) {
            $fail(__('payment-methods.iban.invalid'));

            return;
        }

        $rearranged = substr($value, 4).substr($value, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        if ($this->mod97($numeric) !== 1) {
            $fail(__('payment-methods.iban.invalid'));
        }
    }

    /**
     * Compute the numeric string's value mod 97 without ever holding a number
     * larger than PHP's native int range. Processing in 7-digit chunks keeps
     * the intermediate "remainder + next chunk" concatenation at <= 9 digits,
     * safe even on a 32-bit int.
     */
    private function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = ((int) ($remainder.$chunk)) % 97;
        }

        return $remainder;
    }
}
