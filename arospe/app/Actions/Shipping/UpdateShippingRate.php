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
     * The whole `invalid_rate_attributes` dataset (ShippingRateValidationTest.php)
     * is re-run against this action via ->with(), so every validation rule
     * threaded through CreateShippingRate is threaded through here too
     * (0033 R-7).
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
        $attributes['min_weight_kg'] = $attributes['min_weight_kg'] ?? '0';

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

            $field = str_contains($e->getMessage(), 'shipping_carrier_id')
                ? 'shipping_carrier_id'
                : 'shipping_zone_id';

            throw ValidationException::withMessages([
                $field => trans('validation.exists', ['attribute' => $field]),
            ]);
        }

        return $shippingRate;
    }
}
