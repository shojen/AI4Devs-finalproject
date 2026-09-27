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

];
