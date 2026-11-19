<?php

namespace App\Enums;

/**
 * The PDF layouts an invoice can use (story 0100). The first case is the column default.
 */
enum InvoiceTemplate: string
{
    case Classic = 'classic';
    case Modern = 'modern';

    /**
     * The Blade view that renders this layout (story 0102 FD-4).
     */
    public function view(): string
    {
        return 'invoices.pdf.'.$this->value;
    }
}
