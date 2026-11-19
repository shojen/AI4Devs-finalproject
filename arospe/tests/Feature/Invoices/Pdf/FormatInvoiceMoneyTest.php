<?php

// Story 0102 FD-9 -- money prints per locale from the stored decimal string, never through a float (T-15).

use App\Actions\Invoices\FormatInvoiceMoney;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    FakesPdf::fake();
});

// T-15
test('stored amounts are formatted exactly per locale without float rounding (T-15)', function (string $amount, string $locale, string $expected) {
    expect(PdfFixtures::plain(app(FormatInvoiceMoney::class)($amount, $locale)))->toBe($expected);
})->with([
    'es 0.10' => ['0.10', 'es', '0,10 €'],
    'es thousands' => ['1234.56', 'es', '1.234,56 €'],
    'es millions' => ['1234567.89', 'es', '1.234.567,89 €'],
    'es negative' => ['-45.50', 'es', '-45,50 €'],
    'es zero' => ['0.00', 'es', '0,00 €'],
    'es large' => ['99999999.99', 'es', '99.999.999,99 €'],
    'en 0.10' => ['0.10', 'en', '€0.10'],
    'en thousands' => ['1234.56', 'en', '€1,234.56'],
    'en millions' => ['1234567.89', 'en', '€1,234,567.89'],
    'en negative' => ['-45.50', 'en', '-€45.50'],
    'en zero' => ['0.00', 'en', '€0.00'],
    'en large' => ['99999999.99', 'en', '€99,999,999.99'],
]);

test('a value a float cannot hold exactly keeps its cents', function () {
    // 0.1 + 0.2 style traps: 4.35 * 100 = 434.99999... as a float.
    expect(PdfFixtures::plain(app(FormatInvoiceMoney::class)('4.35', 'en')))->toBe('€4.35')
        ->and(PdfFixtures::plain(app(FormatInvoiceMoney::class)('99999999.99', 'en')))->toBe('€99,999,999.99');
});
