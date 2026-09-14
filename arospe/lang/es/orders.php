<?php

// Story 0045 -- key-for-key identical to lang/en/orders.php; see that file's
// header comment for what this file does and does not carry.

return [

    'statuses' => [
        'pending' => 'Pendiente',
        'processing' => 'Procesando',
        'shipped' => 'Enviado',
        'delivered' => 'Entregado',
        'cancelled' => 'Cancelado',
    ],

    'payment_statuses' => [
        'pending_payment' => 'Pendiente de pago',
        'paid' => 'Pagado',
        'refunded' => 'Reembolsado',
        'partially_refunded' => 'Parcialmente reembolsado',
    ],

    'errors' => [
        'total_exceeds_maximum' => 'Una o más líneas del pedido producirían un total que excede el valor máximo representable.',
    ],

];
