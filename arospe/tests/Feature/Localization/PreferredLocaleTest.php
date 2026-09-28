<?php

// Story 0066, D14 -- App\Models\User implements Illuminate\Contracts\Translation\HasLocalePreference.
// preferredLocale() always returns a real locale string, NEVER null (rejecting the
// Localizable::withLocale() no-op degrading to "whatever locale the queueing request happened to
// leave set") -- resolving the user's own ui_locale first, falling back to
// LocaleSetting::defaultNotificationLocale(), which is independent from defaultUiLocale() by
// design (0068 D18/D21: two separate settings, two separate columns).
//
// The last two cases go through the REAL notification pipeline end to end (task file: "a unit
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
// ⚠️ Finding for backend-expert/appsec-auditor, not fixed here (out of this agent's scope): the
// REAL call site for PendingEmailVerification, App\Actions\Users\RequestEmailChange, sends it via
// `Notification::route('mail', $newEmail)->notify(...)` -- an AnonymousNotifiable, not
// `$user->notify(...)`. Illuminate\Notifications\AnonymousNotifiable does not implement
// HasLocalePreference (verified against the installed NotificationSender::preferredLocale() /
// AnonymousNotifiable sources), so THAT real call site never consults $user->preferredLocale() at
// all -- it renders in whatever locale SetUiLocale left ambient for the current request, not
// necessarily the target user's own preference (they can differ, e.g. an administrator changing
// another user's email on that user's behalf). D14's claim that "PendingEmailVerification renders
// in the recipient's language with zero edits to either file" therefore does not fully hold for
// the real call path as written today -- only for a direct $user->notify(new
// PendingEmailVerification(...)) call, which is what the test below exercises (the notification
// class's own contract, per D14's literal wording). Whether RequestEmailChange's anonymous routing
// should change is a product/security decision outside this agent's remit (it exists so the mail
// goes to the NEW address, not $user->email -- routing through $user->notify() as-is would misroute
// the delivery address, a separate concern from locale).

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
