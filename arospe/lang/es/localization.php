<?php

// Historia 0068 -- reservado para el área de ajustes de idioma predeterminado. El bloque
// `attributes` que este archivo llevaba originalmente (para los dos nombres de campo en camelCase
// `defaultUiLocale`/`defaultNotificationLocale`) existía solo para App\Concerns\
// LocaleSettingValidationRules, cuyos dos métodos de reglas nada en esta historia llamaba (tanto
// App\Actions\Localization\SetDefaultUiLocale como SetDefaultNotificationLocale reciben un
// parámetro UiLocale ya validado por tipo, nunca una cadena en bruto que necesite validación). La
// revisión de código de la Fase 5 (hallazgo B2) eliminó ese trait por ser una violación
// `trait.unused` de Larastan que el CI de este proyecto convierte en build roja -- la propiedad de
// esa validación (y de los atributos que este archivo llevará) pasa a la historia 0069, cuyo
// formulario de ajustes es su consumidor real. Se deja como archivo de idioma vacío pero válido en
// vez de eliminarlo, para que siga siendo el gemelo exacto de `lang/en/localization.php` y la
// historia 0069 tenga un archivo que rellenar en vez de crear uno nuevo.
return [
    //
];
