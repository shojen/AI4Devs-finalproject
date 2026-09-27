<?php

namespace App\Actions\Users;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Story 0064c -- makes the persisted row the authority for `users.status`.
 *
 * The write is a guarded compare-and-set, in a single statement:
 * `UPDATE users SET status = 'active', updated_at = ? WHERE id = ? AND
 * status = 'inactive'`, issued through the Eloquent builder (not
 * `DB::table`) so `SoftDeletes` scoping applies. InnoDB evaluates that
 * `WHERE` as a current read, so a concurrent suspension that has already
 * committed makes this guard match zero rows even under REPEATABLE READ --
 * see docs/security/model-instance-trust.md and the task file's D-1.
 *
 * On a win (1 affected row) the caller's own instance is synced WITHOUT a
 * second write: `setAttribute()` for `status` and `updated_at`, then the
 * public `syncOriginalAttributes()`, which leaves `previous`/`changes`
 * alone so `getPrevious()` still carries whatever the caller's own prior
 * save put there, and the instance ends clean rather than dirty (D-3).
 * Never `refresh()`/`fresh()` (replaces every attribute and re-syncs
 * everything) and never `forceFill()->save()` (a second write).
 *
 * On a loss (0 affected rows) the caller's instance is left completely
 * untouched, and one plain, normally-scoped `SELECT status` tells the
 * causes apart (D-4/D-5). Because the lookup uses the same scoped
 * Eloquent query -- never `withTrashed()`, never `findOrFail()` -- an
 * absent row and a soft-deleted row both resolve to `null` and are
 * handled identically (silent): the default `SoftDeletes` global scope
 * already excludes a trashed row, so there is no need for a second,
 * separate "is it trashed" query to tell the two apart -- they take the
 * same action either way. A `null` (absent/soft-deleted) or an `active`
 * value (a concurrent double delivery) is silent; anything else (in
 * practice `suspended`) is a lost race against a privileged write and
 * logs exactly one refusal, with the user as both actor and target.
 */
class ActivateInactiveUser
{
    public function __construct(private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt) {}

    public function __invoke(User $user): bool
    {
        $now = $user->freshTimestamp();

        $affectedRows = User::query()
            ->whereKey($user->getKey())
            ->where('status', UserStatus::Inactive->value)
            ->update([
                'status' => UserStatus::Active->value,
                'updated_at' => $now,
            ]);

        if ($affectedRows === 1) {
            $user->setAttribute('status', UserStatus::Active);
            $user->setAttribute('updated_at', $now);
            $user->syncOriginalAttributes(['status', 'updated_at']);

            return true;
        }

        // Eloquent\Builder::value() hydrates a model and reads the attribute through it, so the
        // `status` cast (UserStatus::class) already applies here -- this is a UserStatus enum
        // instance (or null), never the raw database string.
        /** @var UserStatus|null $persistedStatus */
        $persistedStatus = User::query()->whereKey($user->getKey())->value('status');

        if ($persistedStatus !== null && $persistedStatus !== UserStatus::Active) {
            $this->logRefusedPrivilegedAttempt->log(
                $user,
                'activation_refused_status_changed',
                'user',
                $user->getKey(),
            );
        }

        return false;
    }
}
