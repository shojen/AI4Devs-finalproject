<?php

// Story 0103 -- PreviewInvoicePdf and the invoice-settings.preview route (T-26, T-27). The PDF engine is
// faked: the builder the package fake recorded is inspected instead of a real PDF.

use App\Actions\Invoices\PeekNextInvoiceNumber;
use App\Actions\Invoices\PreviewInvoicePdf;
use App\Enums\InvoiceTemplate;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\InvoiceSettings\ScreenFixtures;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    $this->fake = FakesPdf::fake();
    ScreenFixtures::boot();
    $this->seed(StoreLanguageSeeder::class);
});

// T-26
test('the classic preview is a 200 inline PDF of the saved settings, marked as a sample, with the peeked number', function () {
    Invoicing::settings(['template' => 'modern', 'primary_color' => '#112233', 'accent_color' => '#AABBCC']);
    Numbering::arrange(InvoiceType::Standard, 41);
    ScreenFixtures::viewer();
    $expectedNumber = app(PeekNextInvoiceNumber::class)(InvoiceType::Standard);

    $response = $this->get(route('invoice-settings.preview', ['template' => 'classic']))->assertOk();

    $pdf = FakesPdf::lastResponded($this->fake);
    $data = $pdf->viewData;

    expect($pdf->viewName)->toBe(InvoiceTemplate::Classic->view())
        ->and($pdf->downloadName)->toBe('invoice-preview-classic.pdf')
        ->and($data['isSample'])->toBeTrue()
        ->and($data['isCopy'])->toBeFalse()
        ->and($data['number'])->toBe($expectedNumber)->toBe('F2026-00042')
        ->and($data['issuer']['name'])->toBe('Arospe Handmade Sociedad Limitada')
        ->and($data['issuer']['taxId'])->toBe('ESB12345678')
        ->and($data['primaryColor'])->toBe('#112233')
        ->and($data['accentColor'])->toBe('#AABBCC')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('both templates have a preview that renders its own view', function (InvoiceTemplate $template) {
    ScreenFixtures::viewer();

    $this->get(route('invoice-settings.preview', ['template' => $template->value]))->assertOk();

    expect(FakesPdf::lastResponded($this->fake)->viewName)->toBe($template->view())
        ->and(FakesPdf::lastResponded($this->fake)->downloadName)->toBe('invoice-preview-'.$template->value.'.pdf');
})->with(InvoiceTemplate::cases());

test('the sample strings use the invoice locale, never the admin UI locale', function () {
    ScreenFixtures::viewer();
    app()->setLocale('en');

    $this->get(route('invoice-settings.preview', ['template' => 'classic']))->assertOk();

    $data = FakesPdf::lastResponded($this->fake)->viewData;

    expect($data['locale'])->toBe('es')
        ->and($data['recipient']['name'])->toBe(trans('invoices.pdf.sample_customer', [], 'es'))
        ->and($data['recipient']['name'])->not->toBe(trans('invoices.pdf.sample_customer', [], 'en'))
        ->and($data['lines'])->toHaveCount(2)
        ->and(collect($data['lines'])->pluck('description')->unique()->all())->toBe([trans('invoices.pdf.sample_item', [], 'es')]);
});

test('the preview writes no row in any table and consumes no number', function () {
    Invoicing::settings();
    ScreenFixtures::viewer();
    $sequences = Numbering::snapshot();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->get(route('invoice-settings.preview', ['template' => 'modern']))->assertOk();

    expect($writes)->toBe([])
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Numbering::snapshot())->toBe($sequences);
});

test('the action works with no settings row and returns the sample builder for the given template', function () {
    ScreenFixtures::viewer();

    $builder = app(PreviewInvoicePdf::class)(InvoiceTemplate::Modern);
    $builder->generatePdfContent();

    expect($builder->viewName)->toBe(InvoiceTemplate::Modern->view())
        ->and($builder->viewData['isSample'])->toBeTrue()
        ->and(InvoiceSetting::query()->count())->toBe(0);
});

// T-27
test('a user without invoice-settings.view is forbidden, logged once and nothing is rendered', function () {
    Log::spy();
    $actor = ScreenFixtures::actingWith('invoice-settings.manage');

    $this->get(route('invoice-settings.preview', ['template' => 'classic']))->assertForbidden();

    expect(FakesPdf::respondedCount($this->fake))->toBe(0)
        ->and($this->fake->viewName)->toBe('');
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === 'viewAny')
        ->once();
});

test('the action itself refuses an actor without view', function () {
    ScreenFixtures::actingWith();

    expect(fn () => app(PreviewInvoicePdf::class)(InvoiceTemplate::Classic))->toThrow(AuthorizationException::class);
});

test('an unknown template is a 404 for a permitted user', function () {
    ScreenFixtures::viewer();

    $this->get(route('invoice-settings.preview', ['template' => 'classic']))->assertOk();
    $this->get('/invoice-settings/preview/bogus')->assertNotFound();

    expect(FakesPdf::respondedCount($this->fake))->toBe(1);
});

test('a guest is redirected to login without a refusal log', function () {
    Log::spy();

    $this->get(route('invoice-settings.preview', ['template' => 'classic']))->assertRedirect(route('login'));

    Log::shouldNotHaveReceived('warning');
});

test('the preview route is guarded by auth, verified and the shared invoice-downloads throttle, without a can: gate', function () {
    $middleware = app('router')->getRoutes()->getByName('invoice-settings.preview')->gatherMiddleware();

    expect($middleware)->toContain('auth', 'verified', 'throttle:invoice-downloads')
        ->and(collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'can:')))->toBeFalse();
});

test('the 31st request in a minute is throttled, and it shares the bucket with invoice downloads', function () {
    ScreenFixtures::viewer();
    $url = route('invoice-settings.preview', ['template' => 'classic']);

    foreach (range(1, 30) as $attempt) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});
