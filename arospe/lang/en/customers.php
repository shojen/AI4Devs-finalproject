<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customers Index Screen
    |--------------------------------------------------------------------------
    |
    | Copy for the Customers list screen (App\Livewire\Customers\Index, story
    | 0044). The file 0041 deliberately deferred to this story (D-14).
    |
    */

    'index' => [
        'summary' => ':count customer|:count customers',
        'empty' => 'No customers found.',
        'shipping_location' => 'Shipping location',
        'action_not_allowed' => 'Action not allowed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Create / Edit Form
    |--------------------------------------------------------------------------
    |
    | Copy for the create/edit modal's shipping/billing address blocks and the
    | "same as shipping" copy affordance (D-1).
    |
    */

    'form' => [
        'shipping_heading' => 'Shipping address',
        'billing_heading' => 'Billing address',
        'same_as_shipping' => 'Same as shipping',
        'address_line1' => 'Address line 1',
        'address_line2' => 'Address line 2',
        'city' => 'City',
        'postal_code' => 'Postal code',
        'province' => 'Province',
        'country' => 'Country',
        'country_hint' => 'Two-letter country code (ISO 3166-1 alpha-2), e.g. ES.',
        'country_placeholder' => 'example: ES',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delete Confirmation
    |--------------------------------------------------------------------------
    */

    'delete' => [
        'confirm_title' => 'Delete customer',
        'confirm_body' => 'Are you sure you want to delete ":name"? This cannot be undone.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Detail Screen
    |--------------------------------------------------------------------------
    |
    | Copy for the customer detail screen (App\Livewire\Customers\Show, story
    | 0047): the identity header, the order-history section and the list's
    | "view detail" row action. Generic chrome (Order, Status, Total, Date,
    | Email, Phone) stays as bare __('…') literals matching users.blade.php;
    | only the copy specific to this screen lives here.
    |
    */

    'detail' => [
        'back_to_list' => 'Back to customers',
        'order_history_heading' => 'Order history',
        'no_orders' => 'This customer has no orders yet.',
        'view_detail' => 'View :name',
    ],

];
