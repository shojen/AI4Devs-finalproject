<?php

use App\Actions\Translations\SetTranslation;
use App\Concerns\ProductValidationRules;
use Illuminate\Validation\Rules\Unique;

// Story 0076 (D-4, D-16): only the self-authorizing actions may call the unauthorized, unvalidated
// SetTranslation primitive. One expect() per namespace -- expect([...]) evaluates disjunctively.

arch('App\Livewire\Products never uses the SetTranslation primitive')
    ->expect('App\Livewire\Products')
    ->not->toUse(SetTranslation::class);

arch('App\Livewire\Orders never uses the SetTranslation primitive')
    ->expect('App\Livewire\Orders')
    ->not->toUse(SetTranslation::class);

test('productNameRules() still carries no uniqueness rule', function () {
    $harness = new class
    {
        use ProductValidationRules;

        /**
         * @return array<int, mixed>
         */
        public function name(): array
        {
            return $this->productNameRules();
        }
    };

    $rules = $harness->name();

    expect($rules)->toBe(['required', 'string', 'max:255']);

    foreach ($rules as $rule) {
        expect($rule instanceof Unique || (is_string($rule) && str_starts_with($rule, 'unique')))->toBeFalse();
    }
});
