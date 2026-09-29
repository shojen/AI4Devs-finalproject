<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Blog Tags Management Screen
    |--------------------------------------------------------------------------
    |
    | Copy for App\Livewire\BlogTags\Index (story 0060), consumed by
    | resources/views/livewire/blog-tags.blade.php. Keep this file key-for-key
    | identical to lang/es/blog-tags.php.
    |
    | There is deliberately no "delete blocked" or usage-count copy: deleting a
    | tag is unconditional (D-2), so no such state exists to describe.
    |
    */

    'index' => [
        'title' => 'Blog tags',
        'new' => 'New tag',
        'empty' => 'No blog tags have been created yet.',
        'column_name' => 'Name',
        'column_actions' => 'Actions',
        'action_not_allowed' => 'Action not allowed',
        'edit_aria' => 'Edit :name',
        'delete_aria' => 'Delete :name',
        'create_heading' => 'Create tag',
        'edit_heading' => 'Edit tag',
        'name_label' => 'Name',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete_heading' => 'Delete tag',
        'delete_body' => 'Are you sure you want to delete ":name"? The tag will be removed from any post that uses it. This cannot be undone.',
        'delete_confirm' => 'Delete :name',
    ],

];
