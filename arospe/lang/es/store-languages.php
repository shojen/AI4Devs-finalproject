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
        'already_inactive' => 'Este idioma ya se ha eliminado previamente.',
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

    /*
    |--------------------------------------------------------------------------
    | Textos de la pantalla Idiomas de la tienda
    |--------------------------------------------------------------------------
    |
    | Textos de la sección de idiomas de contenido de App\Livewire\StoreLanguages\Index -- historia
    | 0069. Los textos de la sección de valores predeterminados del panel están en
    | lang/{locale}/localization.php.
    |
    */

    'index' => [
        'heading' => 'Idiomas de la tienda',
        'section_heading' => 'Idiomas de contenido',
        'section_description' => 'Los idiomas en los que se puede escribir el contenido de tu tienda (productos, categorías, páginas). Es independiente del idioma de este panel.',
        'add_button' => 'Añadir idioma',
        'add_modal_heading' => 'Añadir un idioma de contenido',
        'add_modal_description' => 'Elige un idioma de la lista. Un idioma eliminado antes puede volver a añadirse.',
        'search_placeholder' => 'Buscar idiomas',
        'default_badge' => 'Predeterminado',
        'set_default_label' => 'Establecer :name como predeterminado',
        'remove_label' => 'Eliminar :name',
        'column_language' => 'Idioma',
        'column_actions' => 'Acciones',
        'empty' => 'Todavía no hay idiomas de contenido.',
        'action_not_allowed' => 'Acción no permitida',
        'already_default_tooltip' => 'Ya es el idioma predeterminado',
        'remove_default_tooltip' => 'Establece antes otro idioma como predeterminado',
        'remove_last_language_tooltip' => 'Añade otro idioma antes de eliminar este',
        'remove_modal_heading' => '¿Eliminar :name?',
        'remove_modal_body' => 'Eliminar un idioma lo desactiva. Se puede volver a añadir más adelante y el contenido existente se conserva.',
        'remove_usage' => ':count traducción usa este idioma.|:count traducciones usan este idioma.',
        'remove_replacement_label' => 'Nuevo idioma predeterminado',
        'remove_replacement_placeholder' => 'Elige un sustituto',
        'remove_replacement_description' => 'Este es el idioma predeterminado de la tienda. Elige el idioma que pasará a serlo.',
        'remove_last_language_notice' => 'Es el único idioma activo. Añade otro idioma antes de eliminarlo.',
        'confirm_remove' => 'Eliminar idioma',
        'cancel' => 'Cancelar',
    ],

];
