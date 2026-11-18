<?php

// Story 0100b (Phase 3, red step) -- the tax ID field of App\Livewire\Customers\Index and the
// re-keying of its validation errors onto the component's camelCase properties (FD-1).

use App\Livewire\Customers\Index;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function customersTaxIdActor(array $permissions = ['customers.view', 'customers.create', 'customers.edit']): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

test('opening the edit modal copies the stored tax ID, and a null tax ID gives an empty string (T-1)', function () {
    $this->actingAs(customersTaxIdActor());
    $withTaxId = Customer::factory()->withTaxId('B-12345678')->create();
    $withoutTaxId = Customer::factory()->create(['tax_id' => null]);

    Livewire::test(Index::class)
        ->call('openEditModal', $withTaxId->id)
        ->assertSet('taxId', 'B-12345678')
        ->call('openEditModal', $withoutTaxId->id)
        ->assertSet('taxId', '');
});

test('creating with a messy tax ID stores the normalised value (T-2)', function () {
    $this->actingAs(customersTaxIdActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Cliente Nuevo')
        ->set('email', 'nuevo@example.com')
        ->set('taxId', ' b-12345678 ')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::query()->where('email', 'nuevo@example.com')->value('tax_id'))->toBe('B-12345678');
});

test('editing with an emptied tax ID clears it (T-3, FD-2)', function () {
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->withTaxId('B-12345678')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set('taxId', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->tax_id)->toBeNull();
});

test('editing only the name leaves the tax ID unchanged (T-4)', function () {
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->withTaxId('B-12345678')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set('name', 'Nombre Nuevo')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->name)->toBe('Nombre Nuevo')
        ->and($customer->fresh()->tax_id)->toBe('B-12345678');
});

test('creating without touching the tax ID stores null, not an empty string (T-5)', function () {
    $this->actingAs(customersTaxIdActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Sin Fiscal')
        ->set('email', 'sin.fiscal@example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::query()->where('email', 'sin.fiscal@example.com')->firstOrFail()->tax_id)->toBeNull();
});

test('after editing a customer with a tax ID, opening the create modal or closing the modal empties it (T-6)', function () {
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->withTaxId('B-12345678')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->assertSet('taxId', 'B-12345678')
        ->call('openCreateModal')
        ->assertSet('taxId', '')
        ->call('openEditModal', $customer->id)
        ->call('closeModal')
        ->assertSet('taxId', '');
});

test('creating with a tax ID another customer already has succeeds (T-7)', function () {
    $this->actingAs(customersTaxIdActor());
    Customer::factory()->withTaxId('B-12345678')->create();

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Gemelo')
        ->set('email', 'gemelo@example.com')
        ->set('taxId', 'B-12345678')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::query()->where('tax_id', 'B-12345678')->count())->toBe(2);
});

test('a malformed tax ID is refused on the taxId property only and nothing is written (T-8)', function (string $value) {
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->withTaxId('B-12345678')->create();

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set('taxId', $value)
        ->call('save')
        ->assertHasErrors(['taxId'])
        ->assertSet('showModal', true);

    expect(array_keys($component->errors()->toArray()))->toBe(['taxId'])
        ->and($customer->fresh()->tax_id)->toBe('B-12345678');
})->with([
    'underscore' => ['ES_B1'],
    'semicolon' => ['B1;'],
    'markup' => ['<b>'],
    'sql quote' => ["B1'--"],
    '33 characters' => [str_repeat('A', 33)],
]);

test('the tax ID refusal is rendered inside the modal naming the field in each locale (T-9)', function (string $locale) {
    app()->setLocale($locale);
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->create();

    $html = Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set('taxId', 'ES_B1')
        ->call('save')
        ->html();

    expect($html)->toContain(__('customers.attributes.tax_id'));
    expect(preg_match('/data-test="customer-modal".*'.preg_quote(__('customers.attributes.tax_id'), '/').'/s', $html))->toBe(1);
})->with(['en', 'es']);

test('a malformed billing or shipping country is refused on its own property (T-10, FD-1)', function (string $property) {
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set($property, 'ESP')
        ->call('save')
        ->assertHasErrors([$property])
        ->assertSet('showModal', true);
})->with(['billingCountry', 'shippingCountry']);

test('an invalid tax ID and an invalid email each carry their own error (T-11)', function () {
    $this->actingAs(customersTaxIdActor());

    Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Doble Error')
        ->set('email', 'not-an-email')
        ->set('taxId', 'ES_B1')
        ->call('save')
        ->assertHasErrors(['email', 'taxId']);

    expect(Customer::query()->count())->toBe(0);
});

test('a view-only actor whose edit permission is revoked mid-session cannot change the tax ID (T-12, FD-10)', function () {
    $actor = customersTaxIdActor(['customers.view', 'customers.edit']);
    $this->actingAs($actor);
    $customer = Customer::factory()->withTaxId('B-12345678')->create();

    $component = Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set('taxId', 'B-87654321');

    $actor->revokePermissionTo('customers.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('save')->assertForbidden();

    expect($customer->fresh()->tax_id)->toBe('B-12345678');
});

test('a view-only actor calling save on the create path is refused and nothing is written (T-13)', function () {
    $this->actingAs(customersTaxIdActor(['customers.view']));

    Livewire::test(Index::class)
        ->set('name', 'Intruso')
        ->set('email', 'intruso@example.com')
        ->set('taxId', 'B-12345678')
        ->call('save')
        ->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});

test('a view-only actor opening the edit modal is refused and never receives the tax ID (T-14)', function () {
    $this->actingAs(customersTaxIdActor(['customers.view']));
    $customer = Customer::factory()->withTaxId('B-12345678')->create();

    Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->assertForbidden()
        ->assertSet('taxId', '');
});

test('setting the tax ID to an array is refused by the string typed property and nothing is written (T-15)', function () {
    $this->actingAs(customersTaxIdActor());

    expect(fn () => Livewire::test(Index::class)
        ->call('openCreateModal')
        ->set('name', 'Tipo Roto')
        ->set('email', 'roto@example.com')
        ->set('taxId', ['a'])
        ->call('save'))->toThrow(TypeError::class);

    expect(Customer::query()->count())->toBe(0);
});

test('the editing customer id is locked against a forged value (T-16)', function () {
    $this->actingAs(customersTaxIdActor());
    $customer = Customer::factory()->withTaxId('B-12345678')->create();
    $other = Customer::factory()->withTaxId('B-87654321')->create();

    expect(fn () => Livewire::test(Index::class)
        ->call('openEditModal', $customer->id)
        ->set('editingCustomerId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);

    expect($other->fresh()->tax_id)->toBe('B-87654321');
});

test('the tax ID label and hint resolve in both locales with no raw key shown (T-20)', function (string $locale) {
    app()->setLocale($locale);
    $this->actingAs(customersTaxIdActor());

    $html = Livewire::test(Index::class)->call('openCreateModal')->html();

    expect($html)->toContain(__('customers.form.tax_id'))
        ->toContain(e(__('customers.form.tax_id_hint')))
        ->not->toContain('customers.form.tax_id')
        ->toContain('data-test="customer-tax-id-input"');
})->with(['en', 'es']);
