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
