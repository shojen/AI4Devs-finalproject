<?php

use App\Actions\Customers\CreateCustomer;

// Story 0041, Phase 5 code-review finding F-3: App\Concerns\CustomerValidationRules::OPTIONAL_FIELDS
// and customerRules()'s own 'nullable' keys have always agreed by coincidence -- nothing checked
// that they must. This is the drift guard: it fails the moment either list gains or loses a member
// the other does not know about, rather than the two silently diverging (e.g. a future column added
// to customerRules() as 'nullable' but never added to OPTIONAL_FIELDS would never get its blank
// string normalised to null before validation, per docs/errors-log.md's "Livewire skips
// ConvertEmptyStringsToNull ... and Laravel skips non-implicit rules for a blank string" entry).
//
// customerRules() is read through App\Actions\Customers\CreateCustomer -- a trait method/constant
// cannot be invoked/read via the trait's own name directly (PHP: "Cannot access trait constant ...
// directly"), only through a class that `use`s it. CreateCustomer is resolved from the container,
// never `new`-ed, per docs/conventions/code-style.md's "an action must be resolved from the
// container, never `new`-ed" rule.

// Story 0100a (T-17c): the generic guard below would also catch a missing tax_id, but only as a
// diff; this names the field so the failure says exactly what drifted.
test('OPTIONAL_FIELDS contains tax_id and customerRules() declares it nullable', function () {
    /** @var array<string, array<int, mixed>> $rules */
    $rules = (new ReflectionMethod(CreateCustomer::class, 'customerRules'))->invoke(app(CreateCustomer::class), null);

    expect(CreateCustomer::OPTIONAL_FIELDS)->toContain('tax_id')
        ->and($rules)->toHaveKey('tax_id')
        ->and($rules['tax_id'])->toContain('nullable');
});

test('OPTIONAL_FIELDS covers exactly the nullable keys of customerRules()', function () {
    /** @var array<string, array<int, mixed>> $rules */
    $rules = (new ReflectionMethod(CreateCustomer::class, 'customerRules'))->invoke(app(CreateCustomer::class), null);

    $nullable = array_keys(array_filter(
        $rules,
        fn (array $set): bool => in_array('nullable', $set, true),
    ));

    // Every nullable customerRules() key is in OPTIONAL_FIELDS (nothing customerRules() treats as
    // optional is missing from the blank-to-null normalisation list)...
    expect(array_values(array_diff($nullable, CreateCustomer::OPTIONAL_FIELDS)))->toBe([])
        // ...and every OPTIONAL_FIELDS member is a genuinely nullable customerRules() key (nothing
        // is normalised that validation would reject as required anyway).
        ->and(array_values(array_diff(CreateCustomer::OPTIONAL_FIELDS, $nullable)))->toBe([]);
});
