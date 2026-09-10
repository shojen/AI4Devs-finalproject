<?php

namespace App\Actions\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\ShippingRateValidationRules;
use App\Models\ShippingRate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateShippingRate
{
    use ShippingRateValidationRules;

    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Update an existing shipping rate rule -- including reassigning it to a
     * different zone or carrier. Reassigning a rate to a different zone is
     * the operation D-5's zone-delete-blocked message itself tells the
     * administrator to perform, so it must actually work end to end.
     *
     * Self-authorizes `update` on the target $shippingRate as its own first
     * statement, before validation and before any write (D-11, Phase 2
     * review finding B1).
     *
     * The whole `invalid_rate_attributes` dataset (tests/Feature/ShippingRates/Datasets.php)
     * is re-run against this action via ->with(), so every validation rule
     * threaded through CreateShippingRate is threaded through here too
     * (0033 R-7).
     *
     * Phase 4 security-audit finding F-1: unlike CreateShippingRate,
     * this action does NOT default an omitted `min_weight_kg` to '0'.
     * CreateShippingRate's default is correct because an omitted min on a
     * brand-new rate genuinely means "from 0 kg" (D-7, matching the
     * column's own `default(0)`). On an UPDATE, an omitted key means "not
     * being touched", never "reset to 0" -- every caller of this action
     * (App\Livewire\Shipping\* and every test in
     * tests/Feature/ShippingRates/UpdateShippingRateTest.php) already
     * submits the FULL attribute set on every save, matching
     * App\Actions\Products\UpdateProduct's own full-payload convention, so
     * `minWeightRules()`'s pre-existing `required` rule is what now
     * correctly rejects a payload that omits it, as a field-level
     * validation error, instead of the value being silently reset to 0 --
     * which, under D-1's cheapest-wins tiebreak, could make a narrow
     * promotional rate silently widen to cover parcels it was never
     * configured for (a real undercharging bug).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(ShippingRate $shippingRate, array $attributes): ShippingRate
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'update',
            $shippingRate,
            targetType: 'shipping_rate',
            targetId: $shippingRate->id,
        );

        $attributes['name'] = trim((string) ($attributes['name'] ?? ''));

        Validator::make($attributes, [
            'name' => $this->shippingRateNameRules(),
            'shipping_carrier_id' => $this->shippingCarrierIdRules(),
            'shipping_zone_id' => $this->shippingZoneIdRules(),
            'min_weight_kg' => $this->minWeightRules(),
            'max_weight_kg' => $this->maxWeightRules(),
            'price' => $this->priceRules(),
            'delivery_estimate' => $this->deliveryEstimateRules(),
        ])->validate();

        try {
            $shippingRate->update($attributes);
        } catch (QueryException $e) {
            // Narrowed to 1452 (ER_NO_REFERENCED_ROW_2), NEVER the whole 23000
            // SQLSTATE class -- same reasoning as CreateShippingRate's identical
            // catch: a carrier or zone row deleted between Rule::exists()
            // validation and this UPDATE.
            if (($e->errorInfo[1] ?? null) !== 1452) {
                throw $e;
            }

            // Phase 4 security-audit finding F-5: discriminate on the CONSTRAINT NAME, never
            // the column name -- see CreateShippingRate's identical catch for the full
            // reasoning. QueryException::formatMessage() appends the whole UPDATE statement
            // to the message, which mentions 'shipping_carrier_id' as a column name
            // regardless of which FK actually failed.
            //
            // Phase 4 RE-audit finding R-3: check $e->getPrevious()?->getMessage() -- the raw
            // PDOException -- NEVER $e->getMessage() itself, which interpolates the query's
            // BOUND VALUES into the formatted SQL. A rate whose `name` is set to the literal
            // string 'shipping_rates_shipping_carrier_id_foreign' would otherwise make a
            // genuine ZONE-fk failure get misattributed to shipping_carrier_id, since that
            // string appears in the formatted message as data. See CreateShippingRate's
            // identical catch for the full reasoning.
            $field = str_contains($e->getPrevious()?->getMessage() ?? '', 'shipping_rates_shipping_carrier_id_foreign')
                ? 'shipping_carrier_id'
                : 'shipping_zone_id';

            throw ValidationException::withMessages([
                $field => trans('validation.exists', ['attribute' => $field]),
            ]);
        }

        return $shippingRate;
    }
}
