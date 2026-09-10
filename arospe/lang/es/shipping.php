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
    | `carriers.index.*` más allá de `heading`, y todo el grupo `rates`,
    | añadidos por la historia 0037 (el marcado real de la pantalla de
    | Envíos: tarjetas de transportista sobre una tabla de tarifas agrupada,
    | según la propia leyenda de la captura del §2.4 del PRD -- D-1).
    |
    */

    'carriers' => [
        'statuses' => [
            'active' => 'Activo',
            'inactive' => 'Inactivo',
        ],

        'index' => [
            'heading' => 'Transportistas',
            'toggle_label' => 'Activo',
            'toggle_aria' => 'Cambiar el estado activo de :name',
            'action_not_allowed' => 'Acción no permitida',
        ],

        'editor' => [
            // OQ-B (recomendada, adoptada): distingue un transportista inactivo en el
            // selector del formulario de tarifas -- sin esto, un administrador podría
            // crear una tarifa para un transportista que nunca cotizará, sin ninguna
            // señal al respecto.
            'carrier_option_inactive' => ':name (Inactivo)',
        ],
    ],

    'rates' => [
        // Hallazgo M1 de la revisión de código de la Fase 5: saveRate() valida un array de
        // datos en snake_case (el propio docblock de App\Livewire\Shipping\Index explica por
        // qué), así que sin este bloque el placeholder :attribute de Laravel humaniza el
        // nombre de la COLUMNA en bruto en lugar de la etiqueta real del campo. Coincide con
        // el propio bloque `attributes` de lang/es/sales-regions.php (la excepción
        // documentada en naming.md).
        'attributes' => [
            'name' => 'nombre',
            'shipping_carrier_id' => 'transportista',
            'shipping_zone_id' => 'zona',
            'min_weight_kg' => 'peso mínimo',
            'max_weight_kg' => 'peso máximo',
            'price' => 'precio',
            'delivery_estimate' => 'estimación de entrega',
        ],

        'index' => [
            'new_rate' => 'Nueva tarifa',
            'action_not_allowed' => 'Acción no permitida',
            'column_name' => 'Nombre',
            'column_zone' => 'Zona',
            'column_weight' => 'Peso',
            'column_price' => 'Precio',
            'column_delivery' => 'Entrega',
            'column_actions' => 'Acciones',
            // D-8: nunca un 'null' literal -- solo se muestra cuando max_weight_kg es null.
            'weight_open_ended' => ':min kg y superior',
            'weight_range' => ':min–:max kg',
            'price_format' => ':price €',
            // El estado vacío general de la tabla -- solo se muestra cuando TODOS los
            // transportistas tienen cero tarifas, nunca por transportista (un
            // transportista sin tarifas muestra 'no_rates_yet' en su lugar, su propio
            // grupo se sigue mostrando -- D-4/D-8).
            'empty' => 'Todavía no hay tarifas de envío. Crea la primera para empezar.',
            'no_rates_yet' => 'Este transportista todavía no tiene tarifas.',
            'edit_rate' => 'Editar :name',
            'delete_rate' => 'Eliminar :name',
            'delete_confirm_title' => 'Eliminar tarifa de envío',
            'delete_confirm_text' => '¿Seguro que quieres eliminar ":name"? Esta acción no se puede deshacer.',
        ],

        'editor' => [
            'create_title' => 'Crear tarifa de envío',
            'edit_title' => 'Editar tarifa de envío',
            'name_label' => 'Nombre',
            'carrier_label' => 'Transportista',
            'carrier_placeholder' => 'Selecciona un transportista',
            'zone_label' => 'Zona',
            'zone_placeholder' => 'Selecciona una zona',
            // D-10/OQ-C (adoptada): un enlace secundario junto al selector de zona,
            // para que un administrador al que le falta una zona no pierda un
            // formulario de tarifa a medio rellenar.
            'manage_zones_link' => 'Gestionar zonas de envío',
            'min_weight_label' => 'Peso mín. (kg)',
            'max_weight_label' => 'Peso máx. (kg)',
            'max_weight_help' => 'Déjalo en blanco para "y superior" -- sin límite superior.',
            'price_label' => 'Precio (€)',
            'delivery_estimate_label' => 'Estimación de entrega',
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
