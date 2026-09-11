<?php

// View-level rendering tests for App\Livewire\Customers\Index /
// resources/views/livewire/customers.blade.php (story 0044), per
// ai-spec/tasks/in-progress/0044-customers-list-create-edit-ui.md's "Tests to perform" section.
//
// Written at TDD Phase 3 step 1 (red), before the real component/view exist. Component logic,
// persistence and the two authorization layers are covered by IndexTest.php -- nothing here
// duplicates that. Every test below asserts against the RENDERED HTML
// (assertSee/assertDontSee/->html()), which that file never does.
//
// Mirrors tests/Feature/Users/IndexRenderingTest.php's disabled/tooltip regex-scoped assertion
// shape (row actions are icon-only, so there is no visible text to assert against instead) and
// tests/Feature/ProductCategories/IndexRenderingTest.php's empty-state/nested-path guard shape.

use App\Livewire\Customers\Index;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function customersIndexRenderingActor(array $permissions = ['customers.view', 'customers.create', 'customers.edit', 'customers.delete']): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/**
 * The fifteen writable form fields the component's public surface promises (task file, Component
 * section) -- three identity fields plus six shipping and six billing address fields, each a
 * public string wire:model target.
 *
 * @return array<int, string>
 */
function customersIndexRenderingBoundFieldNames(): array
{
    return [
        'name', 'email', 'phone',
        'shippingAddressLine1', 'shippingAddressLine2', 'shippingCity',
        'shippingPostalCode', 'shippingProvince', 'shippingCountry',
        'billingAddressLine1', 'billingAddressLine2', 'billingCity',
        'billingPostalCode', 'billingProvince', 'billingCountry',
    ];
}

/**
 * Count how many of the given field names appear as a `wire:model="<name>"` attribute in $html.
 * The closing quote is the delimiter that ends the element name (task file's own instruction),
 * so this can never match a name that is merely a PREFIX of another bound property.
 *
 * @param  array<int, string>  $fieldNames
 */
function customersIndexRenderingCountBoundFields(string $html, array $fieldNames): int
{
    $pattern = '/wire:model="('.implode('|', array_map(
        fn (string $name): string => preg_quote($name, '/'),
        $fieldNames
    )).')"/';

    preg_match_all($pattern, $html, $matches);

    return count($matches[0]);
}

// =====================================================================
// A row shows its identifying and shipping-location details (Gherkin: "Each listed customer
// shows its identifying and shipping-location details").
// =====================================================================

test('a row renders the customers name, email, phone and shipping city and country', function () {
    $actor = customersIndexRenderingActor();
    $this->actingAs($actor);

    Customer::factory()->create([
        'name' => 'Diego Ferrer',
        'email' => 'diego.ferrer@arospe.es',
        'phone' => '+34 600 111 222',
        'shipping_city' => 'Valencia',
        'shipping_country' => 'ES',
    ]);

    Livewire::test(Index::class)
        ->assertSee('Diego Ferrer')
        ->assertSee('diego.ferrer@arospe.es')
        ->assertSee('+34 600 111 222')
        ->assertSee('Valencia')
        ->assertSee('ES');
});

test('a customer with no phone and no shipping address renders em dashes, not blank cells', function () {
    // Exactly one customer on the page, so a plain em-dash count is unambiguous: D-6's four
    // columns give the Phone and Shipping location cells as the only two that can render an em
    // dash for missing data (Customer/name+email is always present, Actions never renders one).
    $actor = customersIndexRenderingActor();
    $this->actingAs($actor);

    Customer::factory()->minimal()->create(['name' => 'No Contact Details']);

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('No Contact Details')
        ->and(substr_count($html, '—'))->toBe(2);
});

// =====================================================================
// Per-row action affordances (D-3: flat, no self-row carve-out; three actor tiers proving
// customers.edit and customers.delete are independently checked, never bundled).
// =====================================================================

test('the edit and delete row actions render enabled or disabled per the actors own customers.edit / customers.delete grant, independently', function (array $permissions, bool $expectEditDisabled, bool $expectDeleteDisabled) {
    $actor = customersIndexRenderingActor($permissions);
    $this->actingAs($actor);

    $customer = Customer::factory()->create();

    $html = Livewire::test(Index::class)->html();

    // The row actions are icon-only (task file, View section), so there is no visible text to
    // assert against instead -- locate each <button> by its data-test hook and read whether
    // Blade rendered the `disabled` attribute onto it, mirroring
    // tests/Feature/Users/IndexRenderingTest.php's own $isRowActionDisabled helper.
    $isRowActionDisabled = fn (string $dataTest): bool => (bool) preg_match(
        '/data-test="'.preg_quote($dataTest, '/').'"[^>]*\sdisabled="disabled"/',
        $html
    );

    expect($html)->toContain('data-test="edit-customer-'.$customer->id.'"')
        ->and($html)->toContain('data-test="delete-customer-'.$customer->id.'"')
        ->and($isRowActionDisabled('edit-customer-'.$customer->id))->toBe($expectEditDisabled)
        ->and($isRowActionDisabled('delete-customer-'.$customer->id))->toBe($expectDeleteDisabled);
})->with([
    'view-only actor — both actions disabled' => [['customers.view'], true, true],
    'customers.edit holder — edit enabled, delete disabled' => [['customers.view', 'customers.edit'], false, true],
    'customers.delete holder — delete enabled, edit disabled' => [['customers.view', 'customers.delete'], true, false],
]);

// =====================================================================
// The create action (Gherkin: "A customer administrator without the create permission sees no
// enabled create action").
// =====================================================================

test('the create action renders disabled for an actor without customers.create', function () {
    $actor = customersIndexRenderingActor(['customers.view']);
    $this->actingAs($actor);

    $html = Livewire::test(Index::class)->html();

    expect($html)->toMatch('/data-test="create-customer"[^>]*\sdisabled="disabled"/');
});

test('the create action renders enabled for an actor holding customers.create', function () {
    $actor = customersIndexRenderingActor(['customers.view', 'customers.create']);
    $this->actingAs($actor);

    $html = Livewire::test(Index::class)->html();

    expect($html)->toContain('data-test="create-customer"')
        ->and($html)->not->toMatch('/data-test="create-customer"[^>]*\sdisabled="disabled"/');
});

// =====================================================================
// The modal's field count (R-4: a count assertion over rendered markup, scoped and PROVEN
// movable, per the errors-log lesson about an over-count reading as the true number).
// =====================================================================

test('the create/edit modal renders exactly the fifteen bound form inputs, and the counting method is proven to move', function () {
    $actor = customersIndexRenderingActor();
    $this->actingAs($actor);

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    $allFifteen = customersIndexRenderingBoundFieldNames();
    expect(customersIndexRenderingCountBoundFields($html, $allFifteen))->toBe(15);

    // Prove the count is genuinely discriminating rather than a constant that would pass
    // regardless of what actually rendered: re-run the identical delimiter-scoped counting
    // method against the SAME html, searching for only fourteen of the fifteen names, and
    // confirm the match total drops by exactly one. A selector that also matched a sibling or
    // wrapper element (the errors-log failure mode this test guards against) would not move
    // predictably like this.
    $fourteen = $allFifteen;
    array_pop($fourteen);
    expect(customersIndexRenderingCountBoundFields($html, $fourteen))->toBe(14);
});

// =====================================================================
// The flat-path naming convention (naming.md's Index-in-a-subfolder exception).
// =====================================================================

test('no nested livewire/customers/index.blade.php view exists — the component resolves the flat path', function () {
    expect(file_exists(resource_path('views/livewire/customers/index.blade.php')))->toBeFalse();
});
