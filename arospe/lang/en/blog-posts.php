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
        'trashed_truncated' => 'Showing the :shown most recently deleted posts of :total.',
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
        'title_label' => 'Title',
        'category_label' => 'Category',
        'category_placeholder' => 'Select a category',
        'no_categories_heading' => 'There are no blog categories yet',
        'no_categories_text' => 'Every post belongs to a category. Create one before writing a post.',
        'no_categories_link' => 'Go to blog categories',
        'status_label' => 'Status',
        'published_at_label' => 'Publication date',
        'published_at_hint' => 'Date and time in UTC.',
        'body_label' => 'Body',
        'tags_label' => 'Tags',
        'tags_hint' => 'Type a tag and press Enter. Existing tags are suggested as you type.',
        'tag_input_placeholder' => 'Add a tag',
        'tag_add' => 'Add tag',
        'tag_create_not_allowed' => 'Creating a new tag requires permission to create tags',
        'tag_remove_aria' => 'Remove tag :name',
        'tag_suggestions_label' => 'Suggested tags',
        'tags_limit' => 'A post can have at most :max tags.',
        'tag_too_long' => 'A tag name can have at most :max characters.',
        'save' => 'Save',
        'cancel' => 'Cancel',
    ],

];
