<?php

use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;

// No forgetCachedPermissions() beforeEach() -- PaymentMethodSeeder touches no permission cache
// at all, matching ShippingCarrierSeederTest.php's own reasoning for the identical omission.

// --- Catalog coverage ---

// Count alone passes against "seeded the wrong method"; identity alone passes against "seeded
// two" -- both are asserted together, and never against a hardcoded row id.
test('seeding creates exactly one bank_transfer row with a null IBAN', function () {
    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::count())->toBe(1);

    $method = PaymentMethod::first();

    expect($method->code)->toBe(PaymentMethodCode::BankTransfer)
        ->and($method->iban)->toBeNull();
});

// --- Idempotency (a separate test from the single-run assertion above) ---

test('running the seeder twice still yields exactly one row', function () {
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    expect(PaymentMethod::count())->toBe(1);
});

// --- The no-clobber guarantee (the load-bearing test in this story) ---

test('re-seeding does not clobber an administrator-configured IBAN', function () {
    $this->seed(PaymentMethodSeeder::class);

    $method = PaymentMethod::first();
    $method->update(['iban' => 'ES9121000418450200051332']);

    $this->seed(PaymentMethodSeeder::class);

    expect($method->fresh()->iban)->toBe('ES9121000418450200051332');
});
