<?php

namespace App\Enums;

/**
 * An order's payment status (story 0045, PRD §3.2) -- a separate dimension
 * from OrderStatus, since the two vocabularies evolve independently (a
 * shipped order can still be unpaid, a cancelled order can still be
 * refunded, etc).
 *
 * Deliberately no label() and no transition logic -- see OrderStatus's own
 * docblock; the same reasoning applies unchanged.
 */
enum PaymentStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
