<?php

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

// Story 0056 -- the generic notification-viewing query contract: unread
// count, recent list, mark-as-read. Ships no production class (D-2): the
// contract is a set of direct calls to vendor-provided methods on
// App\Models\User's existing `Notifiable` trait. Only the ordering pin is
// kept here; the other vendor call shapes are framework behaviour and are
// no longer pinned by this file.
// See ai-spec/tasks/in-progress/0056-notification-viewing-backend.md for
// the full query contract.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Sends a real CustomerCreated notification directly to $user at a frozen
 * point in time, via the plain Notifiable::notify() entry point -- never
 * through NotifyCustomerCreated's recipient resolution, which is 0043's
 * own test plan, not this story's (see "Deliberately not tested" in the
 * task file). Restores real time immediately after, so a frozen clock
 * never leaks into the rest of a test.
 */
function notifyCustomerAt(User $user, Carbon $when): void
{
    Carbon::setTestNow($when);
    $user->notify(new CustomerCreated(Customer::factory()->create()));
    Carbon::setTestNow();
}

test('ordering is by receipt time, not by insertion order or id', function () {
    $admin = User::factory()->create();

    // Inserted FIRST but given the NEWER created_at.
    notifyCustomerAt($admin, Carbon::now());
    $newerNotificationId = $admin->notifications()->first()->id;

    // Inserted SECOND but given the OLDER created_at -- reversed from
    // insertion order, so a query sorting by id/insertion order rather
    // than created_at would return this one first instead.
    notifyCustomerAt($admin, Carbon::now()->subDay());

    $recent = $admin->notifications()->latest()->limit(15)->get();

    expect($recent->first()->id)->toBe($newerNotificationId);
});
