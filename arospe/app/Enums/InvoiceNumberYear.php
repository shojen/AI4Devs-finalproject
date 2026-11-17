<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * How the year appears inside an invoice number (story 0100, FD-2).
 */
enum InvoiceNumberYear: string
{
    case None = 'none';
    case Full = 'YYYY';
    case Short = 'YY';

    /**
     * Render the year token for the given moment (`2026`, `26` or an empty string).
     */
    public function render(CarbonInterface $at): string
    {
        return match ($this) {
            self::None => '',
            self::Full => $at->format('Y'),
            self::Short => $at->format('y'),
        };
    }
}
