<?php

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer and the customers migration do not
// exist yet. Every test in this file is expected to fail (class/table not found) until
// backend-expert/database-expert implement them -- that is the correct, intended "red" outcome.
//
// D-15: the list-retrieval contract is a SPECIFICATION, not a query scope — there is deliberately
// no ListCustomers action and no local scope shipped by this story, since a scope whose only
// caller does not exist yet is speculative. The contract itself is
// `Customer::query()->orderBy('name')`, returning every row, with no eager loading and no
// query-level permission filter (access is the route gate's/component's job, per the module-gate
// pattern — neither exists yet either, since this story ships no screen). So this test pins the
// contract by literally executing it, rather than asserting against a method that does not exist
// -- 0044 pins the identical contract again at its own component layer once that screen ships.
//
// No actingAs()/permission setup here on purpose: D-15 states the query itself carries no
// permission filter, so there is nothing authorization-shaped to set up for a query this story
// never gates.

use App\Models\Customer;

test('customers are listed in name order', function () {
    Customer::factory()->create(['name' => 'Zoe Martínez']);
    Customer::factory()->create(['name' => 'Ana García']);
    Customer::factory()->create(['name' => 'Marta López']);

    $names = Customer::query()->orderBy('name')->pluck('name')->all();

    expect($names)->toBe(['Ana García', 'Marta López', 'Zoe Martínez']);
});

test('an empty catalog returns no customers, rather than throwing', function () {
    $customers = Customer::query()->orderBy('name')->get();

    expect($customers)->toBeEmpty();
});
