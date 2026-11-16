<?php

// Story 0100 (T-8) -- App\Enums\InvoiceTemplate: at least two templates must exist (owner request).

use App\Enums\InvoiceTemplate;

test('there are at least two templates with distinct values, Classic first', function () {
    $values = array_map(fn (InvoiceTemplate $case): string => $case->value, InvoiceTemplate::cases());

    expect(count($values))->toBeGreaterThanOrEqual(2)
        ->and(array_unique($values))->toHaveCount(count($values))
        ->and($values)->toBe(['classic', 'modern']);
});

test('the column default is the first case', function () {
    expect(InvoiceTemplate::cases()[0])->toBe(InvoiceTemplate::Classic);
});
