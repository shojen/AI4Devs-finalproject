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
        'title_label' => 'Título',
        'category_label' => 'Categoría',
        'category_placeholder' => 'Selecciona una categoría',
        'no_categories_heading' => 'Todavía no hay categorías del blog',
        'no_categories_text' => 'Toda entrada pertenece a una categoría. Crea una antes de escribir una entrada.',
        'no_categories_link' => 'Ir a las categorías del blog',
        'status_label' => 'Estado',
        'published_at_label' => 'Fecha de publicación',
        'published_at_hint' => 'Fecha y hora en UTC.',
        'body_label' => 'Contenido',
        'tags_label' => 'Etiquetas',
        'tags_hint' => 'Escribe una etiqueta y pulsa Intro. Las etiquetas existentes se sugieren mientras escribes.',
        'tag_input_placeholder' => 'Añadir una etiqueta',
        'tag_add' => 'Añadir etiqueta',
        'tag_create_not_allowed' => 'Crear una etiqueta nueva requiere permiso para crear etiquetas',
        'tag_remove_aria' => 'Quitar la etiqueta :name',
        'tag_suggestions_label' => 'Etiquetas sugeridas',
        'tags_limit' => 'Una entrada puede tener como máximo :max etiquetas.',
        'tag_too_long' => 'El nombre de una etiqueta puede tener como máximo :max caracteres.',
        'save' => 'Guardar',
        'cancel' => 'Cancelar',
    ],

];
