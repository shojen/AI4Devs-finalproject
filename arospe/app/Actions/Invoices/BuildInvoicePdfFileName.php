<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;

/**
 * The file name an invoice PDF travels under (stories 0102 and 0104): `invoice-<number>.pdf` with
 * every character outside letters, numerals, dot, underscore and hyphen replaced by a hyphen. The
 * download and the e-mail attachment share it so the two always agree.
 */
class BuildInvoicePdfFileName
{
    public function __invoke(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9._-]/u', '-', $invoice->number).'.pdf';
    }
}
