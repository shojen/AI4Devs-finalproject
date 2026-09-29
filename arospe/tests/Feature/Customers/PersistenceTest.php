<?php

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer, database/factories/CustomerFactory.php
// and the customers migration do not exist yet. Every test in this file is expected to fail
// (class/table not found) until backend-expert/database-expert implement them -- that is the
// correct, intended "red" outcome, per docs/workflow.md's TDD-first-step.
//
// These are pure model/schema persistence checks -- they build rows straight through the
// Eloquent factory, never through App\Actions\Customers\CreateCustomer, so no authorization setup
// is needed here (see CreateCustomerTest.php / UpdateCustomerTest.php for the action-level tests).

use App\Models\Customer;
use Illuminate\Support\Str;

test('the customer model uses HasUuids and a created customer\'s id is a 36-character UUID string, not an integer', function () {
    $customer = Customer::factory()->create();

    expect($customer->id)->toBeString()
        ->and(strlen($customer->id))->toBe(36)
        ->and(Str::isUuid($customer->id))->toBeTrue()
        ->and(is_int($customer->id))->toBeFalse();
});

test('a factory-made customer persists and reloads with every column byte-identical', function () {
    $customer = Customer::factory()->create();

    $fresh = Customer::query()->findOrFail($customer->id);

    // The fifteen writable columns, per D-7 ("every writable column is in #[Fillable]; nothing
    // is withheld") -- a local array rather than a file-level constant, since a top-level `const`
    // would be a global symbol other test files could collide on.
    $columns = [
        'name', 'email', 'phone',
        'shipping_address_line1', 'shipping_address_line2', 'shipping_city',
        'shipping_postal_code', 'shipping_province', 'shipping_country',
        'billing_address_line1', 'billing_address_line2', 'billing_city',
        'billing_postal_code', 'billing_province', 'billing_country',
    ];

    foreach ($columns as $column) {
        expect($fresh->{$column})->toBe($customer->{$column});
    }
});

// D-3: name + email are required, everything else is optional and must persist as a real
// database NULL when the caller never supplied it -- not an empty string.
test('the minimal() factory state persists with every optional column null', function () {
    $customer = Customer::factory()->minimal()->create();

    $fresh = $customer->fresh();

    expect($fresh->name)->not->toBeNull()
        ->and($fresh->email)->not->toBeNull()
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->shipping_address_line1)->toBeNull()
        ->and($fresh->shipping_address_line2)->toBeNull()
        ->and($fresh->shipping_city)->toBeNull()
        ->and($fresh->shipping_postal_code)->toBeNull()
        ->and($fresh->shipping_province)->toBeNull()
        ->and($fresh->shipping_country)->toBeNull()
        ->and($fresh->billing_address_line1)->toBeNull()
        ->and($fresh->billing_address_line2)->toBeNull()
        ->and($fresh->billing_city)->toBeNull()
        ->and($fresh->billing_postal_code)->toBeNull()
        ->and($fresh->billing_province)->toBeNull()
        ->and($fresh->billing_country)->toBeNull();
});

test('a name containing non-ASCII characters round-trips byte-exactly', function () {
    $customer = Customer::factory()->create(['name' => 'Núñez, Øyvind']);

    expect($customer->fresh()->name)->toBe('Núñez, Øyvind');
});
