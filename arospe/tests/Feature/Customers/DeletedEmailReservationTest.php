<?php

// Story 0042 — D-1: a soft-deleted customer's email stays reserved. Rule::unique(Customer::class)
// does NOT apply the soft-delete scope (verified for `users`, recorded in schema.md), so this
// whole behaviour arrives with zero new code -- this file pins that it actually holds rather
// than being an accidental side effect that could silently regress.

use App\Actions\Customers\CreateCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo('customers.create');
    $this->actingAs($this->actor);
});

// Assert the validator's own result, NOT a database exception -- Rule::unique(Customer::class)
// does not apply the soft-delete scope, so the app layer refuses first and the 23000 never fires.
// A test asserting on a QueryException would be measuring the wrong layer.
test('creating a new customer with a soft-deleted customer\'s email is rejected with a validation error on email', function () {
    $trashed = Customer::factory()->trashed()->create(['email' => 'reservado@example.com']);

    $caught = null;

    try {
        app(CreateCustomer::class)(['name' => 'Nuevo Cliente', 'email' => 'reservado@example.com']);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught->errors())->toHaveKey('email');

    expect(Customer::withTrashed()->count())->toBe(1)
        ->and(Customer::withTrashed()->findOrFail($trashed->id)->deleted_at)->not->toBeNull();
});
