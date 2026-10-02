<?php

// Story 0067 owns the `switcher.*` group (the chrome interface-language switcher). Story 0069 owns
// `settings.*` (the Store Languages screen's dashboard defaults section) and `attributes` (the two
// camelCase property names App\Concerns\LocaleSettingValidationRules validates).
return [
    'switcher' => [
        'heading' => 'Language',
    ],

    'settings' => [
        'heading' => 'Dashboard defaults',
        'description' => 'System-wide defaults. They apply to every dashboard visitor who has not chosen their own language, and to the sign-in page itself. They are not your personal preference.',
        'default_ui_locale_label' => 'Default dashboard language',
        'default_notification_locale_label' => 'Default notification email language',
        'default_notification_locale_description' => 'The language notifications are sent in to a recipient who has not chosen their own.',
        'save' => 'Save',
        'saved' => 'Default saved.',
        'action_not_allowed' => 'Action not allowed',
    ],

    'attributes' => [
        'defaultUiLocale' => 'default dashboard language',
        'defaultNotificationLocale' => 'default notification email language',
    ],
];
