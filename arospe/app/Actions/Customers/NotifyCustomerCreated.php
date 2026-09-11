<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreated;
use Illuminate\Support\Facades\Notification;

/**
 * Story 0043 -- resolves the recipient set and dispatches CustomerCreated.
 * A dedicated action rather than three lines inlined into CreateCustomer:
 * it keeps the cross-story surface to one constructor-injected dependency
 * and one call inside 0041's action, and gives the recipient rule its own
 * directly-callable test. Not an abstraction -- no base class, no
 * interface, no registry. Story 0046 (OrderCreated) copies this file's
 * SHAPE (resolve recipients by permission, Notification::send, one
 * concrete class), it does not inherit from it.
 *
 * Deliberately authorizes NOTHING of its own -- it is a collaborator
 * invoked only from inside App\Actions\Customers\CreateCustomer's own
 * already-authorized `create` flow (self-authorized via
 * App\Policies\CustomerPolicy before validation, before persistence), the
 * same "a collaborator invoked only by an already-authorized action needs
 * no gate" pattern App\Actions\Products\SyncProductGallery /
 * SyncProductSalesRegions / SyncProductAttributeValues and
 * App\Actions\Roles\EnforceGrantorPermissionScope already establish in this
 * codebase (see conventions/base-standards.md's directory-structure
 * section). This is structural, not an oversight, and it is enforced by
 * NotifyCustomerCreatedTest.php's own reachability assertion -- if a future
 * story ever calls this class directly from a second, independently
 * reachable entry point, that story owns adding a gate.
 */
class NotifyCustomerCreated
{
    public function __invoke(Customer $customer): void
    {
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
        $recipients = User::permission('customers.view')->get();

        Notification::send($recipients, new CustomerCreated($customer));
    }
}
