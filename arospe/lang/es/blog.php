<?php

// Created by story 0061, which has the lowest id of the three stories that need this file. Story 0062
// extended it; 0063 keeps its screen copy in lang/{en,es}/blog-posts.php instead. lang/en/blog.php must stay key-for-key identical.
return [

    'categories' => [
        'delete_blocked' => '{1} Esta categoría está en uso en 1 entrada — reasígnala antes de eliminarla.'
            .'|[2,*] Esta categoría está en uso en :count entradas — reasígnalas antes de eliminarla.',

        // Copy for App\Livewire\BlogCategories\Index (story 0062). Never add a key under
        // `delete_blocked` above: that message is story 0061's and is rendered verbatim.
        'index' => [
            'title' => 'Categorías del blog',
            'new' => 'Nueva categoría',
            'empty' => 'Todavía no se ha creado ninguna categoría del blog.',
            'column_name' => 'Nombre',
            'column_posts' => 'Entradas',
            'column_actions' => 'Acciones',
            'action_not_allowed' => 'Acción no permitida',
            'edit_aria' => 'Editar :name',
            'delete_aria' => 'Eliminar :name',
            'create_heading' => 'Crear categoría',
            'edit_heading' => 'Editar categoría',
            'name_label' => 'Nombre',
            'save' => 'Guardar',
            'cancel' => 'Cancelar',
            'delete_heading' => 'Eliminar categoría',
            'delete_body' => '¿Seguro que quieres eliminar ":name"? Esta acción no se puede deshacer.',
            'delete_confirm' => 'Eliminar :name',
        ],
    ],

];
