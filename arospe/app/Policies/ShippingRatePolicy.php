<?php

namespace App\Policies;

use App\Models\ShippingRate;
use App\Models\User;

/**
 * Authorization rules for shipping rate rules (story 0036).
 *
 * Gates on the already-seeded `shipping.*` module permissions -- no new
 * permission and no RolePermissionSeeder change. Matches
 * App\Policies\ShippingZonePolicy's exact shape: four abilities, no
 * per-target rule on any of them, and no `view`/`restore`/`forceDelete`
 * methods -- nothing in this app calls those for this model.
 *
 * All three write actions (App\Actions\Shipping\CreateShippingRate /
 * UpdateShippingRate / DeleteShippingRate) self-authorize against this
 * policy as their own first statement (D-11) -- so, unlike
 * ShippingZonePolicy at the time it first shipped, this policy has real
 * call sites from day one. `viewAny` is the one genuinely callerless
 * ability in this story: nothing here lists rates behind a gate
 * (App\Actions\Shipping\ListShippingRatesByCarrier is a plain query), and
 * 0037 is its gating consumer.
 *
 * D-5: the zone-delete in-use-by-a-rate-rule count guard does NOT belong
 * here, and does not belong on ShippingRatePolicy either -- it lives in
 * App\Actions\Shipping\DeleteShippingZone, because it is a data
 * precondition (a ValidationException with a count), not an authorization
 * rule, and a policy-level rule would be reachable by the Super Admin
 * Gate::before bypass, defeating the whole point of the guard.
 */
class ShippingRatePolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a
     * permission once on the class that owns the rule" convention.
     */
    public const VIEW_PERMISSION = 'shipping.view';

    public const CREATE_PERMISSION = 'shipping.create';

    public const EDIT_PERMISSION = 'shipping.edit';

    public const DELETE_PERMISSION = 'shipping.delete';

    /**
     * Determine whether the user can view the shipping rate catalog.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can create a shipping rate.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can update a shipping rate.
     *
     * No target-dependent branch -- there is no untouchable row in this
     * domain, the same shape ShippingZonePolicy::update() already
     * establishes.
     */
    public function update(User $actor, ShippingRate $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can delete a shipping rate.
     *
     * D-5: unlike shipping ZONES, a shipping RATE carries no in-use guard
     * of any kind -- deleting a rate blocks on nothing.
     */
    public function delete(User $actor, ShippingRate $target): bool
    {
        return $actor->hasPermissionTo(self::DELETE_PERMISSION);
    }
}
