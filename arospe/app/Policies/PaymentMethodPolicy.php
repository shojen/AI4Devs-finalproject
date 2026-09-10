<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Authorization rules for the Payment Methods store-settings screen
 * (story 0038).
 *
 * `create()`/`delete()` explicitly return `false` rather than being left
 * undefined -- an undefined ability makes `Gate::authorize('create', ...)`
 * throw BadMethodCallException (a 500), while an explicit `false` yields a
 * clean, assertable `Gate::denies(...)`. Bank transfer is the only payment
 * method this phase, and no code path creates or deletes a second one --
 * `Administrator` legitimately holds `payment-methods.create`/`.delete`
 * from the seeded module x action grid, but this policy is what still
 * refuses both regardless.
 *
 * **Not a Super Admin refusal (Phase 4 security audit finding F-4).**
 * `AppServiceProvider`'s `Gate::before` bypass short-circuits before this
 * policy's `create()`/`delete()` ever run, for any target other than the
 * Super Admin `Role` row -- so `Gate::allows('create', PaymentMethod::class)`
 * IS `true` for a Super Admin actor, matching the identical, already-
 * documented behaviour for `RolePolicy::delete()`'s Administrator refusal
 * (see docs/architecture/authorization.md). The "bank transfer is the only
 * method" invariant holds for a Super Admin too, but through layers 1 and
 * 3 of the three named in this story's own task file (no create/delete
 * code path exists anywhere; `code` is not mass-assignable and is unique)
 * -- never through this policy alone.
 */
class PaymentMethodPolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a
     * permission once on the class that owns the rule" convention.
     */
    public const VIEW_PERMISSION = 'payment-methods.view';

    public const EDIT_PERMISSION = 'payment-methods.edit';

    /**
     * Determine whether the user can view the payment methods catalog.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can configure a payment method's IBAN.
     *
     * No target-dependent branch, matching SalesRegionPolicy::update()'s
     * shape -- kept as an instance method anyway so a future per-row
     * Gate::allows() UI hint reuses the identical method a target-dependent
     * rule would need.
     */
    public function update(User $actor, PaymentMethod $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Bank transfer is the only payment method this phase -- no create path
     * exists, and none may.
     */
    public function create(User $actor): bool
    {
        return false;
    }

    /**
     * Bank transfer is the only payment method this phase -- no delete path
     * exists, and none may.
     */
    public function delete(User $actor, PaymentMethod $target): bool
    {
        return false;
    }
}
