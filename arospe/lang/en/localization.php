<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation attribute names
    |--------------------------------------------------------------------------
    |
    | The two camelCase field names App\Concerns\LocaleSettingValidationRules validates --
    | consumed by story 0069's settings form. No domain-refusal copy exists yet:
    | App\Actions\Localization\SetDefaultUiLocale and SetDefaultNotificationLocale each take an
    | already-validated UiLocale parameter and throw only AuthorizationException, never a
    | ValidationException of their own (story 0068).
    |
    */

    'attributes' => [
        'defaultUiLocale' => 'default dashboard language',
        'defaultNotificationLocale' => 'default notification language',
    ],

];
