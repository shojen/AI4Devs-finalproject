<?php

// Story 0102 FD-2/FD-3/FD-14 -- the view data comes from the invoice snapshot only (T-2, T-3, T-4, T-10b, T-16, T-17).

use App\Enums\InvoiceTemplate;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StoreLanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Invoices\FakesPdf;
use Tests\Support\Invoices\Invoicing;
use Tests\Support\Invoices\PdfFixtures;

/**
 * The SQL of every statement run by the closure.
 *
 * @return list<string>
 */
function pdfQueriesOf(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

/**
 * @param  list<string>  $queries
 * @return list<string>
 */
function pdfQueriesOn(array $queries, string ...$tables): array
{
    $pattern = '/\b(from|join|into|update)\s+[`"]?('.implode('|', $tables).')[`"]?(\s|$)/i';

    return array_values(array_filter($queries, fn (string $sql): bool => preg_match($pattern, $sql) === 1));
}

beforeEach(function () {
    FakesPdf::fake();
    Storage::fake('public');
    $this->seed(RolePermissionSeeder::class);
    $this->seed(StoreLanguageSeeder::class);
});

// T-2
test('building the data never reads orders, customers or invoice settings, and a null logo reads no media (T-2)', function () {
    Invoicing::settings();
    $invoice = PdfFixtures::saved();

    $queries = pdfQueriesOf(fn () => PdfFixtures::data($invoice));

    expect(pdfQueriesOn($queries, 'orders', 'customers', 'invoice_settings', 'media'))->toBe([]);
});

test('with a logo the only media query is one primary-key lookup (T-2)', function () {
    Invoicing::settings();
    $media = Media::factory()->withRealFiles()->create();
    $invoice = PdfFixtures::saved(['logo_media_id' => $media->id]);

    $queries = pdfQueriesOf(fn () => PdfFixtures::data($invoice));
    $mediaQueries = pdfQueriesOn($queries, 'media');

    expect(pdfQueriesOn($queries, 'orders', 'customers', 'invoice_settings'))->toBe([])
        ->and($mediaQueries)->toHaveCount(1)
        ->and($mediaQueries[0])->toMatch('/where\s+[`"]?(media[`"]?\.[`"]?)?id[`"]?\s*=\s*\?/i');
});

test('the order number printed is the invoice own order_number, not the live order (T-2)', function () {
    $invoice = PdfFixtures::saved(['order_number' => 'ORD-SNAPSHOT-1']);
    $invoice->order->forceFill(['order_number' => 'ORD-LIVE-2'])->save();

    $html = PdfFixtures::html($invoice->fresh());

    expect($html)->toContain('ORD-SNAPSHOT-1')->not->toContain('ORD-LIVE-2');
});

// T-3
test('later changes to settings, customer or order never alter the data or the HTML of an issued invoice (T-3)', function (Closure $mutate) {
    $settings = Invoicing::settings();
    $invoice = PdfFixtures::saved(['template' => 'modern', 'primary_color' => '#112233', 'accent_color' => '#AABBCC']);
    $beforeHtml = PdfFixtures::html($invoice);
    $beforeData = json_encode(PdfFixtures::data($invoice));

    $mutate($invoice, $settings);

    $after = $invoice->fresh();

    expect(PdfFixtures::html($after))->toBe($beforeHtml)
        ->and(json_encode(PdfFixtures::data($after)))->toBe($beforeData);
})->with([
    'settings template and colours' => [fn ($invoice, $settings) => $settings->forceFill(['template' => 'classic', 'primary_color' => '#FF0000', 'accent_color' => '#00FF00'])->save()],
    'settings issuer identity' => [fn ($invoice, $settings) => $settings->forceFill(['issuer_legal_name' => 'Somebody Else SL', 'issuer_tax_id' => 'ESX0000000X'])->save()],
    'settings logo' => [fn ($invoice, $settings) => $settings->forceFill(['logo_media_id' => Media::factory()->create()->id])->save()],
    'customer renamed' => [fn ($invoice) => $invoice->order->customer->forceFill(['name' => 'Renamed Customer'])->save()],
    'customer tax id changed' => [fn ($invoice) => $invoice->order->customer->forceFill(['tax_id' => 'Z-99999999'])->save()],
    'order edited' => [fn ($invoice) => $invoice->order->forceFill(['order_number' => 'ORD-CHANGED', 'billing_address_line1' => 'Elsewhere 9', 'total' => '999.99'])->save()],
]);

test('the invoice own template is used whatever the settings template is (T-3)', function () {
    Invoicing::settings(['template' => 'modern']);
    $classic = PdfFixtures::saved(['template' => 'classic']);

    expect(PdfFixtures::html($classic))->toBe(view(InvoiceTemplate::Classic->view(), PdfFixtures::data($classic))->render())
        ->and(PdfFixtures::html($classic))->not->toBe(view(InvoiceTemplate::Modern->view(), PdfFixtures::data($classic))->render());
});

// T-4
test('the same invoice and flag rendered a day apart gives identical data and HTML (T-4)', function (bool $isCopy) {
    $invoice = PdfFixtures::saved();

    CarbonImmutable::setTestNow('2026-06-01 09:00:00');
    $firstHtml = PdfFixtures::html($invoice, $isCopy);
    $firstData = json_encode(PdfFixtures::data($invoice, $isCopy));

    CarbonImmutable::setTestNow('2026-06-02 17:45:00');
    $secondHtml = PdfFixtures::html($invoice, $isCopy);
    $secondData = json_encode(PdfFixtures::data($invoice, $isCopy));

    CarbonImmutable::setTestNow();

    expect($secondHtml)->toBe($firstHtml)->and($secondData)->toBe($firstData);
})->with([
    'original' => [false],
    'copy' => [true],
]);

// T-10b
test('an unsaved invoice with in-memory lines renders in both templates with no query and no write (T-10b)', function (InvoiceTemplate $template) {
    $invoice = PdfFixtures::unsaved(['template' => $template->value]);
    $html = '';

    $queries = pdfQueriesOf(function () use ($invoice, &$html): void {
        $html = PdfFixtures::html($invoice);
    });

    expect($queries)->toBe([])
        ->and($html)->toContain('F2026-00042')
        ->and($invoice->exists)->toBeFalse();
})->with(InvoiceTemplate::cases());

test('an unsaved invoice with a logo runs only the single media lookup (T-10b)', function () {
    $media = Media::factory()->withRealFiles()->create();
    $invoice = PdfFixtures::unsaved(['logo_media_id' => $media->id]);

    $queries = pdfQueriesOf(fn () => PdfFixtures::html($invoice));

    expect($queries)->toHaveCount(1)
        ->and(pdfQueriesOn($queries, 'media'))->toHaveCount(1);
});

test('the SAMPLE mark is printed only when asked, independently of the COPY mark (T-10b)', function (InvoiceTemplate $template) {
    $invoice = PdfFixtures::unsaved(['template' => $template->value]);
    $sample = PdfFixtures::label('sample', 'en');
    $copy = PdfFixtures::label('copy', 'en');

    expect(PdfFixtures::html($invoice, false, 'en', false))->not->toContain($sample)->not->toContain($copy)
        ->and(PdfFixtures::html($invoice, false, 'en', true))->toContain($sample)->not->toContain($copy)
        ->and(PdfFixtures::html($invoice, true, 'en', false))->toContain($copy)->not->toContain($sample)
        ->and(PdfFixtures::html($invoice, true, 'en', true))->toContain($sample)->toContain($copy);
})->with(InvoiceTemplate::cases());

// T-16
test('one line and two hundred lines print in position order with the stored totals (T-16)', function (int $count) {
    $positions = range(1, $count);
    shuffle($positions);
    $lines = array_map(fn (int $position): array => [
        'position' => $position,
        'description' => sprintf('Line item %04d', $position),
        'sku' => sprintf('SKU-%04d', $position),
        'quantity' => 1,
        'unit_price' => '1.00',
        'line_subtotal' => '1.00',
        'tax_amount' => '0.21',
        'line_total' => '1.21',
    ], $positions);
    $invoice = PdfFixtures::saved(['subtotal' => '1234.00', 'tax_amount' => '259.14', 'shipping_amount' => '0.00', 'total' => '1493.14'], $lines);

    $html = PdfFixtures::html($invoice);
    preg_match_all('/Line item (\d{4})/', $html, $matches);

    expect(array_map('intval', $matches[1]))->toBe(range(1, $count))
        ->and(PdfFixtures::plain($html))->toContain('€1,493.14')->toContain('€1,234.00');
})->with([
    'one line' => [1],
    'two hundred lines' => [200],
]);

test('a concept line prints in its position among the item lines (T-16)', function () {
    $lines = PdfFixtures::defaultLines();
    $lines[] = ['position' => 3, 'kind' => 'adjustment', 'description' => 'Payment on account of order ORD-2026-0007', 'sku' => null, 'quantity' => 1, 'unit_price' => '10.00', 'line_subtotal' => '10.00', 'tax_amount' => '2.10', 'line_total' => '12.10'];
    $invoice = PdfFixtures::saved([], $lines);

    $html = PdfFixtures::html($invoice);

    expect(strpos($html, 'Large vase'))->toBeLessThan(strpos($html, 'Payment on account of order ORD-2026-0007'));
});

// T-17
test('a corrective reads the corrected number and date from its frozen corrects row (T-17)', function () {
    $original = PdfFixtures::saved(['number' => 'F2026-00007', 'issued_at' => '2026-02-01 08:00:00']);
    $corrective = PdfFixtures::savedCorrective($original);

    $html = PdfFixtures::html($corrective);

    expect($html)->toContain('F2026-00007')->toContain('R2026-00003')
        ->and(PdfFixtures::printsDate($html, CarbonImmutable::parse('2026-02-01')))->toBeTrue();
});

test('a corrective without a reason prints no reason line (T-17)', function () {
    $original = PdfFixtures::saved(['number' => 'F2026-00007']);
    $corrective = PdfFixtures::savedCorrective($original, ['reason' => null]);

    $html = PdfFixtures::html($corrective);

    expect($html)->toContain('F2026-00007')
        ->not->toContain(PdfFixtures::label('reason_refund', 'en'))
        ->not->toContain(PdfFixtures::label('reason_order_edited', 'en'))
        ->not->toContain(PdfFixtures::label('reason_order_cancelled', 'en'));
});
