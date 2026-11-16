<?php

// Story 0100 (T-4, T-15) -- App\Actions\Invoices\ComposeInvoiceNumber is pure: it reads the passed
// settings, series, moment and counter, never now() and never the database.

use App\Actions\Invoices\ComposeInvoiceNumber;
use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use Carbon\CarbonImmutable;

function composeSettings(array $attributes): InvoiceSetting
{
    return (new InvoiceSetting)->forceFill($attributes);
}

test('it composes prefix, year, separator and zero-padded counter per FD-2', function (array $attributes, InvoiceType $series, string $at, int $counter, string $expected) {
    $settings = composeSettings($attributes);

    expect((new ComposeInvoiceNumber)($series, $settings, CarbonImmutable::parse($at), $counter))->toBe($expected);
})->with(fn (): array => [
    'default standard' => [
        ['standard_prefix' => 'F', 'standard_year_format' => 'YYYY', 'standard_separator' => '-', 'standard_padding' => 5],
        InvoiceType::Standard, '2026-03-01', 1, 'F2026-00001',
    ],
    'short year corrective with slash' => [
        ['corrective_prefix' => 'R', 'corrective_year_format' => 'YY', 'corrective_separator' => '/', 'corrective_padding' => 4],
        InvoiceType::Corrective, '2026-03-01', 7, 'R26/0007',
    ],
    'composite prefix with dot separator' => [
        ['standard_prefix' => 'INV/', 'standard_year_format' => 'YYYY', 'standard_separator' => '.', 'standard_padding' => 4],
        InvoiceType::Standard, '2026-03-01', 42, 'INV/2026.0042',
    ],
    'empty prefix' => [
        ['standard_prefix' => '', 'standard_year_format' => 'YYYY', 'standard_separator' => '-', 'standard_padding' => 5],
        InvoiceType::Standard, '2026-03-01', 3, '2026-00003',
    ],
    'year none still emits the separator' => [
        ['standard_prefix' => 'F', 'standard_year_format' => 'none', 'standard_separator' => '-', 'standard_padding' => 5],
        InvoiceType::Standard, '2026-03-01', 1, 'F-00001',
    ],
    'empty separator' => [
        ['standard_prefix' => 'F', 'standard_year_format' => 'none', 'standard_separator' => '', 'standard_padding' => 5],
        InvoiceType::Standard, '2026-03-01', 1, 'F00001',
    ],
    'padding 10' => [
        ['standard_prefix' => 'F', 'standard_year_format' => 'YYYY', 'standard_separator' => '-', 'standard_padding' => 10],
        InvoiceType::Standard, '2026-03-01', 12, 'F2026-0000000012',
    ],
    'counter wider than the padding is never truncated' => [
        ['standard_prefix' => 'F', 'standard_year_format' => 'YYYY', 'standard_separator' => '-', 'standard_padding' => 5],
        InvoiceType::Standard, '2026-03-01', 100000, 'F2026-100000',
    ],
]);

test('the series selects its own format columns', function () {
    $settings = composeSettings([
        'standard_prefix' => 'F', 'standard_year_format' => 'YYYY', 'standard_separator' => '-', 'standard_padding' => 5,
        'corrective_prefix' => 'R', 'corrective_year_format' => 'YY', 'corrective_separator' => '/', 'corrective_padding' => 3,
    ]);
    $at = CarbonImmutable::parse('2026-03-01');

    expect((new ComposeInvoiceNumber)(InvoiceType::Standard, $settings, $at, 9))->toBe('F2026-00009')
        ->and((new ComposeInvoiceNumber)(InvoiceType::Corrective, $settings, $at, 9))->toBe('R26/009');
});

test('the year follows the passed moment across the new-year boundary, never now()', function () {
    $settings = composeSettings([
        'standard_prefix' => 'F', 'standard_year_format' => 'YYYY', 'standard_separator' => '-', 'standard_padding' => 5,
    ]);
    $compose = new ComposeInvoiceNumber;

    expect($compose(InvoiceType::Standard, $settings, CarbonImmutable::parse('2026-12-31 23:59:59'), 1))->toBe('F2026-00001')
        ->and($compose(InvoiceType::Standard, $settings, CarbonImmutable::parse('2027-01-01 00:00:00'), 1))->toBe('F2027-00001');
});
