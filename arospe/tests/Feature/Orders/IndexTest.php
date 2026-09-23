<?php

// Story 0055 -- App\Livewire\Orders\Index, routes/orders.php. Route + access layer only; the
// markup and data contract live in IndexRenderingTest.php. Per docs/testing/README.md a
// Livewire::test() authorization test and an HTTP one are NOT substitutes for each other.
//
// Helpers are prefixed `ordersUi…`: tests/Feature/Orders/ already holds many files with global
// Pest helpers, and a redeclare is a fatal error (amendment 16).

use App\Livewire\Orders\Index;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function ordersUiIndexActor(array $permissions = ['orders.view']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

test('a signed-out visitor requesting the orders list is redirected to login', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
});

test('a signed-in user holding no orders permission is forbidden from the orders list, and the refusal names no permission', function () {
    $this->actingAs(ordersUiIndexActor([]));

    $response = $this->get(route('orders.index'));

    $response->assertForbidden();
    $response->assertDontSee('orders.view', false);
});

test('a user holding exactly orders.view gets the orders list', function () {
    $this->actingAs(ordersUiIndexActor(['orders.view']));

    $this->get(route('orders.index'))->assertOk();
});

test('a Super Admin holding zero permission rows gets the orders list through Gate::before', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('orders.index'))->assertOk();
});

test('the orders.index route is gated by exactly can:orders.view', function () {
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'orders.index');

    expect($route)->not->toBeNull();

    $canMiddleware = collect($route->gatherMiddleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'can:'))
        ->values()
        ->all();

    expect($canMiddleware)->toBe(['can:orders.view']);
});

test('mounting the Index component without orders.view throws AuthorizationException', function () {
    // The Livewire harness otherwise renders the exception into a 403 response instead of rethrowing.
    $this->withoutExceptionHandling();

    $this->actingAs(ordersUiIndexActor([]));

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

test('the list component exposes no public method beyond mount, orders, ordersSummary and canViewCustomers', function () {
    $publicMethods = collect((new ReflectionClass(Index::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() !== Index::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values()
        ->all();

    // "The list writes nothing" -- a mutating method reachable over /livewire/update would
    // falsify it while every markup assertion still passed (0047's precedent).
    expect($publicMethods)->toEqualCanonicalizing(['mount', 'orders', 'ordersSummary', 'canViewCustomers']);
});
