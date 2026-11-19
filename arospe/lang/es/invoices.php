<?php

// Story 0101b: rechazos al emitir una factura y texto guardado en la línea de una factura por concepto.
return [
    'errors' => [
        'issuer_incomplete' => 'Rellena la razón social, el NIF/CIF y la línea 1 de la dirección en los ajustes de facturas antes de emitir facturas.',
        'nothing_left' => 'No queda nada por facturar de este pedido.',
        'cancelled' => 'Un pedido cancelado no se puede facturar.',
        'out_of_date' => 'Las facturas de este pedido están desactualizadas tras una edición. Regenéralas primero.',
        'amount_format' => 'Introduce el importe con punto y como máximo dos decimales, por ejemplo 200.50.',
        'amount_minimum' => 'El importe debe ser de al menos 0.01.',
        'amount_exceeds_remaining' => 'El importe supera lo que queda por facturar: quedan :remaining.',
        'customer_missing' => 'El cliente de este pedido ya no existe, por lo que no se puede facturar.',
        'number_collision' => 'Ya existe una factura con este número. Cambia el prefijo de facturas en los ajustes de facturas.',
    ],

    'pdf' => [
        'title' => 'Factura',
        'corrective_title' => 'Factura rectificativa',
        'number' => 'Número',
        'issued_on' => 'Fecha de emisión',
        'order' => 'Pedido',
        'issuer' => 'Emisor',
        'recipient' => 'Facturar a',
        'tax_id' => 'NIF/CIF',
        'description' => 'Descripción',
        'sku' => 'SKU',
        'quantity' => 'Cant.',
        'unit_price' => 'Precio unitario',
        'line_total' => 'Importe',
        'subtotal' => 'Base imponible',
        'shipping' => 'Envío (sin impuestos)',
        'tax' => 'IVA',
        'total' => 'Total a pagar',
        'corrects' => 'Rectifica la factura',
        'reason' => 'Motivo',
        'copy' => 'COPIA',
        'sample' => 'MUESTRA',
        'reasons' => [
            'refund' => 'Reembolso',
            'order_edited' => 'Pedido editado',
            'order_cancelled' => 'Pedido cancelado',
        ],
    ],

    'lines' => [
        'payment_on_account' => 'Pago a cuenta del pedido :number',
    ],
];
