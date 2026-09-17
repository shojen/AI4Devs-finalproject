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
        'order_not_editable' => 'Este pedido ya no se puede editar.',
        'last_line_item_cannot_be_removed' => 'Un pedido debe conservar al menos una línea. Cancele el pedido en lugar de eliminar su última línea.',
        'too_many_line_items' => 'Un pedido no puede tener más de :max líneas.',
    ],

    'transitions' => [
        'requires_confirmation' => 'Mover este pedido hacia atrás requiere confirmación explícita.',
        'same_status' => 'Este pedido ya tiene ese estado.',
        'cancellation_unsupported' => 'Cancelar o reabrir un pedido no está disponible aquí.',
    ],

];
