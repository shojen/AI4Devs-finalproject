<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\Invoice;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download an issued invoice as a PDF attachment (story 0102 FD-12). Authorizes `view` through the
 * refusal-logging helper (the route carries no `can:`, which would not be logged), then renders
 * from the snapshot; the COPY mark follows `hasBeenSent()`.
 */
class DownloadInvoice
{
    public function __construct(
        private LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
        private RenderInvoicePdf $renderInvoicePdf,
        private BuildInvoicePdfFileName $buildInvoicePdfFileName,
    ) {}

    public function __invoke(Invoice $invoice): Response
    {
        $this->logRefusedPrivilegedAttempt->authorize('view', $invoice, targetType: 'invoice', targetId: $invoice->id);

        $name = ($this->buildInvoicePdfFileName)($invoice);

        $response = ($this->renderInvoicePdf)($invoice, $invoice->hasBeenSent())
            ->download($name)
            ->toResponse(request());

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
