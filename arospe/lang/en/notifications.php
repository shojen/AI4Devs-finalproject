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

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Copy for App\Notifications\ScheduledBlogPostPublishFailed (story 0064b, D-9). Kept here rather
    | than in blog-posts.php: this is where a future bell arm for the same notification type (H-3) will
    | read its own summary from, matching this file's existing `summary.*` group above.
    |
    */

    'mail' => [
        'blog_post_publish_failed' => [
            'subject' => 'Scheduled post could not be published: :title',
            'untitled' => 'Untitled post',
            'line_publish' => 'The post could not be published automatically at its scheduled time. It is still scheduled, and the system will keep retrying automatically; you will not receive another email about this post for 24 hours.',
            'line_announce' => 'The post was published, but the follow-up notification to administrators could not be sent.',
            'edit_button' => 'Edit post',
            'all_posts_link' => 'All posts',
        ],
    ],

];
