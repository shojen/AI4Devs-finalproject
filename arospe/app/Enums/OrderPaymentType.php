<?php

namespace App\Enums;

/**
 * Story 0084 -- how an order's payment was made, stored on `order_payments.type`.
 */
enum OrderPaymentType: string
{
    case Transfer = 'transfer';
    case Card = 'card';
    case Paypal = 'paypal';

    /**
     * The translated, human-readable name of this payment type.
     */
    public function label(): string
    {
        return __('orders.payment.types.'.$this->value);
    }
}
