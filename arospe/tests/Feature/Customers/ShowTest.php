<?php

// Story 0047 -- routes/customers.php's `customers.show` route + App\Livewire\Customers\Show,
// route/component layer only. The markup contract (identity header, order rows, read-only
// claim) lives in ShowRenderingTest.php. Per docs/testing/README.md: a Livewire::test()
// authorization test and an HTTP one are NOT substitutes for each other -- /livewire/update
// never re-runs route middleware -- so both appear below.

use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function customersShowTestActor(array $permissions = ['customers.view']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

// =====================================================================
// $this->get(route('customers.show', $customer)) — HTTP layer
// =====================================================================

test('guests are redirected to the login page when visiting a customer detail page', function () {
    $customer = Customer::factory()->create();

    $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
});

test('a signed-in administrator without customers.view is forbidden from a customer detail page', function () {
    $customer = Customer::factory()->create();
    $actor = customersShowTestActor([]);
    $this->actingAs($actor);

    $this->get(route('customers.show', $customer))->assertForbidden();
});

test('an administrator holding customers.view can reach a customer detail page', function () {
    $customer = Customer::factory()->create();
    $actor = customersShowTestActor(['customers.view']);
    $this->actingAs($actor);

    $this->get(route('customers.show', $customer))->assertOk();
});

test('a Super Admin holding zero permission rows can reach a customer detail page', function () {
    $customer = Customer::factory()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('customers.show', $customer))->assertOk();
});

test('a soft-deleted customer\'s detail page is not found', function () {
    $customer = Customer::factory()->create();
    $customer->delete();

    $actor = customersShowTestActor(['customers.view']);
    $this->actingAs($actor);

    // Route-model binding resolves through Customer's default (SoftDeletingScope-scoped) query,
    // so a trashed customer simply never binds -- confirmed here rather than assumed, since this
    // is the first route in the repo to bind a Customer.
    $this->get(route('customers.show', $customer->id))->assertNotFound();
});

test('a malformed, non-UUID customer id in the URL is not found', function () {
    $actor = customersShowTestActor(['customers.view']);
    $this->actingAs($actor);

    $this->get('/customers/not-a-uuid')->assertNotFound();
});

test('GET /customers still resolves to customers.index once the parameterised route is registered', function () {
    $request = Request::create('/customers', 'GET');

    $route = app('router')->getRoutes()->match($request);

    expect($route->getName())->toBe('customers.index');
});

test('the customers.show route is gated by exactly can:customers.view', function () {
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'customers.show');

    expect($route)->not->toBeNull();

    $canMiddleware = collect($route->gatherMiddleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'can:'))
        ->values();

    expect($canMiddleware->all())->toBe(['can:customers.view']);
});

// =====================================================================
// Livewire::test() — component layer
// =====================================================================

test('mounting the Show component without customers.view throws AuthorizationException', function () {
    // withoutExceptionHandling() is required here, not merely stylistic: mount(Customer $customer)
    // takes a model-typed parameter, and Livewire's testing harness routes a model-bound mount
    // through machinery that otherwise renders the AuthorizationException into a 403 response
    // rather than letting it propagate as a real exception -- matching the identical shape
    // tests/Feature/Products/EditorTest.php's own model-bound mount(?Product $product) tests use.
    $this->withoutExceptionHandling();

    $customer = Customer::factory()->create();
    $actor = customersShowTestActor([]);
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Show::class, ['customer' => $customer]))
        ->toThrow(AuthorizationException::class);
});

test('mounting the Show component with customers.view succeeds', function () {
    $customer = Customer::factory()->create();
    $actor = customersShowTestActor(['customers.view']);
    $this->actingAs($actor);

    Livewire::test(Show::class, ['customer' => $customer])
        ->assertOk();
});
