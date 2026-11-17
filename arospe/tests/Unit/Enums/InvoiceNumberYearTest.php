<?php

// Story 0100 (T-16) -- App\Enums\InvoiceNumberYear::render().

use App\Enums\InvoiceNumberYear;
use Carbon\CarbonImmutable;

test('the three tokens keep their stored values', function () {
    expect(InvoiceNumberYear::None->value)->toBe('none')
        ->and(InvoiceNumberYear::Full->value)->toBe('YYYY')
        ->and(InvoiceNumberYear::Short->value)->toBe('YY')
        ->and(InvoiceNumberYear::cases())->toHaveCount(3);
});

test('each token renders the year of the passed moment', function (InvoiceNumberYear $token, string $at, string $expected) {
    expect($token->render(CarbonImmutable::parse($at)))->toBe($expected);
})->with(fn (): array => [
    'full 2026' => [InvoiceNumberYear::Full, '2026-06-15', '2026'],
    'full 2009 keeps four digits' => [InvoiceNumberYear::Full, '2009-01-01', '2009'],
    'short 2026' => [InvoiceNumberYear::Short, '2026-06-15', '26'],
    'short 2009 pads to 09' => [InvoiceNumberYear::Short, '2009-01-01', '09'],
    'short 2100 renders 00' => [InvoiceNumberYear::Short, '2100-05-05', '00'],
    'none renders nothing' => [InvoiceNumberYear::None, '2026-06-15', ''],
]);
