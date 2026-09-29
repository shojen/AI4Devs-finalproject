<?php

namespace App\Actions\Shipping;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\ShippingRate;

class DeleteShippingRate
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Delete a shipping rate rule.
     *
     * Self-authorizes `delete` on the target $shippingRate as its own first
     * statement, above the delete (D-11, Phase 2 review finding B1).
     *
     * NO in-use guard, and no count of any kind -- deleting a RATE blocks on
     * nothing. The guard in this story belongs to
     * App\Actions\Shipping\DeleteShippingZone (D-5); this action must not
     * grow a sibling of it -- that is the exact misattribution D-5's
     * 2026-08-19 correction already records from the other direction.
     *
     * A plain instance ->delete(), through the model, never the query
     * builder (base-standards.md's "deleting a user goes through the model,
     * not the query builder" convention, applied here).
     */
    public function __invoke(ShippingRate $shippingRate): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'delete',
            $shippingRate,
            targetType: 'shipping_rate',
            targetId: $shippingRate->id,
        );

        return (bool) $shippingRate->delete();
    }
}
