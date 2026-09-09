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
    | No `zones.delete_blocked` key here -- deliberately deferred to story
    | 0036, which owns the in-use-by-a-rate-rule count guard (D-1). A
    | `:count`-bearing string whose wording no product owner has approved is
    | dead copy in two locales until then.
    |
    | `index.*`/`editor.*` added by story 0034 (the zone list/create/rename/
    | delete/geography-assignment screen). Still no `zones.delete_blocked`
    | key (D-6): DeleteShippingZone raises no ValidationException today, and
    | this screen renders whatever message a future guard raises without
    | needing a key of its own.
    |
    */

    'carriers' => [
        'statuses' => [
            'active' => 'Active',
            'inactive' => 'Inactive',
        ],

        'index' => [
            'heading' => 'Shipping carriers',
        ],
    ],

    'zones' => [
        'fields' => [
            'name' => 'Name',
        ],

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
