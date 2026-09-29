<?php

namespace App\Policies;

use App\Models\StoreLanguage;
use App\Models\User;

/**
 * Authorization rules for the Store Languages catalog (story 0068).
 *
 * Four abilities -- the MediaPolicy shape, not SalesRegionPolicy's two-of-four -- because unlike
 * the fixed, seeded region catalog, this one genuinely is admin-creatable and admin-removable, so
 * every seeded `store-languages.*` verb has a real call site: create/AddStoreLanguage,
 * delete/RemoveStoreLanguage, update/SetDefaultStoreLanguage (D14).
 *
 * `hasPermissionTo()` inside a policy body is correct even though it does not itself reach
 * `Gate::before` -- a policy method is only ever reached *through* the Gate, and a Super Admin
 * actor is granted before the policy is consulted at all. See docs/architecture/authorization.md.
 */
class StoreLanguagePolicy
{
    /**
     * Named once on the class that owns the rule, per naming.md's "name a permission once on the
     * class that owns the rule" convention. App\Policies\LocaleSettingPolicy borrows these two
     * (D25) rather than restating them.
     */
    public const VIEW_PERMISSION = 'store-languages.view';

    public const CREATE_PERMISSION = 'store-languages.create';

    public const EDIT_PERMISSION = 'store-languages.edit';

    public const DELETE_PERMISSION = 'store-languages.delete';

    /**
     * Determine whether the user can view the Store Languages screen.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can add a store language, by picking a code from the bundled
     * ISO 639-1 list (App\Actions\StoreLanguages\AddStoreLanguage).
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(self::CREATE_PERMISSION);
    }

    /**
     * Determine whether the user can change the store's default language
     * (App\Actions\StoreLanguages\SetDefaultStoreLanguage).
     */
    public function update(User $actor, StoreLanguage $target): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }

    /**
     * Determine whether the user can remove (deactivate) a store language
     * (App\Actions\StoreLanguages\RemoveStoreLanguage).
     */
    public function delete(User $actor, StoreLanguage $target): bool
    {
        return $actor->hasPermissionTo(self::DELETE_PERMISSION);
    }
}
