<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderCancellationBlockedException;
use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Manually cancel an existing order (story 0050, PRD §3.2), permitted only
 * while `Pending`/`Processing` and never while `payment_status` is
 * `PartiallyRefunded` -- refused with no confirmation path in every other
 * state.
 *
 * An independent action, not a call into or out of `TransitionOrderStatus`
 * (D-5): cancellation is a state-machine allow-list over TWO dimensions
 * (`status` AND `payment_status`), where `TransitionOrderStatus` is a rank
 * comparison over one. `OrderStatus::rank()` deliberately has no
 * `Cancelled` arm (story 0049 D-3), so cancellation cannot be expressed as
 * a rank comparison at all without reopening that guard.
 *
 * Performs, in exactly this order (each property below is pinned by its
 * own test rather than by a comment):
 *
 * 1. `Gate::authorize('cancel', $order)` -- so the permission refusal
 *    always wins; an unauthorized caller must not learn the order's
 *    current state from the refusal that follows.
 * 2. Refuse if the order is already `Cancelled`, with a
 *    `ValidationException` on the `status` field (D-3) -- runs ABOVE the
 *    state guard below, or an already-cancelled order (not in
 *    `{Pending, Processing}`) would fall into the blocked branch and
 *    report the wrong reason for the wrong case.
 * 3. Refuse if `! $order->isManuallyCancellable()`, via a DIRECT THROW of
 *    `OrderCancellationBlockedException` -- never a second `Gate` check,
 *    so it binds a Super Admin actor too (see `OrderPolicy::cancel()`'s
 *    own docblock for why a Gate-mediated state rule would be inert
 *    against exactly that actor).
 * 4. Write, via `forceFill()` -- `status` is omitted from `Order`'s
 *    `#[Fillable]`.
 *
 * No `DB::transaction()`: a single-row, single-column write with no
 * second statement and no side effect to wrap, matching
 * `TransitionOrderStatus`'s own D-3 note. `payment_status` is read and
 * never written (D-1) -- cancelling triggers no refund; refunding stays
 * `App\Actions\Orders\RecordRefund`'s separately-performed operation.
 */
class CancelOrder
{
    /**
     * There is no `bool $confirmed` parameter, and its absence is
     * specified (D-7): PRD §3.2 says manual cancellation in the guarded
     * states is "blocked", full stop -- unlike the backward transition
     * `TransitionOrderStatus` explicitly makes confirmable, so a
     * confirmation bypass would contradict the PRD.
     */
    public function __invoke(Order $order): Order
    {
        Gate::authorize('cancel', $order);

        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => __('orders.cancellation.already_cancelled'),
            ]);
        }

        if (! $order->isManuallyCancellable()) {
            throw new OrderCancellationBlockedException;
        }

        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        return $order;
    }
}
