<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Following naming.md's <Noun>ValidationRules / <noun>Rules() convention
 * (story 0036).
 *
 * The name method is `shippingRateNameRules()`, NOT `nameRules()` -- 0033's
 * own D-6 flagged this exact latent collision: ProfileValidationRules and
 * ProductCategoryValidationRules already declare `nameRules()`, and traits
 * compose flat at the consumer, so a third same-named trait method is a
 * FATAL error the moment two of them are composed onto one class.
 */
trait ShippingRateValidationRules
{
    /**
     * Get the validation rules used to validate a shipping rate's name.
     *
     * D-9: no uniqueness of any kind -- the prototype ships four rows named
     * "Estándar" across different carriers and zones, and even within the
     * same carrier+zone (two named services on one bracket, D-2).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function shippingRateNameRules(): array
    {
        return ['required', 'string', 'max:150'];
    }

    /**
     * Get the validation rules used to validate a shipping rate's minimum
     * weight (kg).
     *
     * D-7: `decimal:0,3` rather than a bare `numeric` -- `numeric` happily
     * accepts scientific notation ('1e2' == 100), which `decimal`'s pattern
     * has no e/E branch for and therefore rejects. It also caps precision at
     * 3 places, matching `decimal(8,3)`; without it 2.0001 reaches MySQL and
     * is silently truncated or errors depending on strict mode.
     *
     * `min_weight_kg` defaults to '0' in the calling action when omitted
     * from the submitted payload (matching the column's own `default(0)`,
     * D-7) -- so by the time this rule runs the value is never genuinely
     * absent, and `required` is safe rather than a trap for the
     * "min_weight_kg defaults to 0 when omitted" scenario.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function minWeightRules(): array
    {
        return [
            'required',
            'numeric',
            'decimal:0,3',
            'min:0',
            // Bounded so a forged payload cannot overflow decimal(8,3) into a raw
            // SQLSTATE 22003 (a 500) instead of a field-level message.
            'max:99999.999',
        ];
    }

    /**
     * Get the validation rules used to validate a shipping rate's maximum
     * weight (kg) -- nullable, meaning "and above" (D-4).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function maxWeightRules(): array
    {
        return [
            // 'nullable' FIRST and it short-circuits: an absent max means "and
            // above" (D-4), so gte must not run against a null.
            'nullable',
            'numeric',
            // 'decimal:0,3' not a bare 'numeric': Validator::validateDecimal()
            // calls validateNumeric() first, then matches a pattern with no e/E
            // branch -- so it rejects scientific notation, which 'numeric' happily
            // accepts ('1e2' would sail through as 100). It also caps precision at
            // 3, matching decimal(8,3); without it 2.0001 reaches MySQL and is
            // silently truncated or errors depending on strict mode.
            'decimal:0,3',
            'min:0',
            // Bounded so a forged payload cannot overflow decimal(8,3) into a raw
            // SQLSTATE 22003 (a 500) instead of a field-level message.
            'max:99999.999',
            // On the MAX field, never 'lte:max_weight_kg' on the min field: when
            // max is null the lte comparison has no value to compare against.
            'gte:min_weight_kg',
        ];
    }

    /**
     * Get the validation rules used to validate a shipping rate's price
     * (EUR).
     *
     * `min:0`, never `gt:0` -- `0.00` is a legal free-shipping rate (D-7).
     * `max:99999999.99` bounds a forged payload from overflowing
     * `decimal(10,2)` into a raw SQLSTATE 22003 (a 500).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function priceRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'];
    }

    /**
     * Get the validation rules used to validate a shipping rate's free-text
     * delivery estimate (D-8) -- '24h', '3-5 días', etc. No structured
     * day-range representation.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function deliveryEstimateRules(): array
    {
        return ['required', 'string', 'max:50'];
    }

    /**
     * Get the validation rules used to validate the id of the carrier a
     * shipping rate belongs to.
     *
     * A pre-flight check, not a race guard -- the `restrictOnDelete()` FK
     * has the last word, which is why the rate actions catch a narrowed
     * 1452 (ER_NO_REFERENCED_ROW_2), never the whole 23000 class.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function shippingCarrierIdRules(): array
    {
        return ['required', 'uuid', Rule::exists('shipping_carriers', 'id')];
    }

    /**
     * Get the validation rules used to validate the id of the zone a
     * shipping rate belongs to.
     *
     * Same pre-flight-only reasoning as shippingCarrierIdRules() above --
     * see its docblock.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function shippingZoneIdRules(): array
    {
        return ['required', 'uuid', Rule::exists('shipping_zones', 'id')];
    }
}
