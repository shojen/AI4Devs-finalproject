<?php

// Story 0082 -- the validation messages of the dashboard's sales-series actions
// (App\Actions\Dashboard\ResolveSalesBuckets). Story 0083 extends this file with the dashboard's
// UI strings (every other group).
return [

    'errors' => [
        'range_invalid' => 'La fecha de inicio no puede ser posterior a la fecha de fin.',
        'range_too_long' => 'El periodo seleccionado es demasiado largo: puede abarcar como máximo :max intervalos.',
        'statuses_required' => 'Selecciona al menos un estado de pedido.',
    ],

    'hero' => [
        'greeting_morning' => 'Buenos días, :name',
        'greeting_afternoon' => 'Buenas tardes, :name',
        'greeting_evening' => 'Buenas noches, :name',
        'tagline' => 'Esto es lo que ocurre hoy en tu tienda.',
    ],

    'counters' => [
        'users' => 'Usuarios activos',
        'products' => 'Productos',
        'images' => 'Imágenes',
    ],

    'blog' => [
        'title' => 'Últimas publicaciones',
        'empty' => 'Todavía no hay publicaciones publicadas ni programadas.',
        'view_all' => 'Ver todas las publicaciones',
        'scheduled_for' => 'Se publica el :date',
    ],

    'stock' => [
        'title' => 'Stock bajo',
        'empty' => 'Ningún producto se está agotando.',
        'view_all' => 'Ver todos los productos',
        'out_of_stock' => 'Agotado',
        'low_stock' => 'Stock bajo',
        'units' => ':count unidad|:count unidades',
        'variants_low' => ':count variante con poco stock|:count variantes con poco stock',
    ],

    'orders' => [
        'title' => 'Últimos pedidos',
        'empty' => 'Todavía no hay pedidos.',
        'view_all' => 'Ver todos los pedidos',
    ],

    'sales' => [
        'title' => 'Resumen de ventas',
        'kpi' => [
            'sales' => 'Ventas',
            'income' => 'Ingresos reales',
            'orders' => 'Pedidos',
            'sales_definition' => 'Total vendido en el periodo, antes de reembolsos e incluyendo pedidos sin pagar.',
            'income_definition' => 'Dinero realmente cobrado en el periodo, descontando reembolsos.',
            'orders_definition' => 'Número de pedidos realizados en el periodo.',
            'collected' => 'Cobrado: :percent% de las ventas',
            'income_hint' => 'Los ingresos se cuentan cuando los pedidos se pagan',
        ],
        'filters_label' => 'Filtros del resumen de ventas',
        'granularity' => [
            'label' => 'Agrupar por',
            'day' => 'Día',
            'month' => 'Mes',
            'year' => 'Año',
        ],
        'presets' => [
            'label' => 'Rangos rápidos',
            'last_7_days' => 'Últimos 7 días',
            'last_30_days' => 'Últimos 30 días',
            'this_month' => 'Este mes',
            'this_year' => 'Este año',
        ],
        'from' => 'Desde',
        'to' => 'Hasta',
        'statuses' => 'Estado del pedido',
        'reset' => 'Restablecer',
        'chart_a_title' => 'Ventas frente a ingresos reales',
        'chart_b_title' => 'Pedidos por estado',
        'legend_help' => 'Pulsar una entrada de la leyenda solo oculta esa serie en este gráfico; usa los filtros de estado para cambiar las cifras.',
        'empty' => 'No hay pedidos en el periodo seleccionado.',
        'range_year_window' => 'Las fechas deben estar entre los años :min y :max.',
        'date_invalid' => 'Introduce una fecha válida.',
        'granularity_invalid' => 'Elige día, mes o año.',
        'statuses_max' => 'Selecciona como máximo :max estados de pedido.',
        'statuses_invalid' => 'Uno de los estados de pedido seleccionados no es válido.',
        'period' => 'Periodo',
        'money_caption' => 'Ventas e ingresos reales por periodo',
        'orders_caption' => 'Pedidos por periodo y estado',
        'difference' => 'Diferencia',
        'total' => 'Total',
        'summary' => 'Del :from al :to: :sales vendidos, :income cobrados, :orders pedidos.',
        'loading' => 'Cargando el resumen de ventas…',
        'updating' => 'Actualizando…',
    ],

    'untitled' => 'Sin título',

    'deleted_customer' => 'Cliente eliminado',

    'no_widgets' => 'Todavía no hay nada que mostrar.',

];
