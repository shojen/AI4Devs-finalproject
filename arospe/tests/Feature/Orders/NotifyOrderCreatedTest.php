<?php

use App\Actions\Orders\NotifyOrderCreated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderCreated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Story 0046 -- the recipient-resolution rule, exercised directly against
// app(NotifyOrderCreated::class) rather than through CreateOrder, per this
// action's own "independently testable against an Order factory" design
// point. Shape-copies tests/Feature/Customers/NotifyCustomerCreatedTest.php
// (story 0043), which this story's own task file names as the reference to
// mirror wholesale.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function orderForNotification(): Order
{
    return Order::factory()->for(Customer::factory(), 'customer')->create();
}

test('a user holding orders.view through a role is in the recipient set', function () {
    $role = Role::create(['name' => 'Order Watcher', 'guard_name' => 'web']);
    $role->givePermissionTo('orders.view');

    $recipient = User::factory()->create();
    $recipient->assignRole($role);

    Notification::fake();

    $order = orderForNotification();
    app(NotifyOrderCreated::class)($order);

    Notification::assertSentTo($recipient, OrderCreated::class);
});

test('a user holding orders.view granted directly (not via a role) is in the recipient set', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('orders.view');

    Notification::fake();

    $order = orderForNotification();
    app(NotifyOrderCreated::class)($order);

    Notification::assertSentTo($recipient, OrderCreated::class);
});

// The permission-typo guard (R-3): the single most plausible copy-paste slip when a story is a
// deliberate shape copy of another (customers.view left in place, or orders.create substituted for
// orders.view) -- this fails CLOSED and silently, so it needs its own dataset rather than a single
// case.
test('a user holding every other module\'s .view permission, or a non-view orders ability, but not orders.view is not notified', function (Closure $grantWrongPermission) {
    $bystander = User::factory()->create();
    $grantWrongPermission($bystander);

    Notification::fake();

    $order = orderForNotification();
    app(NotifyOrderCreated::class)($order);

    Notification::assertNotSentTo($bystander, OrderCreated::class);
})->with([
    'every other module\'s .view' => [function (User $user): void {
        foreach (RolePermissionSeeder::MODULES as $module) {
            if ($module !== 'orders') {
                $user->givePermissionTo("{$module}.view");
            }
        }
    }],
    'orders.create' => [fn (User $user) => $user->givePermissionTo('orders.create')],
    'orders.edit' => [fn (User $user) => $user->givePermissionTo('orders.edit')],
    'orders.delete' => [fn (User $user) => $user->givePermissionTo('orders.delete')],
]);

test('a soft-deleted holder of orders.view is not notified', function () {
    $holder = User::factory()->create();
    $holder->givePermissionTo('orders.view');
    $holder->delete();

    Notification::fake();

    $order = orderForNotification();
    app(NotifyOrderCreated::class)($order);

    Notification::assertNotSentTo($holder, OrderCreated::class);
});

// D-1: the Super Admin's access comes from the Gate::before bypass, an authorization-layer
// construct that grants no role_has_permissions/model_has_permissions rows for User::permission()
// to match -- so the Super Admin falls out of the recipient set for free, and the story keeps it
// that way deliberately.
test('a Super Admin holding no explicit orders.view grant is not notified', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Notification::fake();

    $order = orderForNotification();
    app(NotifyOrderCreated::class)($order);

    Notification::assertNotSentTo($superAdmin, OrderCreated::class);
});

test('with no eligible recipients, dispatch is a clean no-op', function () {
    Notification::fake();

    $order = orderForNotification();

    // No assertion failure/exception is itself the assertion.
    app(NotifyOrderCreated::class)($order);

    Notification::assertNothingSent();
});

test('recipients are resolved at dispatch time, never cached from a prior resolution', function () {
    Notification::fake();

    // Resolve (and thereby "warm") the action from the container BEFORE the grant exists -- pins
    // that the action itself holds no state.
    $notifyOrderCreated = app(NotifyOrderCreated::class);

    $role = Role::create(['name' => 'Late Order Watcher', 'guard_name' => 'web']);
    $role->givePermissionTo('orders.view');

    $lateHolder = User::factory()->create();
    $lateHolder->assignRole($role);

    $order = orderForNotification();
    $notifyOrderCreated($order);

    Notification::assertSentTo($lateHolder, OrderCreated::class);
});

// The task file's own explicit query-count guard: dispatching to THREE recipients must resolve the
// order's customer relation ONCE, not once per recipient -- a single-recipient test cannot see
// this, since Notification::send() calls toArray() once per notifiable and toArray() reads
// $order->customer->name. A lazy, un-preloaded relation here is a latent N-query loop invisible
// until a second administrator exists.
test('dispatching to three recipients resolves the order\'s customer relation once, not once per recipient', function () {
    $recipients = User::factory()->count(3)->create();
    $recipients->each(fn (User $user) => $user->givePermissionTo('orders.view'));

    // A fresh, unhydrated model -- no relation preloaded by this test itself -- so the only way
    // $order->customer resolves for three separate toArray() calls without a query per recipient
    // is if NotifyOrderCreated loads it once, up front, before constructing the notification.
    $order = Order::query()->findOrFail(orderForNotification()->id);

    $customerQueryCount = 0;
    DB::listen(function ($query) use (&$customerQueryCount): void {
        if (str_contains($query->sql, 'customers')) {
            $customerQueryCount++;
        }
    });

    Notification::fake();

    app(NotifyOrderCreated::class)($order);

    Notification::assertSentTo($recipients, OrderCreated::class);
    expect($customerQueryCount)->toBeLessThanOrEqual(1);
});

// =====================================================================
// NotifyOrderCreated deliberately authorizes nothing of its own -- that is safe only because it is
// a collaborator with exactly one caller, itself already authorized (CreateOrder). This mirrors the
// identical reachability test tests/Feature/Customers/NotifyCustomerCreatedTest.php already
// establishes for NotifyCustomerCreated, and the same structural pattern
// tests/Feature/Products/ProductAuthorizationTest.php establishes for SyncProductGallery/
// SyncProductSalesRegions.
// =====================================================================

// Strip comments/docblocks before searching, so a legitimate mention in prose (this file's own
// header, or a future factory docblock) can never false-positive this test -- only a real `use`
// import, a type-hint, a `new NotifyOrderCreated`, `NotifyOrderCreated::class` or an
// `app(NotifyOrderCreated::class)` call counts.
function fileReferencesNotifyOrderCreatedOutsideComments(string $path): bool
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        return false;
    }

    if (! str_contains($contents, 'NotifyOrderCreated')) {
        return false;
    }

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $text = is_array($token) ? $token[1] : $token;

        if (str_contains($text, 'NotifyOrderCreated')) {
            return true;
        }
    }

    return false;
}

test('NotifyOrderCreated is referenced only by CreateOrder anywhere under app/, database/ or routes/', function () {
    $allowedFiles = array_map('realpath', [
        app_path('Actions/Orders/CreateOrder.php'),
        app_path('Actions/Orders/NotifyOrderCreated.php'),
    ]);

    $offenders = [];
    $scanRoots = [app_path(), base_path('database'), base_path('routes')];

    foreach ($scanRoots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (File::allFiles($root) as $file) {
            $path = $file->getRealPath();

            if ($path === false || $file->getExtension() !== 'php' || in_array($path, $allowedFiles, true)) {
                continue;
            }

            if (fileReferencesNotifyOrderCreatedOutsideComments($path)) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});
