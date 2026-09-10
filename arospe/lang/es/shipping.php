<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zonas de envío (historia 0033) y transportistas (historia 0035)
    |--------------------------------------------------------------------------
    |
    | Este archivo lo creó originalmente la historia 0033 con solo el grupo
    | `zones.*` que necesitaba, antes de que aterrizara la historia 0035
    | (transportistas de envío) -- según la Parallel Agent File-Ownership
    | Rule de contracts.md, 0033 y 0035 nunca se implementaron de forma
    | concurrente. La historia 0035 añade ahora su propio grupo de nivel
    | superior `carriers` junto a `zones` más abajo.
    |
    | `index.*`/`editor.*` añadidos por la historia 0034 (la pantalla de
    | listado/creación/renombrado/borrado y asignación de geografía de las
    | zonas); renderiza el mensaje que lance el guard de abajo sin necesitar
    | una clave propia.
    |
    | `zones.delete_blocked` añadida por la historia 0036 (D-5/R-6): el
    | mensaje del guard de bloqueo por uso en una regla de tarifa, en la
    | forma SIMPLE `singular|plural` de trans_choice() -- la convención
    | documentada en naming.md (la propia `categories.delete_blocked` de
    | lang/en/products.php), no la forma de rango explícito `{1}`/`[2,*]`.
    | Corrección propia de R-6: este contador nunca es cero (el guard solo
    | se dispara una vez que el contador ya es positivo), así que la forma
    | de rango explícito no aporta nada aquí.
    |
    */

    'carriers' => [
        'statuses' => [
            'active' => 'Activo',
            'inactive' => 'Inactivo',
        ],

        'index' => [
            'heading' => 'Transportistas',
        ],
    ],

    'zones' => [
        'fields' => [
            'name' => 'Nombre',
        ],

        // D-5/R-6: mensaje del guard de bloqueo por uso en una regla de tarifa
        // (App\Actions\Shipping\DeleteShippingZone). Forma simple
        // singular|plural, igual que la propia `categories.delete_blocked` de
        // lang/en/products.php -- este contador nunca es cero, así que la
        // forma de rango explícito no aporta nada aquí. Redactada a mano, no
        // traducida mecánicamente del inglés (R-6).
        'delete_blocked' => 'Esta zona está siendo usada por :count tarifa de envío y no se puede eliminar.'
            .'|Esta zona está siendo usada por :count tarifas de envío y no se puede eliminar.',

        'index' => [
            'heading' => 'Zonas de envío',
            'new_zone' => 'Nueva zona',
            'column_name' => 'Nombre',
            'column_coverage' => 'Cobertura',
            'column_actions' => 'Acciones',
            // Solo se llama con un total positivo -- una zona sin cobertura muestra
            // 'coverage_empty' en su lugar, de forma neutral y no como una advertencia (D-8).
            'coverage_count' => ':count entrada|:count entradas',
            'coverage_empty' => '—',
            'empty' => 'Todavía no hay zonas de envío. Crea la primera para empezar.',
            'action_not_allowed' => 'Acción no permitida',
            'edit_zone' => 'Editar :name',
            'delete_zone' => 'Eliminar :name',
            'delete_confirm_title' => 'Eliminar zona de envío',
            'delete_confirm_text' => '¿Seguro que quieres eliminar ":name"? Esta acción no se puede deshacer.',
        ],

        'editor' => [
            'create_title' => 'Crear zona de envío',
            'edit_title' => 'Editar zona de envío',
            'name_label' => 'Nombre',
            'geography_label' => 'Cobertura geográfica',
            // D-12: se muestra cuando SearchGeographyEntries::resolveSelected() no puede
            // verificar alguno de los ids enviados -- el guardado se rechaza por completo,
            // nunca se guarda un subconjunto parcial.
            'geography_unresolvable' => 'Una o varias de las entradas geográficas seleccionadas no se pudieron verificar. Revisa tu selección e inténtalo de nuevo.',
            // D-3: el resumen de cobertura por nivel, de solo lectura, junto al área de chips
            // acotada. 'coverage_summary_item' compone un segmento "<etiqueta> <total>" por
            // nivel (p. ej. "País 1"), unidos con ' · ' en la vista -- nunca una clave por nivel.
            'coverage_summary_empty' => 'Esta zona todavía no cubre ninguna entrada geográfica.',
            'coverage_summary_item' => ':label :count',
            'coverage_summary_total' => ':count entrada seleccionada en total.|:count entradas seleccionadas en total.',
        ],
    ],

];
