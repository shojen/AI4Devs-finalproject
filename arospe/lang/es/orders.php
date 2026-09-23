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
        'refunded_line_item_cannot_be_removed' => 'La línea no se puede eliminar porque ya se han reembolsado algunas de sus unidades.',
        'quantity_below_refunded' => 'La cantidad no puede ser inferior a las :refunded unidades ya reembolsadas.',
        'too_many_line_items' => 'Un pedido no puede tener más de :max líneas.',
    ],

    'transitions' => [
        'requires_confirmation' => 'Mover este pedido hacia atrás requiere confirmación explícita.',
        'same_status' => 'Este pedido ya tiene ese estado.',
        'cancellation_unsupported' => 'Cancelar o reabrir un pedido no está disponible aquí.',
    ],

    'refunds' => [
        'invalid_payment_state' => 'Este pedido no se puede reembolsar en su estado de pago actual.',
        'item_not_owned' => 'Una o más líneas no pertenecen a este pedido.',
        'exceeds_outstanding_units' => 'Una o más líneas se reembolsarían por más unidades de las que quedan pendientes.',
    ],

    'cancellation' => [
        'blocked' => 'Este pedido ya no se puede cancelar.',
        'already_cancelled' => 'Este pedido ya está cancelado.',
    ],

    // Story 0054 -- tokens written to `orders.flag_reason` by
    // App\Actions\Orders\ResolveVirtualOrderSalesRegion, keyed by its REASON_* constants.
    'flag_reasons' => [
        'billing_ip_country_mismatch' => 'El país de facturación no coincide con el país derivado de la dirección IP del comprador.',
        'ip_country_missing' => 'No se capturó un país derivado de la IP para este pedido, por lo que no se pudo validar la dirección de facturación.',
        'mixed_basket' => 'Este pedido mezcla productos físicos y virtuales, por lo que su región fiscal no se puede resolver automáticamente.',
    ],

];
