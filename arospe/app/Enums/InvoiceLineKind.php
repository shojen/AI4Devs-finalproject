<?php

namespace App\Enums;

/**
 * What an invoice line represents (story 0101b, FD-6): a copy of an order line, or the single
 * concept line of a partial invoice.
 */
enum InvoiceLineKind: string
{
    case Item = 'item';
    case Adjustment = 'adjustment';
}
