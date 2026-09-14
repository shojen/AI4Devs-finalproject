<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Authorization rules for orders (story 0045).
 *
 * Gates on the already-seeded `orders.*` module permissions -- no new
 * permission and no RolePermissionSeeder change. Modelled on
 * App\Policies\ShippingRatePolicy (D-13): four flat abilities, no
 * per-target rule on any of them, and no `view`/`restore`/`forceDelete`
 * methods -- nothing in this app calls those for this model, and `orders`
 * has no `deleted_at` at all.
 *
 * `create` is the only ability with a real caller in this story
 * (App\Actions\Orders\CreateOrder, self-authorizing through
 * App\Actions\Auth\LogRefusedPrivilegedAttempt as its own first statement --
 * this story ships no route and no Livewire component, so that action is
 * the ONLY reachable enforcement point). `viewAny`/`update`/`delete` ship
 * with no caller yet, deliberately: stories 0048-0052 introduce genuinely
 * row-state-dependent rules (editing line items blocked once Shipped, a
 * refund refused outside Paid/PartiallyRefunded, etc) that each add a
 * branch to update()/delete()'s EXISTING body rather than creating the
 * class and relocating every call site's target -- see D-13 in the task
 * file for the full reasoning and the Phase 2 reversal of the original
 * "no policy" decision.
 *
 * The Gate::before Super Admin bypass applies unchanged, exactly as it does
 * to the other eleven policies.
 */
class OrderPolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a
     * permission once on the class that owns the rule" convention.
     */
    public const VIEW_PERMISSION = 'orders.view';

    public const CREATE_PERMISSION = 'orders.create';

    public const EDIT_PERMISSION = 'orders.edit';

    public const DELETE_PERMISSION = 'orders.delete';

    /**
     * Determine whether the user can view the order book.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can create an order.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can update an order.
     *
     * No target-dependent branch today -- there is no row-state rule yet,
     * the same starting shape ShippingRatePolicy::update() established.
     * Stories 0048-0052 add their row-state branches to this body.
     */
    public function update(User $actor, Order $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can delete an order.
     *
     * Orders are never hard-deleted this phase (Cancelled is a status, not
     * a delete) -- this ability ships flat, with no caller yet, for the
     * same "ability ownership drifts across an epic otherwise" reason as
     * update() above.
     */
    public function delete(User $actor, Order $target): bool
    {
        return $actor->hasPermissionTo(self::DELETE_PERMISSION);
    }
}
