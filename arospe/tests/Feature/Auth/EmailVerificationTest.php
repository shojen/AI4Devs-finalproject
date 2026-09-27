<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('completing Fortifys own verification flow flips a previously inactive user to active', function () {
    $user = User::factory()->unverified()->create();
    expect($user->status)->toBe(UserStatus::Inactive);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});

// Story 0064c (D-6, technique 2) -- an interleave hook lands a suspension exactly between
// Fortify's own markEmailAsVerified()->save() write and the Verified dispatch that follows it,
// through the real route (never Event::fake() -- reading note (b)).
//
// Non-vacuity check (task file's R-5 / "Tests to perform" instruction, resolved here at Phase 3):
// does this request survive App\Listeners\RejectNonActiveUserLogin for a Suspended user? No --
// $this->actingAs() sets the guard's user directly via Illuminate\Auth\SessionGuard::setUser()
// (Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication::be()), which fires
// Authenticated but never Login. RejectNonActiveUserLogin::handleAuthenticated() only forces a
// logout when ITS OWN Login handler already flagged the current request via
// request()->attributes -- and that flag is set exclusively by a Login event, which never fires
// here. This matches RejectNonActiveUserLogin's own class docblock: "an already-authenticated
// user's ordinary subsequent request fires Authenticated alone... already-live sessions are
// explicitly out of scope." The suspension in this test lands mid-request, on an already-live
// session, not at a fresh Login -- so it is never in that listener's scope in production either.
// Not vacuous; written.
test('a suspension that lands mid-verification wins over the caller writing email_verified_at', function () {
    $user = User::factory()->unverified()->create();

    $fired = false;
    DB::listen(function ($query) use (&$fired, $user): void {
        if ($fired) {
            return;
        }

        if (preg_match('/^update\s+[`"]?users[`"]?\s/i', $query->sql) !== 1) {
            return;
        }

        if (! str_contains($query->sql, 'email_verified_at')) {
            return;
        }

        $fired = true;

        DB::table('users')->where('id', $user->id)->update(['status' => UserStatus::Suspended->value]);
    });

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($fired)->toBeTrue()
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->status)->toBe(UserStatus::Suspended);
});
