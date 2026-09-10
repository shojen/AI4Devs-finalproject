<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Authorization rules for the Customers area (story 0041).
 *
 * Modelled directly on App\Policies\SalesRegionPolicy's shape: flat,
 * tier-free abilities delegating straight to hasPermissionTo(), permission
 * names as constants on the class that owns the rule, no target-dependent
 * branch on update(). A customer record can never be the acting user and
 * holds no privilege tier (D-11), so every rule reduces to "does the actor
 * hold customers.<action>" with zero row-level nuance -- that is what makes
 * the ability bodies trivial, not a reason to skip the class (D-12).
 *
 * Only the abilities something actually calls are defined: this story's own
 * two actions call `create`/`update`, and 0044's mount() is `viewAny`'s
 * first and only caller. `delete()` is deliberately absent -- 0042 adds it
 * to this same file.
 *
 * hasPermissionTo() inside a policy body is correct here, even though it
 * does not itself reach Gate::before -- a policy method is only ever
 * reached THROUGH the Gate, and the Super Admin is granted before the
 * policy is consulted at all. See docs/architecture/authorization.md.
 */
class CustomerPolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a
     * permission once on the class that owns the rule" convention.
     */
    public const VIEW_PERMISSION = 'customers.view';

    public const CREATE_PERMISSION = 'customers.create';

    public const EDIT_PERMISSION = 'customers.edit';

    /**
     * Determine whether the user can view the Customers screen.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can create a customer.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can update a customer.
     *
     * No target-dependent branch, and $target is deliberately ignored -- a
     * customer is a passive record with no privilege tier. The parameter is
     * kept anyway so the per-row Gate::allows() UI hint a future screen
     * renders asks the IDENTICAL method a write authorizes with, and so
     * that IF a later story ever does introduce a row-level rule, it edits
     * one method body rather than relocating every call site's target. If
     * such a branch is ever added, it must be evaluated against a freshly
     * re-fetched row rather than the caller's instance, per
     * SalesRegionPolicy's own Phase 4 note.
     */
    public function update(User $actor, Customer $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }
}
