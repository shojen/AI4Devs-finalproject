<?php

namespace App\Enums;

/**
 * The two dashboard/notification locales this app offers -- story 0068 (B1, ownership moved
 * here from story 0066 to break the 0066 <-> 0068 circular dependency; the shape is exactly the
 * one 0066 originally specified). TitleCase keys, lowercase backing values, per naming.md.
 *
 * `label()` (story 0067, the second consumer) returns each locale's endonym -- the language's own
 * name -- and is deliberately NOT translated through `__()`: a switcher must stay recognisable to
 * someone reading the wrong language.
 *
 * Deliberately no `default()` method -- the default is resolved through App\Models\LocaleSetting's
 * accessors (0066's D-6), never forked onto the enum itself as a second source of truth.
 */
enum UiLocale: string
{
    case English = 'en';
    case Spanish = 'es';

    /**
     * The language's own name, identical in every interface locale.
     */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Spanish => 'Español',
        };
    }
}
