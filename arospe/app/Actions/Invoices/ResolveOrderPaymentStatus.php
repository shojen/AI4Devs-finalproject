<?php

namespace App\Actions\Invoices;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

/**
 * Pure derivation of an order's payment status (story 0106, truth table; the first matching row
 * wins). It reads nothing: the caller passes inputs read under the order lock.
 *
 * 0. A cancelled order, or one without any invoice (legacy-shaped), keeps its stored status.
 * 1. A refund recorded and every line fully refunded: Refunded.
 * 2. A refund recorded otherwise: PartiallyRefunded.
 * 3. Billable above zero and nothing left to collect: Paid.
 * 4. Something collected: PartiallyPaid.
 * 5. Otherwise: PendingPayment.
 */
class ResolveOrderPaymentStatus
{
    /**
     * @param  numeric-string  $refundedAmount
     * @param  numeric-string  $billable
     * @param  numeric-string  $netCollected
     */
    public function __invoke(
        PaymentStatus $stored,
        OrderStatus $status,
        bool $hasInvoices,
        string $refundedAmount,
        bool $everyLineFullyRefunded,
        string $billable,
        string $netCollected,
    ): PaymentStatus {
        if ($status === OrderStatus::Cancelled || ! $hasInvoices) {
            return $stored;
        }

        if (bccomp($refundedAmount, '0.00', 2) > 0) {
            return $everyLineFullyRefunded ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
        }

        if (bccomp($billable, '0.00', 2) > 0 && bccomp(bcsub($billable, $netCollected, 2), '0.00', 2) <= 0) {
            return PaymentStatus::Paid;
        }

        if (bccomp($netCollected, '0.00', 2) > 0) {
            return PaymentStatus::PartiallyPaid;
        }

        return PaymentStatus::PendingPayment;
    }
}
