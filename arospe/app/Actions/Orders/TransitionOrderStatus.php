<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderStatusRegressionRequiresConfirmationException;
use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Move an existing order along PRD §3.2's linear status ladder (`Pending` ->
 * `Processing` -> `Shipped` -> `Delivered`), advancing freely and refusing a
 * backward move unless the caller explicitly confirms it (story 0049).
 *
 * Performs, in exactly this order (D-3 -- the ordering is part of the guard,
 * not an implementation detail; each property below is pinned by its own
 * test rather than by a comment):
 *
 * 1. Authorize -- so the permission refusal always wins; an unauthorized
 *    caller must not learn the order's current status from a confirmation
 *    prompt.
 * 2. Refuse any Cancelled-involving transition, in either direction, with
 *    no confirmation path -- BEFORE any rank()/isBackwardFrom() call, so
 *    OrderStatus::rank()'s missing Cancelled arm can never raise
 *    \UnhandledMatchError here (D-6).
 * 3. Refuse a same-status transition -- $confirmed is not consulted (D-4).
 *    Runs above the regression check so Cancelled -> Cancelled is caught by
 *    step 2 rather than reaching rank() by coincidence of equal ranks.
 * 4. Refuse an unconfirmed backward move (D-2).
 * 5. Write, via forceFill() -- `status` is omitted from Order's #[Fillable].
 *
 * No DB::transaction(): a single-row, single-column write with no second
 * statement and no side effect to wrap (see the task file's own D-3 note).
 */
class TransitionOrderStatus
{
    public function __invoke(Order $order, OrderStatus $newStatus, bool $confirmed = false): Order
    {
        Gate::authorize('transitionStatus', $order);

        if ($order->status === OrderStatus::Cancelled || $newStatus === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => __('orders.transitions.cancellation_unsupported'),
            ]);
        }

        if ($newStatus === $order->status) {
            throw ValidationException::withMessages([
                'status' => __('orders.transitions.same_status'),
            ]);
        }

        if ($newStatus->isBackwardFrom($order->status) && ! $confirmed) {
            throw new OrderStatusRegressionRequiresConfirmationException;
        }

        $order->forceFill(['status' => $newStatus])->save();

        return $order;
    }
}
