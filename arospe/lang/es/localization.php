<?php

// La historia 0067 es dueña del grupo `switcher.*` (el selector de idioma de la interfaz). La
// historia 0069 es dueña de `settings.*` (la sección de valores predeterminados del panel en la
// pantalla Idiomas de la tienda) y de `attributes` (los nombres camelCase de las dos propiedades
// que valida App\Concerns\LocaleSettingValidationRules).
return [
    'switcher' => [
        'heading' => 'Idioma',
    ],

    'settings' => [
        'heading' => 'Valores predeterminados del panel',
        'description' => 'Valores predeterminados de todo el sistema. Se aplican a cada visitante del panel que no haya elegido su propio idioma y a la propia página de inicio de sesión. No son tu preferencia personal.',
        'default_ui_locale_label' => 'Idioma predeterminado del panel',
        'default_notification_locale_label' => 'Idioma predeterminado de los correos de notificación',
        'default_notification_locale_description' => 'El idioma en el que se envían las notificaciones a un destinatario que no ha elegido el suyo.',
        'save' => 'Guardar',
        'saved' => 'Valor predeterminado guardado.',
        'action_not_allowed' => 'Acción no permitida',
    ],

    'attributes' => [
        'defaultUiLocale' => 'idioma predeterminado del panel',
        'defaultNotificationLocale' => 'idioma predeterminado de los correos de notificación',
    ],
];
