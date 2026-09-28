<?php

namespace App\Actions\Users;

use App\Enums\UiLocale;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class SetUserUiLocale
{
    /**
     * Set the given user's admin UI locale preference.
     *
     * The column's single writer (story 0066, D-7). The self-only rule is
     * DERIVED from the authenticated user, never accepted as a parameter
     * (D-11): an HTTP caller (an authenticated actor present) may only ever
     * write their own row; a console/queue caller (no authenticated actor)
     * may target any user.
     *
     * @throws InvalidArgumentException if called with no authenticated actor and no explicit target.
     * @throws AuthorizationException if an authenticated actor targets a row that is not their own.
     */
    public function __invoke(UiLocale $locale, ?User $user = null): User
    {
        $actor = Auth::user();
        $target = $user ?? $actor;

        if ($target === null) {
            throw new InvalidArgumentException('SetUserUiLocale requires an explicit $user when there is no authenticated actor.');
        }

        if ($actor !== null && ! $target->is($actor)) {
            throw new AuthorizationException;
        }

        $target->forceFill(['ui_locale' => $locale->value])->save();

        return $target;
    }
}
