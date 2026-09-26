<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

// Story 0064a (decision D-6) -- a CHARACTERISATION test, not an independently falsifiable one.
// With the `status !== Inactive` guard removed this test would still pass: the first real save()
// re-runs syncChanges(), so getPrevious() no longer carries `email_verified_at` and the second
// delivery is stopped by the fail-closed branch anyway. The unit test
// 'delivering Verified twice to the same instance saves exactly once' is what kills that
// mutation. This one pins the end-to-end behaviour a future refactor of *how* the listener
// persists must preserve: real save(), real reload, real dispatcher.
test('delivering Verified repeatedly activates once and writes once', function () {
    $user = User::factory()->unverified()->create();

    $user->forceFill(['email_verified_at' => now()])->save();

    $updates = [];
    DB::listen(function ($query) use (&$updates): void {
        if (preg_match('/^update\s+[`"]?users[`"]?\s/i', $query->sql) === 1) {
            $updates[] = $query->sql;
        }
    });

    event(new Verified($user));

    $updatedAtAfterFirstDelivery = $user->fresh()->updated_at;

    $this->travel(5)->minutes();

    event(new Verified($user));

    $reloaded = User::query()->findOrFail($user->id);
    event(new Verified($reloaded));

    expect($reloaded->fresh()->status)->toBe(UserStatus::Active)
        ->and($updates)->toHaveCount(1)
        ->and($reloaded->fresh()->updated_at->equalTo($updatedAtAfterFirstDelivery))->toBeTrue();
});
