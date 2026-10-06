<?php

namespace App\Enums;

/**
 * An order's payment status (story 0045, PRD §3.2) -- a separate dimension
 * from OrderStatus, since the two vocabularies evolve independently (a
 * shipped order can still be unpaid, a cancelled order can still be
 * refunded, etc).
 *
 * Deliberately no label() -- see OrderStatus's own docblock. It does carry
 * the one cross-dimension rule: which fulfilment statuses an order may hold
 * while in each payment state (allowsOrderStatus()).
 */
enum PaymentStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * May an order in this payment state hold the given fulfilment status?
     *
     * An unpaid order cannot be processed, shipped or delivered; a fully
     * refunded one can only be cancelled; a partially refunded one can no
     * longer be cancelled manually. Non-throwing predicate:
     * App\Actions\Orders\TransitionOrderStatus's guard and the order
     * detail screen both wrap exactly this call.
     */
    public function allowsOrderStatus(OrderStatus $status): bool
    {
        return in_array($status, match ($this) {
            self::PendingPayment => [OrderStatus::Pending, OrderStatus::Cancelled],
            self::Paid => OrderStatus::cases(),
            self::Refunded => [OrderStatus::Cancelled],
            self::PartiallyRefunded => [OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered],
        }, true);
    }
}
