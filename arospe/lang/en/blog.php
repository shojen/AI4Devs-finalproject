<?php

// Created by story 0061, which has the lowest id of the three stories that need this file; stories
// 0062 and 0063 EXTEND it rather than create it. lang/es/blog.php must stay key-for-key identical.
return [

    'categories' => [
        'delete_blocked' => '{1} This category is used by 1 post — reassign it before deleting.'
            .'|[2,*] This category is used by :count posts — reassign them before deleting.',

        // Copy for App\Livewire\BlogCategories\Index (story 0062). Never add a key under
        // `delete_blocked` above: that message is story 0061's and is rendered verbatim.
        'index' => [
            'title' => 'Blog categories',
            'new' => 'New category',
            'empty' => 'No blog categories have been created yet.',
            'column_name' => 'Name',
            'column_posts' => 'Posts',
            'column_actions' => 'Actions',
            'action_not_allowed' => 'Action not allowed',
            'edit_aria' => 'Edit :name',
            'delete_aria' => 'Delete :name',
            'create_heading' => 'Create category',
            'edit_heading' => 'Edit category',
            'name_label' => 'Name',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'delete_heading' => 'Delete category',
            'delete_body' => 'Are you sure you want to delete ":name"? This cannot be undone.',
            'delete_confirm' => 'Delete :name',
        ],
    ],

];
