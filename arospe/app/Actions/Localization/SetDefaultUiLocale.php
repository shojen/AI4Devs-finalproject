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
 * (there is only ever one settings row), `firstOrCreate`s the singleton if the seeder has not run
 * yet, and writes with forceFill() since neither column is fillable. No DB::transaction() --
 * one column, one row, no multi-row invariant to protect (unlike store_languages' default swap).
 */
class SetDefaultUiLocale
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
                'default_ui_locale' => $locale->value,
                'default_notification_locale' => LocaleSetting::defaultNotificationLocale()->value,
            ]);
        } else {
            $settings->forceFill(['default_ui_locale' => $locale->value])->save();
        }

        Log::info('Default dashboard language changed', [
            'actor_id' => Auth::id(),
            'default_ui_locale' => $locale->value,
        ]);

        return $settings->refresh();
    }
}
