<?php

namespace App\Actions\Localization;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Change the store's default dashboard language (story 0068, D23 -- a narrow single-column
 * action, never a two-locale action, so a caller changing one default cannot silently clobber a
 * concurrent change to the other via a read-modify-write). Authorizes against the class itself
 * (there is only ever one settings row) and writes with an atomic upsert since neither column is
 * fillable. No DB::transaction() -- one column, one row, no multi-row invariant to protect
 * (unlike store_languages' default swap).
 *
 * Phase 4 finding L1 (Low, CWE-362/CWE-755): the original find()-then-forceCreate() shape had a
 * race on the FIRST write to an unseeded table -- two concurrent first-writes both see no row,
 * both attempt to insert id=SINGLETON_ID, and the loser surfaced a raw
 * UniqueConstraintViolationException (a 500) instead of a clean write. A single
 * `INSERT ... ON DUPLICATE KEY UPDATE` (Laravel's upsert()) closes the read-then-write gap
 * entirely: MySQL resolves the conflict atomically at the row level, so the two concurrent calls
 * simply serialise against the same row rather than racing to insert it. `update` names ONLY this
 * action's own column (D23's no-lost-update property): a concurrent SetDefaultNotificationLocale
 * call's own upsert names only its own column, so neither action can ever clobber the other's
 * write, on the very first row or any later one.
 */
class SetDefaultUiLocale
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    public function __invoke(UiLocale $locale): LocaleSetting
    {
        $this->logRefusedPrivilegedAttempt->authorize('update', LocaleSetting::class, targetType: 'locale_setting', targetId: LocaleSetting::SINGLETON_ID);

        $now = now();

        LocaleSetting::query()->upsert(
            [[
                'id' => LocaleSetting::SINGLETON_ID,
                'default_ui_locale' => $locale->value,
                'default_notification_locale' => LocaleSetting::defaultNotificationLocale()->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['id'],
            update: ['default_ui_locale', 'updated_at'],
        );

        $settings = LocaleSetting::query()->findOrFail(LocaleSetting::SINGLETON_ID);

        Log::info('Default dashboard language changed', [
            'actor_id' => Auth::id(),
            'default_ui_locale' => $locale->value,
        ]);

        return $settings;
    }
}
