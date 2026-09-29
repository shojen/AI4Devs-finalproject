<?php

namespace App\Enums;

enum PaymentMethodCode: string
{
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return __('payment-methods.names.'.$this->value);
    }
}
