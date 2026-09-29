<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Topbar Titles and Subtitles
    |--------------------------------------------------------------------------
    |
    | Story 0057a. Each authenticated screen declares its own `heading` and
    | `subheading` named slots, rendered by the persistent topbar in
    | layouts/app/sidebar.blade.php. A screen that already owns a title key in
    | its own domain file reuses that key; only the missing titles and every
    | subtitle live here. Static copy only (D-3): dynamic counts stay inline.
    |
    */

    'dashboard' => [
        'title' => 'Dashboard',
        'subtitle' => 'Overview of your admin panel',
    ],

    'users' => [
        'title' => 'Users',
        'subtitle' => 'Manage accounts, roles and permissions for your team.',
    ],

    'roles' => [
        'title' => 'Roles & permissions',
        'subtitle' => 'Define what each role can see and do.',
    ],

    'sales_regions' => [
        'subtitle' => 'Tax rates by country and fiscal territory.',
    ],

    'product_categories' => [
        'title' => 'Product categories',
        'subtitle' => 'Organise the catalogue into categories.',
    ],

    'products' => [
        'subtitle' => 'Manage the catalogue, prices and stock.',
    ],

    'product_editor' => [
        'subtitle' => 'Details, gallery, tax regions and variants.',
    ],

    'attribute_types' => [
        'title' => 'Attribute types',
        'subtitle' => 'Attributes and values used to build product variants.',
    ],

    'shipping' => [
        'subtitle' => 'Carriers and their rates by zone and weight.',
    ],

    'shipping_zones' => [
        'subtitle' => 'Group geographic areas into shipping zones.',
    ],

    'payment_methods' => [
        'subtitle' => 'Configure how customers pay.',
    ],

    'customers' => [
        'title' => 'Customers',
        'subtitle' => "Your store's customers and their order history.",
    ],

    'orders' => [
        'title' => 'Orders',
        'subtitle' => 'Track, edit and fulfil the order book.',
    ],

    'blog_posts' => [
        'subtitle' => 'Write, schedule and publish blog posts.',
    ],

    'blog_post_editor' => [
        'subtitle' => 'Title, category, status, body and tags.',
    ],

    'blog_tags' => [
        'subtitle' => 'Organise blog posts with tags.',
    ],

    'blog_categories' => [
        'subtitle' => 'Group blog posts into categories.',
    ],

    'settings' => [
        'profile' => 'Profile',
        'security' => 'Security',
        'appearance' => 'Appearance',
        'profile_subtitle' => 'Update your name and email address',
        'security_subtitle' => 'Keep your account secure: password, two-factor authentication and passkeys',
        'appearance_subtitle' => 'Update the appearance settings for your account',
    ],

];
