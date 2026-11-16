<?php

namespace App\Enums;

/**
 * The PDF layouts an invoice can use (story 0100). The first case is the column default.
 */
enum InvoiceTemplate: string
{
    case Classic = 'classic';
    case Modern = 'modern';
}
