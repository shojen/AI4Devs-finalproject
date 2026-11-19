<?php

// Story 0102 -- guard: no test of this folder may start Chromium by accident. `tests/Pest.php` fails
// any test here that neither called FakesPdf::fake() nor opted out; this file checks the swap and
// that every sibling file declares its use (the smoke T-30 is the only opt-out, gated on the env).

use PHPUnit\Framework\AssertionFailedError;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Support\Invoices\FakesPdf;

test('FakesPdf::fake swaps the PDF engine for the package fake', function () {
    $fake = FakesPdf::fake();

    expect(Pdf::getFacadeRoot())->toBe($fake)
        ->and($fake)->toBeInstanceOf(FakePdfBuilder::class);
});

test('every test file of the PDF folder fakes the engine, except the opt-in smoke', function () {
    FakesPdf::fake();

    $unguarded = collect(glob(__DIR__.'/*Test.php'))
        ->reject(fn (string $file): bool => $file === __FILE__)
        ->filter(function (string $file): bool {
            $source = (string) file_get_contents($file);

            if (str_contains($source, 'INVOICE_PDF_E2E')) {
                return ! str_contains($source, 'FakesPdf::optOut()');
            }

            return ! str_contains($source, 'FakesPdf::fake()');
        })
        ->map(fn (string $file): string => basename($file))
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

test('the guard fails a test that does not use the fake', function () {
    FakesPdf::reset();

    expect(fn () => FakesPdf::assertUsed())->toThrow(AssertionFailedError::class);

    FakesPdf::fake();
});
