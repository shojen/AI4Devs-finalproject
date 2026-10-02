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

test('the list component exposes exactly the allow-listed public methods, and markAsPaid is the only writer', function () {
    $publicMethods = collect((new ReflectionClass(Index::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() !== Index::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values()
        ->all();

    // Story 0085 changed "the list writes nothing" ON PURPOSE: the list now has one write, the
    // "Mark as paid" action. Reflection cannot prove WHICH method writes, so this is an explicit
    // allow-list (a new public method fails here and forces a deliberate decision, 0047's precedent);
    // the class docblock states that markAsPaid is the only writer, and IndexMarkAsPaidTest pins its
    // behaviour. confirmMarkAsPaid / dismissMarkAsPaid only toggle dialog state, and
    // confirmingPaidRow is a read-only computed.
    expect($publicMethods)->toEqualCanonicalizing([
        'mount',
        'orders',
        'ordersSummary',
        'canViewCustomers',
        'confirmMarkAsPaid',
        'dismissMarkAsPaid',
        'markAsPaid',
        'confirmingPaidRow',
    ]);
});
