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
     * Story 0036 Phase 4 RE-audit finding N-2: the split below is real and
     * differs by caller -- this comment previously described only the
     * CREATE side as if it were the whole story.
     *
     * On CREATE, `min_weight_kg` defaults to '0' in the calling action
     * (CreateShippingRate) when omitted from the submitted payload
     * (matching the column's own `default(0)`, D-7) BEFORE this rule ever
     * runs -- so on that path the value is never genuinely absent by the
     * time `required` is checked, and `required` is a redundant safety net
     * rather than the actual enforcement mechanism.
     *
     * On UPDATE, `UpdateShippingRate` (Phase 4 finding F-1) deliberately
     * does NOT apply that default -- an omitted key on an update means
     * "not being touched", never "reset to 0". `required` is what does the
     * real work there: it is what makes an omitted `min_weight_kg` fail as
     * a field-level validation error instead of silently resetting the row
     * to a 0 minimum (see UpdateShippingRate's own docblock for the
     * undercharging bug this closes).
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
     * Corrected 2026-09-10 (story 0037, D-2) -- the inline comment below
     * previously read: "'nullable' FIRST and it short-circuits: an absent
     * max means 'and above' (D-4), so gte must not run against a null."
     * That is true only for a genuine PHP `null` -- a caller of THIS trait
     * alone gets no protection against a raw blank string: `''` never
     * reaches `Validator::isNotNullIfMarkedAsNullable()`'s `is_null()`
     * check at all, because Laravel's own `isValidatable()` tests
     * `presentOrRuleIsImplicit()` FIRST and, for a blank string, skips
     * every rule below that is not itself implicit (`numeric`, `decimal`,
     * `min`, `max`, `gte` all qualify) -- a coincidentally identical
     * outcome reached through a DIFFERENT mechanism than "nullable short-
     * circuits", verified against installed vendor source
     * (`Validator.php:819,839,886`). Story 0037's own D-2 is what found
     * this: Livewire's `/livewire/update` requests skip
     * `ConvertEmptyStringsToNull` entirely, so a blank `max_weight_kg`
     * field never becomes `null` on its own -- the caller (this trait's
     * one Livewire consumer, `App\Livewire\Shipping\Index::saveRate()`)
     * MUST normalise a blank string to a real `null` itself, before this
     * rule set ever runs, or the value reaches `DECIMAL(8,3)` as a raw
     * `''` (a 500 under MySQL's strict mode, silently accepted only on a
     * looser engine -- see docs/errors-log.md's D-2 entry). This trait's
     * own `'nullable'` rule is correct and unchanged; only the comment's
     * claim about WHY it protects a blank input has been corrected.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function maxWeightRules(): array
    {
        return [
            // 'nullable' FIRST: a genuine null max means "and above" (D-4),
            // so gte must not run against it. This does NOT by itself
            // protect a raw blank string -- see this method's own docblock.
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
