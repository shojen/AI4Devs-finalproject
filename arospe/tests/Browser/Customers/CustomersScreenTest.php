<?php

// Pest 4 browser tests for the Customers screen (story 0044), per
// ai-spec/tasks/in-progress/0044-customers-list-create-edit-ui.md's "Tests to perform" section.
// The wired-up tests/Browser/ suite runs on Chromium in CI (task 0006b, done).
//
// Written at TDD Phase 3 step 1 (red), before routes/customers.php, App\Livewire\Customers\Index
// or its view exist. Every test below is expected to fail on a missing route / missing class /
// hook-not-found, not on a PHP syntax error or a hang.
//
// SELECTOR STRATEGY: select by data-test hook only, never by visible copy, for every element
// this file INTERACTS with (click/fill) — content ASSERTIONS (assertSee of a customer's own name
// or a validation message) are naturally text-based and are not selectors.
//
// ASSUMED data-test HOOKS this file's selectors rely on, per docs/testing/frontend/README.md's
// workflow and mirroring tests/Browser/Products/EditorJourneyTest.php's own "ASSUMED data-test
// HOOKS" convention — frontend-expert's implementation is expected to satisfy these exactly:
//   - data-test="create-customer"              the header's "New customer" trigger (both the
//                                               enabled and disabled branch, task file's own
//                                               instruction for the row-action hooks applied here)
//   - data-test="customer-modal"                the create/edit modal's own content, rendered
//                                               only inside @if ($showModal) — the same
//                                               "the hook must live on conditionally-rendered
//                                               content, never on <flux:modal> itself" rule
//                                               payment-methods.blade.php established
//                                               (docs/errors-log.md, 2026-09-10 entry)
//   - data-test="save-customer-button"          the create/edit modal's Save control
//   - data-test="cancel-customer-modal"         the create/edit modal's Cancel control
//   - data-test="same-as-shipping"              the "Same as shipping" copy affordance (D-1)
//   - data-test="delete-customer-modal"         the delete-confirmation modal's own content,
//                                               rendered only inside @if ($showDeleteModal)
//   - data-test="confirm-delete-customer-button" the delete-confirmation modal's confirm control
//   - data-test="cancel-delete-customer-modal"  the delete-confirmation modal's Cancel control
// The two REAL, story-dictated hooks — data-test="edit-customer-{id}" /
// data-test="delete-customer-{id}" — are used verbatim, per the task file's own View section.
//
// fill()/assertValue() target every bound field by its Livewire property name (the
// wire:model="..." target), matching every sibling screen's own browser tests (e.g.
// tests/Browser/PaymentMethodsIndexTest.php's fill('iban', ...)) — never a label or placeholder.

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function customersBrowserActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['customers.view', 'customers.create', 'customers.edit', 'customers.delete']);

    return $actor;
}

function customersBrowserViewOnlyActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(['customers.view']);

    return $actor;
}

// =====================================================================
// Scenario: Create a customer with full details (PRD §3.1)
// =====================================================================

test('creating a customer with full details through a real fill and save round-trip adds it to the list', function () {
    $this->actingAs(customersBrowserActor());

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@create-customer')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@customer-modal')
        ->fill('name', 'Diego Ferrer')
        ->fill('email', 'diego.ferrer@example.com')
        ->fill('phone', '+34 600 111 222')
        ->fill('shippingAddressLine1', 'Calle Mayor 1')
        ->fill('shippingAddressLine2', 'Piso 2')
        ->fill('shippingCity', 'Madrid')
        ->fill('shippingPostalCode', '28013')
        ->fill('shippingProvince', 'Madrid')
        ->fill('shippingCountry', 'ES')
        ->fill('billingAddressLine1', 'Avenida Libertad 5')
        ->fill('billingAddressLine2', 'Bajo A')
        ->fill('billingCity', 'Barcelona')
        ->fill('billingPostalCode', '08001')
        ->fill('billingProvince', 'Barcelona')
        ->fill('billingCountry', 'ES')
        ->click('@save-customer-button')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@customer-modal')
        ->assertSee('Diego Ferrer');

    $customer = Customer::where('email', 'diego.ferrer@example.com')->firstOrFail();

    expect($customer->name)->toBe('Diego Ferrer')
        ->and($customer->phone)->toBe('+34 600 111 222')
        ->and($customer->shipping_city)->toBe('Madrid')
        ->and($customer->shipping_country)->toBe('ES')
        ->and($customer->billing_city)->toBe('Barcelona')
        ->and($customer->billing_country)->toBe('ES');
});

