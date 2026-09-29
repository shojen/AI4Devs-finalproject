<?php

namespace Database\Seeders;

use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A one-time bootstrap from config('app.locale') (D22), the same ONE-TIME shape as
 * StoreLanguageSeeder rather than a repeatable resync -- a repeatable "repair the default" branch
 * would silently overwrite an administrator's deliberate choice on every deploy. A separate class
 * from StoreLanguageSeeder even though both ship together: one-seeder-per-table is this repo's
 * convention, and these are two unrelated domain concepts that merely share an install step.
 */
class LocaleSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * When config('app.locale') is not a UiLocale case (APP_LOCALE=fr is a legal deployment
     * value), this seed THROWS rather than silently picking one -- the fail-loud posture this
     * project's seeders share, since a silently-chosen default would ship a persisted "default"
     * that contradicts the app's own configuration with nothing to surface it.
     */
    public function run(): void
    {
        if (LocaleSetting::query()->doesntExist()) {
            $locale = UiLocale::tryFrom((string) config('app.locale'));

            throw_if(
                $locale === null,
                RuntimeException::class,
                'config(app.locale) ['.config('app.locale').'] is not one of the offered UiLocale values; the locale settings bootstrap has no default to seed.',
            );

            LocaleSetting::forceCreate([
                'id' => LocaleSetting::SINGLETON_ID,
                'default_ui_locale' => $locale->value,
                'default_notification_locale' => $locale->value,
            ]);
        }

        // Seeder::$command is uninitialized (null) when invoked without an Artisan command in
        // context (e.g. directly from a test), matching SalesRegionSeeder's own defensive
        // nullsafe call.
        // @phpstan-ignore nullsafe.neverNull
        $this->command?->info('Locale settings seeded: '.LocaleSetting::count().' row(s).');
    }
}
