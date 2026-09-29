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

    /*
    |--------------------------------------------------------------------------
    | Pantalla de configuración de métodos de pago (historia 0039)
    |--------------------------------------------------------------------------
    |
    | Textos para resources/views/livewire/payment-methods.blade.php. 'index'
    | es el grupo propio de esta pantalla, siguiendo el mismo patrón que el
    | grupo 'index' de lang/es/users.php.
    |
    */

    'index' => [
        'heading' => 'Métodos de pago',
        'configured_label' => 'Configurado',
        'not_configured_label' => 'No configurado',
        'configure_action' => 'Configurar',
        'edit_action' => 'Editar',
        'action_not_allowed' => 'Acción no permitida',
    ],

    'editor' => [
        'title' => 'Configurar :method',
        'iban_label' => 'IBAN',
        'iban_description' => 'La cuenta a la que los clientes deben transferir el pago.',
    ],

];
