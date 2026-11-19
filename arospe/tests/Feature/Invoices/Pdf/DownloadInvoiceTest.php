<?php

// Story 0102 FD-12 -- route invoices.download (T-7, T-19..T-27). The PDF engine is faked: the package fake
// answers a response with an empty body, so the builder it recorded is inspected instead.

use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    $this->fake = FakesPdf::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
    $this->viewer = PdfFixtures::userWith('invoices.view');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function pdfDownloadInvoice(array $overrides = []): Invoice
{
    return PdfFixtures::saved($overrides);
}

// T-7
test('a permitted user downloads the invoice as an attachment with the sanitised file name (T-7)', function () {
    $invoice = pdfDownloadInvoice(['template' => 'modern']);

    $this->actingAs($this->viewer)->get(route('invoices.download', $invoice))->assertOk();

    $pdf = FakesPdf::lastResponded($this->fake);
    $html = FakesPdf::respondedHtml($this->fake);

    expect($pdf->downloadName)->toBe('invoice-F2026-00042.pdf')
        ->and($pdf->isDownload())->toBeTrue()
        ->and($pdf->viewName)->toBe('invoices.pdf.modern')
        ->and($html)->toContain('F2026-00042')->toContain('Lucia Perez')->toContain('ORD-2026-0007')->toContain('B-12345678');
});

// T-19
test('the download file name keeps only safe characters and ends in .pdf (T-19)', function (string $number, string $expected) {
    $invoice = pdfDownloadInvoice(['number' => $number]);

    $this->actingAs($this->viewer)->get(route('invoices.download', $invoice))->assertOk();

    $name = FakesPdf::lastResponded($this->fake)->downloadName;

    expect($name)->toBe($expected)
        ->and($name)->toMatch('/^[A-Za-z0-9._-]+\.pdf$/')
        ->and($name)->not->toContain('/')->not->toContain('"')->not->toContain("\r")->not->toContain("\n");
})->with([
    'plain' => ['F2026-00001', 'invoice-F2026-00001.pdf'],
    'slashes' => ['F/2026/0001', 'invoice-F-2026-0001.pdf'],
    'dots and slash' => ['INV.2026/7', 'invoice-INV.2026-7.pdf'],
    'space' => ['a b', 'invoice-a-b.pdf'],
    'backslash' => ['..\\x', 'invoice-..-x.pdf'],
    'quotes' => ['"quote"', 'invoice--quote-.pdf'],
    'non ascii' => ['é/ü', 'invoice----.pdf'],
    'header injection' => ["F\r\nX-Injected: 1", 'invoice-F--X-Injected--1.pdf'],
]);

// T-20
test('a download of an invoice never sent carries no COPY mark (T-20)', function () {
    $invoice = pdfDownloadInvoice();

    expect($invoice->hasBeenSent())->toBeFalse();

    $this->actingAs($this->viewer)->get(route('invoices.download', $invoice))->assertOk();

    expect(FakesPdf::respondedHtml($this->fake))->not->toContain(PdfFixtures::label('copy', 'es'));
});

// T-21
test('the 31st download by one user within a minute is throttled while another user is unaffected (T-21)', function () {
    $invoice = pdfDownloadInvoice();
    $other = PdfFixtures::userWith('invoices.view');
    $url = route('invoices.download', $invoice);

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($this->viewer)->get($url)->assertOk();
    }

    $this->actingAs($this->viewer)->get($url)->assertStatus(429);
    $this->actingAs($other)->get($url)->assertOk();
});

// T-22
test('a voided invoice, a cancelled order and a soft-deleted customer still download with the same content (T-22)', function (Closure $change) {
    $invoice = pdfDownloadInvoice();
    $url = route('invoices.download', $invoice);

    $this->actingAs($this->viewer)->get($url)->assertOk();
    $before = FakesPdf::respondedHtml($this->fake);

    $change($invoice);

    $this->actingAs($this->viewer)->get($url)->assertOk();

    expect(FakesPdf::respondedHtml($this->fake))->toBe($before);
})->with([
    'voided invoice' => [fn (Invoice $invoice) => $invoice->forceFill(['voided_at' => now()])->save()],
    'cancelled order' => [fn (Invoice $invoice) => $invoice->order->forceFill(['status' => 'cancelled'])->save()],
    'soft-deleted customer' => [fn (Invoice $invoice) => $invoice->order->customer->delete()],
]);

// T-23
test('a user without invoices.view is refused, no PDF is built and the refusal is logged (T-23)', function (array $permissions) {
    Log::spy();
    $invoice = pdfDownloadInvoice();
    $actor = PdfFixtures::userWith(...$permissions);

    $this->actingAs($actor)->get(route('invoices.download', $invoice))->assertForbidden();

    expect($this->fake->viewName)->toBe('')
        ->and(FakesPdf::respondedCount($this->fake))->toBe(0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'view'
            && ($context['target_type'] ?? null) === 'invoice'
            && ($context['target_id'] ?? null) === $invoice->id)
        ->once();
})->with([
    'no permission' => [[]],
    'invoices.create only' => [['invoices.create']],
    'invoice-settings.manage only' => [['invoice-settings.manage']],
]);

// T-24
test('a guest is redirected to login without a privileged-refusal log (T-24)', function () {
    Log::spy();
    $invoice = pdfDownloadInvoice();

    $this->get(route('invoices.download', $invoice))->assertRedirect(route('login'));

    Log::shouldNotHaveReceived('warning');
    expect($this->fake->viewName)->toBe('');
});

test('the download route is guarded by the auth, verified and throttle middleware (T-24)', function () {
    $middleware = app('router')->getRoutes()->getByName('invoices.download')->gatherMiddleware();

    expect($middleware)->toContain('auth', 'verified', 'throttle:invoice-downloads')
        ->and(collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'can:')))->toBeFalse();
});

// T-25
test('an unknown or malformed invoice id is a 404 for a permitted user (T-25)', function (string $id) {
    $this->actingAs($this->viewer)->get(route('invoices.download', ['invoice' => $id]))->assertNotFound();
})->with([
    'unknown uuid' => ['00000000-0000-7000-8000-000000000000'],
    'malformed' => ['not-an-invoice'],
    'numeric' => ['12345'],
]);

// T-26
test('a Super Admin downloads any invoice (T-26)', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $this->actingAs($superAdmin)->get(route('invoices.download', pdfDownloadInvoice()))->assertOk();

    expect(FakesPdf::respondedCount($this->fake))->toBe(1);
});

test('only GET is routed: POST and PUT are 405 (T-26)', function (string $method) {
    $invoice = pdfDownloadInvoice();

    $this->actingAs($this->viewer)->call($method, route('invoices.download', $invoice))->assertStatus(405);
})->with(['POST', 'PUT']);

// T-27
test('a refused download reads no order, customer or invoice settings row (T-27)', function () {
    Invoicing::settings();
    $invoice = pdfDownloadInvoice();
    $actor = PdfFixtures::userWith();
    $touched = [];
    DB::listen(function ($query) use (&$touched): void {
        if (preg_match('/\b(from|join)\s+[`"]?(orders|customers|invoice_settings|invoice_lines|media)[`"]?(\s|$)/i', $query->sql) === 1) {
            $touched[] = $query->sql;
        }
    });

    $this->actingAs($actor)->get(route('invoices.download', $invoice))->assertForbidden();

    expect($touched)->toBe([]);
});
