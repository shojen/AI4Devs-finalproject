<?php

// Story 0102 T-30 (R) -- the only test that starts real Chromium. Opt-in: INVOICE_PDF_E2E=1, with Node,
// puppeteer's Chrome and the LARAVEL_PDF_* env keys available. It is the only proof that the Browsershot
// hardening (JavaScript off, timeout, no host resolution) applies to a real render.

use App\Actions\Invoices\RenderInvoicePdf;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    FakesPdf::optOut();
});

test('a real render of a hostile-content invoice is a valid, bounded PDF that ran no script and embedded no local file (T-30)', function () {
    config(['invoicing.pdf.timeout_seconds' => 30]);

    $hostile = PdfFixtures::unsaved([
        'template' => 'modern',
        'recipient_name' => '<script>document.title="PWNED-BY-SCRIPT"</script><img src="file:///etc/passwd"><iframe src="file:///etc/hostname"></iframe>',
        'recipient_address_line1' => '<link rel="stylesheet" href="http://169.254.169.254/latest/meta-data/">',
    ]);

    $startedAt = microtime(true);
    $pdf = app(RenderInvoicePdf::class)($hostile, true)->generatePdfContent();
    $elapsed = microtime(true) - $startedAt;

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1024)
        ->and($pdf)->not->toContain('PWNED-BY-SCRIPT')
        ->and($pdf)->not->toContain('root:x:0:0')
        ->and($elapsed)->toBeLessThan(30.0);
})->skip(fn (): bool => getenv('INVOICE_PDF_E2E') !== '1', 'Opt-in: set INVOICE_PDF_E2E=1 to run the real Chromium render.');

test('a real render of an invoice whose customer text contains Browsershot-forbidden tokens succeeds and leaves no temp file (M-1)', function () {
    $tempPath = (string) config('laravel-pdf.browsershot.temp_path');

    $tricky = PdfFixtures::unsaved([
        'recipient_name' => 'view-source //localhost Perez',
        'recipient_address_line1' => 'Piso 2 view-source:http //127.0.0.1/admin',
    ], [
        ['position' => 1, 'description' => 'Mug view-source //127.0.0.1 //localhost', 'sku' => 'MUG-1', 'quantity' => 1, 'unit_price' => '50.00', 'line_subtotal' => '50.00', 'tax_amount' => '10.50', 'line_total' => '60.50'],
    ]);

    $pdf = app(RenderInvoicePdf::class)($tricky, false)->generatePdfContent();

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1024)
        ->and($tempPath !== '' && is_dir($tempPath) ? glob($tempPath.'/*') : [])->toBe([]);
})->skip(fn (): bool => getenv('INVOICE_PDF_E2E') !== '1', 'Opt-in: set INVOICE_PDF_E2E=1 to run the real Chromium render.');
