<?php

namespace App\Enums;

/**
 * The state of one invoice e-mail delivery (story 0104). A `sent` row never changes again.
 */
enum InvoiceDeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
