<?php

// Story 0066 -- App\Http\Middleware\SetUiLocale, registered globally on the `web` middleware
// group (D8), resolving App::setLocale() on every request from (1) the authenticated user's own
// `users.ui_locale`, falling back to (2) LocaleSetting::defaultUiLocale() -- the administrator-
// configured default from story 0068, never config('app.locale') directly (D6: that tier is
// reached only INSIDE that accessor). This file proves the resolution chain end to end over real
// HTTP requests. The notification-locale half (User::preferredLocale(), D14) has its own file,
// tests/Feature/Localization/PreferredLocaleTest.php.
//
// Every case that asserts "the default" pins config(['app.locale' => 'en']) explicitly -- this
// repo has already been bitten once by a test depending on an ambient config value
// (SUPER_ADMIN_EMAIL, see docs/errors-log). The two "explicitly-populated locale_settings" cases
// use LocaleSetting::factory()->create([...]), NOT LocaleSetting::query()->updateOrCreate(...) as
// the task file's own text names: verified by execution against the installed model --
// LocaleSetting carries #[Fillable([])] (0068), so both firstOrCreate()'s create() path and the
// existing-row fill()->save() path inside updateOrCreate() go through Eloquent's ordinary
// mass-assignment guard, and LocaleSetting::totallyGuarded() is true (empty fillable, default
// guarded=['*']) -- so `updateOrCreate(['id' => SINGLETON_ID], [...])` throws
// MassAssignmentException on the very `id` key before it ever reaches `default_ui_locale`. The
// factory bypasses this the same way it already does throughout tests/Feature/Localization/
// (Model::unguarded() inside Factory::make()), and is safe here because each test below creates
// the singleton row exactly once.

use App\Models\LocaleSetting;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

test('an authenticated user with a stored es preference gets es as the request locale', function () {
    $user = User::factory()->create(['ui_locale' => 'es']);

    $this->actingAs($user)->get(route('dashboard'));

    expect(App::getLocale())->toBe('es');
});

test('the stored preference outlives the session it was chosen in -- persists across sign-out and a fresh sign-in', function () {
    // PRD-stated persistence, not merely a same-session assertion: a session- or
    // cookie-backed implementation would pass a same-session test and fail this one.
    $user = User::factory()->create(['ui_locale' => 'es']);

    $this->actingAs($user)->get(route('dashboard'));
    expect(App::getLocale())->toBe('es');

    $this->post(route('logout'))->assertRedirect();

    // fresh() forces the read to come from the users row, not anything the
    // now-destroyed session might have cached.
    $this->actingAs($user->fresh())->get(route('dashboard'));

    expect(App::getLocale())->toBe('es');
});

test('a guest request resolves to the config-tier default when no locale_settings row exists', function () {
    config(['app.locale' => 'en']);
    expect(LocaleSetting::count())->toBe(0);

    $this->get('/');

    expect(App::getLocale())->toBe('en');
});

test('an authenticated user with no stored preference resolves to the config-tier default when no locale_settings row exists', function () {
    config(['app.locale' => 'en']);
    expect(LocaleSetting::count())->toBe(0);

    $user = User::factory()->create(['ui_locale' => null]);

    $this->actingAs($user)->get(route('dashboard'));

    expect(App::getLocale())->toBe('en');
});

test('a guest request resolves to the administrator-configured default once locale_settings holds one', function () {
    // ⚠️ 0068's R-13: with no row, the accessor falls through to config('app.locale') on its own,
    // so a test asserting only "the default" keeps passing after 0068 lands while silently testing
    // the wrong tier. This case and the next pin an EXPLICITLY POPULATED row, config('app.locale')
    // set to something DIFFERENT, so only the persisted tier can make this pass.
    config(['app.locale' => 'en']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'en',
    ]);

    $this->get('/');

    expect(App::getLocale())->toBe('es');
});

test('a no-preference user resolves to the administrator-configured default once locale_settings holds one', function () {
    config(['app.locale' => 'en']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'en',
    ]);

    $user = User::factory()->create(['ui_locale' => null]);

    $this->actingAs($user)->get(route('dashboard'));

    expect(App::getLocale())->toBe('es');
});

test('changing the configured default does not affect a user who already has their own preference', function () {
    config(['app.locale' => 'en']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'es',
        'default_notification_locale' => 'en',
    ]);

    $user = User::factory()->create(['ui_locale' => 'en']);

    $this->actingAs($user)->get(route('dashboard'));

    expect(App::getLocale())->toBe('en');
});

test('a previous authenticated request does not leak its locale into an immediately following guest request', function () {
    // Two requests inside ONE test method, on purpose: a single-request test cannot tell
    // "resets to default" apart from "never sets anything", since the locale already starts
    // at the default before the middleware ever runs (D9).
    //
    // An explicitly populated locale_settings row is required here, unlike the two config-tier
    // cases above -- App::setLocale() (called by the middleware for the first, 'es' request) has
    // the side effect of overwriting config('app.locale') itself (vendor
    // Illuminate\Foundation\Application::setLocale()). Without a row, the second (guest) request's
    // fallback would land on LocaleSetting::defaultUiLocale()'s tier-3 config read, which the FIRST
    // request had already poisoned to 'es' -- an assertion that would incidentally pass even if the
    // middleware leaked the previous locale forward, for a reason unrelated to what this test pins.
    // Seeding a row forces both requests through tier 1 (the real, persisted fallback) regardless
    // of what App::setLocale() does to config('app.locale') along the way, so the leak-forward
    // property is pinned without depending on that incidental side effect. See docs/errors-log.md.
    config(['app.locale' => 'fr']);
    LocaleSetting::factory()->create([
        'default_ui_locale' => 'en',
        'default_notification_locale' => 'es',
    ]);

    $user = User::factory()->create(['ui_locale' => 'es']);

    $this->actingAs($user)->get(route('dashboard'));
    expect(App::getLocale())->toBe('es');

    // Force the second request to resolve auth state from scratch, the way a genuinely
    // separate visitor's request would -- actingAs() sets the resolved user directly on the
    // guard instance kept in the container, which otherwise persists across every subsequent
    // $this->get() call within this same test method regardless of session/cookies.
    Auth::forgetGuards();
    $this->flushSession();

    $this->get('/');

    expect(App::getLocale())->toBe('en');
});

test('a stored ui_locale value outside the enum falls through to the default instead of raising an error', function () {
    // Bypasses the model entirely (DB::table()->update()), simulating a value that predates a
    // narrower enum or was written by a direct SQL statement -- the app-level guard (no #[Fillable]
    // entry, single-writer action) would otherwise block seeding this fixture. This is the
    // highest-blast-radius bug the story can ship: with an enum cast, this would be an
    // unconditional 500 on every request for this account (D5).
    config(['app.locale' => 'en']);
    $user = User::factory()->create(['ui_locale' => 'es']);

    DB::table('users')->where('id', $user->id)->update(['ui_locale' => 'fr']);

    $response = $this->actingAs($user->fresh())->get(route('dashboard'));

    $response->assertOk();
    expect(App::getLocale())->toBe('en');
});
