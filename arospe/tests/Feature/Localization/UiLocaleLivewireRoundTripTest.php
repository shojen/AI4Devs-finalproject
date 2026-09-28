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
// Manual "prove it can fail" verification for Phase 4/5 (cannot be done in this red phase --
// SetUiLocale and its bootstrap/app.php `web`-group registration do not exist yet): temporarily
// move `\App\Http\Middleware\SetUiLocale::class` out of bootstrap/app.php's
// `$middleware->web(append: [...])` call and onto routes/users.php's
// `Route::livewire('users', ...)->middleware([...])` list instead, re-run ONLY the test below, and
// confirm it goes RED -- a route-level registration is never consulted for a /livewire/update
// round-trip (Livewire 4's PersistentMiddleware allow-list governs only route-level middleware,
// and a custom class could never join its eight hardcoded entries, D8). Then restore the original
// registration. TODO(Phase 4/5): perform this and record the result in the task file.

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
    expect(App::getLocale())->toBe('es');
});
