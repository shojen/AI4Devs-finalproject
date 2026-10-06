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

    'sales' => [
        'title' => 'Sales overview',
        'kpi' => [
            'sales' => 'Sales',
            'income' => 'Real income',
            'orders' => 'Orders',
            'sales_definition' => 'Total sold in the period, before refunds, including unpaid orders.',
            'income_definition' => 'Money actually collected in the period, net of refunds.',
            'orders_definition' => 'Number of orders placed in the period.',
            'collected' => 'Collected: :percent% of sales',
            'income_hint' => 'Income is counted once orders are paid',
        ],
        'filters_label' => 'Sales overview filters',
        'granularity' => [
            'label' => 'Group by',
            'day' => 'Day',
            'month' => 'Month',
            'year' => 'Year',
        ],
        'presets' => [
            'label' => 'Quick ranges',
            'last_7_days' => 'Last 7 days',
            'last_30_days' => 'Last 30 days',
            'this_month' => 'This month',
            'this_year' => 'This year',
        ],
        'from' => 'From',
        'to' => 'To',
        'statuses' => 'Order status',
        'reset' => 'Reset',
        'chart_a_title' => 'Sales vs real income',
        'chart_b_title' => 'Orders by status',
        'legend_help' => 'Clicking a legend entry only hides that series in this chart; use the status chips to change the figures.',
        'empty' => 'There are no orders in the selected period.',
        'range_year_window' => 'Dates must fall between the years :min and :max.',
        'date_invalid' => 'Enter a valid date.',
        'granularity_invalid' => 'Choose day, month or year.',
        'statuses_max' => 'Select at most :max order statuses.',
        'statuses_invalid' => 'One of the selected order statuses is not valid.',
        'period' => 'Period',
        'money_caption' => 'Sales and real income per period',
        'orders_caption' => 'Orders per period and status',
        'difference' => 'Difference',
        'total' => 'Total',
        'summary' => 'From :from to :to: :sales sold, :income collected, :orders orders.',
        'loading' => 'Loading the sales overview…',
        'updating' => 'Updating…',
    ],

    'untitled' => 'Untitled',

    'deleted_customer' => 'Deleted customer',

    'no_widgets' => 'There is nothing to show yet.',

];
