<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceType;
use App\Models\InvoiceSetting;
use Carbon\CarbonInterface;

/**
 * Compose an invoice number from a series' numbering format (story 0100, FD-2): prefix + year +
 * separator + zero-padded counter. Pure: it reads only its arguments, never `now()` or the
 * database; the caller passes the issue moment in the application timezone. A counter wider than
 * the padding is never truncated.
 */
class ComposeInvoiceNumber
{
    public function __invoke(InvoiceType $series, InvoiceSetting $settings, CarbonInterface $at, int $counter): string
    {
        $key = $series->value;

        return $settings->{"{$key}_prefix"}
            .$settings->{"{$key}_year_format"}->render($at)
            .$settings->{"{$key}_separator"}
            .str_pad((string) $counter, (int) $settings->{"{$key}_padding"}, '0', STR_PAD_LEFT);
    }
}