// =====================================================================
// Scenario: Create a customer with only the identifying details
// =====================================================================

test('creating a customer with only the identifying details adds it, with its optional columns left null', function () {
    $this->actingAs(customersBrowserActor());

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@create-customer')
        ->assertNoJavaScriptErrors()
        ->fill('name', 'Minimal Customer')
        ->fill('email', 'minimal.customer@example.com')
        ->click('@save-customer-button')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@customer-modal')
        ->assertSee('Minimal Customer');

    $customer = Customer::where('email', 'minimal.customer@example.com')->firstOrFail();

    expect($customer->phone)->toBeNull()
        ->and($customer->shipping_city)->toBeNull()
        ->and($customer->shipping_country)->toBeNull()
        ->and($customer->billing_city)->toBeNull();
});

// =====================================================================
// Scenario: A duplicate customer email is rejected / differing only in capitalisation
// (PRD §3.1). Corrected at Phase 2 (INVEST validation): this repo is MySQL-only in both test and
// dev (phpunit.xml, .env.example) — there is no SQLite environment to also pass against.
// =====================================================================

test('a duplicate customer email is rejected inline, the modal stays open, and no second customer is added', function (string $submittedEmail) {
    $this->actingAs(customersBrowserActor());
    Customer::factory()->withEmail('cliente@example.com')->create();
    $countBefore = Customer::count();

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@create-customer')
        ->assertNoJavaScriptErrors()
        ->fill('name', 'Segundo Cliente')
        ->fill('email', $submittedEmail)
        ->click('@save-customer-button')
        ->assertNoJavaScriptErrors()
        ->assertSee(__('validation.unique', ['attribute' => 'email']))
        // The modal-stays-open half is the assertion that catches a form that closes and
        // silently discards input (task file, "Tests to perform"). Structural, not inferred:
        // data-test="customer-modal" only renders inside @if ($showModal).
        ->assertPresent('@customer-modal');

    expect(Customer::where('email', 'cliente@example.com')->count())->toBe(1)
        ->and(Customer::count())->toBe($countBefore);
})->with([
    'identical address' => ['cliente@example.com'],
    'differing only in capitalisation' => ['CLIENTE@example.com'],
]);

// =====================================================================
// Scenario: The edit form opens pre-filled with the customer's stored details
// =====================================================================

test('opening the edit modal pre-fills every field with the customers currently stored values', function () {
    $this->actingAs(customersBrowserActor());

    $customer = Customer::factory()->create([
        'name' => 'Ana García',
        'email' => 'ana.garcia@example.com',
        'phone' => '+34 600 222 333',
        'shipping_address_line1' => 'Calle Mayor 1',
        'shipping_address_line2' => 'Piso 2',
        'shipping_city' => 'Madrid',
        'shipping_postal_code' => '28013',
        'shipping_province' => 'Madrid',
        'shipping_country' => 'ES',
        'billing_address_line1' => 'Avenida Libertad 5',
        'billing_address_line2' => 'Bajo A',
        'billing_city' => 'Barcelona',
        'billing_postal_code' => '08001',
        'billing_province' => 'Barcelona',
        'billing_country' => 'ES',
    ]);

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@edit-customer-'.$customer->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@customer-modal')
        ->assertValue('name', 'Ana García')
        ->assertValue('email', 'ana.garcia@example.com')
        ->assertValue('phone', '+34 600 222 333')
        ->assertValue('shippingAddressLine1', 'Calle Mayor 1')
        ->assertValue('shippingAddressLine2', 'Piso 2')
        ->assertValue('shippingCity', 'Madrid')
        ->assertValue('shippingPostalCode', '28013')
        ->assertValue('shippingProvince', 'Madrid')
        ->assertValue('shippingCountry', 'ES')
        ->assertValue('billingAddressLine1', 'Avenida Libertad 5')
        ->assertValue('billingAddressLine2', 'Bajo A')
        ->assertValue('billingCity', 'Barcelona')
        ->assertValue('billingPostalCode', '08001')
        ->assertValue('billingProvince', 'Barcelona')
        ->assertValue('billingCountry', 'ES');
});

// =====================================================================
// Scenario: Saving an edit form without changing anything leaves the customer as it was
// =====================================================================

test('saving the edit form unchanged leaves the customer exactly as it was', function () {
    $this->actingAs(customersBrowserActor());

    $customer = Customer::factory()->create();
    $before = $customer->fresh()->getAttributes();

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@edit-customer-'.$customer->id)
        ->assertNoJavaScriptErrors()
        ->click('@save-customer-button')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@customer-modal');

    expect($customer->fresh()->getAttributes())->toBe($before);
});

