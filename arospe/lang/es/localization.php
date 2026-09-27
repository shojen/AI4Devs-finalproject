<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nombres de atributos de validación
    |--------------------------------------------------------------------------
    |
    | Los dos nombres de campo en camelCase que valida App\Concerns\LocaleSettingValidationRules
    | -- usados por el formulario de ajustes de la historia 0069. Todavía no existe texto de
    | rechazo de dominio: App\Actions\Localization\SetDefaultUiLocale y
    | SetDefaultNotificationLocale reciben un parámetro UiLocale ya validado y solo lanzan
    | AuthorizationException, nunca una ValidationException propia (historia 0068).
    |
    */

    'attributes' => [
        'defaultUiLocale' => 'idioma predeterminado del panel',
        'defaultNotificationLocale' => 'idioma predeterminado de las notificaciones',
    ],

];
