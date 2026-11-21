<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\PreviewInvoicePdf;
use App\Enums\InvoiceTemplate;
use Symfony\Component\HttpFoundation\Response;

class InvoiceSettingsPreviewController extends Controller
{
    public function __invoke(InvoiceTemplate $template, PreviewInvoicePdf $previewInvoicePdf): Response
    {
        $response = $previewInvoicePdf($template)
            ->inline('invoice-preview-'.$template->value.'.pdf')
            ->toResponse(request());

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
