<?php

use App\Livewire\Notifications\Bell;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

// Story 0057 -- component-level assertions a browser test cannot make cheaply.
// 0056's query shapes are pinned by NotificationViewingTest; this file only
// covers what the Bell component does with them.

/**
 * Writes a raw `notifications` row of an arbitrary `type`, bypassing every
 * real producer -- the whole point of the fallback path is a type this
 * component has never seen.
 *
 * @param  array<string, mixed>  $data
 */
function rawNotificationFor(User $user, string $type, array $data = []): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode($data),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('opening the dropdown writes read_at and the count is re-read as zero within the same request', function () {
    $admin = User::factory()->create();
    $admin->notify(new CustomerCreated(Customer::factory()->create()));
    $this->actingAs($admin);

    Livewire::test(Bell::class)
        ->assertSeeHtml('data-test="notification-bell-unread-indicator"')
        ->call('open')
        ->assertDontSeeHtml('data-test="notification-bell-unread-indicator"');

    expect($admin->notifications()->whereNotNull('read_at')->count())->toBe(1)
        ->and($admin->unreadNotifications()->count())->toBe(0);
});

test('opening the bell never reads or writes another administrators notifications', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $customer = Customer::factory()->create();
    $first->notify(new CustomerCreated($customer));
    $second->notify(new CustomerCreated($customer));
    $secondsRow = $second->notifications()->firstOrFail();
    $this->actingAs($first);

    Livewire::test(Bell::class)
        ->call('open')
        ->assertDontSeeHtml('notification-item-'.$secondsRow->id);

    expect($second->unreadNotifications()->count())->toBe(1);
});

test('the list is not queried until the dropdown has been opened', function () {
    $admin = User::factory()->create();
    $admin->notify(new CustomerCreated(Customer::factory()->create()));
    $this->actingAs($admin);

    Livewire::test(Bell::class)
        ->assertDontSeeHtml('data-test="notification-item-')
        ->assertDontSeeHtml('data-test="notification-empty-state"');
});

test('a notification of an unrecognized type renders through the fallback with no link', function () {
    $admin = User::factory()->create();
    $id = rawNotificationFor($admin, 'App\\Notifications\\SomeFutureEvent', ['anything' => 'goes']);
    $this->actingAs($admin);

    Livewire::test(Bell::class)
        ->call('open')
        ->assertSeeHtml('data-test="notification-item-'.$id.'"')
        ->assertSee(__('notifications.fallback'))
        ->assertDontSeeHtml('wire:navigate');
});

test('a recognized type whose payload lost a key degrades to the fallback instead of raising', function () {
    $admin = User::factory()->create();
    $id = rawNotificationFor($admin, CustomerCreated::class, []);
    $this->actingAs($admin);

    Livewire::test(Bell::class)
        ->call('open')
        ->assertSeeHtml('data-test="notification-item-'.$id.'"')
        ->assertSee(__('notifications.fallback'))
        ->assertDontSeeHtml('wire:navigate');
});

test('an attacker-influenced type and payload value are encoded, never rendered as markup', function () {
    $admin = User::factory()->create();
    rawNotificationFor($admin, '<script>alert(1)</script>');
    rawNotificationFor($admin, CustomerCreated::class, [
        'customer_id' => 'x', 'customer_name' => '<img src=x onerror=alert(1)>',
    ]);
    $this->actingAs($admin);

    Livewire::test(Bell::class)
        ->call('open')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img src=x');
});

test('every notifications translation key exists in both locales', function () {
    expect(Arr::dot(require lang_path('es/notifications.php')))
        ->toHaveKeys(array_keys(Arr::dot(require lang_path('en/notifications.php'))));
});
