<?php

use Illuminate\Support\Facades\Blade;

// Story 0055 D-7 -- <x-money> renders a stored decimal STRING unchanged apart from its currency
// affix. Asserted on 10.00, 0.00 and 1234.50 so a formatter that quietly casts to float (10.00 →
// 10, 1234.50 → 1234.5) is caught.

test('the money component renders a decimal string unchanged apart from its currency affix', function (string $amount) {
    $html = Blade::render('<x-money :amount="$amount" />', ['amount' => $amount]);

    expect(trim(strip_tags($html)))->toBe('€ '.$amount);
})->with(['10.00', '0.00', '1234.50']);

test('the money component never adds a thousands separator or trims trailing zeros', function () {
    $html = Blade::render('<x-money amount="1234.50" />');

    expect($html)->toContain('1234.50')->not->toContain('1,234')->not->toContain('1234.5<');
});
