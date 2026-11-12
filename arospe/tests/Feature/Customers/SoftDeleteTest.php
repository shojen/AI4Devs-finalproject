<?php

// Story 0042 — SoftDeletes on App\Models\Customer, with NO Customer::delete() override.
//
// D-2 is this file's whole reason for existing: `use SoftDeletes;` is the entire implementation,
// and the regression guard for that is a test, not a code review note -- the
// identifying-columns-unchanged assertion below fails immediately if a future contributor
// "helpfully" copies User::delete()'s obfuscation onto Customer.

use App\Models\Customer;

// The direct regression guard for D-2: a future contributor copying User::delete()'s
// obfuscation onto Customer fails exactly here, and nothing else in the suite would catch it.
test("the customer's identifying columns are untouched by the delete", function () {
    $customer = Customer::factory()->create([
        'name' => 'Cliente Original',
        'email' => 'cliente-original@example.com',
        'phone' => '+34600000000',
        'shipping_address_line1' => 'Calle Falsa 123',
        'shipping_city' => 'Madrid',
        'billing_address_line1' => 'Calle Falsa 123',
        'billing_city' => 'Madrid',
    ]);

    $customer->delete();

    $trashed = Customer::withTrashed()->findOrFail($customer->id);

    expect($trashed->name)->toBe('Cliente Original')
        ->and($trashed->email)->toBe('cliente-original@example.com')
        ->and($trashed->phone)->toBe('+34600000000')
        ->and($trashed->shipping_address_line1)->toBe('Calle Falsa 123')
        ->and($trashed->shipping_city)->toBe('Madrid')
        ->and($trashed->billing_address_line1)->toBe('Calle Falsa 123')
        ->and($trashed->billing_city)->toBe('Madrid');
});
