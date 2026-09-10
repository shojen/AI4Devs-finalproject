<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shipping zones (story 0033) and carriers (story 0035)
    |--------------------------------------------------------------------------
    |
    | This file was originally created by story 0033 with only the `zones.*`
    | group it needed, ahead of story 0035 (shipping carriers) landing --
    | per contracts.md's Parallel Agent File-Ownership Rule, 0033 and 0035
    | were never implemented concurrently. Story 0035 now adds its own
    | `carriers` top-level group alongside `zones` below.
    |
    | `index.*`/`editor.*` added by story 0034 (the zone list/create/rename/
    | delete/geography-assignment screen); it renders whatever message the
    | guard below raises without needing a key of its own.
    |
    | `zones.delete_blocked` added by story 0036 (D-5/R-6): the
    | in-use-by-a-rate-rule count guard's message, in the SIMPLE
    | `singular|plural` trans_choice() form -- naming.md's documented
    | convention (lang/en/products.php's own `categories.delete_blocked`),
    | not the explicit-range `{1}`/`[2,*]` form. R-6's own correction: this
    | key's count is never zero (the guard only ever fires once the count is
    | already positive), so the explicit-range form buys nothing here.
    |
    | `carriers.index.*` beyond `heading`, and the whole `rates` group, added
    | by story 0037 (the Shipping screen's real markup: carrier cards above a
    | grouped rate table, per PRD §2.4's own screenshot caption -- D-1).
    |
    */

    'carriers' => [
        'statuses' => [
            'active' => 'Active',
            'inactive' => 'Inactive',
        ],

        'index' => [
            'heading' => 'Shipping carriers',
            'toggle_label' => 'Active',
            'toggle_aria' => 'Toggle :name active state',
            'action_not_allowed' => 'Action not allowed',
        ],

        'editor' => [
            // OQ-B (recommended, adopted): distinguishes a disabled carrier in the
            // rate form's carrier select -- otherwise an administrator can create a
            // rate for a carrier that will never quote, with no signal at all.
            'carrier_option_inactive' => ':name (Inactive)',
        ],
    ],

    'rates' => [
        // Phase 5 code-review finding M1: saveRate() validates a snake_case data array
        // (App\Livewire\Shipping\Index's own docblock explains why), so without this
        // block Laravel's :attribute placeholder humanizes the raw COLUMN name --
        // "The shipping carrier id field is required." -- instead of the field's real
        // label. Matches lang/en/sales-regions.php's own `attributes` block precedent
        // (naming.md's documented exception: a validation attributes leaf names the
        // field, not the model, even when snake_case rather than camelCase here).
        'attributes' => [
            'name' => 'name',
            'shipping_carrier_id' => 'carrier',
            'shipping_zone_id' => 'zone',
            'min_weight_kg' => 'minimum weight',
            'max_weight_kg' => 'maximum weight',
            'price' => 'price',
            'delivery_estimate' => 'delivery estimate',
        ],

        'index' => [
            'new_rate' => 'New rate',
            'action_not_allowed' => 'Action not allowed',
            'column_name' => 'Name',
            'column_zone' => 'Zone',
            'column_weight' => 'Weight',
            'column_price' => 'Price',
            'column_delivery' => 'Delivery',
            'column_actions' => 'Actions',
            // D-8: never a bare 'null' -- rendered only when max_weight_kg is null.
            'weight_open_ended' => ':min kg and above',
            'weight_range' => ':min–:max kg',
            'price_format' => ':price €',
            // The overall table empty state -- rendered only when EVERY carrier has
            // zero rates, never per carrier (a single empty carrier renders
            // 'no_rates_yet' instead, its own group still shown -- D-4/D-8).
            'empty' => 'No shipping rates yet. Create your first one to get started.',
            'no_rates_yet' => 'No rates yet for this carrier.',
            'edit_rate' => 'Edit :name',
            'delete_rate' => 'Delete :name',
            'delete_confirm_title' => 'Delete shipping rate',
            'delete_confirm_text' => 'Are you sure you want to delete ":name"? This cannot be undone.',
        ],

        'editor' => [
            'create_title' => 'Create shipping rate',
            'edit_title' => 'Edit shipping rate',
            'name_label' => 'Name',
            'carrier_label' => 'Carrier',
            'carrier_placeholder' => 'Select a carrier',
            'zone_label' => 'Zone',
            'zone_placeholder' => 'Select a zone',
            // D-10/OQ-C (adopted): a secondary link beside the zone select, so an
            // administrator missing a zone does not lose a half-filled rate form.
            'manage_zones_link' => 'Manage shipping zones',
            'min_weight_label' => 'Min. weight (kg)',
            'max_weight_label' => 'Max. weight (kg)',
            'max_weight_help' => 'Leave blank for "and above" -- no upper limit.',
            'price_label' => 'Price (€)',
            'delivery_estimate_label' => 'Delivery estimate',
        ],
    ],

    'zones' => [
        'fields' => [
            'name' => 'Name',
        ],

        // D-5/R-6: the zone-delete-blocked-by-a-rate-rule guard's message
        // (App\Actions\Shipping\DeleteShippingZone). Simple singular|plural
        // form, matching lang/en/products.php's `categories.delete_blocked`
        // -- this count is never zero, so the explicit-range form buys
        // nothing here.
        'delete_blocked' => 'This zone is used by :count shipping rate and cannot be deleted.'
            .'|This zone is used by :count shipping rates and cannot be deleted.',

        'index' => [
            'heading' => 'Shipping zones',
            'new_zone' => 'New zone',
            'column_name' => 'Name',
            'column_coverage' => 'Coverage',
            'column_actions' => 'Actions',
            // Only ever called for a positive count -- a zero-coverage zone renders
            // 'coverage_empty' instead, neutrally rather than as a warning (D-8).
            'coverage_count' => ':count entry|:count entries',
            'coverage_empty' => '—',
            'empty' => 'No shipping zones yet. Create your first one to get started.',
            'action_not_allowed' => 'Action not allowed',
            'edit_zone' => 'Edit :name',
            'delete_zone' => 'Delete :name',
            'delete_confirm_title' => 'Delete shipping zone',
            'delete_confirm_text' => 'Are you sure you want to delete ":name"? This cannot be undone.',
        ],

        'editor' => [
            'create_title' => 'Create shipping zone',
            'edit_title' => 'Edit shipping zone',
            'name_label' => 'Name',
            'geography_label' => 'Geography coverage',
            // D-12: rendered when SearchGeographyEntries::resolveSelected() cannot vouch for
            // every submitted id -- the save is rejected in full, never a partial subset.
            'geography_unresolvable' => 'One or more selected geography entries could not be verified. Please review your selection and try again.',
            // D-3: the read-only per-level coverage summary beside the bounded chip area.
            // 'coverage_summary_item' composes one "<label> <count>" segment per level
            // (e.g. "País 1"), joined with ' · ' in the view -- never one key per level.
            'coverage_summary_empty' => 'This zone covers no geography entry yet.',
            'coverage_summary_item' => ':label :count',
            'coverage_summary_total' => ':count entry selected in total.|:count entries selected in total.',
        ],
    ],

];
