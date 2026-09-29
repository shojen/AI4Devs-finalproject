<?php

// Story 0041, Phase 3 (TDD "red" step): App\Models\Customer and the customers migration do not
// exist yet. This test is expected to fail (class/table not found) until backend-expert/
// database-expert implement them -- that is the correct, intended "red" outcome.
//
// This is the INTEGRATION half of the "a customer is not a dashboard user" acceptance criterion
// (D-11) -- the four STRUCTURAL halves (no HasRoles, no Authenticatable, no PasskeyUser, no
// SoftDeletes) live in tests/Unit/Models/CustomerTest.php, which needs no database at all.
//
// spatie/laravel-permission's polymorphic pivots key on `model_type` + `model_uuid` (see
// docs/database/schema.md's ER diagram) -- filtering by `model_uuid` alone, without also
// constraining `model_type`, is the STRONGER assertion: it proves nothing in either pivot
// references this customer's id at all, rather than only proving nothing references it typed as
// App\Models\Customer specifically (which would hold trivially, since Customer is not
// Authenticatable and Spatie never writes a row for a non-authenticatable model in the first
// place).

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

test('a created customer holds zero model_has_roles and model_has_permissions rows referencing it', function () {
    $customer = Customer::factory()->create();

    expect(DB::table('model_has_roles')->where('model_uuid', $customer->id)->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->where('model_uuid', $customer->id)->count())->toBe(0);
});
