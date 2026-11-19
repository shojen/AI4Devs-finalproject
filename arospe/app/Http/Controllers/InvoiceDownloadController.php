<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\DownloadInvoice;
use App\Models\Invoice;
use Symfony\Component\HttpFoundation\Response;

class InvoiceDownloadController extends Controller
{
    public function __invoke(Invoice $invoice, DownloadInvoice $downloadInvoice): Response
    {
        return $downloadInvoice($invoice);
    }
}
