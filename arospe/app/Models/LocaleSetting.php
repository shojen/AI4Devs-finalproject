<?php

namespace App\Models;

use App\Enums\UiLocale;
use Database\Factories\LocaleSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The app's single default-locale settings row -- the admin-configurable default dashboard
 * language and default notification-email language, both constrained to App\Enums\UiLocale
 * (story 0068, D18). A singleton over a fixed literal primary key, deliberately distinct from
 * `store_languages` (a different value domain, a different cardinality, and a different i18n
 * layer -- PRD assumption 14) and from `users.ui_locale` (story 0066's own per-user preference).
 *
 * @property int $id
 * @property string $default_ui_locale plain string, NOT enum-cast -- D21
 * @property string $default_notification_locale plain string, NOT enum-cast -- D21
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class LocaleSetting extends Model
{
    /** @use HasFactory<LocaleSettingFactory> */
    use HasFactory;

    /**
     * The row's one and only id (D19, D20) -- a fixed `TINYINT UNSIGNED` literal, never
     * auto-increment. `current()` is the one resolution path; a second row can only ever appear
     * via a deliberate `forceCreate(['id' => 2, ...])` bypass.
     */
    public const SINGLETON_ID = 1;

    /**
     * The primary key is a fixed literal, not a generated one.
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Fetch the singleton settings row. The one sanctioned resolution path (D20) --
     * `findOrFail(SINGLETON_ID)`, never an arbitrary lookup. Every approved caller only ever
     * invokes this once the row is known to exist (after the seeder, or after either write action
     * has firstOrCreate/upserted it), so the `OrFail` half is a fail-loud backstop rather than a
     * reachable branch in normal operation -- `defaultUiLocale()` / `defaultNotificationLocale()`
     * below use a plain `find()` instead precisely because THEY must tolerate a missing row.
     */
    public static function current(): self
    {
        return self::query()->findOrFail(self::SINGLETON_ID);
    }

    /**
     * The store's default dashboard language -- the ONLY sanctioned way to read it. Resolves
     * through a three-tier chain, in order: the persisted admin choice, `config('app.locale')`
     * mapped through UiLocale, then UiLocale::English as a last resort. Both the persisted and
     * the config tier use `tryFrom()`, NEVER `from()` -- this runs on the fallback branch of
     * every web request, including every guest request, so a stored or configured value outside
     * the enum must fall through rather than raise `\ValueError` and 500 the whole application
     * (D21, and 0066's own D-5 hazard reappearing one layer up).
     */
    public static function defaultUiLocale(): UiLocale
    {
        $stored = self::query()->find(self::SINGLETON_ID)?->default_ui_locale;

        return UiLocale::tryFrom((string) $stored)
            ?? UiLocale::tryFrom((string) config('app.locale'))
            ?? UiLocale::English;
    }

    /**
     * The store's default notification-email language -- reads only its own column, never
     * `default_ui_locale`. Same three-tier resolution chain as defaultUiLocale().
     */
    public static function defaultNotificationLocale(): UiLocale
    {
        $stored = self::query()->find(self::SINGLETON_ID)?->default_notification_locale;

        return UiLocale::tryFrom((string) $stored)
            ?? UiLocale::tryFrom((string) config('app.locale'))
            ?? UiLocale::English;
    }
}
