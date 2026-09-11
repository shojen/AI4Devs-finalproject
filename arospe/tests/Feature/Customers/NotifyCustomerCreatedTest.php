<?php

use App\Actions\Customers\NotifyCustomerCreated;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Story 0043 -- the recipient-resolution rule, exercised directly against
// app(NotifyCustomerCreated::class) rather than through CreateCustomer, per
// this action's own "independently testable against a Customer factory"
// design point.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('a user holding customers.view through a role is in the recipient set', function () {
    $role = Role::create(['name' => 'Customer Watcher', 'guard_name' => 'web']);
    $role->givePermissionTo('customers.view');

    $recipient = User::factory()->create();
    $recipient->assignRole($role);

    Notification::fake();

    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    Notification::assertSentTo($recipient, CustomerCreated::class);
});

test('a user holding customers.view granted directly (not via a role) is in the recipient set', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('customers.view');

    Notification::fake();

    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    Notification::assertSentTo($recipient, CustomerCreated::class);
});

test('a user holding every other module\'s .view permission but not customers.view is not notified', function () {
    $bystander = User::factory()->create();
    foreach (RolePermissionSeeder::MODULES as $module) {
        if ($module !== 'customers') {
            $bystander->givePermissionTo("{$module}.view");
        }
    }

    Notification::fake();

    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    Notification::assertNotSentTo($bystander, CustomerCreated::class);
});

test('a soft-deleted holder of customers.view is not notified', function () {
    $holder = User::factory()->create();
    $holder->givePermissionTo('customers.view');
    $holder->delete();

    Notification::fake();

    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    Notification::assertNotSentTo($holder, CustomerCreated::class);
});

test('a Super Admin holding no explicit customers.view grant is not notified', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Notification::fake();

    $customer = Customer::factory()->create();
    app(NotifyCustomerCreated::class)($customer);

    Notification::assertNotSentTo($superAdmin, CustomerCreated::class);
});

test('with no eligible recipients, dispatch is a clean no-op', function () {
    Notification::fake();

    $customer = Customer::factory()->create();

    // No assertion failure/exception is itself the assertion.
    app(NotifyCustomerCreated::class)($customer);

    Notification::assertNothingSent();
});

test('recipients are resolved at dispatch time, never cached from a prior resolution', function () {
    Notification::fake();

    // Resolve (and thereby "warm") the action from the container BEFORE
    // the grant exists -- pins that the action itself holds no state.
    $notifyCustomerCreated = app(NotifyCustomerCreated::class);

    $role = Role::create(['name' => 'Late Customer Watcher', 'guard_name' => 'web']);
    $role->givePermissionTo('customers.view');

    $lateHolder = User::factory()->create();
    $lateHolder->assignRole($role);

    $customer = Customer::factory()->create();
    $notifyCustomerCreated($customer);

    Notification::assertSentTo($lateHolder, CustomerCreated::class);
});

// =====================================================================
// NotifyCustomerCreated deliberately authorizes nothing of its own (see its own docblock) --
// that is safe only because it is a collaborator with exactly one caller, itself already
// authorized. This mirrors the reachability test
// tests/Feature/Products/ProductAuthorizationTest.php already establishes for
// SyncProductGallery/SyncProductSalesRegions.
// =====================================================================

// Strip comments/docblocks before searching, so a legitimate mention in prose (this file's own
// header, or a future factory docblock) can never false-positive this test -- only a real `use`
// import, a type-hint, a `new NotifyCustomerCreated`, `NotifyCustomerCreated::class` or an
// `app(NotifyCustomerCreated::class)` call counts.
function fileReferencesNotifyCustomerCreatedOutsideComments(string $path): bool
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        return false;
    }

    if (! str_contains($contents, 'NotifyCustomerCreated')) {
        return false;
    }

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $text = is_array($token) ? $token[1] : $token;

        if (str_contains($text, 'NotifyCustomerCreated')) {
            return true;
        }
    }

    return false;
}

test('NotifyCustomerCreated is referenced only by CreateCustomer anywhere under app/, database/ or routes/', function () {
    $allowedFiles = array_map('realpath', [
        app_path('Actions/Customers/CreateCustomer.php'),
        app_path('Actions/Customers/NotifyCustomerCreated.php'),
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

            if (fileReferencesNotifyCustomerCreatedOutsideComments($path)) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});
