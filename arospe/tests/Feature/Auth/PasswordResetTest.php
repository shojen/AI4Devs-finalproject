<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});

test('completing a password reset verifies a previously unverified email and activates the user', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($user->email_verified_at)->toBeNull();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    $user->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active);
});

test('completing a password reset never reactivates a suspended user', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create(['status' => UserStatus::Suspended]);

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect($user->fresh()->status)->toBe(UserStatus::Suspended);
});

test('completing a password reset for an already verified user leaves email_verified_at unchanged', function () {
    Notification::fake();

    $user = User::factory()->create(['status' => UserStatus::Active]);
    $originalVerifiedAt = $user->email_verified_at;

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    $user->refresh();

    expect($user->email_verified_at)->toEqual($originalVerifiedAt)
        ->and($user->status)->toBe(UserStatus::Active);
});

// Story 0064c (D-6, technique 2) -- an interleave hook lands a suspension exactly between
// App\Actions\Fortify\ResetUserPassword's own forceFill(password, email_verified_at)->save()
// write and the Verified dispatch that follows it, through the real route (never Event::fake()
// -- reading note (b)). ResetUserPassword takes no lock and opens no transaction of its own
// (task file's Verified findings), so this is the exposed path R-2 names, not defence in depth.
test('a suspension that lands mid-reset wins over the caller writing the new password', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $originalPassword = $user->password;

    $fired = false;
    DB::listen(function ($query) use (&$fired, $user): void {
        if ($fired) {
            return;
        }

        if (preg_match('/^update\s+[`"]?users[`"]?\s/i', $query->sql) !== 1) {
            return;
        }

        if (! str_contains($query->sql, 'password') || ! str_contains($query->sql, 'email_verified_at')) {
            return;
        }

        $fired = true;

        DB::table('users')->where('id', $user->id)->update(['status' => UserStatus::Suspended->value]);
    });

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect($fired)->toBeTrue()
        ->and($user->fresh()->password)->not->toBe($originalPassword)
        ->and($user->fresh()->status)->toBe(UserStatus::Suspended);
});
