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
        'confirm_backward_heading' => '¿Mover este pedido hacia atrás?',
        'confirm_backward_body' => 'Esto lleva el pedido a un estado anterior al actual. Confirma solo si es lo que pretendes.',
        'confirm_backward_action' => 'Moverlo atrás',
    ],

    'refunds' => [
        'invalid_payment_state' => 'Este pedido no se puede reembolsar en su estado de pago actual.',
        'item_not_owned' => 'Una o más líneas no pertenecen a este pedido.',
        'exceeds_outstanding_units' => 'Una o más líneas se reembolsarían por más unidades de las que quedan pendientes.',
        'action' => 'Registrar reembolso',
        'modal_title' => 'Registrar un reembolso',
        'units_to_refund' => 'Unidades a reembolsar',
        'outstanding' => ':count pendientes',
        'confirm' => 'Reembolsar',
        'nothing_selected' => 'Introduce al menos una unidad a reembolsar.',
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

    /*
    |--------------------------------------------------------------------------
    | Textos de pantalla (historia 0055)
    |--------------------------------------------------------------------------
    */

    'index' => [
        'summary' => ':count pedido|:count pedidos',
        'empty' => 'Todavía no se ha registrado ningún pedido.',
        'flagged' => 'Requiere atención',
        'flagged_generic' => 'Requiere atención: este pedido está marcado para revisión manual.',
        'view_order' => 'Ver pedido :number',
        'columns' => [
            'order' => 'Pedido',
            'customer' => 'Cliente',
            'status' => 'Estado',
            'payment' => 'Pago',
            'total' => 'Total',
            'placed' => 'Realizado',
            'actions' => 'Acciones',
        ],
    ],

    'detail' => [
        'back_to_list' => 'Volver a pedidos',
        'customer_heading' => 'Cliente',
        'shipping_address' => 'Dirección de envío (en el momento del pedido)',
        'no_address' => 'No hay dirección de envío registrada.',
        'line_items_heading' => 'Líneas del pedido',
        'lifecycle_heading' => 'Estado y ciclo de vida',
        'totals_heading' => 'Totales',
        'tax_heading' => 'Impuestos',
        'action_not_allowed' => 'No tienes permiso para hacer esto, o el pedido no está en un estado que lo permita.',
        'flag_callout_heading' => 'Requiere atención',
        'placed_at' => 'Realizado el :date',
        'subtotal' => 'Subtotal',
        'tax_amount' => 'Impuestos',
        'shipping_amount' => 'Envío',
        'total' => 'Total',
        'refunded_amount' => 'Reembolsado',
        'tax_region' => 'Región de ventas',
        'tax_rate' => 'Tipo',
        'tax_provisional' => 'Provisional: este pedido está marcado para revisión, así que esta base no está confirmada.',
        'tax_unresolved' => 'Aún sin resolver',
    ],

    'line_items' => [
        'product' => 'Producto',
        'sku' => 'SKU',
        'quantity' => 'Cantidad',
        'unit_price' => 'Precio unitario',
        'line_total' => 'Total de línea',
        'refunded' => 'Reembolsadas',
        'variant' => 'Variante',
        'add' => 'Añadir línea',
        'remove' => 'Eliminar',
        'save_quantity' => 'Guardar cantidad',
        'select_product' => 'Selecciona un producto',
        'select_variant' => 'Selecciona una variante',
        'catalog_empty' => 'No hay productos disponibles para añadir.',
        'catalog_truncated' => 'Se muestran solo los primeros :max productos.',
        'remove_refunded_hint' => 'Una línea con unidades reembolsadas no se puede eliminar.',
        'product_unavailable' => 'Ese producto no está disponible para añadir.',
        'variant_required' => 'Elige una variante para este producto.',
    ],

    'lifecycle' => [
        'status' => 'Estado',
        'apply' => 'Aplicar',
        'cancel' => 'Cancelar pedido',
        'cancel_dialog_heading' => '¿Cancelar este pedido?',
        'cancel_dialog_body' => 'La cancelación es definitiva: un pedido cancelado no se puede reabrir. Su estado de pago no cambia y no se reembolsa nada automáticamente.',
        'cancel_dialog_confirm' => 'Cancelar pedido',
        'dialog_dismiss' => 'Dejarlo como está',
    ],

];