// =====================================================================
// Scenario: Editing a customer while keeping its own email is accepted
// =====================================================================

test('editing a customer while keeping its own email is accepted, the address not treated as a duplicate of its own record', function () {
    $this->actingAs(customersBrowserActor());

    $customer = Customer::factory()->create([
        'email' => 'stays.same@example.com',
        'phone' => '+34 600 000 000',
    ]);

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@edit-customer-'.$customer->id)
        ->assertNoJavaScriptErrors()
        ->fill('phone', '+34 600 999 888')
        ->click('@save-customer-button')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@customer-modal')
        ->assertDontSee(__('validation.unique', ['attribute' => 'email']));

    expect($customer->fresh()->phone)->toBe('+34 600 999 888')
        ->and($customer->fresh()->email)->toBe('stays.same@example.com');
});

// =====================================================================
// Scenario: Copying the shipping address into the billing address (D-1) — a one-time copy, never
// a live binding: changing shipping AFTERWARDS must not move billing.
// =====================================================================

test('the same as shipping affordance copies the shipping values once, and a later shipping change does not follow', function () {
    $this->actingAs(customersBrowserActor());

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@create-customer')
        ->assertNoJavaScriptErrors()
        ->fill('shippingAddressLine1', 'Calle Mayor 1')
        ->fill('shippingAddressLine2', 'Piso 2')
        ->fill('shippingCity', 'Madrid')
        ->fill('shippingPostalCode', '28013')
        ->fill('shippingProvince', 'Madrid')
        ->fill('shippingCountry', 'ES')
        ->click('@same-as-shipping')
        ->assertNoJavaScriptErrors()
        ->assertValue('billingAddressLine1', 'Calle Mayor 1')
        ->assertValue('billingAddressLine2', 'Piso 2')
        ->assertValue('billingCity', 'Madrid')
        ->assertValue('billingPostalCode', '28013')
        ->assertValue('billingProvince', 'Madrid')
        ->assertValue('billingCountry', 'ES')
        ->fill('shippingCity', 'Valencia')
        ->assertNoJavaScriptErrors()
        ->assertValue('billingCity', 'Madrid');
});

// =====================================================================
// Scenario: Deleting a customer removes it from the active list / A deleted customer's record is
// preserved (PRD §3.1 — 0042's soft-delete semantics, observed from the UI).
// =====================================================================

test('confirming deletion removes the customer from the list while the record survives', function () {
    $this->actingAs(customersBrowserActor());
    $customer = Customer::factory()->create(['name' => 'Diego Ferrer']);

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->assertSee('Diego Ferrer')
        ->click('@delete-customer-'.$customer->id)
        ->assertNoJavaScriptErrors()
        ->assertPresent('@delete-customer-modal')
        ->click('@confirm-delete-customer-button')
        ->assertNoJavaScriptErrors()
        ->assertDontSee('Diego Ferrer');

    expect(Customer::find($customer->id))->toBeNull();

    $trashed = Customer::withTrashed()->find($customer->id);
    expect($trashed)->not->toBeNull()
        ->and($trashed->deleted_at)->not->toBeNull();
});

// =====================================================================
// Scenario: Cancelling a deletion leaves the customer untouched
// =====================================================================

test('cancelling the delete confirmation leaves the customer untouched', function () {
    $this->actingAs(customersBrowserActor());
    $customer = Customer::factory()->create(['name' => 'Diego Ferrer']);

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->click('@delete-customer-'.$customer->id)
        ->assertNoJavaScriptErrors()
        ->assertSee('Diego Ferrer')
        ->click('@cancel-delete-customer-modal')
        ->assertNoJavaScriptErrors()
        ->assertMissing('@delete-customer-modal')
        ->assertSee('Diego Ferrer');

    expect($customer->fresh()->deleted_at)->toBeNull();
});

// =====================================================================
// Scenario: A customer administrator without the edit / delete permission cannot open the edit
// form / delete — both row actions render disabled and are selected by their data-test hook,
// never by visible text (the actions are icon-only).
// =====================================================================

test('a view-only actor sees both row actions rendered disabled', function () {
    $this->actingAs(customersBrowserViewOnlyActor());
    $customer = Customer::factory()->create();

    visit('/customers')
        ->assertNoJavaScriptErrors()
        ->assertDisabled('@edit-customer-'.$customer->id)
        ->assertDisabled('@delete-customer-'.$customer->id);
});
