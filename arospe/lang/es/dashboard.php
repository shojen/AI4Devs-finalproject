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

    'untitled' => 'Sin título',

    'deleted_customer' => 'Cliente eliminado',

    'no_widgets' => 'Todavía no hay nada que mostrar.',

];
