<?php

// Story 0068 (D8, D17) -- the Store Languages removal-guard extension point.
//
// This app-owned config file follows config/modules.php's / config/html-sanitizer.php's two hard
// rules (docs/conventions/base-standards.md):
//   - NO closures anywhere -- `php artisan config:cache` serialises the merged config with
//     var_export(), which cannot represent a Closure (or an arbitrary object).
//   - This is a REGISTRY a later story extends by appending DATA, never behavior. Story 0070+
//     completes App\Models\StoreLanguage::translationUsageCount()'s removal warning by appending
//     one {table, column} literal here -- no edit to that method, to RemoveStoreLanguage, or to
//     any component.
//
// The bundled ISO 639-1 language list itself does NOT live here -- a ~184-entry data fixture is
// not a registry entry; it lives at database/data/iso-639-languages.json (D17).
return [

    /*
    |--------------------------------------------------------------------------
    | Translation relations
    |--------------------------------------------------------------------------
    |
    | Every table/column pair a store language may be referenced from, summed by
    | App\Models\StoreLanguage::translationUsageCount() to advise (never block) the removal
    | confirmation story 0069 renders. Shipped empty: no translatable content table exists yet.
    |
    | @var array<int, array{table: string, column: string}>
    |
    */

    'translation_relations' => [],

];
