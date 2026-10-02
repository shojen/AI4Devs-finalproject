<?php

// Story 0067 owns the `switcher.*` group (the chrome interface-language switcher); `attributes`
// and `settings.*` stay reserved for story 0069.
//
// Story 0068 -- reserved for the default-locale settings area. The `attributes` block this file
// originally carried (for the two camelCase field names `defaultUiLocale`/`defaultNotificationLocale`)
// existed solely to serve App\Concerns\LocaleSettingValidationRules's two rule methods, which
// nothing in this story called (both App\Actions\Localization\SetDefaultUiLocale and
// SetDefaultNotificationLocale take an already-type-safe UiLocale parameter, never a raw string
// needing validation). Phase 5 code review finding B2 deleted that trait as a Larastan
// `trait.unused` violation this project's CI fails the build on -- ownership of the validation
// concern (and the attributes this file will then carry) moves to story 0069, whose settings form
// is its real, calling consumer. Left as an empty, valid lang file rather than deleted outright, so
// `lang/es/localization.php` stays its key-for-key twin and 0069 has a file to populate rather than
// create.
return [
    'switcher' => [
        'heading' => 'Language',
    ],
];
