<?php

namespace Tests\Support\Invoices;

use App\Actions\Invoices\BuildInvoicePdfData;
use App\Enums\InvoiceTemplate;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Story 0102 -- fixtures of the invoice PDF tests: recognisable saved or unsaved invoices, the
 * HTML a template renders for one, and the small normalisations the assertions need.
 *
 * Assumed contract (to reconcile with the implementation): the view data carries `locale`,
 * `isCopy` and `isSample`; lang keys live under `invoices.pdf.` (see KEYS).
 */
final class PdfFixtures
{
    /** The `invoices.pdf.*` translation keys the tests rely on. */
    public const KEYS = [
        'title' => 'invoices.pdf.title',
        'total' => 'invoices.pdf.total',
        'corrective_title' => 'invoices.pdf.corrective_title',
        'copy' => 'invoices.pdf.copy',
        'sample' => 'invoices.pdf.sample',
        'reason_refund' => 'invoices.pdf.reasons.refund',
        'reason_order_edited' => 'invoices.pdf.reasons.order_edited',
        'reason_order_cancelled' => 'invoices.pdf.reasons.order_cancelled',
    ];

    /**
     * Invoice columns for a recognisable standard invoice (all amounts are strings).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function attributes(array $overrides = []): array
    {
        return array_replace([
            'number' => 'F2026-00042',
            'order_number' => 'ORD-2026-0007',
            'issued_at' => '2026-03-15 10:30:00',
            'template' => 'classic',
            'tax_rate' => '21.000',
            'subtotal' => '200.00',
            'shipping_amount' => '12.50',
            'tax_amount' => '42.00',
            'total' => '254.50',
            'issuer_legal_name' => 'Arospe Handmade Sociedad Limitada',
            'issuer_tax_id' => 'ESB12345678',
            'issuer_address_line1' => 'Calle Mayor 1',
            'issuer_city' => 'Madrid',
            'issuer_postal_code' => '28001',
            'recipient_name' => 'Lucia Perez',
            'recipient_tax_id' => 'B-12345678',
            'recipient_address_line1' => 'Calle Falsa 123',
            'recipient_city' => 'Sevilla',
            'recipient_postal_code' => '41001',
        ], $overrides);
    }

    /**
     * Two item lines whose stored amounts add up to the default invoice totals.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultLines(): array
    {
        return [
            ['position' => 1, 'description' => 'Ceramic mug', 'sku' => 'MUG-1', 'quantity' => 2, 'unit_price' => '50.00', 'line_subtotal' => '100.00', 'tax_amount' => '21.00', 'line_total' => '121.00'],
            ['position' => 2, 'description' => 'Large vase', 'sku' => 'VASE-1', 'quantity' => 1, 'unit_price' => '100.00', 'line_subtotal' => '100.00', 'tax_amount' => '21.00', 'line_total' => '121.00'],
        ];
    }

    /**
     * A saved standard invoice on a real order, with its lines.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<array<string, mixed>>|null  $lines
     */
    public static function saved(array $overrides = [], ?array $lines = null): Invoice
    {
        $order = Invoicing::order();
        $invoice = Invoice::factory()->forOrder($order)->create(self::attributes($overrides));

        foreach ($lines ?? self::defaultLines() as $line) {
            InvoiceLine::factory()->create(['invoice_id' => $invoice->id] + $line);
        }

        return $invoice->fresh();
    }

    /**
     * A saved corrective of the invoice (default: refund, negated amounts, one negative line).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function savedCorrective(Invoice $of, array $overrides = []): Invoice
    {
        $corrective = Invoice::factory()->corrective($of)->create(array_replace([
            'number' => 'R2026-00003',
            'issued_at' => '2026-04-02 09:00:00',
            'template' => $of->template,
        ], $overrides));

        InvoiceLine::factory()->create([
            'invoice_id' => $corrective->id,
            'position' => 1,
            'description' => 'Ceramic mug',
            'sku' => 'MUG-1',
            'quantity' => -2,
            'unit_price' => '50.00',
            'line_subtotal' => '-100.00',
            'tax_amount' => '-21.00',
            'line_total' => '-121.00',
        ]);

        return $corrective->fresh();
    }

    /**
     * An unsaved invoice whose lines relation is set in memory (FD-14): no query, no write.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<array<string, mixed>>|null  $lines
     */
    public static function unsaved(array $overrides = [], ?array $lines = null): Invoice
    {
        $invoice = Invoice::factory()->make(self::attributes([
            'order_id' => (string) Str::uuid(),
        ] + $overrides));
        $invoice->id = (string) Str::uuid();

        $invoice->setRelation('lines', collect(array_map(
            fn (array $line): InvoiceLine => InvoiceLine::factory()->make(['invoice_id' => $invoice->id] + $line),
            $lines ?? self::defaultLines(),
        )));

        return $invoice;
    }

    /**
     * The view data of the invoice.
     *
     * @return array<string, mixed>
     */
    public static function data(Invoice $invoice, bool $isCopy = false, string $locale = 'en', bool $isSample = false): array
    {
        return app(BuildInvoicePdfData::class)($invoice, $isCopy, $locale, $isSample);
    }

    /**
     * The HTML the invoice's own template renders for it.
     */
    public static function html(Invoice $invoice, bool $isCopy = false, string $locale = 'en', bool $isSample = false): string
    {
        return view(InvoiceTemplate::from($invoice->template)->view(), self::data($invoice, $isCopy, $locale, $isSample))->render();
    }

    /**
     * Replace the no-break spaces ICU puts inside money strings by plain spaces.
     */
    public static function plain(string $text): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
    }

    /**
     * Whether the HTML prints the date in any of the usual numeric or spelled-out forms.
     */
    public static function printsDate(string $html, CarbonInterface $date, string $locale = 'en'): bool
    {
        $date = $date->copy()->locale($locale);
        $html = self::plain($html);

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'm/d/Y', 'j/n/Y'] as $format) {
            if (str_contains($html, $date->format($format))) {
                return true;
            }
        }

        foreach (['LL', 'D MMMM YYYY', 'D [de] MMMM [de] YYYY', 'MMMM D, YYYY'] as $format) {
            if (str_contains($html, e($date->isoFormat($format)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The translation of a key in a locale, HTML-escaped as a template prints it.
     */
    public static function label(string $key, string $locale): string
    {
        return e(trans(self::KEYS[$key] ?? $key, [], $locale));
    }

    /**
     * A verified user holding exactly the given permissions.
     */
    public static function userWith(string ...$permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
