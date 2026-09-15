<?php

namespace App\Actions\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderCreated;
use Illuminate\Support\Facades\Notification;

/**
 * Story 0046 -- resolves the recipient set and dispatches OrderCreated. A
 * shape copy of App\Actions\Customers\NotifyCustomerCreated (story 0043),
 * not an inheritance -- same collaborator shape, applied to a different
 * model: one constructor-free class, called only from
 * App\Actions\Orders\CreateOrder's own already-authorized `create` flow.
 *
 * Deliberately authorizes NOTHING of its own -- it is a collaborator
 * invoked only after CreateOrder's own DB::transaction() has committed
 * (self-authorized via App\Policies\OrderPolicy::create() before
 * validation, before persistence), the same "a collaborator invoked only
 * by an already-authorized action needs no gate" pattern
 * App\Actions\Customers\NotifyCustomerCreated / App\Actions\Products\
 * SyncProductGallery / SyncProductSalesRegions / SyncProductAttributeValues
 * and App\Actions\Roles\EnforceGrantorPermissionScope already establish in
 * this codebase. This is structural, not an oversight, and it is enforced
 * by NotifyOrderCreatedTest.php's own reachability assertion -- if a future
 * story ever calls this class directly from a second, independently
 * reachable entry point, that story owns adding a gate.
 */
class NotifyOrderCreated
{
    public function __invoke(Order $order): void
    {
        // F-1 (Phase 2 INVEST finding): App\Models\Order::customer() is a
        // plain belongsTo with no withTrashed(), and D-12 explicitly allows
        // an order against a soft-deleted customer so their order history
        // is never orphaned (see App\Actions\Orders\CreateOrder's own F-5).
        // Resolved with Customer::withTrashed()->findOrFail() -- findOrFail,
        // not find(), because customer_id is read from an already-persisted
        // Order (never caller input here) and restrictOnDelete() on
        // orders.customer_id makes a missing row structurally impossible;
        // findOrFail() states that as code rather than leaving a silently
        // possible null to dereference (Phase 4 audit finding F-C) -- and
        // set on the instance ONCE, here, before Notification::send() ever
        // calls OrderCreated::toArray() -- Notification::send() invokes
        // toArray() once per recipient, and each of those calls reads the
        // relation already held in memory rather than re-querying, which is
        // what keeps this a single customers query regardless of recipient
        // count (pinned by NotifyOrderCreatedTest.php's own query-count
        // assertion).
        //
        // Phase 4 audit finding F-D (accepted, not fixed): this mutates the
        // SAME $order instance CreateOrder returns to its own caller, so
        // that caller's Order->customer now resolves to a soft-deleted
        // customer where the model's own belongsTo (no withTrashed()) would
        // have given null. Not a data leak (D-12 already permits this, and
        // the order row's own frozen address columns already expose more
        // than the customer's name) -- but a caller of CreateOrder should
        // not rely on $order->customer's loaded state; re-resolve it
        // explicitly if a future screen needs it.
        $order->setRelation('customer', Customer::withTrashed()->findOrFail($order->customer_id));

        // User::permission() -- Spatie's own scope, matching a permission
        // held via a role OR directly, resolved LIVE at dispatch time
        // (never cached or snapshotted), against the `web` guard (the only
        // guard this app uses). "Gate on permissions, never role names"
        // (architecture/authorization.md) applied to a recipient set.
        //
        // Soft-deleted administrators are excluded for free by the
        // SoftDeletingScope already on User::query() -- no whereNull is
        // written here, and none should be added; a future withTrashed()
        // would silently start notifying deleted accounts.
        //
        // The Super Admin is deliberately excluded (D-1): their access
        // comes from the Gate::before bypass, an authorization-layer
        // construct that grants no role_has_permissions/model_has_permissions
        // row for this data query to match -- and that is the intended,
        // reversible outcome, not a gap. See this story's task file, D-1.
        $recipients = User::permission('orders.view')->get();

        Notification::send($recipients, new OrderCreated($order));
    }
}
