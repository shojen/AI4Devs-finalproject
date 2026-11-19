<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * The view data of an invoice PDF (story 0102 FD-2, FD-14): built from the invoice, its lines and
 * its corrected invoice only, never from orders, customers or settings. Works on an unsaved
 * invoice whose `lines` relation is set in memory. Amounts are pre-formatted strings.
 */
class BuildInvoicePdfData
{
    private const DEFAULT_PRIMARY_COLOR = '#1F2937';

    private const DEFAULT_ACCENT_COLOR = '#6B7280';

    public function __construct(
        private FormatInvoiceMoney $formatMoney,
        private LoadInvoiceLogo $loadLogo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Invoice $invoice, bool $isCopy, string $locale, bool $isSample = false): array
    {
        $money = fn (string $amount): string => ($this->formatMoney)($amount, $locale);
        $isCorrective = $invoice->type === InvoiceType::Corrective;
        $corrected = $isCorrective && $invoice->corrects_invoice_id !== null ? $invoice->corrects : null;

        return [
            'locale' => $locale,
            'isCopy' => $isCopy,
            'isSample' => $isSample,
            'isCorrective' => $isCorrective,
            'number' => $invoice->number,
            'orderNumber' => $invoice->order_number,
            'issuedOn' => $this->date($invoice->issued_at, $locale),
            'correctedNumber' => $corrected?->number,
            'correctedIssuedOn' => $corrected === null ? null : $this->date($corrected->issued_at, $locale),
            'reasonKey' => $isCorrective ? $invoice->reason?->value : null,
            'issuer' => [
                'name' => $invoice->issuer_legal_name,
                'taxId' => $invoice->issuer_tax_id,
                'addressLines' => $this->addressLines($invoice, 'issuer'),
            ],
            'recipient' => [
                'name' => $invoice->recipient_name,
                'taxId' => $invoice->recipient_tax_id,
                'addressLines' => $this->addressLines($invoice, 'recipient'),
            ],
            'lines' => $invoice->lines
                ->sortBy('position')
                ->values()
                ->map(fn (InvoiceLine $line): array => [
                    'description' => $line->description,
                    'sku' => $line->sku,
                    'quantity' => (string) $line->quantity,
                    'unitPrice' => $money($line->unit_price),
                    'lineSubtotal' => $money($line->line_subtotal),
                    'taxAmount' => $money($line->tax_amount),
                    'lineTotal' => $money($line->line_total),
                ])
                ->all(),
            'taxRate' => $this->taxRate($invoice->tax_rate, $locale),
            'subtotal' => $money($invoice->subtotal),
            'shipping' => $money($invoice->shipping_amount),
            'tax' => $money($invoice->tax_amount),
            'total' => $money($invoice->total),
            'logo' => ($this->loadLogo)($invoice->logo_media_id),
            'primaryColor' => $this->color($invoice->primary_color, self::DEFAULT_PRIMARY_COLOR),
            'accentColor' => $this->color($invoice->accent_color, self::DEFAULT_ACCENT_COLOR),
        ];
    }

    private function date(mixed $moment, string $locale): string
    {
        return $moment->format($locale === 'es' ? 'd/m/Y' : 'Y-m-d');
    }

    private function color(?string $value, string $default): string
    {
        return $value !== null && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1 ? $value : $default;
    }

    private function taxRate(?string $rate, string $locale): ?string
    {
        if ($rate === null) {
            return null;
        }

        $trimmed = str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
        $trimmed = $trimmed === '' ? '0' : $trimmed;

        return $locale === 'es' ? str_replace('.', ',', $trimmed) : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function addressLines(Invoice $invoice, string $party): array
    {
        $cityLine = trim(($invoice->{$party.'_postal_code'} ?? '').' '.($invoice->{$party.'_city'} ?? ''));

        return array_values(array_filter([
            $invoice->{$party.'_address_line1'},
            $invoice->{$party.'_address_line2'},
            $cityLine,
            $invoice->{$party.'_province'},
            $invoice->{$party.'_country'},
        ], fn (?string $line): bool => $line !== null && $line !== ''));
    }
}
