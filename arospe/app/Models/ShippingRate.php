<?php

namespace App\Models;

use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-carrier rate rule: a shipping zone plus a weight bracket, carrying a
 * price and a free-text delivery estimate (story 0036, PRD §2.4 AC 2/AC 3).
 *
 * Deliberately NOT soft-deleted (D-14): the zone-delete guard's in-use count
 * (App\Actions\Shipping\DeleteShippingZone) means what it says only because a
 * deleted rate is genuinely gone -- adding SoftDeletes later would silently
 * make the count start excluding trashed rates with no edit to the guard
 * (0024 D-12's recorded trap), and it would let a trashed rate keep blocking
 * its zone's deletion at the FK level while being invisible in the count.
 *
 * `product_id`-shaped columns (`shipping_carrier_id`, `shipping_zone_id`) ARE
 * fillable here, unlike `product_variants.product_id` -- a rate's parent is
 * chosen by the administrator on every save (reassigning a rate to a
 * different zone is a supported operation, D-5), not fixed at creation.
 *
 * @property string $id
 * @property string $name
 * @property string $shipping_carrier_id
 * @property string $shipping_zone_id
 * @property string $min_weight_kg
 * @property string|null $max_weight_kg
 * @property string $price
 * @property string $delivery_estimate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ShippingCarrier $carrier
 * @property-read ShippingZone $zone
 */
#[Fillable(['name', 'shipping_carrier_id', 'shipping_zone_id', 'min_weight_kg', 'max_weight_kg', 'price', 'delivery_estimate'])]
class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:N casts return a STRING, never a float (0024 R-4) --
            // @property string $price / $min_weight_kg / $max_weight_kg above
            // documents this deliberately rather than as an oversight.
            'min_weight_kg' => 'decimal:3',
            'max_weight_kg' => 'decimal:3',
            'price' => 'decimal:2',
        ];
    }

    /**
     * The carrier this rate rule belongs to.
     *
     * @return BelongsTo<ShippingCarrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'shipping_carrier_id');
    }

    /**
     * The shipping zone this rate rule applies to.
     *
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * Scope to rates whose weight bracket covers the given parcel weight.
     *
     * Inclusive at BOTH ends (D-3): `min_weight_kg <= $weight <= max_weight_kg`,
     * with the upper bound skipped entirely when `max_weight_kg` is null. The
     * nested `orWhereNull` is the open-ended "and above" tier (D-4) -- without
     * it, `max_weight_kg >= $weight` evaluates to NULL (not true) for every
     * open-ended tier and silently drops them all (R-1). This is the ONLY
     * place this bracket logic may live -- every caller (validation,
     * App\Actions\Shipping\ResolveApplicableShippingRate) reuses this scope
     * rather than re-deriving the comparison.
     *
     * @param  Builder<ShippingRate>  $query
     */
    public function scopeCoveringWeight(Builder $query, float|string $weight): void
    {
        $query->where('min_weight_kg', '<=', $weight)
            ->where(function (Builder $query) use ($weight): void {
                $query->whereNull('max_weight_kg')
                    ->orWhere('max_weight_kg', '>=', $weight);
            });
    }
}
