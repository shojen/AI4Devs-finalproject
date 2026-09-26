<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pantallas de entradas del blog
    |--------------------------------------------------------------------------
    |
    | Textos de App\Livewire\BlogPosts\Index (historia 0063), usados por
    | resources/views/livewire/blog-posts.blade.php, y del editor de entradas
    | (App\Livewire\BlogPosts\Editor), que añade sus propias claves `editor.*`
    | a este archivo. Mantener este archivo con exactamente las mismas claves
    | que lang/en/blog-posts.php.
    |
    */

    'index' => [
        'title' => 'Entradas del blog',
        'new' => 'Nuevo artículo',
        'empty' => 'Todavía no se ha creado ninguna entrada del blog.',
        'empty_filtered' => 'Ninguna entrada coincide con los filtros seleccionados.',
        'column_title' => 'Título',
        'column_category' => 'Categoría',
        'column_status' => 'Estado',
        'column_date' => 'Fecha',
        'column_actions' => 'Acciones',
        'action_not_allowed' => 'Acción no permitida',
        'edit_aria' => 'Editar :title',
        'delete_aria' => 'Eliminar :title',
        'filter_category_label' => 'Categoría',
        'filter_tag_label' => 'Etiqueta',
        'filter_all_categories' => 'Todas las categorías',
        'filter_all_tags' => 'Todas las etiquetas',
        'delete_heading' => 'Eliminar entrada',
        'delete_body' => '¿Seguro que quieres eliminar ":title"? Podrás restaurarla más tarde desde la sección de entradas eliminadas.',
        'delete_confirm' => 'Eliminar :title',
        'cancel' => 'Cancelar',
        'trashed_heading' => 'Entradas eliminadas',
        'trashed_column_deleted' => 'Eliminada el',
        'restore_aria' => 'Restaurar :title',
        'restore_not_allowed' => 'Restaurar una entrada requiere permiso para editar entradas',
    ],

    'statuses' => [
        'draft' => 'Borrador',
        'published' => 'Publicado',
        'scheduled' => 'Programado',
    ],

    'editor' => [
        'title_create' => 'Nuevo artículo',
        'title_edit' => 'Editar entrada',
    ],

];
