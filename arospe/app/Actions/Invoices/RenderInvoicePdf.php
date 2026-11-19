<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceTemplate;
use App\Models\Invoice;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Build the PDF of an invoice from its frozen snapshot (story 0102). Nothing is stored; the caller
 * decides what to do with the builder (download, attach). Chromium runs hardened (FD-10): no
 * JavaScript, a timeout and no host resolution.
 */
class RenderInvoicePdf
{
    public function __construct(
        private BuildInvoicePdfData $buildData,
        private ResolveInvoiceLocale $resolveLocale,
    ) {}

    public function __invoke(Invoice $invoice, bool $isCopy, bool $isSample = false): PdfBuilder
    {
        $template = InvoiceTemplate::from($invoice->template);
        $locale = ($this->resolveLocale)();
        $margin = (float) config('invoicing.pdf.margin_mm');
        $timeoutMilliseconds = (int) config('invoicing.pdf.timeout_seconds') * 1000;

        return Pdf::view($template->view(), ($this->buildData)($invoice, $isCopy, $locale, $isSample))
            ->format((string) config('invoicing.pdf.format'))
            ->margins($margin, $margin, $margin, $margin, 'mm')
            ->withBrowsershot(function (Browsershot $browsershot) use ($timeoutMilliseconds): void {
                $browsershot
                    ->disableJavascript()
                    ->timeout($timeoutMilliseconds)
                    ->addChromiumArguments(['host-resolver-rules' => 'MAP * ~NOTFOUND']);
            });
    }
}
