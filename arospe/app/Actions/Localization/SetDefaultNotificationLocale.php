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
 */
class SetDefaultNotificationLocale
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    public function __invoke(UiLocale $locale): LocaleSetting
    {
        $this->logRefusedPrivilegedAttempt->authorize('update', LocaleSetting::class);

        $settings = LocaleSetting::query()->find(LocaleSetting::SINGLETON_ID);

        if ($settings === null) {
            $settings = LocaleSetting::forceCreate([
                'id' => LocaleSetting::SINGLETON_ID,
                'default_ui_locale' => LocaleSetting::defaultUiLocale()->value,
                'default_notification_locale' => $locale->value,
            ]);
        } else {
            $settings->forceFill(['default_notification_locale' => $locale->value])->save();
        }

        Log::info('Default notification language changed', [
            'actor_id' => Auth::id(),
            'default_notification_locale' => $locale->value,
        ]);

        return $settings->refresh();
    }
}
