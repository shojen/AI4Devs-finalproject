<?php

namespace App\Actions\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Concerns\ShippingRateValidationRules;
use App\Models\ShippingRate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateShippingRate
{
    use ShippingRateValidationRules;

    /**
     * Constructor injection: __invoke()'s single domain argument is this
     * action's whole public signature, called that way by every direct-call
     * test -- so the collaborator is resolved from the container without
     * widening that signature. See docs/conventions/code-style.md's
     * constructor-injection exception.
     */
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Create a new shipping rate rule.
     *
     * Self-authorizes `create` on ShippingRate::class as its own first
     * statement, before the trim and before Validator::make() (D-11, Phase 2
     * review finding B1). There is no route and no Livewire component in
     * this story (D-10), so this action is the ONLY reachable enforcement
     * point -- the whole reason D-11 requires it to self-authorize.
     * `targetType: 'shipping_rate'` is passed explicitly, since
     * LogRefusedPrivilegedAttempt::resolveTarget() auto-resolves only User
     * and Role instances/classes; there is no `targetId` yet, matching
     * CreateShippingZone's / CreateProductCategory's own class-level
     * create-time call.
     *
     * `name` is trimmed BEFORE validation, not after: Laravel's `required`
     * treats a string of spaces as present, so without this a
     * whitespace-only name would validate and persist.
     *
     * `min_weight_kg` defaults to '0' when omitted from the payload (D-7,
     * matching the column's own `default(0)`) BEFORE validation runs -- so
     * minWeightRules()'s `required` rule is never actually tested against a
     * genuinely-absent field, and the value still round-trips through
     * validation like every other field rather than being applied silently
     * at the database layer only. `max_weight_kg` is left untouched when
     * omitted: an absent key means "and above" (D-4), and defaulting it
     * here would break that tier entirely.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): ShippingRate
    {
        $this->logRefusedPrivilegedAttempt->authorize('create', ShippingRate::class, targetType: 'shipping_rate');

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
            return ShippingRate::create($attributes);
        } catch (QueryException $e) {
            // Narrowed to 1452 (ER_NO_REFERENCED_ROW_2), NEVER the whole 23000
            // SQLSTATE class -- D-5's 2026-09-09 correction, applied here to this
            // action's own differently-shaped race: a carrier or zone row deleted
            // between Rule::exists() validation and this INSERT. The FK has the
            // last word; Rule::exists() above is a pre-flight check only.
            if (($e->errorInfo[1] ?? null) !== 1452) {
                throw $e;
            }

            // Phase 4 security-audit finding F-5: discriminate on the CONSTRAINT NAME, never
            // the column name -- QueryException::formatMessage() appends the WHOLE INSERT
            // statement to the message, which mentions 'shipping_carrier_id' as a column name
            // in every insert regardless of which FK actually failed, making a
            // str_contains($e->getMessage(), 'shipping_carrier_id') check wrong whenever the
            // ZONE fk is the one that failed. The constraint name is unambiguous and always
            // present in the message.
            $field = str_contains($e->getMessage(), 'shipping_rates_shipping_carrier_id_foreign')
                ? 'shipping_carrier_id'
                : 'shipping_zone_id';

            throw ValidationException::withMessages([
                $field => trans('validation.exists', ['attribute' => $field]),
            ]);
        }
    }
}
