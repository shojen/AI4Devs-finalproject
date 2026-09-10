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
        'bank_transfer' => 'Transferencia bancaria',
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
        'invalid' => 'Introduce un IBAN válido.',
        'not_configured' => 'Todavía no se ha configurado ningún IBAN.',
    ],

];
