<?php

// Story 0066 -- proves App\Http\Middleware\SetUiLocale re-applies the locale on Livewire's
// /livewire/update round-trip, not only on the initial page GET. This is the single highest-risk
// test in the story (task file QA test case #1): Livewire::test() disables the ENTIRE middleware
// stack via RequestBroker::temporarilyDisableExceptionHandlingAndMiddleware()'s ->withoutMiddleware()
// call (verified against the installed Livewire v4.3.3 source -- SubsequentRender.php:35,
// RequestBroker.php:28), so a test built on it would be structurally incapable of ever going red
// for a broken middleware registration. This file instead makes a LITERAL POST to the real update
// endpoint.
//
// ⚠️ Correction to the task file's own text: it names a hardcoded '/livewire/update' path. The
// INSTALLED vendor tree does not use that literal path -- verified by execution --
// Livewire\Mechanisms\HandleRequests\EndpointResolver::updatePath() hashes the endpoint per
// installation from APP_KEY (`/livewire-<8 hex chars>/update`), a hardening feature evidently
// absent from whatever Livewire build the task file's prose was checked against. This is still a
// literal, real POST outside Livewire::test() -- app('livewire')->getUpdateUri() is the exact
// resolution the real front-end JS client and Livewire's own SubsequentRender.php (Livewire::test()'s
// OWN subsequent-request helper, before it disables the middleware) both use; nothing here is a
// testing shortcut. The full real 'web' middleware group -- CSRF, session, SetUiLocale included --
// runs for real. The only reason this needs no CSRF token is
// PreventRequestForgery::runningUnitTests(), which is how every other raw $this->post() in this
// suite already gets away with it (see e.g. tests/Feature/Auth/RememberMeAuthenticationTest.php).
//
// The payload shape below (components[0].snapshot/calls/updates) matches
// Livewire\Features\SupportTesting\SubsequentRender::makeSubsequentRequest() exactly, and the
// snapshot itself is extracted from a REAL preceding GET's rendered HTML via
// Livewire\Drawer\Utils::extractAttributeDataFromHtml() -- the DOM's own wire:snapshot attribute,
// not a hand-built payload.
//
// Phase 5 code-review fix -- two independent reasons the original version of this test could never
// go red were found: (1) the 'es' locale set by the preceding GET is never reset before the POST,
// so the ambient value going into the POST is already 'es' regardless of what runs during it; (2)
// even with that reset, Livewire's OWN SupportLocales::hydrate() (LivewireServiceProvider.php:200)
// calls app()->setLocale($memo['locale']) on every round-trip, restoring 'es' from the initial
// render's OWN snapshot memo -- independent of whether SetUiLocale ran at all. So the test below
// resets the ambient locale to 'en' right before the POST AND binds a partial Mockery spy over the
// real SetUiLocale instance, asserting handle() was actually invoked during the POST -- that
// assertion is structurally incapable of passing if SetUiLocale is removed from the `web` group or
// never invoked, regardless of what Livewire's own hydration does to App::getLocale() afterwards.
//
// Manual "prove it can fail" verification, performed 2026-09-28: temporarily commented out
// bootstrap/app.php's `$middleware->web(append: [SetUiLocale::class]);` call, re-ran ONLY the test
// below, and confirmed it went RED (the spy's `shouldHaveReceived('handle')->once()` assertion
// failed -- 0 invocations recorded), then restored bootstrap/app.php exactly as it was (`git diff
// bootstrap/app.php` showed no changes afterwards). Recorded in the task file's Provenance section.

use App\Http\Middleware\SetUiLocale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\App;
use Livewire\Drawer\Utils;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('a literal POST to the real /livewire/update endpoint re-applies the authenticated users stored ui locale', function () {
    config(['app.locale' => 'en']);
    $administrator = User::factory()->create(['ui_locale' => 'es']);
    $administrator->assignRole('Administrator');
    $this->actingAs($administrator);

    // A real GET against the existing gated component (no new component needed) -- no
    // Livewire::test(Index::class), so the initial render itself already proves the `auth`/
    // `verified`/`can:users.view` route middleware AND the new global `web`-group middleware
    // resolve the locale correctly.
    $html = $this->get(route('users.index'))->getContent();
    expect(App::getLocale())->toBe('es');

    $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');
    expect($snapshot)->not->toBeNull();

    $uri = app('livewire')->getUpdateUri();

    // Prove the round-trip itself re-resolves the locale through SetUiLocale, not merely that
    // App::getLocale() ends up 'es' again afterwards (see the file header). Poison the ambient
    // locale so a leftover value from the GET above cannot be mistaken for genuine
    // re-resolution, and bind a partial spy over the real middleware instance so its actual
    // invocation during THIS request can be asserted.
    App::setLocale('en');
    $spy = Mockery::spy(SetUiLocale::class)->makePartial();
    app()->instance(SetUiLocale::class, $spy);

    $response = $this->postJson($uri, [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [],
            'updates' => [],
        ]],
    ], ['X-Livewire' => 'true']);

    $response->assertOk();

    // The whole point of this test: the round-trip is a SEPARATE HTTP request through a
    // SEPARATE route registration than the initial GET. Asserting only the GET above would
    // pass even if SetUiLocale were registered on the `users.index` route alone rather than
    // globally on `web` (D8) -- exactly the registration mistake the manual verification step
    // above exists to catch.
    $spy->shouldHaveReceived('handle')->once();
    expect(App::getLocale())->toBe('es');
});
