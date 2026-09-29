<?php

use App\Actions\Users\ActivateInactiveUser;
use App\Enums\UserStatus;
use App\Listeners\ActivateVerifiedUser;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;

// Story 0064c (D-7, OQ-2(a), approved) -- App\Listeners\ActivateVerifiedUser no longer decides
// AND writes by calling $user->save() itself; it decides, then delegates the write to an
// injected App\Actions\Users\ActivateInactiveUser. The save()-counting double this file used to
// assert against (ActivateVerifiedUserTrackedUser, below) stops measuring the activation for that
// reason -- these tests are rebuilt around a recording fake of the action instead
// (ActivateInactiveUserFake, below), passed to the listener's constructor. The fake emulates the
// real action's contract exactly (App\Actions\Users\ActivateInactiveUser::__invoke(User): bool):
// on success it returns true AND sets status=Active on the instance -- if it only counted calls
// without also mutating the instance, the same-instance idempotency test below ("delivering
// Verified twice...") would call it TWICE, because nothing would ever flip the in-memory
// `status !== Inactive` guard that is the only thing stopping a second delivery.
//
// The five refusal/idempotency cases from story 0064a keep their names and rules unchanged; one
// new case is added (a false return from the action leaves the instance untouched). Every other
// case predating 0064a (the getPrevious()/getOriginal() security-audit cases, the not-a-User-
// instance guard) also keeps its name and rules, adapted to the same fake-counting mechanics for
// consistency -- none is deleted or renamed, per this repo's standing "no test deletion without
// approval" rule.
//
// Read this before touching a mutation: App\Listeners\ActivateVerifiedUser's constructor is
// `public function __construct(private readonly ActivateInactiveUser $activateInactiveUser) {}`
// -- a real, concrete class type, not an interface/callable -- because
// tests/Feature/Providers/EventListenerRegistrationTest.php needs real container
// auto-resolution to keep working. PHP enforces that type unconditionally at construction, so
// ActivateInactiveUserFake (below) must satisfy it via `extends ActivateInactiveUser`; a
// standalone class with only a structural resemblance throws a TypeError before any test logic
// runs (see docs/errors-log.md for the failure mode this caused once already). Every test that
// only asserts NON-invocation (the in-memory guards returning early, before the action could run)
// still passes on those guards alone, untouched, byte-for-byte, by this story (D-2).
//
// This file boots no application and no database (tests/Unit -- see tests/Pest.php): the refusal
// tests below prove the in-memory guards run BEFORE any database access, which is also why
// mutation A13 ("move the database access above the in-memory guards") is killed here BY ERROR,
// not by an assertion -- with no app booted, any real database touch throws before any expectation
// runs.
//
// Phase 4 security audit (F1, story 0007): the listener reads
// $user->getPrevious()['email_verified_at'] rather than the live attribute or getOriginal() --
// see the listener's own docblock for why. Two consequences for these tests:
//
// 1. Eloquent's getOriginal() (which getPrevious() does NOT call, but which a naive alternative
//    implementation might) internally does `(new static)` to rewind the model. A Mockery partial
//    mock's `static` resolves to Mockery's own generated subclass, and constructing that class
//    directly (bypassing Mockery::mock()'s factory) produces a *strict* mock with no expectations
//    set for __construct, which throws. So every test below that needs getPrevious()/
//    getOriginal() semantics uses a plain `User` subclass with save() overridden to just record
//    the call (harmless today, inert once Phase 3 step 2 lands, since the listener will no longer
//    call save() at all), instead of a Mockery mock.
// 2. getPrevious() is only populated by Eloquent's own syncChanges() -- called from inside
//    performUpdate()/performInsert(), which our double's save() override deliberately bypasses
//    (no real persistence, no app/DB bootstrap available in tests/Unit; see tests/Pest.php). So
//    each test that needs a populated getPrevious() calls the model's own (public) syncChanges()
//    directly, after hydrating the "before" state via setRawAttributes(..., true) (exactly what
//    Eloquent does when hydrating a row from a real query) and then changing the live attribute --
//    exactly the dirty-tracking state a real save() would have produced right before firing
//    Verified.
final class ActivateVerifiedUserTrackedUser extends User
{
    public bool $saveWasCalled = false;

    // Unit tests in this repo boot no Laravel application at all (see tests/Pest.php --
    // only Feature/Browser extend Tests\TestCase), so Eloquent's date-cast conversion cannot fall
    // back to $this->getConnection()->getQueryGrammar()->getDateFormat() the way it normally
    // would; a fixed $dateFormat avoids that connection lookup entirely.
    protected $dateFormat = 'Y-m-d H:i:s';

    public function save(array $options = []): bool
    {
        $this->saveWasCalled = true;

        return true;
    }
}

/**
 * A recording fake of App\Actions\Users\ActivateInactiveUser. The listener's constructor is
 * hand-injected with a real, concrete class type (`private readonly ActivateInactiveUser
 * $activateInactiveUser`, not an interface) -- see App\Listeners\ActivateVerifiedUser -- so PHP
 * enforces that type unconditionally at construction, before any test logic runs. A standalone
 * class with only a structural resemblance to ActivateInactiveUser (same __invoke signature, no
 * `extends` relationship) throws a TypeError the moment `new ActivateVerifiedUser($fake)` runs;
 * see docs/errors-log.md for that failure mode. This fake instead `extends` the real class to
 * satisfy the type, with its own constructor that deliberately does NOT call
 * `parent::__construct()`: the real one needs App\Actions\Auth\LogRefusedPrivilegedAttempt, which
 * is unavailable and unwanted here (this file boots no application/database -- see
 * tests/Pest.php). `__invoke()` is overridden and never touches the parent's uninitialized
 * property, so that's safe. It emulates the real action's contract exactly
 * (`__invoke(User): bool`): on success it returns true AND sets status=Active on the instance --
 * if it only counted calls without also mutating the instance, the same-instance idempotency test
 * below ("delivering Verified twice...") would call it TWICE, because nothing would ever flip the
 * in-memory `status !== Inactive` guard that is the only thing stopping a second delivery.
 */
