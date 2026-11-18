<?php

// Story 0100a (Phase 3, red step) -- the existing customer gates cover the tax ID (T-18..T-20),
// and neither a refusal nor a success writes the value to a log line.

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('without customers.create a create carrying a tax ID is refused and nothing is written (T-18)', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view');
    $this->actingAs($actor);

    expect(fn () => app(CreateCustomer::class)(['name' => 'Cliente', 'email' => 'c@example.com', 'tax_id' => 'B-12345678']))
        ->toThrow(AuthorizationException::class)
        ->and(Customer::query()->count())->toBe(0);
});

test('without customers.edit an update of the tax ID is refused and the value stays (T-18)', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view');
    $this->actingAs($actor);
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    expect(fn () => app(UpdateCustomer::class)($customer, ['tax_id' => 'B-87654321']))
        ->toThrow(AuthorizationException::class)
        ->and($customer->fresh()->tax_id)->toBe('B-12345678');
});

test('a holder of customers.edit may change the tax ID, beside the refusal above (T-18)', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.edit');
    $this->actingAs($actor);
    $customer = Customer::factory()->create();

    app(UpdateCustomer::class)($customer, ['name' => $customer->name, 'email' => $customer->email, 'tax_id' => 'B-87654321']);

    expect($customer->fresh()->tax_id)->toBe('B-87654321');
});

test('a refused update logs one Privileged action refused warning for customer with no tax ID in it (T-19)', function () {
    Log::spy();
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.view');
    $this->actingAs($actor);
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    try {
        app(UpdateCustomer::class)($customer, ['tax_id' => 'ZZ-99887766']);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['target_type'] ?? null) === 'customer'
            && ($context['target_id'] ?? null) === $customer->id
            && ! str_contains((string) json_encode($context), 'ZZ-99887766')
            && ! str_contains((string) json_encode($context), 'B-12345678'))
        ->once();
});

test('a refused create logs target_type customer without the tax ID (T-19)', function () {
    Log::spy();
    $actor = User::factory()->create();
    $this->actingAs($actor);

    try {
        app(CreateCustomer::class)(['name' => 'Cliente', 'email' => 'c@example.com', 'tax_id' => 'ZZ-99887766']);
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['target_type'] ?? null) === 'customer'
            && ! str_contains((string) json_encode($context), 'ZZ-99887766'))
        ->once();
});

test('a permitted save carrying a tax ID logs no line that contains it (T-19)', function () {
    Log::spy();
    $actor = User::factory()->create();
    $actor->givePermissionTo('customers.create', 'customers.edit');
    $this->actingAs($actor);
    $customer = Customer::factory()->create();

    app(CreateCustomer::class)(['name' => 'Cliente', 'email' => 'c@example.com', 'tax_id' => 'ZZ-99887766']);
    app(UpdateCustomer::class)($customer, ['name' => $customer->name, 'email' => $customer->email, 'tax_id' => 'ZZ-11223344']);

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
    expect($customer->fresh()->tax_id)->toBe('ZZ-11223344');
});

test('an unauthenticated update is refused and logged before validation, even with an invalid tax ID (T-20)', function () {
    Log::spy();
    Auth::logout();
    $customer = Customer::factory()->create(['tax_id' => 'B-12345678']);

    expect(fn () => app(UpdateCustomer::class)($customer, ['tax_id' => 'ES_B1;']))
        ->toThrow(AuthorizationException::class)
        ->and($customer->fresh()->tax_id)->toBe('B-12345678');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === null
            && ($context['target_type'] ?? null) === 'customer')
        ->once();
});
