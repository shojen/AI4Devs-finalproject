<?php

use App\Actions\Customers\UpdateCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer, App\Actions\Customers\UpdateCustomer,
// database/factories/CustomerFactory.php and the customers migration do not exist yet. Every
// test in this file is expected to fail (class/table not found) until backend-expert/
// database-expert implement them in the next step of the TDD cycle -- that is the correct,
// intended "red" outcome.
//
// Every assertion goes through the ACTION directly (`app(UpdateCustomer::class)($customer,
// $attributes)`), never a Livewire component (D-1). UpdateCustomer authorizes `update` on the
// resolved Customer as its own first statement (D-12); every test below actingAs() an actor
// holding customers.edit, or the call throws AuthorizationException before validation ever runs
// -- see AuthorizationTest.php for the refusal/success/Super-Admin-bypass shape of the gate
// itself.
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('customers.edit');
    $this->actingAs($this->actor);
});

/**
 * The complete attribute payload UpdateCustomer expects — the whole record, mirroring what a
 * re-saved form submits (D-11: "the backend stores exactly what it receives", no partial-field
 * PATCH semantics anywhere in this story). Starts from the customer's own current column values
 * so a test that only cares about one or two fields does not have to restate the rest.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function customerUpdatePayload(Customer $customer, array $overrides = []): array
{
    $columns = [
        'name', 'email', 'phone',
        'shipping_address_line1', 'shipping_address_line2', 'shipping_city',
        'shipping_postal_code', 'shipping_province', 'shipping_country',
        'billing_address_line1', 'billing_address_line2', 'billing_city',
        'billing_postal_code', 'billing_province', 'billing_country',
    ];

    return array_merge($customer->only($columns), $overrides);
}

test('changing a customer\'s name and phone persists both, leaving every other column intact', function () {
    $customer = Customer::factory()->create([
        'phone' => '+34 600 000 000',
        'shipping_city' => 'Sevilla',
    ]);

    $attributes = customerUpdatePayload($customer, [
        'name' => 'Nombre Actualizado',
        'phone' => '+34 611 111 111',
    ]);

    app(UpdateCustomer::class)($customer, $attributes);

    $fresh = $customer->fresh();

    expect($fresh->name)->toBe('Nombre Actualizado')
        ->and($fresh->phone)->toBe('+34 611 111 111')
        ->and($fresh->email)->toBe($customer->email)
        ->and($fresh->shipping_city)->toBe('Sevilla');
});

// The ->ignore() regression test: without it, re-saving a customer with their OWN unchanged
// email would be indistinguishable from a genuine duplicate and refuse every ordinary edit.
test('saving a customer with its own unchanged email succeeds', function () {
    $customer = Customer::factory()->create(['email' => 'propio@example.com']);

    $attributes = customerUpdatePayload($customer, ['name' => 'Nombre Cambiado']);

    $updated = app(UpdateCustomer::class)($customer, $attributes);

    expect($updated->fresh()->name)->toBe('Nombre Cambiado')
        ->and($updated->fresh()->email)->toBe('propio@example.com');
});

test('changing a customer\'s email onto another customer\'s email is rejected, and the first customer keeps their original email', function () {
    $customerA = Customer::factory()->create(['email' => 'clientea@example.com']);
    $customerB = Customer::factory()->create(['email' => 'clienteb@example.com']);

    $attributes = customerUpdatePayload($customerA, ['email' => 'clienteb@example.com']);

    $caught = null;

    try {
        app(UpdateCustomer::class)($customerA, $attributes);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('email');

    // Re-read from the DATABASE, not the in-memory $customerA instance, so a mutation the action
    // applied in memory before validating (if any) cannot make this pass for the wrong reason.
    expect(Customer::query()->findOrFail($customerA->id)->email)->toBe('clientea@example.com');
});

test('a rejected edit leaves every column of the stored row unchanged', function () {
    $customer = Customer::factory()->create([
        'name' => 'Nombre Original',
        'phone' => '+34 600 222 333',
        'shipping_city' => 'Valencia',
    ]);

    $attributes = customerUpdatePayload($customer, [
        'name' => 'Nombre Que Nunca Se Guarda',
        'phone' => '+34 699 999 999',
        'shipping_city' => 'Bilbao',
        'email' => 'not-an-email',
    ]);

    try {
        app(UpdateCustomer::class)($customer, $attributes);
    } catch (Throwable) {
        //
    }

    // Assert on a FRESH read of the row, never the in-memory $customer, so a partial in-memory
    // fill() that never reached save() cannot make this pass for the wrong reason.
    $fresh = Customer::query()->findOrFail($customer->id);

    expect($fresh->name)->toBe('Nombre Original')
        ->and($fresh->phone)->toBe('+34 600 222 333')
        ->and($fresh->shipping_city)->toBe('Valencia')
        ->and($fresh->email)->toBe($customer->email);
});

// =====================================================================
// Editing — blank-to-null normalisation (D-9, Phase 4 audit F-1/F-2): starting from a customer
// with every optional column already populated, submitting a single one of them back as an
// explicit '' must clear it to a real database null rather than persist a literal empty string.
// One dataset entry per App\Concerns\CustomerValidationRules::OPTIONAL_FIELDS member (via
// UpdateCustomer::OPTIONAL_FIELDS), matching CreateCustomerTest.php's identical dataset.
// =====================================================================

test('an optional column submitted as a blank string is cleared to null on update, starting from a populated value', function (string $field) {
    $customer = Customer::factory()->create();

    $attributes = customerUpdatePayload($customer, [$field => '']);

    $updated = app(UpdateCustomer::class)($customer, $attributes);

    expect($updated->fresh()->{$field})->toBeNull();
})->with(UpdateCustomer::OPTIONAL_FIELDS);

// D-13: a customer's email is contact data, not an authentication identifier — changing it needs
// no mailbox-confirmation flow, unlike users.email's RequestEmailChange/pending_email mechanism.
// Asserted negatively so the absence is proven rather than assumed, and so this also guards
// against 0043's future "new customer" notification leaking onto the UPDATE path.
test('changing a customer\'s email dispatches no notification and sends no mail', function () {
    Notification::fake();
    Mail::fake();

    $customer = Customer::factory()->create(['email' => 'antes@example.com']);

    $attributes = customerUpdatePayload($customer, ['email' => 'despues@example.com']);

    $updated = app(UpdateCustomer::class)($customer, $attributes);

    expect($updated->fresh()->email)->toBe('despues@example.com');

    Notification::assertNothingSent();
    Mail::assertNothingSent();
});
