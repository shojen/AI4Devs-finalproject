<?php

// Story 0064c -- R-2 of story 0064a: App\Listeners\ActivateVerifiedUser decides on the in-memory
// User instance a caller hands it and (today) writes status=Active with a blind save(). If an
// administrator suspends the account between the moment that instance was loaded and the moment
// the listener's write runs, today's code overwrites Suspended with Active. This file reproduces
// that race DETERMINISTICALLY (decision D-6, "the stale instance"), never with a real
// two-connection lock-blocking test: no pcntl_fork, no second real database connection --
// RefreshDatabase keeps every write of this test uncommitted, so a second connection would see
// nothing and wait out InnoDB's lock timeout instead of proving anything about this code (reading
// note (e) of the task file's "Tests to perform" section).
//
// Never Event::fake() anywhere in this file (reading note (b)): the real dispatcher resolving the
// real listener (with its real, injected App\Actions\Users\ActivateInactiveUser) is the entire
// point. Never refresh()/fresh() the stale instance under test (reading note (d)): that instance
// staying stale is what makes the race real.
//
// Read this before touching a mutation: F1/F1b/F1c and F5 are expected RED against today's
// unmodified listener (it still calls $user->save() directly, with no compare-and-set); F3, F4,
// F6, F7 and F8 are expected to already pass today, because the in-memory guards they exercise are
// already correct -- they earn their place by the named mutations in the task file's D-8 table and
// under their own bullets, applied to the FINISHED implementation, not by being red now.
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Build the D-6 "stale instance" fixture: a persisted, unverified Inactive user, then a SECOND
 * load of that same row (instance A) that performs the exact write shape every real caller
 * performs before firing Verified -- its own forceFill(email_verified_at)->save() -- so
 * getPrevious() is populated exactly as Fortify's VerifyEmailController,
 * App\Actions\Fortify\ResetUserPassword and App\Actions\Users\ConfirmEmailChange leave it. The
 * returned instance is never refreshed again by this helper -- every caller of this helper owns
 * that responsibility (reading note (d)).
 */
function activateVerifiedUserStaleInstance(): User
{
    $user = User::factory()->unverified()->create();

    /** @var User $stale */
    $stale = User::query()->findOrFail($user->getKey());
    $stale->forceFill(['email_verified_at' => now()])->save();

    return $stale;
}

// =====================================================================
// F1 -- the wide-window race: a suspension lands between load and dispatch.
// =====================================================================

