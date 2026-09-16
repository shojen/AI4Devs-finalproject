<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pantalla de listado de clientes
    |--------------------------------------------------------------------------
    |
    | Textos para la pantalla de listado de clientes (App\Livewire\Customers\Index,
    | historia 0044). El archivo que la historia 0041 dejó deliberadamente
    | pendiente para esta historia (D-14).
    |
    */

    'index' => [
        'summary' => ':count cliente|:count clientes',
        'empty' => 'No se han encontrado clientes.',
        'shipping_location' => 'Ubicación de envío',
        'action_not_allowed' => 'Acción no permitida',
    ],

    /*
    |--------------------------------------------------------------------------
    | Formulario de creación / edición
    |--------------------------------------------------------------------------
    |
    | Textos para los bloques de dirección de envío/facturación del modal de
    | creación/edición y la opción de copia "igual que el envío" (D-1).
    |
    */

    'form' => [
        'shipping_heading' => 'Dirección de envío',
        'billing_heading' => 'Dirección de facturación',
        'same_as_shipping' => 'Igual que el envío',
        'address_line1' => 'Dirección línea 1',
        'address_line2' => 'Dirección línea 2',
        'city' => 'Ciudad',
        'postal_code' => 'Código postal',
        'province' => 'Provincia',
        'country' => 'País',
        'country_hint' => 'Código de país de dos letras (ISO 3166-1 alfa-2), por ejemplo ES.',
        'country_placeholder' => 'ejemplo: ES',
    ],

    /*
    |--------------------------------------------------------------------------
    | Confirmación de borrado
    |--------------------------------------------------------------------------
    */

    'delete' => [
        'confirm_title' => 'Eliminar cliente',
        'confirm_body' => '¿Seguro que quieres eliminar a ":name"? Esta acción no se puede deshacer.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pantalla de detalle
    |--------------------------------------------------------------------------
    |
    | Textos para la pantalla de detalle de cliente (App\Livewire\Customers\Show,
    | historia 0047): la cabecera de identidad, la sección de historial de
    | pedidos y la acción de fila "ver detalle" del listado.
    |
    */

    'detail' => [
        'back_to_list' => 'Volver a clientes',
        'order_history_heading' => 'Historial de pedidos',
        'no_orders' => 'Este cliente todavía no tiene pedidos.',
        'view_detail' => 'Ver :name',
    ],

];
