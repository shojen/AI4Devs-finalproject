<?php

namespace App\Enums;

/**
 * The two invoice series, each with its own numbering format (story 0100, FD-11).
 */
enum InvoiceType: string
{
    case Standard = 'standard';
    case Corrective = 'corrective';
}
