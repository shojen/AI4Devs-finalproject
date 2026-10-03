<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain error messages
    |--------------------------------------------------------------------------
    |
    | Copy for App\Actions\StoreLanguages\AddStoreLanguage's refusals (keyed `code`) and
    | App\Actions\StoreLanguages\RemoveStoreLanguage's / SetDefaultStoreLanguage's refusals
    | (keyed `languageId`) -- story 0068. UI copy for the picker/removal-confirmation screen
    | itself is story 0069's.
    |
    */

    'errors' => [
        'code_not_in_fixture' => 'The selected language code is not part of the bundled language list.',
        'code_already_active' => 'This language is already active in the catalog.',
        'already_inactive' => 'This language has already been removed.',
        'cannot_remove_default' => 'The store default language must be reassigned to another active language before it can be removed.',
        'cannot_remove_last_active_language' => 'The store must always have at least one active language.',
        'default_must_be_active' => 'Only an active store language can be marked as the store default.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation attribute names
    |--------------------------------------------------------------------------
    |
    | Used as the third argument to the Validator::make() call in
    | App\Actions\StoreLanguages\AddStoreLanguage, so a rejected field is named in plain language
    | rather than as the raw parameter name.
    |
    */

    'attributes' => [
        'code' => 'language code',
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Languages screen copy
    |--------------------------------------------------------------------------
    |
    | UI copy for App\Livewire\StoreLanguages\Index's content languages section -- story 0069.
    | The dashboard defaults section's copy lives in lang/{locale}/localization.php.
    |
    */

    'index' => [
        'heading' => 'Store languages',
        'section_heading' => 'Content languages',
        'section_description' => 'The languages your store content (products, categories, pages) can be written in. This is separate from the language of this dashboard.',
        'add_button' => 'Add language',
        'add_modal_heading' => 'Add a content language',
        'add_modal_description' => 'Pick a language from the list. A language you removed earlier can be added again.',
        'search_placeholder' => 'Search languages',
        'default_badge' => 'Default',
        'set_default_label' => 'Set :name as default',
        'remove_label' => 'Remove :name',
        'column_language' => 'Language',
        'column_actions' => 'Actions',
        'empty' => 'No content languages yet.',
        'action_not_allowed' => 'Action not allowed',
        'already_default_tooltip' => 'Already the default',
        'remove_default_tooltip' => 'Set another language as default first',
        'remove_last_language_tooltip' => 'Add another language before removing this one',
        'remove_modal_heading' => 'Remove :name?',
        'remove_modal_body' => 'Removing a language deactivates it. It can be re-added later, and any existing content stays intact.',
        'remove_usage' => ':count translation uses this language.|:count translations use this language.',
        'remove_replacement_label' => 'New default language',
        'remove_replacement_placeholder' => 'Choose a replacement',
        'remove_replacement_description' => 'This is the store default. Choose the language that becomes the default instead.',
        'remove_last_language_notice' => 'This is the only active language. Add another language before removing it.',
        'confirm_remove' => 'Remove language',
        'cancel' => 'Cancel',
    ],

];
