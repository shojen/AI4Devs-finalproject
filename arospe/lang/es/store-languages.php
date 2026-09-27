<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensajes de error de dominio
    |--------------------------------------------------------------------------
    |
    | Textos para los rechazos de App\Actions\StoreLanguages\AddStoreLanguage (clave `code`) y de
    | App\Actions\StoreLanguages\RemoveStoreLanguage / SetDefaultStoreLanguage (clave
    | `languageId`) -- historia 0068. El texto de la pantalla del selector/confirmación de
    | eliminación es de la historia 0069.
    |
    */

    'errors' => [
        'code_not_in_fixture' => 'El código de idioma seleccionado no forma parte de la lista de idiomas incluida.',
        'code_already_active' => 'Este idioma ya está activo en el catálogo.',
        'cannot_remove_default' => 'El idioma predeterminado de la tienda debe reasignarse a otro idioma activo antes de poder eliminarlo.',
        'cannot_remove_last_active_language' => 'La tienda debe tener siempre al menos un idioma activo.',
        'default_must_be_active' => 'Solo un idioma de la tienda activo puede marcarse como predeterminado.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres de atributos de validación
    |--------------------------------------------------------------------------
    |
    | Usados como tercer argumento de la llamada a Validator::make() en
    | App\Actions\StoreLanguages\AddStoreLanguage, para que un campo rechazado se nombre en
    | lenguaje natural en vez de con el nombre del parámetro en bruto.
    |
    */

    'attributes' => [
        'code' => 'código de idioma',
    ],

];
