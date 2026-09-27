<?php

use App\Enums\UserStatus;
use App\Events\OrderFullyRefunded;
use App\Listeners\ActivateVerifiedUser;
use App\Listeners\CancelFullyRefundedOrder;
use App\Listeners\RejectNonActiveUserLogin;
use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;

// Story 0064a: every App\ listener is registered by Laravel 13's auto-discovery of app/Listeners
// and by nothing else (no hand-written Event::listen()). Never Event::fake() in this file: it
// replaces the dispatcher, so getRawListeners() would inspect a fake and a resolution count
// would count nothing.

/**
 * Every `App\` listener registration as `[event => list<'Class@method'>]`, read from the real
 * dispatcher. A bare class name means `@handle`, an array means `[0]@[1]`, and closures
 * (framework and package listeners) are skipped, as is anything outside the `App\` namespace.
 *
 * @return array<string, list<string>>
 */
function appListenerRegistrations(): array
{
    $registrations = [];

    foreach (Event::getRawListeners() as $event => $listeners) {
        foreach ($listeners as $listener) {
            $entry = match (true) {
                is_string($listener) && str_contains($listener, '@') => $listener,
                is_string($listener) => $listener.'@handle',
                is_array($listener) && isset($listener[0], $listener[1]) && is_string($listener[0]) && is_string($listener[1]) => $listener[0].'@'.$listener[1],
                default => null,
            };

            if ($entry !== null && str_starts_with($entry, 'App\\')) {
                $registrations[$event][] = $entry;
            }
        }
    }

    return $registrations;
}

test('no App listener is registered twice for the same event', function () {
    foreach (appListenerRegistrations() as $event => $entries) {
        foreach (array_count_values($entries) as $entry => $times) {
            expect($times)->toBe(1, "{$entry} is bound to {$event} {$times} times; delete the hand-written Event::listen() and rely on discovery.");
        }
    }
});

test('each known App listener binding exists exactly once', function (string $event, string $entry) {
    $bound = appListenerRegistrations()[$event] ?? [];

    expect(array_count_values($bound)[$entry] ?? 0)
        ->toBe(1, "{$entry} must be bound to {$event} exactly once (found: ".json_encode($bound).'). Discovery needs a public handle* method with a typed event.');
})->with([
    'Login' => [Login::class, RejectNonActiveUserLogin::class.'@handle'],
    'Authenticated' => [Authenticated::class, RejectNonActiveUserLogin::class.'@handleAuthenticated'],
    'Verified' => [Verified::class, ActivateVerifiedUser::class.'@handle'],
    'OrderFullyRefunded' => [OrderFullyRefunded::class, CancelFullyRefundedOrder::class.'@handle'],
]);

// The dispatcher builds one listener object per registration per dispatch, so a double
// registration is a double resolution. The user is Active with an explicit id so neither
// listener does anything observable: HasUuids assigns the id in a `creating` hook, and a
// make()d user would otherwise carry a null id that RejectNonActiveUserLogin::handleAuthenticated()
// compares with a null request flag (null !== null is false) and answers with a forced logout.
test('each listener is resolved exactly once per dispatch', function (string $listener, Closure $makeEvent) {
    $resolved = 0;
    app()->resolving($listener, function () use (&$resolved): void {
        $resolved++;
    });

    $user = User::factory()->make([
        'id' => '00000000-0000-7000-8000-000000000001',
        'status' => UserStatus::Active,
    ]);

    event($makeEvent($user));

    expect($resolved)->toBe(1);
})->with([
    'Verified → ActivateVerifiedUser' => [ActivateVerifiedUser::class, fn (User $user) => new Verified($user)],
    'Login → RejectNonActiveUserLogin' => [RejectNonActiveUserLogin::class, fn (User $user) => new Login('web', $user, false)],
    'Authenticated → RejectNonActiveUserLogin' => [RejectNonActiveUserLogin::class, fn (User $user) => new Authenticated('web', $user)],
]);
