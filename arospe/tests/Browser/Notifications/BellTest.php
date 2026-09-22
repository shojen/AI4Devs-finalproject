<?php

use App\Actions\Customers\NotifyCustomerCreated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerCreated;
use App\Notifications\OrderCreated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
 * Story 0057 (+ 0057a) -- the notification bell, at browser level. Every case is
 * one named administrator performing one action, and every case ends with
 * assertNoJavaScriptErrors().
 *
 * Since 0057a the bell is mounted ONCE, in the topbar, so every data-test hook
 * is unique per document and plain counts are exact (0057's visible-instance
 * workarounds are gone; placement is covered by Layout/TopbarTest.php). Selection
 * is always by hook, never by translated copy.
 */

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/** JS expression: how many elements carry this data-test hook. */
function hookCountJs(string $hook): string
{
    return "document.querySelectorAll('[data-test=\"{$hook}\"]').length";
}

/** JS expression: the data-test hooks of every notification row, in DOM order. */
function rowHooksJs(): string
{
    return "Array.from(document.querySelectorAll('[data-test^=\"notification-item-\"]')).map(e => e.dataset.test).join(',')";
}

/** Clicks the bell toggle and gives Livewire a moment to answer. */
function openBell($page)
{
    $page->script("document.querySelector('[data-test=\"notification-bell\"]').click()");

    return $page->wait(1);
}

function bellAdministrator(): User
{
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    return $administrator;
}

/**
 * A raw notification row of any type, at a chosen time -- bypasses every
 * real producer so it can carry an arbitrary type and a distinguishable
 * created_at.
 *
 * @param  array<string, mixed>  $data
 */
function bellRow(User $user, string $type, array $data = [], ?DateTimeInterface $at = null, bool $read = false): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode($data),
        'read_at' => $read ? now() : null,
        'created_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);

    return $id;
}

// Scenario: The bell shows an unread indicator
test('an administrator holding an unread notification sees the unread indicator', function () {
    $administrator = bellAdministrator();
    $administrator->notify(new CustomerCreated(Customer::factory()->create()));
    $this->actingAs($administrator);

    visit('/dashboard')
        ->assertScript(hookCountJs('notification-bell-unread-indicator'), 1)
        ->assertNoJavaScriptErrors();
});

// Scenario: An administrator who has never received a notification sees no indicator
test('an administrator with no notification rows at all sees no indicator', function () {
    $this->actingAs(bellAdministrator());

    visit('/dashboard')
        ->assertScript(hookCountJs('notification-bell'), 1)
        ->assertNotPresent('@notification-bell-unread-indicator')
        ->assertNoJavaScriptErrors();
});

// Scenario: An administrator whose notifications are all read sees no indicator
test('an administrator whose notifications are all read sees no indicator', function () {
    $administrator = bellAdministrator();
    bellRow($administrator, CustomerCreated::class, ['customer_id' => 'x', 'customer_name' => 'Ana'], read: true);
    $this->actingAs($administrator);

    visit('/dashboard')
        ->assertScript(hookCountJs('notification-bell'), 1)
        ->assertNotPresent('@notification-bell-unread-indicator')
        ->assertNoJavaScriptErrors();
});

// Scenario: Reading notifications clears the unread indicator
test('opening the bell clears the unread indicator and it stays cleared after a reload', function () {
    $administrator = bellAdministrator();
    $administrator->notify(new CustomerCreated(Customer::factory()->create()));
    $this->actingAs($administrator);

    $page = visit('/dashboard')->assertScript(hookCountJs('notification-bell-unread-indicator'), 1);
    openBell($page)
        ->assertScript(hookCountJs('notification-bell-unread-indicator'), 0)
        // The reload is the point: it proves read_at was written, not a client flag flipped.
        ->refresh()
        ->assertNotPresent('@notification-bell-unread-indicator')
        ->assertNoJavaScriptErrors();

    expect($administrator->unreadNotifications()->count())->toBe(0);
});

// Scenarios: A recognized notification renders a summary with a link to its record
test('a new-customer and a new-order notification are both listed with their summaries', function () {
    $administrator = bellAdministrator();
    $customer = Customer::factory()->create();
    $administrator->notify(new CustomerCreated($customer));
    $administrator->notify(new OrderCreated(Order::factory()->create()));
    $this->actingAs($administrator);

    $page = openBell(visit('/dashboard'));

    $page->assertScript("Array.from(document.querySelectorAll('[data-test^=\"notification-item-\"] a')).some(e => e.href.endsWith('".route('customers.show', $customer, false)."'))", true)
        // The order screen (story 0055) does not exist yet, so its row links only once the route does.
        ->assertScript("Array.from(document.querySelectorAll('[data-test^=\"notification-item-\"] a')).length", Route::has('orders.show') ? 2 : 1)
        ->assertScript(rowHooksJs().".split(',').length", 2)
        ->assertNoJavaScriptErrors();
});

