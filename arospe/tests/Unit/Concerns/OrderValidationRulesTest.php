<?php

use App\Concerns\OrderValidationRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// This file drives Validator::make() directly (the facade, which needs a booted `validator`
// binding) -- matching tests/Unit/Concerns/PaymentMethodValidationRulesTest.php's own precedent
// for the identical reason. Unit tests are NOT bound to Tests\TestCase by default
// (tests/Pest.php's pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature',
// 'Browser') only covers Feature/Browser), so without this the facade call fails with
// "Target class [validator] does not exist." No RefreshDatabase needed -- nothing here touches
// the database (this file's own banner comment above already states that).
uses(TestCase::class);

// Story 0048, Phase 3 (TDD "red" step): App\Concerns\OrderValidationRules does not yet declare
// orderItemQuantityRules() -- every test below is expected to fail
// with "Call to undefined method" until backend-expert extends the trait (task file: "Phase 3 must
// extract rather than duplicate" the quantity rule out of 0045's own orderItemRules()).
//
// Pure unit tests, no DB: this file lives under tests/Unit/, which tests/Pest.php does NOT bind
// RefreshDatabase to (Feature and Browser only) -- see docs/testing/README.md and
// tests/Unit/Concerns/ProductAttributeValidationRulesTest.php's own precedent for this. Every
// assertion here either stays in-memory (Validator::make() against 'quantity' alone, which never
// touches the database) or inspects the CONSTRUCTED rule array without ever running it through
// $validator->fails() -- doing so against a Rule::exists() would issue a real query against
// whatever connection is configured, which this file must never do.

function orderValidationRulesFixture(): object
{
    return new class
    {
        use OrderValidationRules;

        public function quantityRules(): array
        {
            return $this->orderItemQuantityRules();
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
