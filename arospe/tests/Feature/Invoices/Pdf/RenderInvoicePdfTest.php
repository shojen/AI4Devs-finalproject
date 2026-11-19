<?php

// Story 0102 FD-4/FD-7/FD-8/FD-10/FD-13 -- RenderInvoicePdf under Pdf::fake() (T-9, T-28 and the Browsershot hardening).

use App\Actions\Invoices\RenderInvoicePdf;
use App\Enums\InvoiceTemplate;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Browsershot\Browsershot;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    $this->fake = FakesPdf::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

// T-9
test('the builder uses the invoice own template view, the store locale and the flags (T-9)', function (InvoiceTemplate $template, bool $isCopy) {
    $invoice = PdfFixtures::saved(['template' => $template->value]);

    app(RenderInvoicePdf::class)($invoice, $isCopy)->generatePdfContent();

    $this->fake->assertViewIs($template->view());
    $this->fake->assertViewHas('locale', 'es');
    $this->fake->assertViewHas('isCopy', $isCopy);
    $this->fake->assertViewHas('isSample', false);
})->with(InvoiceTemplate::cases())->with([false, true]);

test('the sample flag reaches the view data (T-9)', function () {
    app(RenderInvoicePdf::class)(PdfFixtures::unsaved(), false, true)->generatePdfContent();

    $this->fake->assertViewHas('isSample', true);
    $this->fake->assertSee(PdfFixtures::label('sample', 'es'));
});

test('paper format and margins come from config (T-9)', function () {
    config(['invoicing.pdf.format' => 'letter', 'invoicing.pdf.margin_mm' => 12]);

    $builder = app(RenderInvoicePdf::class)(PdfFixtures::saved(), false);

    expect($builder->format)->toBe('letter')
        ->and($builder->margins)->toEqual(['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'unit' => 'mm']);
});

test('rendering writes nothing to any disk and queues nothing (T-9)', function () {
    Storage::fake('local');
    Storage::fake('public');

    app(RenderInvoicePdf::class)(PdfFixtures::saved(), false)->generatePdfContent();

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(fn () => $this->fake->assertSaved(fn () => true))->toThrow(AssertionFailedError::class);
    $this->fake->assertNotQueued();
});

test('the PDF language is the fallback locale when the store default has no invoices lang file (T-18)', function () {
    config(['app.fallback_locale' => 'en']);
    Invoicing::defaultStoreLanguage('fr');
    app()->setLocale('es');

    app(RenderInvoicePdf::class)(PdfFixtures::saved(), true)->generatePdfContent();

    $this->fake->assertViewHas('locale', 'en');
    $this->fake->assertSee(PdfFixtures::label('copy', 'en'));
    expect(app()->getLocale())->toBe('es');
});

test('the ambient UI locale is never used as the PDF language (T-18)', function () {
    app()->setLocale('en');

    app(RenderInvoicePdf::class)(PdfFixtures::saved(), false)->generatePdfContent();

    $this->fake->assertViewHas('locale', 'es');
    expect(app()->getLocale())->toBe('en');
});

// FD-10
test('Browsershot is hardened: no JavaScript, a render timeout and no host resolution (FD-10)', function () {
    config(['invoicing.pdf.timeout_seconds' => 17]);

    app(RenderInvoicePdf::class)(PdfFixtures::saved(), false)->generatePdfContent();

    $this->fake->assertBrowsershot(function (Browsershot $browsershot): bool {
        $options = json_encode($browsershot->createPdfCommand()['options']);

        return str_contains($options, '"disableJavascript":true')
            && str_contains($options, '"timeout":17000')
            && str_contains($options, '--host-resolver-rules=MAP * ~NOTFOUND');
    });
});

// T-28
test('a stored template value outside the enum fails loudly and renders nothing (T-28)', function () {
    $invoice = PdfFixtures::saved();
    $invoice->forceFill(['template' => 'brutalist']);

    expect(fn () => app(RenderInvoicePdf::class)($invoice, false)->generatePdfContent())->toThrow(ValueError::class)
        ->and(fn () => $this->fake->assertViewIs(InvoiceTemplate::Classic->view()))->toThrow(AssertionFailedError::class);
});
