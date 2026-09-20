<?php

use App\Actions\Customers\NotifyCustomerCreated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerCreated;
use App\Notifications\OrderCreated;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Story 0056 -- the generic notification-viewing query contract: unread
// count, recent list, mark-as-read. Ships no production class (D-2): every
// operation below is a direct call to a vendor-provided method on
// App\Models\User's existing `Notifiable` trait, so this file IS the
// deliverable -- a regression suite pinning those vendor call shapes
// against this installed laravel/framework, per the task file's own R-5.
// See ai-spec/tasks/in-progress/0056-notification-viewing-backend.md for
// the full query contract this suite exists to protect.

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

test('the unread count spans every notification type', function () {
    $admin = User::factory()->create();

    $admin->notify(new CustomerCreated(Customer::factory()->create()));
    $admin->notify(new OrderCreated(Order::factory()->create()));

    expect($admin->unreadNotifications()->count())->toBe(2);
});

test('an administrator with no notifications at all has a zero count', function () {
    $admin = User::factory()->create();

    expect($admin->unreadNotifications()->count())->toBe(0);
});

test('an administrator whose notifications are all read has a zero count', function () {
    $admin = User::factory()->create();
    $admin->notify(new CustomerCreated(Customer::factory()->create()));

    $admin->unreadNotifications->markAsRead();

    expect($admin->unreadNotifications()->count())->toBe(0);
});

test('mark-as-read writes a real timestamp, not a truthy placeholder', function () {
    $admin = User::factory()->create();
    $admin->notify(new CustomerCreated(Customer::factory()->create()));

    $admin->unreadNotifications->markAsRead();

    $notification = $admin->notifications()->first();

    expect($notification->read_at)->not->toBeNull()
        ->and($notification->read_at)->toBeInstanceOf(CarbonInterface::class);
});

test('the unread count recomputes in the same request, read through the method rather than the cached property', function () {
    $admin = User::factory()->create();
    $admin->notify(new CustomerCreated(Customer::factory()->create()));

    expect($admin->unreadNotifications()->count())->toBe(1);

    // Accessing the property loads and caches the relation on THIS model
    // instance -- markAsRead() below writes through that same cached
    // collection, which is the trap the query contract documents.
    $admin->unreadNotifications->markAsRead();

    // The method re-issues the query and correctly returns 0, with no
    // fresh User instance -- the property alone would still read stale.
    expect($admin->unreadNotifications()->count())->toBe(0)
        ->and($admin->unreadNotifications)->toHaveCount(1);
});

test('mark-as-read is idempotent -- marking an already-read set again does not move read_at', function () {
    $admin = User::factory()->create();

    Carbon::setTestNow(Carbon::now());
    $admin->notify(new CustomerCreated(Customer::factory()->create()));
    $admin->unreadNotifications->markAsRead();
    $firstReadAt = $admin->notifications()->first()->read_at;

    // Advance the clock and mark the (already-read) set again -- if
    // markAsRead()'s is_null($this->read_at) guard were absent, this
    // second call would move read_at to the new frozen time.
    Carbon::setTestNow(Carbon::now()->addHour());
    $admin->readNotifications->markAsRead();
    Carbon::setTestNow();

    expect($admin->notifications()->first()->read_at->eq($firstReadAt))->toBeTrue();
});

test('an administrator does not see another administrator\'s notification', function () {
    $role = Role::create(['name' => 'Customer Watchers', 'guard_name' => 'web']);
    $role->givePermissionTo('customers.view');

    $firstAdmin = User::factory()->create();
    $firstAdmin->assignRole($role);

    $secondAdmin = User::factory()->create();
    $secondAdmin->assignRole($role);

    // One real fan-out dispatch producing both recipients' rows (R-1) --
    // not two independently built fixtures, which could pass this
    // assertion vacuously even against a query missing its own scope.
    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    $firstAdminNotifications = $firstAdmin->notifications()->latest()->limit(15)->get();

    expect($firstAdminNotifications)->toHaveCount(1)
        ->and($firstAdminNotifications->first()->notifiable_id)->toBe($firstAdmin->id);
});

test('reading one administrator\'s notifications leaves the other administrator\'s notification unread', function () {
    $role = Role::create(['name' => 'Customer Watchers', 'guard_name' => 'web']);
    $role->givePermissionTo('customers.view');

    $firstAdmin = User::factory()->create();
    $firstAdmin->assignRole($role);

    $secondAdmin = User::factory()->create();
    $secondAdmin->assignRole($role);

    // Same one-real-dispatch construction as the scoping test above --
    // asserted on the COUNT rather than the list, so a `WHERE
    // notifiable_id = ?` accidentally dropped from either query is caught.
    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    $firstAdmin->unreadNotifications->markAsRead();

    expect($secondAdmin->unreadNotifications()->count())->toBe(1);
});

test('the recent list shows exactly the fifteen most recent notifications, newest first', function () {
    $admin = User::factory()->create();
    // Floored to whole seconds: the `notifications.created_at` column has
    // no fractional-second precision, so comparing against a $now still
    // carrying microseconds would fail on a false mismatch, not a real one.
    $now = Carbon::now()->startOfSecond();

    // Seed cap + 2 (17), at distinguishable times, so an off-by-one in
    // either direction moves the returned count (D-4).
    foreach (range(1, 17) as $i) {
        notifyCustomerAt($admin, $now->copy()->subMinutes(17 - $i));
    }

    $recent = $admin->notifications()->latest()->limit(15)->get();

    expect($recent)->toHaveCount(15)
        ->and($recent->first()->created_at->equalTo($now))->toBeTrue();
});

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

test('a notification stays visible after its recipient\'s module permission is revoked', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('customers.view');

    $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);
    app(NotifyCustomerCreated::class)($customer);

    $admin->revokePermissionTo('customers.view');

    $recent = $admin->notifications()->latest()->limit(15)->get();

    expect($recent)->toHaveCount(1)
        ->and($recent->first()->data['customer_name'])->toBe('Ada Lovelace')
        ->and($recent->first()->data['customer_id'])->toBe($customer->id);
});
