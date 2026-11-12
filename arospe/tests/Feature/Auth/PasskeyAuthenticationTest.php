<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkeys;

// Story 0007: a non-active user must not be able to complete passkey sign-in
// either. Passkey login bypasses Fortify's pipeline entirely -- it never
// reaches Fortify::authenticateUsing() -- so it needs its own enforcement
// point: Laravel\Passkeys\Passkeys::authorizeLoginUsing(), consulted by
// PasskeyLoginController::store() via Passkeys::allowsLogin() before it ever
// calls $guard->login(). A full WebAuthn
// ceremony is not practical to fabricate here, so this asserts against the
// registered authorization callback directly rather than a fake assertion
// payload through POST /passkeys/login.
test('a suspended user cannot sign in with a passkey', function () {
    $user = User::factory()->suspended()->create();

    $passkey = $user->passkeys()->create([
        'name' => 'YubiKey',
        'credential_id' => Str::random(40),
        'credential' => '{}',
    ]);

    $request = Request::create('/passkeys/login', 'POST');

    expect(Passkeys::allowsLogin($request, $passkey))->toBeFalse();
});

// Phase 4 security audit (F2, story 0007): Passkeys::allowsLogin() calls the
// registered authorizeLoginUsing() callback with $passkey->user unchanged --
// and that relation resolves null for a
// trashed owner (SoftDeletingScope). Before this fix, the callback's User
// parameter was non-nullable, so this scenario threw a TypeError instead of
// cleanly refusing the login.
test('a soft-deleted user cannot sign in with a passkey, and the attempt is refused cleanly rather than throwing', function () {
    $user = User::factory()->create();

    $passkey = $user->passkeys()->create([
        'name' => 'YubiKey',
        'credential_id' => Str::random(40),
        'credential' => '{}',
    ]);

    $user->delete();

    $request = Request::create('/passkeys/login', 'POST');

    expect(Passkeys::allowsLogin($request, $passkey->fresh()))->toBeFalse();
});
