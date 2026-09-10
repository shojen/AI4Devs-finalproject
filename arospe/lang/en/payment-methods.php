<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment method names
    |--------------------------------------------------------------------------
    |
    | Resolved by App\Enums\PaymentMethodCode::label() as
    | 'payment-methods.names.'.$this->value -- one key per catalog code.
    |
    */

    'names' => [
        'bank_transfer' => 'Bank transfer',
    ],

    /*
    |--------------------------------------------------------------------------
    | IBAN validation and status copy
    |--------------------------------------------------------------------------
    |
    | 'invalid' is App\Rules\Iban's rejection message. 'not_configured' is
    | the "no IBAN set yet" copy for a freshly seeded method.
    |
    */

    'iban' => [
        'invalid' => 'Please enter a valid IBAN.',
        'not_configured' => 'No IBAN configured yet.',
    ],

];
