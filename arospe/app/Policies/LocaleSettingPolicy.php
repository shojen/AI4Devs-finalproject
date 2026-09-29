<?php

namespace App\Policies;

use App\Models\LocaleSetting;
use App\Models\User;

/**
 * Authorization rules for the app's default-locale settings (story 0068).
 *
 * Two abilities only. Its permission constants deliberately REFERENCE StoreLanguagePolicy's
 * rather than restating the literal strings (D25): a new permission slug is effectively
 * forbidden here (RolePermissionSeeder::MODULES is a fixed 10x4 grid this story does not change),
 * and the settings are managed on the same screen as the Store Languages catalog, so they borrow
 * `store-languages.view` / `.edit` -- the same "one tier spans logically-distinct operations on a
 * shared screen" precedent SalesRegionPolicy::update() already establishes. The alias, rather
 * than a second literal, is what stops the two constants silently drifting apart on a future
 * rename. Stated cost (R-15): anyone holding `store-languages.edit` also controls these two
 * defaults.
 */
class LocaleSettingPolicy
{
    public const VIEW_PERMISSION = StoreLanguagePolicy::VIEW_PERMISSION;

    public const EDIT_PERMISSION = StoreLanguagePolicy::EDIT_PERMISSION;

    /**
     * Determine whether the user can view the default-locale settings.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(self::VIEW_PERMISSION);
    }

    /**
     * Determine whether the user can change either default locale
     * (App\Actions\Localization\SetDefaultUiLocale / SetDefaultNotificationLocale).
     *
     * $target is nullable because there is only ever one settings row, and both actions
     * authorize against the class itself before the row necessarily exists yet (a plain
     * Gate::authorize('update', LocaleSetting::class) call, which Laravel's policy resolution
     * invokes with no second argument at all).
     */
    public function update(User $actor, ?LocaleSetting $target = null): bool
    {
        return $actor->hasPermissionTo(self::EDIT_PERMISSION);
    }
}
