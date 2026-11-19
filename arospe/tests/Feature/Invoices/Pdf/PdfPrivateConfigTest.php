<?php

// Story 0102 Phase 4 L-1 / I-1 / I-3 -- private temp path, pinned driver, no-store download.

use App\Actions\Invoices\RenderInvoicePdf;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\PdfBuilder;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    $this->fake = FakesPdf::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

// L-1
test('.env.example documents LARAVEL_PDF_TEMP_PATH (L-1)', function () {
    expect((string) file_get_contents(base_path('.env.example')))->toContain('LARAVEL_PDF_TEMP_PATH');
});

test('the laravel-pdf temp path defaults to a private storage directory, not the system temp dir (L-1)', function () {
    $tempPath = config('laravel-pdf.browsershot.temp_path');

    expect($tempPath)->toBeString()->not->toBe('')
        ->and($tempPath)->toStartWith(storage_path('app/private'))
        ->and($tempPath)->not->toStartWith(sys_get_temp_dir());
});

// I-1
test('the invoice render pins the browsershot driver whatever the configured default (I-1)', function (string $configured) {
    config(['laravel-pdf.driver' => $configured]);

    $builder = app(RenderInvoicePdf::class)(PdfFixtures::unsaved(), false);

    $name = (new ReflectionProperty(PdfBuilder::class, 'driverName'))->getValue($builder);
    $instance = (new ReflectionProperty(PdfBuilder::class, 'driver'))->getValue($builder);

    expect($name === 'browsershot' || $instance instanceof BrowsershotDriver)->toBeTrue();
})->with(['cloudflare', 'dompdf', 'gotenberg', 'browsershot']);

// I-3
test('the download response is private and no-store because it carries personal data (I-3)', function () {
    $invoice = PdfFixtures::saved();
    $viewer = PdfFixtures::userWith('invoices.view');

    $response = $this->actingAs($viewer)->get(route('invoices.download', $invoice))->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')->toContain('no-store');
});