final class ActivateInactiveUserFake extends ActivateInactiveUser
{
    public int $callCount = 0;

    public function __construct(private readonly bool $returnValue = true) {}

    public function __invoke(User $user): bool
    {
        $this->callCount++;

        if ($this->returnValue) {
            $user->status = UserStatus::Active;
        }

        return $this->returnValue;
    }
}

test('activates an inactive user when their email is verified for the first time', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Active)
        ->and($fake->callCount)->toBe(1);
});

test('never reactivates a suspended user', function () {
    $fake = new ActivateInactiveUserFake;

    $user = Mockery::mock(User::class)->makePartial();
    $user->status = UserStatus::Suspended;
    $user->shouldNotReceive('save');

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Suspended)
        ->and($fake->callCount)->toBe(0);
});

test('is a no-op on an already active user', function () {
    $fake = new ActivateInactiveUserFake;

    $user = Mockery::mock(User::class)->makePartial();
    $user->status = UserStatus::Active;
    $user->shouldNotReceive('save');

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Active)
        ->and($fake->callCount)->toBe(0);
});

// Phase 4 security audit (F1, story 0007): App\Actions\Users\ConfirmEmailChange writes the new
// email_verified_at and calls save() BEFORE firing Verified, on a user who -- unlike a brand-new
// registrant -- had already verified an email before (they're Inactive here only because an
// administrator deactivated a previously-active, previously-verified account). getPrevious() must
// capture the OLD, non-null email_verified_at from that prior save, not null, so the listener
// refuses to reactivate them.
test('does not reactivate a deactivated user who had already verified an email before (e.g. completing an email change)', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => now()->subDays(30)->toDateTimeString()], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($fake->callCount)->toBe(0);
});

// Fail-closed guard: if email_verified_at was never part of the last save's dirty set at all
// (getPrevious() has no entry for it whatsoever, not even a null one), the listener must not
// guess -- it should refuse to activate rather than assume "never verified".
test('does not reactivate when email_verified_at was not part of the last save at all', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    // Only a different attribute changes -- email_verified_at is untouched, so it never enters
    // getDirty()/getPrevious() at all.
    $user->name = 'Someone Else';
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($fake->callCount)->toBe(0);
});

test('does nothing when the verified user is not an App\Models\User instance', function () {
    $fake = new ActivateInactiveUserFake;

    // Illuminate\Auth\Events\Verified::$user is typed as the MustVerifyEmail interface, not
    // concretely App\Models\User -- the listener's `instanceof User` guard is real, reachable
    // code and must not throw when handed some other implementation of that interface.
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

    (new ActivateVerifiedUser($fake))->handle(new Verified($notAUser));

    expect($fake->callCount)->toBe(0);
});

// Story 0064c -- new case (task file's Unit test bullet list): a false return from the action
// (the lost-race branch) must leave the caller's instance completely untouched and must not
// throw. The fake's contract mirrors App\Actions\Users\ActivateInactiveUser's own D-4 behaviour on
// a loss: nothing on the instance changes.
test('a false return from the action leaves the instance untouched and does not throw', function () {
    $fake = new ActivateInactiveUserFake(returnValue: false);

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($fake->callCount)->toBe(1);
});

// Story 0064a (decision D-0) -- idempotency: delivering `Verified` again must leave exactly the
// state one delivery would. The listener is already idempotent, so these tests pin that rather
// than drive a change; each one is validated by a named mutation of the listener, not by a first
// red run.

// Kills: removing the `status !== Inactive` guard. The fake sets status=Active on its first,
// successful call -- exactly what stops a second delivery from reaching the action at all.
test('delivering Verified twice to the same instance saves exactly once', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));
    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Active)
        ->and($fake->callCount)->toBe(1);
});

// Kills: replacing the array_key_exists('email_verified_at', ...) guard with
// `($previous['email_verified_at'] ?? null) === null`, which would activate a user whose last
// save never touched that column.
test('leaves a reloaded inactive user inactive when getPrevious() is empty', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null, 'status' => UserStatus::Inactive->value], true);

    expect($user->getPrevious())->toBe([]);

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($fake->callCount)->toBe(0);
});

// Kills: dropping the previous-value null check (an administrator-deactivated user would be
// reactivated by a later confirmation).
test('never reactivates a previously verified inactive user, however often Verified arrives', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => now()->subDays(30)->toDateTimeString()], true);
    $user->status = UserStatus::Inactive;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));
    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($fake->callCount)->toBe(0);
});

// Kills: changing the guard from `!== Inactive` to `=== Active`, which lets a suspended user
// through. The previous value is null (the would-be first verification) so only the status check
// can refuse.
test('never activates a suspended user whose email was never verified before, however often Verified arrives', function () {
    $fake = new ActivateInactiveUserFake;

    $user = new ActivateVerifiedUserTrackedUser;
    $user->setRawAttributes(['email_verified_at' => null], true);
    $user->status = UserStatus::Suspended;
    $user->email_verified_at = now();
    $user->syncChanges();

    (new ActivateVerifiedUser($fake))->handle(new Verified($user));
    (new ActivateVerifiedUser($fake))->handle(new Verified($user));

    expect($user->status)->toBe(UserStatus::Suspended)
        ->and($fake->callCount)->toBe(0);
});
