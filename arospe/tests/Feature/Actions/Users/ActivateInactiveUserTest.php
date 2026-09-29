<?php

// Story 0064c -- App\Actions\Users\ActivateInactiveUser does not exist yet. This is the RED half
// of TDD: every test in this file is expected to fail (most with a class-not-found error, since
// app(ActivateInactiveUser::class) cannot resolve a class that has no file) until backend-expert
// creates it at Phase 3 step 2. See the task file's "Tests to perform" reading note (a).
//
// The action collapses one decision -- "is this row still inactive?" -- onto one guarded UPDATE
// (decision D-1): `UPDATE users SET status='active', updated_at=? WHERE id=? AND status='inactive'`,
// through the Eloquent builder (so SoftDeletes scoping applies). On a win (1 row) it syncs the
// caller's own instance without a second write (D-3): setAttribute('status', Active),
// setAttribute('updated_at', $now), then the public syncOriginalAttributes(['status',
// 'updated_at']) -- never refresh()/fresh() (replaces every attribute and re-syncs everything) and
// never forceFill()->save() (a second write). On a loss (0 rows) the instance is left completely
// untouched (D-4): a plain SELECT status then tells the causes apart -- absent/soft-deleted and
// already-Active are both silent; anything else (in practice Suspended) gets one warning via the
// existing App\Actions\Auth\LogRefusedPrivilegedAttempt, with the user as both actor and target
// and the snake_case reason 'activation_refused_status_changed' (task file's docs table entry,
// confirmed here at Phase 3 -- backend-expert must use this exact string).

use App\Actions\Users\ActivateInactiveUser;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @param  array<int, mixed>  $context
 */
function activateInactiveUserLogContextMatchesRefusal(string $message, array $context, User $user): bool
{
    return $message === 'Privileged action refused'
        && ($context['actor_id'] ?? null) === $user->id
        && ($context['ability'] ?? null) === 'activation_refused_status_changed'
        && ($context['target_type'] ?? null) === 'user'
        && ($context['target_id'] ?? null) === $user->id;
}

// =====================================================================
// Win.
// =====================================================================

test('activates an inactive user through exactly one guarded UPDATE and syncs the instance clean', function () {
    $user = User::factory()->inactive()->create();

    /** @var list<object> $updates */
    $updates = [];
    DB::listen(function ($query) use (&$updates): void {
        if (preg_match('/^update\s+[`"]?users[`"]?\s/i', $query->sql) === 1) {
            $updates[] = $query;
        }
    });

    $result = app(ActivateInactiveUser::class)($user);

    expect($result)->toBeTrue()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->isDirty())->toBeFalse()
        ->and($user->fresh()->status)->toBe(UserStatus::Active)
        ->and($updates)->toHaveCount(1);

    // The structural pin (D-1's whole reason for existing): the predicate that makes the write a
    // compare-and-set, not a blind UPDATE, actually reached the database.
    expect($updates[0]->bindings)->toContain(UserStatus::Inactive->value);
});

test('activates via the targeted sync, not refresh(), leaving every other in-memory change alone', function () {
    $user = User::factory()->inactive()->create();

    // A genuinely dirty, uncommitted in-memory change to an attribute the win branch never
    // touches. `name` is an ordinary #[Fillable] string column with no cast and no side effects
    // from being mutated in memory without a save() -- safe to diverge from the database. This is
    // the assertion D-3 needs but never had: the real targeted sync (setAttribute()/setAttribute()
    // on exactly `status` and `updated_at`, then the public syncOriginalAttributes(['status',
    // 'updated_at'])) leaves every other attribute alone, dirty or not. `refresh()` -- D-3's
    // rejected alternative -- re-fetches the whole row and overwrites every attribute via
    // setRawAttributes()/syncOriginal(), which would silently discard this uncommitted `name` and
    // mark the instance clean on it too. Story 0064c's D-8 mutation A12 (refresh() instead of the
    // targeted sync) is invisible to every other test in this file/suite -- see the task file's
    // "Mutation verification run" -- because refresh() happens to reproduce F8's asserted end
    // state for `status`/`updated_at`/getPrevious(). Only an attribute the action never touches,
    // asserted both for its uncommitted value and its dirty flag, tells the two implementations
    // apart.
    $user->name = 'Not Yet Saved';

    $result = app(ActivateInactiveUser::class)($user);

    expect($result)->toBeTrue()
        ->and($user->name)->toBe('Not Yet Saved')
        ->and($user->isDirty('name'))->toBeTrue();
});

// =====================================================================
// Lost to a suspension.
// =====================================================================

test('loses to a suspension that already landed, leaves the instance untouched, and logs one refusal', function () {
    Log::spy();

    $user = User::factory()->inactive()->create();

    // A stale instance's own view is Inactive; an administrator's own write (query-builder, no
    // model event) already suspended the row before the action runs.
    User::query()->whereKey($user->getKey())->update(['status' => UserStatus::Suspended->value]);
    $updatedAtAfterSuspension = $user->fresh()->updated_at;

    $result = app(ActivateInactiveUser::class)($user);

    expect($result)->toBeFalse()
        ->and($user->status)->toBe(UserStatus::Inactive)
        ->and($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($user->fresh()->updated_at->equalTo($updatedAtAfterSuspension))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => activateInactiveUserLogContextMatchesRefusal($message, $context, $user))
        ->once();
});

// =====================================================================
// Lost to a concurrent activation.
// =====================================================================

test('loses to a concurrent activation, leaves the instance untouched, and logs nothing', function () {
    Log::spy();

    $user = User::factory()->inactive()->create();

    // Someone else's request already activated the same row (a double-click on the same link).
    User::query()->whereKey($user->getKey())->update(['status' => UserStatus::Active->value]);
    $updatedAtAfterConcurrentActivation = $user->fresh()->updated_at;

    $result = app(ActivateInactiveUser::class)($user);

    expect($result)->toBeFalse()
        ->and($user->status)->toBe(UserStatus::Inactive)
        ->and($user->fresh()->status)->toBe(UserStatus::Active)
        ->and($user->fresh()->updated_at->equalTo($updatedAtAfterConcurrentActivation))->toBeTrue();

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// Absent row.
// =====================================================================

test('an absent row returns false with no exception and no log', function () {
    Log::spy();

    $user = User::factory()->inactive()->create();

    // A hard delete via the query builder, bypassing the model entirely -- the row is gone, not
    // merely trashed.
    DB::table('users')->where('id', $user->getKey())->delete();

    $result = app(ActivateInactiveUser::class)($user);

    expect($result)->toBeFalse()
        ->and($user->status)->toBe(UserStatus::Inactive)
        ->and(User::withTrashed()->find($user->getKey()))->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// Soft-deleted row.
// =====================================================================

test('a soft-deleted row returns false with no exception and no log', function () {
    Log::spy();

    $user = User::factory()->inactive()->create();
    $user->delete();

    $result = app(ActivateInactiveUser::class)($user);

    expect($result)->toBeFalse()
        ->and($user->status)->toBe(UserStatus::Inactive)
        ->and(User::withTrashed()->findOrFail($user->getKey())->trashed())->toBeTrue();

    Log::shouldNotHaveReceived('warning');
});
