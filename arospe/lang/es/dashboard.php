<?php

// Story 0082 -- the validation messages of the dashboard's sales-series actions
// (App\Actions\Dashboard\ResolveSalesBuckets). Story 0083 extends this file with the dashboard's
// UI strings.
return [

    'errors' => [
        'range_invalid' => 'La fecha de inicio no puede ser posterior a la fecha de fin.',
        'range_too_long' => 'El periodo seleccionado es demasiado largo: puede abarcar como máximo :max intervalos.',
        'statuses_required' => 'Selecciona al menos un estado de pedido.',
    ],

];
