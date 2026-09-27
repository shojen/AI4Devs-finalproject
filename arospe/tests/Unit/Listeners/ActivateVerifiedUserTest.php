<?php

use App\Enums\UserStatus;
use App\Listeners\ActivateVerifiedUser;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;

// The listener's own decision logic (when it transitions `status`, when it persists) is what
// this file isolates. The DB write it triggers on the activation branch is stubbed via a
// partial mock of `User::save()` so this stays a `tests/Unit/` test (no real database write) —
// the actual persisted effect, reached through the real HTTP route, is covered separately in
// tests/Feature/Settings/EmailChangeTest.php per this story's "Tests to perform" list.
//
// Phase 4 security audit (F1, story 0007): the listener now reads
// $user->getPrevious()['email_verified_at'] rather than the live attribute or
// getOriginal() -- see the listener's own docblock for why. Two consequences
// for these tests:
//
// 1. Eloquent's getOriginal() (which getPrevious() does NOT call, but which a
//    naive alternative implementation might) internally does `(new static)` to
//    rewind the model. A Mockery partial mock's `static` resolves to Mockery's
//    own generated subclass, and constructing that class directly (bypassing
//    Mockery::mock()'s factory) produces a *strict* mock with no expectations
//    set for __construct, which throws. So every test below that needs
//    getPrevious()/getOriginal() semantics uses a plain `User` subclass with
//    save() overridden to just record the call, instead of a Mockery mock.
// 2. getPrevious() is only populated by Eloquent's own syncChanges() -- called
//    from inside performUpdate()/performInsert(), which our double's save()
//    override deliberately bypasses (no real persistence, no app/DB
//    bootstrap available in tests/Unit; see tests/Pest.php). So each test that
//    needs a populated getPrevious() calls the model's own (public)
//    syncChanges() directly, after hydrating the "before" state via
//    setRawAttributes(..., true) (exactly what Eloquent does when hydrating a
//    row from a real query) and then changing the live attribute -- which is
//    exactly the dirty-tracking state a real save() would have produced right
//    before firing Verified.
final class ActivateVerifiedUserTrackedUser extends User
{
    public bool $saveWasCalled = false;

    // Story 0064a: idempotency needs "how many times", not just "whether". Deliberately does not
    // call syncChanges(), so getPrevious() keeps its pre-save value across the whole test and a
    // second delivery on the same instance is stopped by the status guard alone.
    public int $saveCallCount = 0;

    // Unit tests in this repo boot no Laravel application at all (see
    // tests/Pest.php -- only Feature/Browser extend Tests\TestCase), so
    // Eloquent's date-cast conversion cannot fall back to
    // $this->getConnection()->getQueryGrammar()->getDateFormat() the way it
    // normally would; a fixed $dateFormat avoids that connection lookup
    // entirely.
    protected $dateFormat = 'Y-m-d H:i:s';

    public function save(array $options = []): bool
    {
        $this->saveWasCalled = true;
        $this->saveCallCount++;

        return true;
    }
}

test('activates an inactive user when their email is verified for the first time', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->saveWasCalled)->toBeTrue();
});

test('never reactivates a suspended user', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->status = UserStatus::Suspended;
    $user->shouldNotReceive('save');

    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Suspended);
});

test('is a no-op on an already active user', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->status = UserStatus::Active;
    $user->shouldNotReceive('save');

    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Active);
});

// Phase 4 security audit (F1, story 0007): App\Actions\Users\ConfirmEmailChange
// writes the new email_verified_at and calls save() BEFORE firing Verified, on
// a user who -- unlike a brand-new registrant -- had already verified an email
// before (they're Inactive here only because an administrator deactivated a
// previously-active, previously-verified account). getPrevious() must capture
// the OLD, non-null email_verified_at from that prior save, not null, so the
// listener refuses to reactivate them.
test('does not reactivate a deactivated user who had already verified an email before (e.g. completing an email change)', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => now()->subDays(30)->toDateTimeString()], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($user->saveWasCalled)->toBeFalse();
});

// Fail-closed guard: if email_verified_at was never part of the last save's
// dirty set at all (getPrevious() has no entry for it whatsoever, not even a
// null one), the listener must not guess -- it should refuse to activate
// rather than assume "never verified".
test('does not reactivate when email_verified_at was not part of the last save at all', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    // Only a different attribute changes -- email_verified_at is untouched,
    // so it never enters getDirty()/getPrevious() at all.
    $user->name = 'Someone Else';
    $user->syncChanges();

    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($user->saveWasCalled)->toBeFalse();
});

test('does nothing when the verified user is not an App\Models\User instance', function () {
    // Illuminate\Auth\Events\Verified::$user is typed as the MustVerifyEmail
    // interface, not concretely App\Models\User — the listener's
    // `instanceof User` guard is real, reachable code and must not throw
    // when handed some other implementation of that interface.
    $notAUser = new class implements MustVerifyEmail
    {
        public function getEmailForVerification(): string
        {
            return 'not-a-user@example.com';
        }

        public function hasVerifiedEmail(): bool
        {
            return false;
        }

        public function markEmailAsVerified(): bool
        {
            return true;
        }

        public function markEmailAsUnverified(): bool
        {
            return true;
        }

        public function sendEmailVerificationNotification(): void {}
    };

    (new ActivateVerifiedUser)->handle(new Verified($notAUser));
})->throwsNoExceptions();

// Story 0064a (decision D-0) -- idempotency: delivering `Verified` again must leave exactly the state
// one delivery would. The listener is already idempotent, so these tests pin that rather than
// drive a change; each one is validated by a named mutation of the listener, not by a first red run.

// Kills: removing the `status !== Inactive` guard. The double's save() never calls syncChanges(),
// so getPrevious() still says "never verified" on the second delivery -- only the status check
// stops it.
test('delivering Verified twice to the same instance saves exactly once', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser)->handle(new Verified($user));
    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->saveCallCount)->toBe(1);
});

// Kills: replacing the array_key_exists('email_verified_at', ...) guard with
// `($previous['email_verified_at'] ?? null) === null`, which would activate a user whose last
// save never touched that column.
test('leaves a reloaded inactive user inactive when getPrevious() is empty', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null, 'status' => UserStatus::Inactive->value], true);

    expect($user->getPrevious())->toBe([]);

    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($user->saveCallCount)->toBe(0);
});

// Kills: dropping the previous-value null check (an administrator-deactivated user would be
// reactivated by a later confirmation).
test('never reactivates a previously verified inactive user, however often Verified arrives', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => now()->subDays(30)->toDateTimeString()], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser)->handle(new Verified($user));
    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($user->saveCallCount)->toBe(0);
});

// Kills: changing the guard from `!== Inactive` to `=== Active`, which lets a suspended user
// through. The previous value is null (the would-be first verification) so only the status
// check can refuse.
test('never activates a suspended user whose email was never verified before, however often Verified arrives', function () {
    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Suspended;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser)->handle(new Verified($user));
    (new ActivateVerifiedUser)->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Suspended)
        ->and($user->saveCallCount)->toBe(0);
});
