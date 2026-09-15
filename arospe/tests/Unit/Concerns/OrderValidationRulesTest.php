<?php

use App\Concerns\OrderValidationRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;

// Story 0048, Phase 3 (TDD "red" step): App\Concerns\OrderValidationRules does not yet declare
// orderItemQuantityRules() or orderItemOwnershipRules() -- every test below is expected to fail
// with "Call to undefined method" until backend-expert extends the trait (task file: "Phase 3 must
// extract rather than duplicate" the quantity rule out of 0045's own orderItemRules(), and add
// orderItemOwnershipRules(string $orderId) as a new method).
//
// Pure unit tests, no DB: this file lives under tests/Unit/, which tests/Pest.php does NOT bind
// RefreshDatabase to (Feature and Browser only) -- see docs/testing/README.md and
// tests/Unit/Concerns/ProductAttributeValidationRulesTest.php's own precedent for this. Every
// assertion here either stays in-memory (Validator::make() against 'quantity' alone, which never
// touches the database) or inspects the CONSTRUCTED rule array without ever running it through
// $validator->fails() -- doing so against a Rule::exists() would issue a real query against
// whatever connection is configured, which this file must never do. orderItemOwnershipRules()'s
// actual database-backed behavior (an id that exists but belongs to a DIFFERENT order) is proven
// at the action level instead, in tests/Feature/Orders/RemoveOrderItemTest.php's and
// UpdateOrderItemQuantityTest.php's own cross-order tests, matching
// tests/Unit/Concerns/ShippingZoneValidationRulesTest.php's identical division of labor.

function orderValidationRulesFixture(): object
{
    return new class
    {
        use OrderValidationRules;

        public function quantityRules(): array
        {
            return $this->orderItemQuantityRules();
        }

        public function ownershipRules(string $orderId): array
        {
            return $this->orderItemOwnershipRules($orderId);
        }

        public function itemRules(): array
        {
            return $this->orderItemRules();
        }
    };
}

// --- orderItemQuantityRules() ---

test('orderItemQuantityRules rejects zero and negative quantities', function (int $invalidQuantity) {
    $validator = Validator::make(
        ['quantity' => $invalidQuantity],
        ['quantity' => orderValidationRulesFixture()->quantityRules()]
    );

    expect($validator->fails())->toBeTrue();
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

test('orderItemQuantityRules accepts a positive integer quantity', function () {
    $validator = Validator::make(
        ['quantity' => 1],
        ['quantity' => orderValidationRulesFixture()->quantityRules()]
    );

    expect($validator->fails())->toBeFalse();
});

// The task file's own extraction requirement: "if 0045 shipped the quantity rule inline inside
// orderItemRules(), pull it out into its own method and have orderItemRules() call it" -- two
// implementations of one rule is the drift this test exists to prevent. Asserting the two rule
// arrays are IDENTICAL is what makes a future divergence (the create path rejecting 0 while the
// edit path stopped) fail here rather than shipping silently. Neither side of this comparison
// touches the database -- orderItemRules() only CONSTRUCTS its Rule::exists() entries for the
// other two fields, it never validates anything.
test('orderItemRules quantity entry is exactly orderItemQuantityRules, not a second, divergent copy', function () {
    $fixture = orderValidationRulesFixture();

    expect($fixture->itemRules()['quantity'])->toBe($fixture->quantityRules());
});

// --- orderItemOwnershipRules(string $orderId) ---

// Structural only, per this file's own banner: proves the method exists, takes an order id, and
// returns a rule set built around Rule::exists('order_items', 'id') scoped by order_id -- never
// executes it against the database. The real belongs-to-a-different-order behavior is pinned at
// the action level (see this file's banner comment).
test('orderItemOwnershipRules returns a non-empty rule set scoped to the given order id, without touching the database', function () {
    $orderId = (string) Str::uuid7();

    $rules = orderValidationRulesFixture()->ownershipRules($orderId);

    expect($rules)->toBeArray()
        ->and($rules)->not->toBeEmpty();

    $hasExistsRule = collect($rules)->contains(fn ($rule): bool => $rule instanceof Exists);

    expect($hasExistsRule)->toBeTrue();
});
