<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Bell
    |--------------------------------------------------------------------------
    |
    | Copy for App\Livewire\Notifications\Bell (story 0057). `summary.*` holds
    | one template per notification type the bell recognizes; `fallback` is the
    | permanent generic label for every other type (D-3) and must never be
    | removed.
    |
    */

    'bell' => [
        'label' => 'Notifications',
        'unread' => 'You have unread notifications',
        'empty' => 'You have no notifications.',
    ],

    'summary' => [
        'customer_created' => 'New customer: :name',
        'order_created' => 'New order :number',
    ],

    'fallback' => 'New notification',

];
