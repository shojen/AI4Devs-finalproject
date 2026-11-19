<?php

namespace App\Enums;

/**
 * Why a corrective invoice was issued (written from stories 0107 and 0108).
 */
enum InvoiceCorrectionReason: string
{
    case Refund = 'refund';
    case OrderEdited = 'order_edited';
    case OrderCancelled = 'order_cancelled';
}
