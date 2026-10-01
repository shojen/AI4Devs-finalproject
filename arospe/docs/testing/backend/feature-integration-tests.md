# Feature / Integration Tests

## What counts as "feature" here

Everything in `tests/Feature/`: HTTP requests through the real router/middleware stack, Livewire component lifecycles, and any Action/class hitting a real (migrated) database — all with `RefreshDatabase` applied automatically (see [`tests/Pest.php`](../../../tests/Pest.php)). This is also where this codebase puts "integration"-shaped tests (an Action class + real DB, no HTTP) since there's no separate `tests/Integration/` directory — see [philosophy.md](../philosophy.md#unit-vs-integration-vs-feature-in-this-codebase).

## HTTP route example

The existing pattern, from [`tests/Feature/DashboardTest.php`](../../../tests/Feature/DashboardTest.php):

```php
<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
});
```

Note both the allowed and denied path are tested — not just the happy one. Any route gated by `auth`/`verified`/`password.confirm` middleware (see [api/routes.md](../../api/routes.md)) needs both.

## Livewire component example

For a class-based component like `App\Livewire\Settings\Security` (see [conventions/base-standards.md](../../conventions/base-standards/livewire-and-flux-conventions.md#livewire-component-convention-class-based-not-single-file)):

```php
<?php

use App\Livewire\Settings\Security;
use App\Models\User;
use Livewire\Livewire;

test('deleting a passkey removes it and closes the modal', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'YubiKey',
        'credential_id' => 'test-credential-id',
        'credential' => '{}',
    ]);
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('deletingPasskeyId', $passkey->id)
        ->call('deletePasskey')
        ->assertSet('showDeleteModal', false);

    expect($user->passkeys()->find($passkey->id))->toBeNull();
});
```

This asserts on real database state (`passkeys()->find()` returning null) rather than only on the Livewire response — the DB assertion is what actually proves the deletion happened, not just that the method returned without error.

## Authorization tests

Access control in this app comes in four layers, and a test that exercises one proves nothing about the others:

1. **Route middleware** — `auth`, `verified`, `password.confirm` on the settings screens; a per-route `can:<permission>` on each of the three module routes (`can:users.view`, `can:roles.manage`, `can:sales-regions.view`).
2. **Roles & permissions** — `spatie/laravel-permission`, with `HasRoles` attached to `App\Models\User` and a seeded 43-permission catalog (see [architecture/authorization.md](../../architecture/authorization.md)).
3. **Policies** — `app/Policies/{UserPolicy,RolePolicy,SalesRegionPolicy}.php`, auto-discovered and called via `Gate::authorize()` from the matching `App\Livewire\*\Index` component *and*, on the write path, from the domain actions themselves.
4. **Step-up authentication** (task 0015a) — `App\Actions\Auth\EnsureRecentPasswordConfirmation`, an in-method check requiring a password confirmed within `config('auth.password_timeout')` before five specific Users writes. Not an ability and not a policy, so no `Gate::forUser()` test reaches it. See [architecture/authorization.md](../../architecture/authorization/step-up-and-refusal-logging.md#step-up-authentication--the-third-layer).

Rules that follow from that:

- **Test both the allowed and the denied path.** Never only the allowed one because "that's what the feature is for": `$this->actingAs($user)->get(route('users.index'))->assertForbidden()` for a user without the permission, `assertOk()` for one with it. `tests/Feature/Policies/UserPolicyTest.php` does this per ability with `Gate::forUser($actor)->allows(...)`, and asserts `Gate::forUser($actor)->authorize(...)` still throws `AuthorizationException` — which is what proves a denial is server-side rather than merely hidden in the UI.
- **A `Livewire::test()` authorization test and an HTTP authorization test are not substitutes for each other.** `Livewire::test(Index::class)` mounts the component without ever running route middleware, so it proves the component's own `Gate::authorize()` calls; `$this->get(route('users.index'))` proves the route's `can:` gate. `tests/Feature/Users/IndexTest.php` carries both for that reason — see [security/livewire-authorization.md](../../security/livewire-authorization.md).
- **Re-check authorization inside the action, not only at mount.** A component method reached over `/livewire/update` is a separate entry point; a test that only asserts `mount()` is denied will pass against a component whose `save()` is wide open.
- **Assert a user cannot act on another user's resource.** E.g. `deletePasskey` for a passkey ID belonging to someone else should 404/throw rather than silently succeed — still *not* covered by any test, which is exactly the kind of silent gap [risk-based-testing.md](../qa/risk-based-testing.md) is meant to catch.
- **Flush the permission cache in `beforeEach`, never between Act and Assert.** The `database` cache store leaks across `RefreshDatabase` tests, so a stale cache hides *revocations*; flushing mid-test destroys the test's ability to detect its own bug.
- **Seeding `auth.password_confirmed_at` is a deliberate, listed amendment — not boilerplate.** Any test that changes another user's role, status or email, deletes a user, or creates an Administrator-tier user now needs `session(['auth.password_confirmed_at' => now()->unix()])` (or `withSession([...])` in a browser test) before the mutation, or the step-up guard refuses it. Task 0015a amended 29 pre-existing tests this way; each one is enumerated in its task file, and **no assertion was loosened to make one pass**. If seeding the key is not enough to make an existing test green, the story changed behaviour — investigate rather than weaken the assertion.
- **A step-up test asserts three things, and the third is the one people skip.** That the refusal happens (assert on the **row**, not only on the response — the target's role/status/`pending_email` must be *unchanged*); that it does **not** happen for the exempt cases (a name-only edit, a self-edit, an ordinary-role creation), which is the regression guard proving the story did not ship a blanket refusal; and that a **permission** refusal still wins when both would apply — an actor lacking the ability with a stale confirmation must get `AuthorizationException`, never the re-confirmation path. Additionally, pin the timeout boundary from **both** sides with `Carbon::setTestNow()`: the vendor uses `>` and not `>=`, and a boundary asserted from one side cannot tell the two apart.

## Database assertions

Prefer asserting on returned/fetched model state (`expect($user->fresh()->...)`) or `assertDatabaseHas()`/`assertDatabaseMissing()` over trusting the HTTP response alone. A `302` redirect or `200 OK` proves the request didn't crash — it doesn't prove the database ended up in the right state.

See [database-strategy.md](database-strategy.md) for why `RefreshDatabase` is what makes `$user->passkeys()->find($passkey->id)` a trustworthy assertion here (each test starts from a clean, migrated schema).

## Counting queries per domain table

When a story fixes a query budget ("one aggregate query however many rows"), measure it with [`Tests\Support\Dashboard\DomainQueryLog`](../../../tests/Support/Dashboard/DomainQueryLog.php): `capture(callable)` returns the statements run **per domain table** (`users`, `products`, `product_variants`, `media`, `blog_posts`, `orders`, `customers`), zero-filled, and `statements(callable)` the total. Permission, role, session, cache and migration tables are deliberately ignored — a `Gate` check on a fresh actor loads roles and permissions through spatie, which would make a raw `DB::listen` count unstable. Assert the budget at two data scales (`->with([3, 30])`) so a per-row query shows up, and assert a refused caller reads no domain table at all. It is a static class, not global functions: a global helper declared by two test files is a fatal redeclare.

## Characterizing a behaviour before extracting it

Before moving markup into a shared component (story 0083 extracted `<x-order-status-badge>`), pin the **current** behaviour with a test that is green before the move and must stay green after it. Asserting label text is not enough when the thing being moved is a colour: [`OrdersListStatusBadgeTest`](../../../tests/Feature/Orders/OrdersListStatusBadgeTest.php) drives the real orders list (`Livewire::test(Orders\Index::class)`), reads each status badge's colour from its rendered `text-{colour}-{shade}` class inside the row's own `data-test` hook, and also pins the cell that deliberately stays inline (the payment badge). Commit that test first, then refactor.

## Asserting a `Route::livewire` route

A `Route::livewire()` route's action name is Livewire's own controller, so a test that asserts `getActionName()` can never see the component. Read the routed component from the action array instead (see [`DashboardTest`](../../../tests/Feature/DashboardTest.php)):

```php
// tests/Feature/DashboardTest.php
expect($route->getAction('livewire_component'))->toBe(Overview::class);
```

## Counts that do not assume an empty table

`RolePermissionSeeder` provisions one extra active Super Admin account when `SUPER_ADMIN_EMAIL` is configured, so a users counter test that expects an exact number breaks depending on the environment. Take a baseline after seeding and add to it, as [`OverviewRenderingTest`](../../../tests/Feature/Dashboard/OverviewRenderingTest.php) does.

_Last updated: 2026-10-01 — Story 0083: added the characterization-before-extraction technique, the `Route::livewire` routed-component assertion and the seed-independent count rule. Still current from story 0082 (the **Counting queries per domain table** section) and earlier tasks: 0017 corrected the **Authorization tests** layers 1 and 3 to the real counts (three module gates, three policies; the headline count stays four layers, and a single-default invariant is a data rule, not a fifth layer); 0015a added step-up as the fourth layer (seed `auth.password_confirmed_at` as a listed change only; assert the refusal on the row, the exempt cases, that a permission refusal wins, and the timeout boundary from both sides, the vendor comparison being `>`); 0004 replaced the stale policies section with the three-layer picture._
