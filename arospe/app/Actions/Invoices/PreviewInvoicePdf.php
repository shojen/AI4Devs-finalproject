<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\InvoiceTemplate;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceSetting;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * A sample PDF of the saved invoice settings in a chosen template (story 0103). The invoice is built
 * in memory and never saved: no row is written and no number is consumed (the number is only peeked).
 * Sample strings use the invoice locale, never the admin's UI locale (0102 FD-8).
 */
class PreviewInvoicePdf
{
    public function __construct(
        private LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private PeekNextInvoiceNumber $peekNextInvoiceNumber,
        private ResolveInvoiceLocale $resolveInvoiceLocale,
        private RenderInvoicePdf $renderInvoicePdf,
    ) {}

    public function __invoke(InvoiceTemplate $template): PdfBuilder
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'viewAny',
            InvoiceSetting::class,
            targetType: 'invoice_setting',
            targetId: InvoiceSetting::SINGLETON_ID,
        );

        $settings = InvoiceSetting::current();
        $locale = ($this->resolveInvoiceLocale)();
        $sampleItem = __('invoices.pdf.sample_item', [], $locale);

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Standard,
            'number' => ($this->peekNextInvoiceNumber)(InvoiceType::Standard),
            'issued_at' => now(),
            'template' => $template->value,
            'primary_color' => $settings->primary_color,
            'accent_color' => $settings->accent_color,
            'logo_media_id' => $settings->logo_media_id,
            'issuer_legal_name' => (string) $settings->issuer_legal_name,
            'issuer_tax_id' => (string) $settings->issuer_tax_id,
            'issuer_address_line1' => $settings->issuer_address_line1,
            'issuer_address_line2' => $settings->issuer_address_line2,
            'issuer_city' => $settings->issuer_city,
            'issuer_postal_code' => $settings->issuer_postal_code,
            'issuer_province' => $settings->issuer_province,
            'issuer_country' => $settings->issuer_country,
            'recipient_name' => __('invoices.pdf.sample_customer', [], $locale),
            'tax_rate' => '21.000',
            'subtotal' => '20.00',
            'shipping_amount' => '0.00',
            'tax_amount' => '4.20',
            'total' => '24.20',
        ]);

        $invoice->setRelation('lines', $invoice->newCollection([
            $this->line(1, $sampleItem, 1, '10.00'),
            $this->line(2, $sampleItem, 2, '5.00'),
        ]));

        return ($this->renderInvoicePdf)($invoice, false, isSample: true);
    }

    private function line(int $position, string $description, int $quantity, string $unitPrice): InvoiceLine
    {
        return (new InvoiceLine)->forceFill([
            'position' => $position,
            'description' => $description,
            'sku' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_subtotal' => '10.00',
            'tax_amount' => '2.10',
            'line_total' => '12.10',
        ]);
    }
}