// Scenario: A notification type the bell has never seen renders through the fallback.
// The highest-value case: it fails if the default arm is ever replaced by an exhaustive match.
test('a notification of a type the bell has never seen renders a generic row with no link', function () {
    $administrator = bellAdministrator();
    $id = bellRow($administrator, 'App\\Notifications\\SomeFutureEvent', ['whatever' => 'shape']);
    $this->actingAs($administrator);

    $page = openBell(visit('/dashboard'));
    $page->assertScript("document.querySelectorAll('[data-test=\"notification-item-{$id}\"]').length > 0", true)
        ->assertScript("Array.from(document.querySelectorAll('[data-test=\"notification-item-{$id}\"]')).length", 1)
        ->assertScript("document.querySelectorAll('[data-test=\"notification-item-{$id}\"] a').length", 0)
        ->assertScript("Array.from(document.querySelectorAll('[data-test=\"notification-item-{$id}\"]'))[0].textContent.trim().length > 0", true)
        ->assertNoJavaScriptErrors();
});

// Scenario: An administrator with no notifications at all sees an empty state
// ...and it is distinct from all-read-but-populated, which still shows the list.
test('the empty state shows only when there are no notifications, not when all are read', function () {
    $empty = bellAdministrator();
    $this->actingAs($empty);
    $page = openBell(visit('/dashboard'));
    $page->assertScript(hookCountJs('notification-empty-state'), 1)->assertNoJavaScriptErrors();

    $populated = bellAdministrator();
    $id = bellRow($populated, CustomerCreated::class, ['customer_id' => 'x', 'customer_name' => 'Ana'], read: true);
    $this->actingAs($populated);
    $page = openBell(visit('/dashboard'));
    $page->assertScript(hookCountJs('notification-empty-state'), 0)
        ->assertScript(hookCountJs("notification-item-{$id}"), 1)
        ->assertNoJavaScriptErrors();
});

// Scenarios: newest first + at most fifteen. Cap+2 so an off-by-one either way moves the number.
test('with seventeen notifications the bell lists exactly fifteen, newest first', function () {
    $administrator = bellAdministrator();
    $ids = [];
    foreach (range(1, 17) as $minutesAgo) {
        $ids[$minutesAgo] = bellRow($administrator, 'App\\Notifications\\SomeFutureEvent', [], now()->subMinutes($minutesAgo));
    }
    $this->actingAs($administrator);

    $page = openBell(visit('/dashboard'));
    $page->assertScript(rowHooksJs().".split(',').length", 15)
        ->assertScript(rowHooksJs().".startsWith('notification-item-{$ids[1]},')", true)
        ->assertScript(rowHooksJs().".includes('{$ids[16]}')", false)
        ->assertNoJavaScriptErrors();
});

// Scenarios: cross-user isolation, from ONE real fan-out dispatch (0056 R-1).
test('one fan-out dispatch gives each administrator only their own row, and reading one leaves the other unread', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $first->givePermissionTo('customers.view');
    $second->givePermissionTo('customers.view');

    (new NotifyCustomerCreated)(Customer::factory()->create());

    $firstRow = $first->notifications()->firstOrFail();
    $secondRow = $second->notifications()->firstOrFail();
    expect($firstRow->id)->not->toBe($secondRow->id);

    $this->actingAs($first);
    $page = openBell(visit('/dashboard'));
    $page->assertScript(hookCountJs("notification-item-{$firstRow->id}"), 1)
        ->assertScript("document.querySelectorAll('[data-test=\"notification-item-{$secondRow->id}\"]').length", 0)
        ->assertNoJavaScriptErrors();

    expect($second->unreadNotifications()->count())->toBe(1);

    $this->actingAs($second);
    visit('/dashboard')
        ->assertScript(hookCountJs('notification-bell-unread-indicator'), 1)
        ->assertNoJavaScriptErrors();
});

// 0056 D-1: revoking the module permission must not filter the notification back out of the UI.
test('a new-customer notification stays listed after its customers.view permission is revoked', function () {
    $administrator = bellAdministrator();
    $administrator->givePermissionTo('customers.view');
    $administrator->notify(new CustomerCreated(Customer::factory()->create()));
    $notificationId = $administrator->notifications()->firstOrFail()->id;
    $administrator->revokePermissionTo('customers.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($administrator);

    $page = openBell(visit('/dashboard'));
    $page->assertScript(hookCountJs("notification-item-{$notificationId}"), 1)->assertNoJavaScriptErrors();
});
