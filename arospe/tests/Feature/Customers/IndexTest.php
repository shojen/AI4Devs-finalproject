<?php

// Story 0044 — App\Livewire\Customers\Index, routes/customers.php. Phase 3 step 1 (TDD "red"):
// neither the route, the component nor its view exist yet, so every test below is EXPECTED to
// fail — on a missing route / missing class, not on a PHP syntax error or a hang. Red step,
// mirroring how tests/Feature/Customers/ListCustomersTest.php (story 0041) and
// tests/Feature/SalesRegions/IndexTest.php (task 0017) were both written before their own
// production code existed.
//
// This file holds the ROUTE + COMPONENT layer only, per the task file's own split: the
// list-retrieval contract (D-15, re-asserted here as 0041's Dependencies section requires) and
// the four gates (mount/openCreateModal/openEditModal/confirmDelete), never the markup contract
// (columns, disabled actions, the fifteen-field count) — that lives in IndexRenderingTest.php —
// and never the real create/edit/delete/copy FORM flow, which only a real browser round-trip can
// prove and therefore lives in tests/Browser/Customers/CustomersScreenTest.php.
//
// Per docs/testing/README.md: a Livewire::test() authorization test and an HTTP one are NOT
// substitutes for each other — /livewire/update never re-runs route middleware — so both appear
// below wherever the task file asks for both.

use App\Livewire\Customers\Index;
use App\Models\Customer;
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
function customersIndexTestActor(array $permissions = ['customers.view', 'customers.create', 'customers.edit', 'customers.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

// =====================================================================
// $this->get(route('customers.index')) — HTTP layer
// =====================================================================

test('guests are redirected to the login page when visiting the customers screen', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('a signed-in administrator without customers.view is forbidden from the customers screen, and the refusal names no permission', function () {
    $actor = customersIndexTestActor([]);
    $this->actingAs($actor);

    $response = $this->get(route('customers.index'));

    $response->assertForbidden();
    // Mirrors tests/Feature/Authorization/ModuleRouteAccessTest.php's own "names no permission"
    // shape: a can:-gated route's 403 is Laravel's generic errors::403 view, never the ability
    // string itself, so the permission catalog is not disclosed to a refused actor.
    $response->assertSee('This action is unauthorized.');
    $response->assertDontSee('customers.view', false);
    $response->assertDontSee('customers.create', false);
    $response->assertDontSee('customers.edit', false);
    $response->assertDontSee('customers.delete', false);
});

test('an administrator holding customers.view can reach the customers screen', function () {
    $actor = customersIndexTestActor(['customers.view']);
    $this->actingAs($actor);

    $this->get(route('customers.index'))->assertOk();
});

test('a Super Admin holding zero permission rows can reach the customers screen', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    $this->get(route('customers.index'))->assertOk();
});

test('an administrator holding every other module\'s view permission but not customers.view is refused', function () {
    // The case that catches a wrong permission string — the most plausible copy-paste slip when
    // scaffolding routes/customers.php from routes/users.php (task file, Tests to perform).
    $actor = customersIndexTestActor([
        'users.view', 'roles.manage', 'sales-regions.view', 'products.view',
        'shipping.view', 'payment-methods.view',
    ]);
    $this->actingAs($actor);

    $this->get(route('customers.index'))->assertForbidden();
});

// =====================================================================
// Livewire::test(Index::class) — the list-retrieval contract (0041 D-15, re-asserted here)
// =====================================================================

test('customers() implements the D-15 retrieval contract: name-ascending order, every row present, no permission filter', function () {
    Customer::factory()->create(['name' => 'Zoe Martínez']);
    Customer::factory()->create(['name' => 'Ana García']);
    Customer::factory()->create(['name' => 'Marta López']);

    // A view-only actor — proving the row SET itself carries no permission-based narrowing; only
    // the per-row canEdit/canDelete hints (IndexRenderingTest.php) differ by actor tier.
    $actor = customersIndexTestActor(['customers.view']);
    $this->actingAs($actor);

    $rows = collect(Livewire::test(Index::class)->get('customers'));

    expect($rows->pluck('name')->all())->toBe(['Ana García', 'Marta López', 'Zoe Martínez'])
        ->and($rows)->toHaveCount(Customer::count());
});

test('an empty customer catalog renders the empty state rather than an empty table', function () {
    $actor = customersIndexTestActor(['customers.view']);
    $this->actingAs($actor);

    expect(Customer::count())->toBe(0);

    Livewire::test(Index::class)
        ->assertSee(__('customers.index.empty'));
});

// =====================================================================
// Livewire::test(Index::class) — the disclosing/mutating gates
// =====================================================================

test('mounting the component directly is forbidden for an administrator holding zero relevant permissions, even though route middleware never ran', function () {
    $this->withoutExceptionHandling();
    $actor = customersIndexTestActor([]);
    $this->actingAs($actor);

    expect(fn () => Livewire::test(Index::class))->toThrow(AuthorizationException::class);
});

test('an administrator holding only customers.view is refused on openCreateModal, and no field is populated', function () {
    // A SECOND ->call() on an already-mounted Livewire::test() component does not reliably
    // re-throw AuthorizationException through expect()->toThrow() — Livewire's own
    // request-simulation pipeline can intercept it into a response instead of letting it escape
    // (docs/errors-log.md). The established fix in this repo (tests/Feature/PaymentMethods/
    // AuthorizationTest.php, tests/Feature/SalesRegions/RefusalLoggingTest.php) is try/catch with
    // an empty catch, asserting only the resulting STATE — never the exception itself — for a
    // second call on an already-hydrated $component.
    $actor = customersIndexTestActor(['customers.view']);
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    try {
        $component->call('openCreateModal');
    } catch (AuthorizationException) {
        //
    }

    // Even a gate that runs AFTER some assignments still throws — asserting state explicitly is
    // what this repo's own Log::spy()-cannot-distinguish caveat requires (see
    // docs/security/livewire-authorization.md), rather than trusting the exception alone.
    $component->assertSet('showModal', false)
        ->assertSet('name', '')
        ->assertSet('email', '');
});

test('an administrator holding only customers.view is refused on openEditModal, and no field is populated', function () {
    // See the identical note above — a second ->call() on an already-mounted component.
    $customer = Customer::factory()->create(['name' => 'Original Name']);

    $actor = customersIndexTestActor(['customers.view']);
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    try {
        $component->call('openEditModal', $customer->id);
    } catch (AuthorizationException) {
        //
    }

    $component->assertSet('showModal', false)
        ->assertSet('editingCustomerId', null)
        ->assertSet('name', '');
});

test('an administrator holding only customers.view is refused on confirmDelete, and no target is set', function () {
    // See the identical note above — a second ->call() on an already-mounted component.
    $customer = Customer::factory()->create();

    $actor = customersIndexTestActor(['customers.view']);
    $this->actingAs($actor);

    $component = Livewire::test(Index::class);

    try {
        $component->call('confirmDelete', $customer->id);
    } catch (AuthorizationException) {
        //
    }

    $component->assertSet('showDeleteModal', false)
        ->assertSet('deletingCustomerId', null)
        ->assertSet('deletingCustomerName', null);
});
