<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pantalla de gestión de etiquetas del blog
    |--------------------------------------------------------------------------
    |
    | Textos de App\Livewire\BlogTags\Index (historia 0060), usados por
    | resources/views/livewire/blog-tags.blade.php. Mantener este archivo con
    | exactamente las mismas claves que lang/en/blog-tags.php.
    |
    | No hay textos de "borrado bloqueado" ni de recuento de uso: borrar una
    | etiqueta es incondicional (D-2), así que no existe ese estado.
    |
    */

    'index' => [
        'title' => 'Etiquetas del blog',
        'new' => 'Nueva etiqueta',
        'empty' => 'Todavía no se ha creado ninguna etiqueta del blog.',
        'column_name' => 'Nombre',
        'column_actions' => 'Acciones',
        'action_not_allowed' => 'Acción no permitida',
        'edit_aria' => 'Editar :name',
        'delete_aria' => 'Eliminar :name',
        'create_heading' => 'Crear etiqueta',
        'edit_heading' => 'Editar etiqueta',
        'name_label' => 'Nombre',
        'save' => 'Guardar',
        'cancel' => 'Cancelar',
        'delete_heading' => 'Eliminar etiqueta',
        'delete_body' => '¿Seguro que quieres eliminar ":name"? La etiqueta se quitará de cualquier entrada que la use. Esta acción no se puede deshacer.',
        'delete_confirm' => 'Eliminar :name',
    ],

];
