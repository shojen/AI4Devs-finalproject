<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campana de notificaciones
    |--------------------------------------------------------------------------
    |
    | Copy de App\Livewire\Notifications\Bell (historia 0057). Clave por clave
    | idéntico a lang/en/notifications.php.
    |
    */

    'bell' => [
        'label' => 'Notificaciones',
        'unread' => 'Tienes notificaciones sin leer',
        'empty' => 'No tienes notificaciones.',
    ],

    'summary' => [
        'customer_created' => 'Nuevo cliente: :name',
        'order_created' => 'Nuevo pedido :number',
    ],

    'fallback' => 'Nueva notificación',

    /*
    |--------------------------------------------------------------------------
    | Correo
    |--------------------------------------------------------------------------
    |
    | Copy de App\Notifications\ScheduledBlogPostPublishFailed (historia 0064b, D-9). Clave por clave
    | idéntico a lang/en/notifications.php.
    |
    */

    'mail' => [
        'blog_post_publish_failed' => [
            'subject' => 'No se pudo publicar la entrada programada: :title',
            'untitled' => 'Entrada sin título',
            'line_publish' => 'La entrada no se pudo publicar automáticamente en la fecha programada. Sigue programada y el sistema seguirá reintentándolo automáticamente; no recibirás otro correo sobre esta entrada durante 24 horas.',
            'line_announce' => 'La entrada se publicó, pero no se pudo enviar la notificación de seguimiento a los administradores.',
            'edit_button' => 'Editar entrada',
            'all_posts_link' => 'Todas las entradas',
        ],
    ],

];
