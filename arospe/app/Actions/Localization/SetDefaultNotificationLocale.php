<?php

namespace App\Actions\Localization;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Change the store's default notification-email language -- SetDefaultUiLocale's sibling, same
 * shape (D23). Consumed by story 0066's User::preferredLocale() (D-14) as the fallback below an
 * administrator's own per-user preference.
 *
 * Phase 4 finding L1 (Low, CWE-362/CWE-755) -- see SetDefaultUiLocale's own docblock for the full
 * explanation: an atomic upsert closes the race on the FIRST write to the unseeded singleton row,
 * and `update` names only this action's own column so D23's no-lost-update property holds even
 * when both actions' upserts land concurrently against the same not-yet-existing row.
 */
class SetDefaultNotificationLocale
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
                'default_ui_locale' => LocaleSetting::defaultUiLocale()->value,
                'default_notification_locale' => $locale->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['id'],
            update: ['default_notification_locale', 'updated_at'],
        );

        $settings = LocaleSetting::query()->findOrFail(LocaleSetting::SINGLETON_ID);

        Log::info('Default notification language changed', [
            'actor_id' => Auth::id(),
            'default_notification_locale' => $locale->value,
        ]);

        return $settings;
    }
}
