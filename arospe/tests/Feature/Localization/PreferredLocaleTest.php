<?php

// Story 0066, D14 -- App\Models\User implements Illuminate\Contracts\Translation\HasLocalePreference.
// preferredLocale() always returns a real locale string, NEVER null (rejecting the
// Localizable::withLocale() no-op degrading to "whatever locale the queueing request happened to
// leave set") -- resolving the user's own ui_locale first, falling back to
// LocaleSetting::defaultNotificationLocale(), which is independent from defaultUiLocale() by
// design (0068 D18/D21: two separate settings, two separate columns).
//
// The remaining cases go through the REAL notification pipeline end to end (task file: "a unit
// test of the method alone would pass with HasLocalePreference not implemented at all, since
// nothing else in the story consumes it"). Deliberately does NOT use Notification::fake(): the fake
// never calls toMail() at all, so a test built on it plus a manual toMail() call outside any
// withLocale() wrapping would render in whatever the AMBIENT locale happens to be, not the one
// NotificationSender::preferredLocale() resolves -- exactly the "passes for the wrong reason" shape
// this repo's philosophy doc warns about. Instead this sends for real against the `array` mail
// transport (.env.testing's MAIL_MAILER) and inspects the captured Symfony message, the same
// technique already used in tests/Feature/Blog/ScheduledBlogPostPublishFailedNotificationTest.php
// (app('mailer')->getSymfonyTransport()->messages(), filtered to the exact recipient address).
//
// The last two cases below exercise the REAL call site, App\Actions\Users\RequestEmailChange,
// rather than a direct $user->notify(...) call. That action sends PendingEmailVerification via
// `Notification::route('mail', $newEmail)->notify(...)` -- an AnonymousNotifiable, which does not
// implement HasLocalePreference on its own and so would never consult $user->preferredLocale().
// RequestEmailChange now chains `->locale($user->preferredLocale())` onto the notification before
// sending, which is what these two tests assert: the mail delivered to the NEW (unverified)
// address renders in the target user's own preference (or the configured notification default
// when they have none set), not whatever locale happens to be ambient for the current request.

use App\Actions\Users\RequestEmailChange;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use App\Models\User;
use App\Notifications\PendingEmailVerification;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\DB;

test('preferredLocale returns the users own stored preference when one is set', function () {
    $user = User::factory()->create(['ui_locale' => 'es']);

    expect($user->preferredLocale())->toBe('es');
});

test('with no stored preference, preferredLocale reads the NOTIFICATION default, never the dashboard default', function () {
    // The two settings are deliberately DIFFERENT here -- if preferredLocale() ever read
    // default_ui_locale by mistake (a copy-paste of the middleware's own fallback), this is the
    // one test that would catch it; with both set to the same value the bug is invisible.
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'es',
    ]);

    $user = User::factory()->create(['ui_locale' => null]);

    expect($user->preferredLocale())->toBe('es')
        ->and(LocaleSetting::defaultUiLocale())->toBe(UiLocale::English);
});

test('preferredLocale never returns null, including for a user holding an unsupported stored value', function () {
    config(['app.locale' => 'en']);
    $user = User::factory()->create(['ui_locale' => 'en']);

    // Bypasses the model, simulating a value that predates a narrower enum.
    DB::table('users')->where('id', $user->id)->update(['ui_locale' => 'fr']);

    $resolved = $user->fresh()->preferredLocale();

    expect($resolved)->not->toBeNull()
        ->and($resolved)->toBeString()
        ->and($resolved)->toBe('en');
});

test('an invited administrator with no chosen preference has their invitation rendered in the configured default notification language', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'es',
    ]);
    $user = User::factory()->create(['ui_locale' => null]);

    $user->notify(new UserInvitation('a-token', $user->email));

    $messagesToUser = collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn ($message) => collect($message->getOriginalMessage()->getTo())
            ->contains(fn ($address) => $address->getAddress() === $user->email))
        ->values();

    expect($messagesToUser)->toHaveCount(1)
        ->and((string) $messagesToUser->first()->getOriginalMessage()->getSubject())
        ->toBe(trans('users.invitation.subject', [], 'es'));
});

test('an administrators own preference decides the language their invitation and pending-email-verification notifications render in', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'en',
    ]);
    $user = User::factory()->create(['ui_locale' => 'es']);

    $user->notify(new UserInvitation('a-token', $user->email));
    $user->notify(new PendingEmailVerification($user, 'new-address@arospe.es'));

    $messagesToUser = collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn ($message) => collect($message->getOriginalMessage()->getTo())
            ->contains(fn ($address) => $address->getAddress() === $user->email))
        ->values();

    expect($messagesToUser)->toHaveCount(2);

    $subjects = $messagesToUser->map(fn ($message) => (string) $message->getOriginalMessage()->getSubject());

    expect($subjects)->toContain(trans('users.invitation.subject', [], 'es'))
        ->and($subjects)->toContain(trans('users.email_change.notification_subject', [], 'es'));
});

test('RequestEmailChange renders the pending-email-verification notification sent to the new address in the targets own stored locale preference, not the ambient request locale', function () {
    // Ambient/default locale is deliberately English here while the target's own preference is
    // Spanish -- a pass proves $user->preferredLocale() (via RequestEmailChange's ->locale() call)
    // drove the render, not whatever locale happened to be ambient for this (unauthenticated) request.
    config(['app.locale' => 'en']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'en',
    ]);
    $user = User::factory()->create(['ui_locale' => 'es']);
    $newEmail = 'new-address@arospe.es';

    app(RequestEmailChange::class)($user, $newEmail);

    $messagesToNewAddress = collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn ($message) => collect($message->getOriginalMessage()->getTo())
            ->contains(fn ($address) => $address->getAddress() === $newEmail))
        ->values();

    expect($messagesToNewAddress)->toHaveCount(1)
        ->and((string) $messagesToNewAddress->first()->getOriginalMessage()->getSubject())
        ->toBe(trans('users.email_change.notification_subject', [], 'es'));
});

test('RequestEmailChange renders the pending-email-verification notification in the configured default notification language when the target has no stored preference', function () {
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'es',
    ]);
    $user = User::factory()->create(['ui_locale' => null]);
    $newEmail = 'another-new-address@arospe.es';

    app(RequestEmailChange::class)($user, $newEmail);

    $messagesToNewAddress = collect(app('mailer')->getSymfonyTransport()->messages())
        ->filter(fn ($message) => collect($message->getOriginalMessage()->getTo())
            ->contains(fn ($address) => $address->getAddress() === $newEmail))
        ->values();

    expect($messagesToNewAddress)->toHaveCount(1)
        ->and((string) $messagesToNewAddress->first()->getOriginalMessage()->getSubject())
        ->toBe(trans('users.email_change.notification_subject', [], 'es'));
});
