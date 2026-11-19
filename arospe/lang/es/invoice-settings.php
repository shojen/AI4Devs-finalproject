<?php

// Story 0100: nombres de atributos y mensajes de rechazo de los ajustes de facturas (FD-13).
return [
    'attributes' => [
        'issuer_legal_name' => 'razón social',
        'issuer_tax_id' => 'NIF/CIF',
        'issuer_address_line1' => 'dirección (línea 1)',
        'issuer_address_line2' => 'dirección (línea 2)',
        'issuer_city' => 'ciudad',
        'issuer_postal_code' => 'código postal',
        'issuer_province' => 'provincia',
        'issuer_country' => 'país',
        'logo_media_id' => 'logotipo',
        'primary_color' => 'color principal',
        'accent_color' => 'color de acento',
        'template' => 'plantilla',
        'standard_prefix' => 'prefijo de facturas',
        'corrective_prefix' => 'prefijo de facturas rectificativas',
        'standard_year_format' => 'formato del año de facturas',
        'corrective_year_format' => 'formato del año de facturas rectificativas',
        'standard_separator' => 'separador de facturas',
        'corrective_separator' => 'separador de facturas rectificativas',
        'standard_padding' => 'longitud del número de facturas',
        'corrective_padding' => 'longitud del número de facturas rectificativas',
        'reset_numbering_yearly' => 'reinicio anual de la numeración',
        'auto_issue_on_order' => 'facturación automática',
    ],

    'validation' => [
        'year_required_when_reset' => 'El año debe formar parte del número cuando la numeración se reinicia cada año.',
        'prefixes_must_differ' => 'El prefijo de las facturas rectificativas debe ser distinto del de las facturas.',
        'auto_issue_requires_issuer' => 'La facturación automática necesita la razón social, el NIF/CIF y la línea 1 de la dirección.',
    ],

    'numbering' => [
        'start_already_issued' => 'El contador inicial ya no se puede cambiar porque esta serie ya ha emitido facturas.',
        'start_invalid' => 'El contador inicial debe ser un número entero entre 1 y 4.294.967.294.',
        'clock_skew' => 'La fecha de la factura es anterior a la de la última factura emitida en esta serie. Revisa el reloj del sistema.',
        'overflow' => 'El contador de facturas ha alcanzado su valor máximo y no se pueden emitir más números.',
        'next_number_exists' => 'El siguiente número de factura con este formato ya existe. Elige otro prefijo o formato de año.',
    ],
];
