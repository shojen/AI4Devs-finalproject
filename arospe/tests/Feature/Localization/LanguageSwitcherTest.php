<?php

// Story 0067 -- the shared behaviour behind both interface-language surfaces, tested once.
//
// Livewire::test() disables the middleware stack, so this file proves ONLY validation and
// delegation -- never "the UI renders in Spanish" (that is the browser tests' job).
//
// The forged-locale cases assert the stored ROW, never an error message: the components declare no
// bound property, so Livewire's error-bag dehydration drops the message entirely (D-19). They also
// go red if UiLocale::from() runs before validation, because Livewire::test() rethrows the resulting
// \ValueError instead of swallowing it.

use App\Livewire\Settings\Language;
use App\Livewire\Settings\LanguageSwitcher;
use App\Models\LocaleSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// A forged value reaches either component over /livewire/update, so both need the guard proven.
test('a locale outside the offered pair is refused and the stored preference is unchanged', function (string $component, string $forged) {
    $user = User::factory()->uiLocale('es')->create();

    Livewire::actingAs($user)->test($component)->call('setLocale', $forged);

    expect($user->fresh()->ui_locale)->toBe('es');
})->with([
    'chrome switcher' => [LanguageSwitcher::class],
    'settings tab' => [Language::class],
])->with(['fr', 'EN', 'en-US', '']);

test('a valid choice is persisted to the signed-in users own row', function (string $component) {
    $user = User::factory()->uiLocale('es')->create();
    $bystander = User::factory()->uiLocale('es')->create();

    Livewire::actingAs($user)->test($component)->call('setLocale', 'en');

    expect($user->fresh()->ui_locale)->toBe('en')
        ->and($bystander->fresh()->ui_locale)->toBe('es');
})->with([
    'chrome switcher' => LanguageSwitcher::class,
    'settings tab' => Language::class,
]);

test('the settings tab stays on itself after a valid choice', function () {
    $user = User::factory()->uiLocale('es')->create();

    Livewire::actingAs($user)->test(Language::class)
        ->call('setLocale', 'en')
        ->assertRedirect(route('language.edit'));
});

test('the current locale is the stored preference when it is one of the offered pair', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'en', 'default_notification_locale' => 'en']);
    $user = User::factory()->uiLocale('es')->create();

    $component = Livewire::actingAs($user)->test(LanguageSwitcher::class);

    expect($component->instance()->currentLocale)->toBe('es');
});

test('the current locale falls back to the store default when nothing is stored', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'en']);
    $user = User::factory()->create(['ui_locale' => null]);

    $component = Livewire::actingAs($user)->test(LanguageSwitcher::class);

    expect($component->instance()->currentLocale)->toBe('es');
});

// L-1: the Referer is client-controlled, so the chrome switcher only redirects back to a same-host
// previous URL. Livewire::test() carries no headers, so the previous URL is set on the session.
test('the chrome switcher never redirects to a previous URL on a foreign host', function () {
    $user = User::factory()->uiLocale('es')->create();
    session()->setPreviousUrl('https://evil.example/phish');

    Livewire::actingAs($user)->test(LanguageSwitcher::class)
        ->call('setLocale', 'en')
        ->assertRedirect(route('dashboard'));
});

test('the chrome switcher returns to a same-host previous URL', function () {
    $user = User::factory()->uiLocale('es')->create();
    $previous = 'http://'.request()->getHost().'/users?page=2';
    session()->setPreviousUrl($previous);

    Livewire::actingAs($user)->test(LanguageSwitcher::class)
        ->call('setLocale', 'en')
        ->assertRedirect($previous);
});

test('the chrome switcher falls back to the dashboard when there is no previous URL', function () {
    $user = User::factory()->uiLocale('es')->create();

    Livewire::actingAs($user)->test(LanguageSwitcher::class)
        ->call('setLocale', 'en')
        ->assertRedirect(route('dashboard'));
});

test('the current locale falls back to the store default for a stale stored value', function () {
    LocaleSetting::factory()->create(['default_ui_locale' => 'es', 'default_notification_locale' => 'en']);
    $user = User::factory()->create();
    // Written past the model: the model layer would refuse to create this fixture.
    DB::table('users')->where('id', $user->id)->update(['ui_locale' => 'fr']);

    $component = Livewire::actingAs($user->fresh())->test(LanguageSwitcher::class);

    expect($component->instance()->currentLocale)->toBe('es');
});
