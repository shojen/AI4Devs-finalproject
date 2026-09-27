<?php

namespace App\Enums;

/**
 * The two dashboard/notification locales this app offers -- story 0068 (B1, ownership moved
 * here from story 0066 to break the 0066 <-> 0068 circular dependency; the shape is exactly the
 * one 0066 originally specified). TitleCase keys, lowercase backing values, per naming.md.
 *
 * Deliberately no `label()` method -- no rendering site exists yet, and this repo adds `label()`
 * only when a second consumer appears (the SalesRegionKind precedent); story 0067 adds one if its
 * switcher needs it.
 *
 * Deliberately no `default()` method -- the default is resolved through App\Models\LocaleSetting's
 * accessors (0066's D-6), never forked onto the enum itself as a second source of truth.
 */
enum UiLocale: string
{
    case English = 'en';
    case Spanish = 'es';
}
