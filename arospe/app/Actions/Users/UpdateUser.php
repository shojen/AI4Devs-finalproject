<?php

namespace App\Actions\Users;

use App\Actions\Auth\EnsureRecentPasswordConfirmation;
use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateUser
{
    /**
     * `EnsureRecentPasswordConfirmation`'s __invoke() takes no arguments, so
     * it cannot be method-injected into this class's own __invoke() the way
     * `RequestEmailChange` is — every caller of this action (the Livewire
     * component and every direct-call test) invokes __invoke() with exactly
     * its six domain arguments. Constructor injection is the only shape that
     * keeps that signature unchanged while still resolving the guard from
     * the container (story 0015a).
     */
    public function __construct(
        private readonly EnsureRecentPasswordConfirmation $ensureRecentPasswordConfirmation,
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Update an existing user's name and, unless the target is the acting
     * user, their role and status.
     *
     * The email is never written here. When the normalised submitted
     * address differs from the user's current stored address, this
     * delegates to App\Actions\Users\RequestEmailChange, which parks it in
     * `pending_email` and mails the verification link to the new address —
     * `users.email`, `email_verified_at` and `status` are left exactly as
     * they were. This applies identically whether the target is another
     * user or the acting user's own row, so there is one email-change
     * mechanism in the app, not two.
     *
     * Authorizes the whole operation itself (story 0008a, hardened after its
     * own Phase 4 findings F1/N1/N2) — a caller-independent guard, not only
     * App\Livewire\Users\Index's. `Gate::authorize('update', $user)` covers
     * both the base `users.edit` ability and the Super Admin-target
     * exclusion, and runs unconditionally — including on a self-edit, same
     * as the dashboard's own `save()` — so a name-only edit can no longer
     * reach a write with no authorization check at all. The self-edit guard
     * that scopes role/status writes is derived here from the authenticated
     * user rather than accepted as a caller-supplied flag: once this action
     * is independently callable, a boolean parameter is a one-argument
     * bypass of the self-lockout protection.
     *
     * `$user->load('roles')` is the very first statement, above even
     * `Gate::authorize('update', ...)` — re-audit finding N1: that Gate call
     * resolves `UserPolicy::update()`'s Super Admin-target exclusion via
     * `$target->hasRole()`, which reads whatever roles collection is
     * already loaded on the instance. A caller that hydrated `$user` with
     * `->with('roles')` before invoking this action (the natural idiom, and
     * what App\Livewire\Users\Index::loadUsers() already does elsewhere in
     * this same component) could otherwise hand it a stale collection and
     * evade that exclusion. Reloading before any authorization check
     * consults the relation closes that for every check below, not only
     * the role-tier ones.
     */
    public function __invoke(
        User $user,
        string $name,
        string $email,
        string $roleId,
        UserStatus $status,
        RequestEmailChange $requestEmailChange,
    ): User {
        $user->load('roles');

        $this->logRefusedPrivilegedAttempt->authorize('update', $user);

        // Story 0064d -- the compare-and-set's baseline (D-1), captured from the caller's OWN
        // hydration, before this action refreshes anything below. The locked re-read inside the
        // transaction compares the row against THIS snapshot, never against whatever (A)'s refresh
        // below finds -- refreshing exists only to make the sensitive-attribute/step-up decision
        // consult current data, not to relocate what the write-time conflict check compares
        // against. Read the same way authorizeRoleAndStatusChange() itself reads them
        // (getRawOriginal() / the loaded roles relation).
        $originalStatus = $user->getRawOriginal('status');
        $originalRoleIds = $this->roleIds($user);

        // Defence in depth: the primary normalisation happens in the
        // component before validate() runs, so the uniqueness rule already
        // saw this lowercased value. Normalising again here keeps this
        // action correct even if a future caller skips that step.
        $email = Str::lower($email);

        $isSelfEdit = Auth::user()?->is($user) ?? false;

        if (! $isSelfEdit) {
            // Story 0064d -- refresh the instance immediately before the sensitive-attribute/
            // step-up decision consults it, so authorizeRoleAndStatusChange() below reasons about
            // the row as it stands NOW rather than whenever the caller (always
            // App\Livewire\Users\Index::save()) hydrated $user. Extends the identical staleness
            // guard `$user->load('roles')` above already established -- see its own docblock --
            // to the scalar attributes read by getRawOriginal('status')/('email'). Structurally
            // scoped to a non-self-edit: a self-edit never reaches authorizeRoleAndStatusChange()
            // at all, so refreshing here would cost a query for no decision it protects.
            $user->refresh();
            $user->load('roles');

            $this->authorizeRoleAndStatusChange($user, $roleId, $email, $status);
        }

        // Story 0015 finding F10: this delegation must run AFTER
        // authorizeRoleAndStatusChange() above (so the sensitive-attribute
        // gate always runs before any email change can be parked or
        // mailed) and BEFORE the DB::transaction() below (so a refusal here
        // -- RequestEmailChange's own throttle, or its pending_email
        // uniqueness collision -- never leaves the name/status/role writes
        // below persisted). It must NOT move inside that transaction:
        // RequestEmailChange ends with a non-transactional
        // Notification::route(...)->notify(...) call, and wrapping it would
        // relocate that side effect too -- see docs/errors-log.md's
        // "Wrapping existing code in a DB::transaction() moved a cache
        // flush nobody had written" entry. The residual this leaves is
        // strictly smaller than before: a later failure inside the
        // transaction can leave a `pending_email` parked with no other
        // change applied -- a reversible, non-privileged state the
        // target's own confirmation link governs.
        $currentEmail = Str::lower((string) $user->getRawOriginal('email'));

        if ($email !== $currentEmail) {
            $requestEmailChange($user, $email);
        }

        DB::transaction(function () use ($user, $name, $status, $roleId, $isSelfEdit, $originalStatus, $originalRoleIds): void {
            if (! $isSelfEdit) {
                // Story 0064d (D-1) -- a locked, verified compare-and-set: re-read the row by
                // primary key under lockForUpdate() and refuse as a conflict (never write
                // through) unless it still matches the snapshot captured before this action ever
                // touched $user. A missing row (the target deleted between form load and
                // submission, D-4) is refused through the identical path, not resurrected or
                // silently written through.
                /** @var User|null $lockedUser */
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                if ($lockedUser === null
                    || $lockedUser->getRawOriginal('status') !== $originalStatus
                    || $this->roleIds($lockedUser) !== $originalRoleIds) {
                    $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'update_conflict', 'user', $user->id);

                    throw ValidationException::withMessages([
                        'status' => __('users.update.conflict'),
                    ]);
                }

                // Sync $user's attributes from the LOCKED row -- the same primitive
                // Model::refresh() uses (setRawAttributes()/syncOriginal()), but fed from the
                // locked read and applied onto the same instance the caller passed in, never a
                // new object: App\Livewire\Users\Index::updateExistingUser() mutates and re-reads
                // this same $target reference after this call for its own audit-log line.
                $user->setRawAttributes($lockedUser->getAttributes());
                $user->syncOriginal();
            }

            $user->fill(['name' => $name]);

            if (! $isSelfEdit) {
                // Property assignment, not an array key, so the enum cast is
                // preserved rather than writing the raw backing string.
                $user->status = $status;
            }

            $user->save();

            if (! $isSelfEdit) {
                $user->syncRoles([(int) $roleId]);
            }
        });

        return $user;
    }

    /**
     * The decision-relevant role-id set, canonicalised for a reliable equality comparison between
     * two separate reads (the pre-refresh snapshot and the locked, at-write-time row) -- sorted,
     * since two reads of the same set are not guaranteed to return rows in the same order.
     *
     * @return array<int, int>
     */
    private function roleIds(User $user): array
    {
        return $user->roles->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    }

    /**
     * Authorize an in-flight role/status/email change against the
     * Administrator-level guards, before any write.
     *
     * The target's role was already loaded fresh by __invoke() (never the
     * possibly-stale cached relation), then read the same way both here and
     * in App\Policies\UserPolicy — `$user->hasRole()` / `$user->roles`, over
     * the whole roles collection — rather than by comparing a single
     * arbitrarily-chosen row, so the two cannot disagree for a target
     * holding more than one role (Phase 4 finding F3). The no-op re-save
     * exemption is likewise a set comparison (the submitted role is the
     * target's only current role), not an unordered `first()`.
     *
     * The submitted role is resolved as a fully-hydrated row — never an
     * id-to-id comparison against a name lookup — and handed to
     * Role::isAdministratorRole() / isSuperAdminRoleRow(), the single shared
     * identity checks. Both directions of the Super Admin tier are refused
     * outright, as **direct throws, never through `Gate`**: a `Gate`-based
     * refusal is undone by a Super Admin actor's own `Gate::before` bypass,
     * which decides before any policy method runs. Assigning the Super
     * Admin role is refused (Phase 4 finding F1); so is modifying a target
     * that *currently holds* it (Phase 4 re-audit finding N2) — the
     * user-side mirror of the role-side guards App\Models\Role::
     * syncModels() / assignToModels() / removeFromModels() already carry,
     * which exist for the identical reason: `syncRoles()` below replaces
     * the target's entire role set, and stripping the platform's own Super
     * Admin this way would be an irrecoverable lockout.
     */
    private function authorizeRoleAndStatusChange(User $user, string $roleId, string $email, UserStatus $status): void
    {
        /** @var Collection<int, Role> $currentRoles */
        $currentRoles = $user->roles;

        if ($currentRoles->contains(fn (Role $role): bool => Role::isSuperAdminRoleRow($role))) {
            // Story 0015b: a non-Gate, direct-throw refusal, logged
            // immediately before the existing throw.
            $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'super_admin_holder_protected', 'user', $user->id);

            throw new AuthorizationException('A Super Admin holder cannot be modified through this action.');
        }

        $submittedRole = Role::query()->find((int) $roleId);

        if ($submittedRole !== null && Role::isSuperAdminRoleRow($submittedRole)) {
            $this->logRefusedPrivilegedAttempt->log(Auth::user(), 'assign_super_admin_role', 'user', $user->id);

            throw new AuthorizationException('The Super Admin role cannot be assigned.');
        }

        $currentRoleIds = $currentRoles->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $isNoOpRoleChange = $currentRoleIds === [(int) $roleId];

        if (! $isNoOpRoleChange) {
            $wasAdministrator = $user->hasRole(RoleName::Administrator->value, 'web');
            $willBeAdministrator = $submittedRole !== null && Role::isAdministratorRole($submittedRole);

            if ($willBeAdministrator && ! $wasAdministrator) {
                $this->logRefusedPrivilegedAttempt->authorize('promoteToAdministrator', $user);
            } elseif ($wasAdministrator && ! $willBeAdministrator) {
                $this->logRefusedPrivilegedAttempt->authorize('downgrade', $user);
            }
        }

        // Compared against the PERSISTED status, never the in-memory
        // attribute (Phase 4 finding F2): a caller that already staged
        // $user->status = $status before invoking this action would
        // otherwise make this comparison silently false, skipping the gate
        // for a status change that is about to be persisted regardless.
        $emailChanged = $email !== Str::lower((string) $user->getRawOriginal('email'));
        $statusChanged = $status->value !== $user->getRawOriginal('status');

        if ($emailChanged || $statusChanged) {
            $this->logRefusedPrivilegedAttempt->authorize('updateSensitiveAttributes', $user);
        }

        // Story 0015a — step-up authentication. Widened by Phase 4 finding
        // F2 (decision D7): the condition is now exactly
        // updateSensitiveAttributes's own `$emailChanged || $statusChanged`
        // PLUS a genuine role change (`! $isNoOpRoleChange`) -- so a
        // third-party email change is step-up-gated too, matching
        // updateSensitiveAttributes's own "an email rewrite is
        // severity-equivalent to account takeover" rule. This method only
        // runs when `! $isSelfEdit` (see __invoke()), so a self-service
        // email change never reaches this branch at all -- the exemption is
        // structural, not a second condition here. Placed after every
        // Gate::authorize() call on this branch (promoteToAdministrator /
        // downgrade above, updateSensitiveAttributes immediately above) so a
        // permission refusal always wins over a step-up refusal — including
        // for a Super Admin actor, since this is a direct throw rather than
        // a Gate check, so Gate::before's bypass does not exempt it. Still
        // above the first write: this whole method runs above __invoke()'s
        // DB::transaction().
        if (! $isNoOpRoleChange || $emailChanged || $statusChanged) {
            ($this->ensureRecentPasswordConfirmation)();
        }
    }
}
