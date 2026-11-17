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
        'payment_state_blocked' => 'El estado de pago del pedido no permite ese estado.',
        'requires_confirmation' => 'Mover este pedido hacia atrás requiere confirmación explícita.',
        'same_status' => 'Este pedido ya tiene ese estado.',
        'cancellation_unsupported' => 'Cancelar o reabrir un pedido no está disponible aquí.',
        'confirm_backward_heading' => '¿Mover este pedido hacia atrás?',
        'confirm_backward_body' => 'Esto lleva el pedido a un estado anterior al actual. Confirma solo si es lo que pretendes.',
        'confirm_backward_action' => 'Moverlo atrás',
        'stale_confirmation' => 'Otra persona ha cambiado este pedido mientras confirmabas. Revisa su estado e inténtalo de nuevo.',
    ],

    'refunds' => [
        'invalid_payment_state' => 'Este pedido no se puede reembolsar en su estado de pago actual.',
        'item_not_owned' => 'Una o más líneas no pertenecen a este pedido.',
        'exceeds_outstanding_units' => 'Una o más líneas se reembolsarían por más unidades de las que quedan pendientes.',
        'stock_return_not_in_refund' => 'Esas unidades no forman parte de este reembolso.',
        'stock_return_exceeds_refunded' => 'No puedes devolver al stock más unidades de las que cubre este reembolso.',
        'stock_return_not_out_of_stock' => 'Esas unidades no están fuera de stock, así que no se pueden devolver a él.',
        'action' => 'Registrar reembolso',
        'modal_title' => 'Registrar un reembolso',
        'units_to_refund' => 'Unidades a reembolsar',
        'outstanding' => ':count unidad pendiente|:count unidades pendientes',
        'dismiss' => 'Cancelar',
        'confirm' => 'Reembolsar',
        'nothing_selected' => 'Introduce al menos una unidad a reembolsar.',
        'stock_return_label' => 'Devolver al stock',
        'stock_none' => 'Ninguna',
        'stock_all' => 'Todas las unidades reembolsadas',
        'stock_partial' => 'Algunas unidades',
        'stock_partial_hint' => 'Hasta las unidades que reembolses en esta línea',
        'stock_units_label' => 'Unidades a devolver al stock',
        'stock_partial_empty' => 'Elige al menos una unidad que devolver, o selecciona Ninguna.',
        'stock_mode_invalid' => 'Esa opción no es válida.',
        'changed_meanwhile' => 'Este pedido cambió mientras tanto. Revísalo e inténtalo de nuevo.',
        'stock_untracked' => 'Esta línea no tiene stock que devolver, así que no se devolverán unidades de ella.',
    ],

    'cancellation' => [
        'blocked' => 'Este pedido ya no se puede cancelar.',
        'already_cancelled' => 'Este pedido ya está cancelado.',
    ],

    // Story 0084 -- App\Actions\Orders\MarkOrderAsPaid's two state refusals.
    'payment' => [
        'already_paid' => 'Este pedido ya está pagado.',
        'cancelled_blocked' => 'Un pedido cancelado no se puede marcar como pagado.',
        // Story 0085 -- el control "Marcar como pagado" en el listado y en el detalle del pedido.
        'action' => 'Marcar como pagado',
        'dialog_heading' => '¿Marcar este pedido como pagado?',
        'dialog_body' => 'Esto registra que el pedido :number se pagó por transferencia bancaria. No se puede deshacer desde aquí.',
        'dialog_amount' => 'Importe recibido',
        'dialog_confirm' => 'Marcar como pagado',
        'dialog_dismiss' => 'Cancelar',
        'marked' => 'Pedido :number marcado como pagado.',
        'info' => 'Pagado el :date (:type)',
        'info_by' => 'Pagado el :date (:type), registrado por :user',
        'not_found' => 'Este pedido ya no existe.',
        'types' => [
            'transfer' => 'Transferencia bancaria',
            'card' => 'Tarjeta',
            'paypal' => 'PayPal',
        ],
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

    // Story 0094 -- the stock dialog on a line's quantity change.
    'stock' => [
        'dialog_heading' => '¿Actualizar el stock?',
        'dialog_body' => 'Cambiar esta cantidad también puede cambiar el stock del producto.',
        'deduct' => '{1} Descontar :count unidad del stock|[2,*] Descontar :count unidades del stock',
        'return' => '{1} Devolver :count unidad al stock|[2,*] Devolver :count unidades al stock',
        'save_without_stock' => 'Guardar sin cambiar el stock',
        'keep_editing' => 'Seguir editando',
        'current' => 'Stock actual',
        'resulting' => 'Stock tras este cambio',
        'negative_warning' => 'Esto deja :name en :stock unidades.',
        'nothing_to_return' => 'Ninguna unidad de esta línea está fuera del stock actualmente',
        'changed_meanwhile' => 'Esta línea cambió mientras el diálogo estaba abierto. Revísala e inténtalo de nuevo.',
        'remove_heading' => '¿Eliminar esta línea?',
        'remove_body' => 'Eliminar esta línea también puede devolver sus unidades al stock del producto.',
        'remove_without_stock' => 'Eliminar sin cambiar el stock',
        'cancel_and_return' => '{1} Cancelar y devolver :count unidad al stock|[2,*] Cancelar y devolver :count unidades al stock',
        'cancel_without_stock' => 'Cancelar sin cambiar el stock',
        'keep_order' => 'Mantener el pedido',
        'cancel_nothing_to_return' => 'Ninguna unidad de este pedido está fuera del stock actualmente',
        'cancel_changed_meanwhile' => 'Este pedido cambió mientras tanto. Revísalo e inténtalo de nuevo.',
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
        'dialog_dismiss' => 'Dejarlo como está',
    ],

];
