<?php

// Story 0082 -- the validation messages of the dashboard's sales-series actions
// (App\Actions\Dashboard\ResolveSalesBuckets). Story 0083 extends this file with the dashboard's
// UI strings (every other group).
return [

    'errors' => [
        'range_invalid' => 'The start date must not be after the end date.',
        'range_too_long' => 'The selected period is too long: it may span at most :max intervals.',
        'statuses_required' => 'Select at least one order status.',
    ],

    'hero' => [
        'greeting_morning' => 'Good morning, :name',
        'greeting_afternoon' => 'Good afternoon, :name',
        'greeting_evening' => 'Good evening, :name',
        'tagline' => 'Here is what is happening in your store today.',
    ],

    'counters' => [
        'users' => 'Active users',
        'products' => 'Products',
        'images' => 'Images',
    ],

    'blog' => [
        'title' => 'Latest posts',
        'empty' => 'There are no published or scheduled posts yet.',
        'view_all' => 'View all posts',
        'scheduled_for' => 'Goes live on :date',
    ],

    'stock' => [
        'title' => 'Low stock',
        'empty' => 'No product is running out of stock.',
        'view_all' => 'View all products',
        'out_of_stock' => 'Out of stock',
        'low_stock' => 'Low stock',
        'units' => ':count unit|:count units',
        'variants_low' => ':count variant low|:count variants low',
    ],

    'orders' => [
        'title' => 'Latest orders',
        'empty' => 'There are no orders yet.',
        'view_all' => 'View all orders',
    ],

    'untitled' => 'Untitled',

    'deleted_customer' => 'Deleted customer',

    'no_widgets' => 'There is nothing to show yet.',

];
