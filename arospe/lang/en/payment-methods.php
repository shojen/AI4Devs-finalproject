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

    /*
    |--------------------------------------------------------------------------
    | Payment methods settings screen (story 0039)
    |--------------------------------------------------------------------------
    |
    | Copy for resources/views/livewire/payment-methods.blade.php. 'index' is
    | this screen's own group, mirroring lang/en/users.php's 'index' group.
    |
    */

    'index' => [
        'heading' => 'Payment methods',
        'configured_label' => 'Configured',
        'not_configured_label' => 'Not configured',
        'configure_action' => 'Configure',
        'edit_action' => 'Edit',
        'action_not_allowed' => 'Action not allowed',
    ],

    'editor' => [
        'title' => 'Configure :method',
        'iban_label' => 'IBAN',
        'iban_description' => 'The account customers must transfer payment to.',
    ],

];
