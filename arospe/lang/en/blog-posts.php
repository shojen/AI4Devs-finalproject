<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Blog Posts Screens
    |--------------------------------------------------------------------------
    |
    | Copy for App\Livewire\BlogPosts\Index (story 0063), consumed by
    | resources/views/livewire/blog-posts.blade.php, and for the routed post
    | editor (App\Livewire\BlogPosts\Editor), which appends its own `editor.*`
    | keys to this file. Keep this file key-for-key identical to
    | lang/es/blog-posts.php.
    |
    */

    'index' => [
        'title' => 'Blog posts',
        'new' => 'New post',
        'empty' => 'No blog posts have been created yet.',
        'empty_filtered' => 'No posts match the selected filters.',
        'column_title' => 'Title',
        'column_category' => 'Category',
        'column_status' => 'Status',
        'column_date' => 'Date',
        'column_actions' => 'Actions',
        'action_not_allowed' => 'Action not allowed',
        'edit_aria' => 'Edit :title',
        'delete_aria' => 'Delete :title',
        'filter_category_label' => 'Category',
        'filter_tag_label' => 'Tag',
        'filter_all_categories' => 'All categories',
        'filter_all_tags' => 'All tags',
        'delete_heading' => 'Delete post',
        'delete_body' => 'Are you sure you want to delete ":title"? You can restore it later from the deleted posts section.',
        'delete_confirm' => 'Delete :title',
        'cancel' => 'Cancel',
        'trashed_heading' => 'Deleted posts',
        'trashed_column_deleted' => 'Deleted on',
        'restore_aria' => 'Restore :title',
        'restore_not_allowed' => 'Restoring a post requires permission to edit posts',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'scheduled' => 'Scheduled',
    ],

    'editor' => [
        'title_create' => 'New post',
        'title_edit' => 'Edit post',
    ],

];