test('F1: a suspension that lands mid-request wins -- the stale instance never activates', function () {
    $stale = activateVerifiedUserStaleInstance();

    User::query()->whereKey($stale->getKey())->update(['status' => UserStatus::Suspended->value]);

    event(new Verified($stale));

    expect($stale->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($stale->status)->not->toBe(UserStatus::Active)
        ->and($stale->isDirty('status'))->toBeFalse();
});

// =====================================================================
// F1b -- a second delivery on the SAME stale instance changes nothing further.
// =====================================================================

test('F1b: a second delivery on the same stale instance still leaves the user suspended', function () {
    $stale = activateVerifiedUserStaleInstance();

    User::query()->whereKey($stale->getKey())->update(['status' => UserStatus::Suspended->value]);

    event(new Verified($stale));
    event(new Verified($stale));

    expect($stale->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($stale->status)->not->toBe(UserStatus::Active);
});

// =====================================================================
// F1c -- the same race inside an outer transaction (the ConfirmEmailChange shape).
// =====================================================================

test('F1c: the same race inside an outer transaction still leaves the user suspended', function () {
    $stale = activateVerifiedUserStaleInstance();

    User::query()->whereKey($stale->getKey())->update(['status' => UserStatus::Suspended->value]);

    DB::transaction(function () use ($stale): void {
        event(new Verified($stale));
    });

    expect($stale->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($stale->status)->not->toBe(UserStatus::Active);
});

// =====================================================================
// F3 -- a user already suspended before the request even started.
// =====================================================================

test('F3: a user suspended before the request is left alone, including the rows updated_at', function () {
    $user = User::factory()->create(['status' => UserStatus::Suspended, 'email_verified_at' => null]);

    // Every real caller writes email_verified_at and saves before firing Verified; a Suspended
    // user is no exception -- this must not be what stops the activation.
    $user->forceFill(['email_verified_at' => now()])->save();
    $updatedAtBeforeEvent = $user->fresh()->updated_at;

    Log::spy();

    event(new Verified($user));

    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($user->fresh()->updated_at->equalTo($updatedAtBeforeEvent))->toBeTrue();

    // D-4: the listener's existing in-memory Suspended early return is today's silent no-op and
    // must stay silent -- logging it would be a behaviour change (OQ-3(c)). If the in-memory
    // `status !== Inactive` guard were ever loosened to `status === Active` instead, this
    // Suspended user would fall through to the action, whose own compare-and-set would still
    // refuse the write (the row really is Suspended) -- but the action's own diagnosis SELECT
    // would then log a refusal that D-4 explicitly says must never fire for this branch. This
    // assertion is what actually kills that specific guard mutation; the status/updated_at
    // assertions above cannot, because the CAS predicate independently refuses either way.
    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// F4 -- a concurrent activation: the row is already Active, this stale instance still thinks
// Inactive (a double-click on the same verification link).
// =====================================================================

test('F4: a persisted concurrent activation is left alone -- stays active, nothing logged', function () {
    $stale = activateVerifiedUserStaleInstance();

    // A second, faster request already ran the full, real activation to completion.
    User::query()->whereKey($stale->getKey())->where('status', UserStatus::Inactive->value)
        ->update(['status' => UserStatus::Active->value]);
    $updatedAtAfterFirstActivation = $stale->fresh()->updated_at;

    Log::spy();

    event(new Verified($stale));

    expect($stale->fresh()->status)->toBe(UserStatus::Active)
        ->and($stale->fresh()->updated_at->equalTo($updatedAtAfterFirstActivation))->toBeTrue()
        ->and($stale->status)->toBe(UserStatus::Inactive);

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// F5 -- a soft delete lands mid-request. Red first: today's save() writes the trashed row.
// =====================================================================

test('F5: a user soft-deleted mid-request is not resurrected, and the deletion holds', function () {
    $stale = activateVerifiedUserStaleInstance();

    // A different instance -- an administrator's own load -- performs the deletion, so $stale
    // itself is never touched by anything but the event dispatch under test.
    $other = User::query()->findOrFail($stale->getKey());
    $other->delete();

    Log::spy();

    event(new Verified($stale));

    $trashed = User::withTrashed()->findOrFail($stale->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->status)->toBe(UserStatus::Inactive)
        ->and($stale->status)->toBe(UserStatus::Inactive);

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// F6 -- a hard delete lands mid-request.
// =====================================================================

test('F6: a user hard-deleted mid-request produces no exception, no row and no log', function () {
    $stale = activateVerifiedUserStaleInstance();

    DB::table('users')->where('id', $stale->getKey())->delete();

    Log::spy();

    event(new Verified($stale));

    expect(User::withTrashed()->find($stale->getKey()))->toBeNull()
        ->and($stale->status)->toBe(UserStatus::Inactive);

    Log::shouldNotHaveReceived('warning');
});

// =====================================================================
// F7 -- other Inactive users are untouched.
// =====================================================================

test('F7: activating one user leaves every other inactive user untouched', function () {
    $stale = activateVerifiedUserStaleInstance();

    $otherInactive = User::factory()->unverified()->create();
    $otherUpdatedAtBefore = $otherInactive->updated_at;

    event(new Verified($stale));

    expect($otherInactive->fresh()->status)->toBe(UserStatus::Inactive)
        ->and($otherInactive->fresh()->updated_at->equalTo($otherUpdatedAtBefore))->toBeTrue();
});

// =====================================================================
// F8 -- the legitimate case: a genuine first verification.
// =====================================================================

test('F8: a legitimate first verification activates, advances updated_at, and ends clean', function () {
    $stale = activateVerifiedUserStaleInstance();
    $updatedAtBeforeEvent = $stale->updated_at;

    $this->travel(5)->minutes();

    event(new Verified($stale));

    expect($stale->fresh()->status)->toBe(UserStatus::Active)
        ->and($stale->status)->toBe(UserStatus::Active)
        ->and($stale->isDirty())->toBeFalse()
        ->and($stale->fresh()->updated_at->greaterThan($updatedAtBeforeEvent))->toBeTrue()
        ->and($stale->getPrevious())->toHaveKey('email_verified_at')
        ->and($stale->getPrevious()['email_verified_at'])->toBeNull();
});
