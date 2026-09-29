<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Authorization rules for orders (story 0045).
 *
 * Gates on the already-seeded `orders.*` module permissions -- no new
 * permission and no RolePermissionSeeder change. Modelled on
 * App\Policies\ShippingRatePolicy (D-13): flat abilities, no per-target
 * rule on any of them, and no `view`/`restore`/`forceDelete` methods --
 * nothing in this app calls those for this model, and `orders` has no
 * `deleted_at` at all.
 *
 * `create` is the only ability with a real caller from story 0045
 * (App\Actions\Orders\CreateOrder, self-authorizing through
 * App\Actions\Auth\LogRefusedPrivilegedAttempt as its own first statement).
 * `update` gained three real callers in story 0048
 * (AddOrderItem/RemoveOrderItem/UpdateOrderItemQuantity). `transitionStatus`
 * is story 0049's fifth ability, reusing EDIT_PERMISSION (see its own
 * docblock below). `viewAny`/`delete` ship with no caller yet, deliberately
 * -- see D-13 in story 0045's task file for the "add a branch to an
 * existing ability's body rather than relocating a call site" reasoning.
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
     * Story 0050 -- the first class to name `orders.refund` with a single
     * constant. Story 0051 (`App\Actions\Orders\RecordRefund`) seeds and
     * consumes this permission with a bare literal, deliberately, since
     * that story creates no OrderPolicy ability of its own (its own DR-2).
     * `cancel()` below is the first class to DECIDE with this permission,
     * so per naming.md's "name a permission once on the class that owns
     * the rule" convention, the constant lands here.
     */
    public const ORDER_REFUND_PERMISSION = 'orders.refund';

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

    /**
     * Determine whether the user can transition an order's status.
     *
     * Story 0049's fifth ability, reusing the existing EDIT_PERMISSION
     * constant rather than declaring a new one (D-7): changing an order's
     * status is editing it, performed by the same administrator working the
     * same order book. Reduces to `orders.edit` today and takes the Order
     * anyway -- every sibling ability (0050-0052) is genuinely row-state-
     * dependent, and a method that has to change its signature to acquire a
     * target is a worse starting point than one that ignores an argument it
     * already receives.
     */
    public function transitionStatus(User $actor, Order $order): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can manually cancel the order.
     *
     * Story 0050's sixth ability, and this repo's first requiring TWO
     * permissions rather than one (D-6, a human product decision):
     * cancelling an order is administratively paired with a refund in
     * practice, so an actor trusted to cancel must also be trusted to
     * handle the refund conversation that usually follows. Holding either
     * permission alone is refused -- this is an intersection, not either
     * permission being interchangeable with the other.
     *
     * The state clause (`isManuallyCancellable()`) is here so a later UI
     * hint agrees with the guard by construction, but it is INERT for a
     * Super Admin: Gate::before bypasses this whole method, state clause
     * included, before it ever runs. The real enforcement is
     * App\Actions\Orders\CancelOrder's own direct throw, which binds every
     * actor including a Super Admin -- read this method's state clause as
     * the hint half of that pair, never the authority.
     *
     * Practical consequence for an ORDINARY actor: because this state
     * clause also runs inside Gate::authorize('cancel', $order) --
     * CancelOrder's own first statement -- an ordinary, fully-permitted
     * actor attempting to cancel a guarded-state order is refused right
     * there, as an AuthorizationException, and never reaches
     * CancelOrder's own already-cancelled/blocked-state checks at all.
     * Those checks exist to bind the one actor this method's clause
     * cannot: a Super Admin.
     */
    public function cancel(User $actor, Order $order): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION)
            && $actor->hasPermissionTo(self::ORDER_REFUND_PERMISSION)
            && $order->isManuallyCancellable();
    }
}
