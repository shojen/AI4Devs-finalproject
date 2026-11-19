<?php

// Story 0102 FD-4..FD-11 -- what the two templates print (T-1, T-5, T-6, T-8, T-10, T-11, T-12 rendered, T-13, T-29).

use App\Enums\InvoiceCorrectionReason;
use App\Enums\InvoiceTemplate;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\PdfFixtures;

beforeEach(function () {
    FakesPdf::fake();
    Storage::fake('public');
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

// T-1
test('every mandatory field is printed by each template (T-1)', function (InvoiceTemplate $template) {
    $invoice = PdfFixtures::saved(['template' => $template->value]);

    $html = PdfFixtures::html($invoice);
    $text = PdfFixtures::plain($html);

    expect($html)
        ->toContain('F2026-00042')
        ->toContain('Arospe Handmade Sociedad Limitada')->toContain('ESB12345678')->toContain('Calle Mayor 1')
        ->toContain('Lucia Perez')->toContain('B-12345678')->toContain('Calle Falsa 123')
        ->toContain('ORD-2026-0007')
        ->toContain('Ceramic mug')->toContain('MUG-1')->toContain('Large vase')->toContain('VASE-1')
        ->and(PdfFixtures::printsDate($html, CarbonImmutable::parse('2026-03-15')))->toBeTrue()
        ->and($text)->toContain('€200.00')->toContain('€42.00')->toContain('€12.50')->toContain('€254.50')->toContain('€121.00')
        ->and($text)->toMatch('/21([.,]0+)?\s*%/');
})->with(InvoiceTemplate::cases());

test('both templates print the same mandatory content from the same data (T-1)', function () {
    $classic = PdfFixtures::saved(['template' => 'classic']);
    $modern = PdfFixtures::saved(['template' => 'modern', 'number' => 'F2026-00044']);
    $mandatory = ['Arospe Handmade Sociedad Limitada', 'ESB12345678', 'Lucia Perez', 'B-12345678', 'ORD-2026-0007', 'Ceramic mug', 'Large vase', '€200.00', '€42.00', '€12.50', '€254.50'];

    foreach ($mandatory as $field) {
        expect(PdfFixtures::plain(PdfFixtures::html($classic)))->toContain($field);
        expect(PdfFixtures::plain(PdfFixtures::html($modern)))->toContain($field);
    }
});

// T-5
test('a corrective is titled as such and names the corrected invoice, its date, the reason and its own negative totals (T-5)', function (InvoiceTemplate $template, InvoiceCorrectionReason $reason) {
    $original = PdfFixtures::saved(['template' => $template->value, 'number' => 'F2026-00007', 'issued_at' => '2026-02-01 08:00:00']);
    $corrective = PdfFixtures::savedCorrective($original, ['reason' => $reason]);

    $html = PdfFixtures::html($corrective);

    expect($html)
        ->toContain(PdfFixtures::label('corrective_title', 'en'))
        ->toContain('R2026-00003')->toContain('F2026-00007')
        ->toContain(PdfFixtures::label('reason_'.$reason->value, 'en'))
        ->and(PdfFixtures::printsDate($html, CarbonImmutable::parse('2026-02-01')))->toBeTrue()
        ->and(PdfFixtures::plain($html))->toContain('-€254.50')->toContain('-€200.00');
})->with(InvoiceTemplate::cases())->with(InvoiceCorrectionReason::cases());

test('a standard invoice shows nothing of the corrective layout (T-5)', function (InvoiceTemplate $template) {
    $html = PdfFixtures::html(PdfFixtures::saved(['template' => $template->value]));

    expect($html)
        ->not->toContain(PdfFixtures::label('corrective_title', 'en'))
        ->not->toContain(PdfFixtures::label('reason_refund', 'en'))
        ->not->toContain(PdfFixtures::label('reason_order_edited', 'en'))
        ->not->toContain(PdfFixtures::label('reason_order_cancelled', 'en'));
})->with(InvoiceTemplate::cases());

// T-6
test('the COPY mark is printed in both templates only when the flag is set (T-6)', function (InvoiceTemplate $template) {
    $invoice = PdfFixtures::saved(['template' => $template->value]);
    $copy = PdfFixtures::label('copy', 'en');

    expect(PdfFixtures::html($invoice, true))->toContain($copy)
        ->and(PdfFixtures::html($invoice, false))->not->toContain($copy);
})->with(InvoiceTemplate::cases());

test('the COPY mark is translated into the invoice locale (T-6)', function () {
    $invoice = PdfFixtures::saved();

    expect(PdfFixtures::label('copy', 'es'))->not->toBe(PdfFixtures::label('copy', 'en'))
        ->and(PdfFixtures::html($invoice, true, 'es'))->toContain(PdfFixtures::label('copy', 'es'));
});

// T-8
test('labels and money follow the invoice locale (T-8)', function (string $locale, string $total, string $subtotal) {
    $invoice = PdfFixtures::saved(['subtotal' => '1234.56', 'total' => '1494.00', 'tax_amount' => '259.44', 'shipping_amount' => '0.00']);

    $html = PdfFixtures::html($invoice, false, $locale);

    expect($html)->toContain(PdfFixtures::label('title', $locale))->toContain(PdfFixtures::label('total', $locale))
        ->and(PdfFixtures::plain($html))->toContain($subtotal)->toContain($total);
})->with([
    'es' => ['es', '1.494,00 €', '1.234,56 €'],
    'en' => ['en', '€1,494.00', '€1,234.56'],
]);

test('Spanish and English labels really differ, so the locale test cannot pass by accident (T-8)', function (string $key) {
    expect(PdfFixtures::label($key, 'es'))->not->toBe(PdfFixtures::label($key, 'en'));
})->with(['title', 'total', 'corrective_title', 'reason_refund']);

// T-18 (rendering side)
test('rendering in another locale leaves the ambient locale unchanged (T-18)', function () {
    app()->setLocale('en');

    PdfFixtures::html(PdfFixtures::saved(), false, 'es');

    expect(app()->getLocale())->toBe('en');
});

// T-10
test('a present logo prints as an image with a base64 data URI, an absent one prints none (T-10)', function (InvoiceTemplate $template) {
    $media = Media::factory()->withRealFiles()->create();
    $withLogo = PdfFixtures::saved(['template' => $template->value, 'logo_media_id' => $media->id]);
    $without = PdfFixtures::saved(['template' => $template->value, 'number' => 'F2026-00043']);

    expect(PdfFixtures::html($withLogo))->toMatch('/<img[^>]+src="data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+"/')
        ->and(PdfFixtures::html($without))->not->toContain('<img');
})->with(InvoiceTemplate::cases());

// T-29
test('a logo file removed from storage renders without a logo and with intact amounts (T-29)', function () {
    $media = Media::factory()->withRealFiles()->create();
    $invoice = PdfFixtures::saved(['template' => 'modern', 'logo_media_id' => $media->id]);
    Storage::disk('public')->delete($media->path);

    $html = PdfFixtures::html($invoice);

    expect($html)->not->toContain('<img')
        ->and(PdfFixtures::plain($html))->toContain('€254.50');
});

// T-11
test('hostile snapshot text is escaped, never executed, in every text column of both templates (T-11)', function (string $hostile, InvoiceTemplate $template) {
    $columns = [
        'number', 'order_number',
        'issuer_legal_name', 'issuer_tax_id', 'issuer_address_line1', 'issuer_address_line2', 'issuer_city', 'issuer_postal_code', 'issuer_province', 'issuer_country',
        'recipient_name', 'recipient_tax_id', 'recipient_address_line1', 'recipient_address_line2', 'recipient_city', 'recipient_postal_code', 'recipient_province', 'recipient_country',
    ];
    $values = [];

    foreach ($columns as $column) {
        $values[$column] = $hostile.'|'.$column;
    }

    $lines = [['position' => 1, 'description' => $hostile.'|description', 'sku' => $hostile.'|sku', 'quantity' => 1, 'unit_price' => '1.00', 'line_subtotal' => '1.00', 'tax_amount' => '0.21', 'line_total' => '1.21']];
    $html = PdfFixtures::html(PdfFixtures::unsaved($values + ['template' => $template->value], $lines));

    foreach ([...array_keys($values), 'description'] as $column) {
        $raw = $hostile.'|'.$column;

        if (e($raw) !== $raw) {
            expect($html)->not->toContain($raw);
        }
    }

    foreach (['number', 'issuer_legal_name', 'recipient_name', 'recipient_address_line1', 'recipient_city', 'order_number', 'description'] as $column) {
        expect($html)->toContain(e($hostile.'|'.$column));
    }

    expect($html)->not->toContain('49|');
})->with([
    'script tag' => ['<script>alert(1)</script>'],
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'style break out' => ['</style><style>body{display:none}</style>'],
    'blade expression' => ['{{ 7*7 }}'],
    'blade php block' => ["@php file_put_contents('/tmp/pwned', '1'); @endphp"],
    'css url' => ['url(http://x)'],
    'attribute break out' => ['"><svg/onload=alert(1)>'],
    'ten thousand characters' => [str_repeat('A', 10_000)],
    'emoji' => ['Cafe ☕ 🎉 ñ'],
    'right to left' => ['مرحبا بالعالم'],
])->with(InvoiceTemplate::cases());

// T-12 (rendered side)
test('the rendered document starts its head with the static Content-Security-Policy meta (T-12)', function (InvoiceTemplate $template) {
    $html = PdfFixtures::html(PdfFixtures::unsaved(['template' => $template->value]));

    expect($html)->toMatch('/<head[^>]*>\s*<meta\s+http-equiv="Content-Security-Policy"\s+content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'"\s*\/?>/i')
        ->and($html)->not->toContain('<script')->not->toContain('<link')->not->toContain('@import');
})->with(InvoiceTemplate::cases());

// T-13
test('valid colours are emitted as CSS custom properties (T-13)', function (?string $primary, ?string $accent) {
    $html = PdfFixtures::html(PdfFixtures::unsaved(['primary_color' => $primary, 'accent_color' => $accent]));

    foreach (array_filter([$primary, $accent]) as $colour) {
        expect($html)->toMatch('/--[a-z-]+\s*:\s*'.preg_quote($colour, '/').'\s*;/');
    }
})->with([
    'upper case' => ['#1A2B3C', '#ABCDEF'],
    'lower case' => ['#abcdef', '#123abc'],
    'one null' => ['#1A2B3C', null],
]);

test('a stored invalid colour falls back to the default and never appears in the output (T-13)', function (string $bad, InvoiceTemplate $template) {
    $default = PdfFixtures::html(PdfFixtures::unsaved(['template' => $template->value, 'primary_color' => null, 'accent_color' => null]));

    $withBad = PdfFixtures::html(PdfFixtures::unsaved(['template' => $template->value, 'primary_color' => $bad, 'accent_color' => $bad]));

    expect($withBad)->toBe($default)->not->toContain($bad);
})->with([
    'css injection' => ['red;} body{display:none'],
    'url break out' => ['#fff) url(x'],
    'too short' => ['#12345'],
    'javascript scheme' => ['javascript:alert(1)'],
])->with(InvoiceTemplate::cases());
