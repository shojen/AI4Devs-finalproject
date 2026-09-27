<?php

namespace Database\Seeders;

use App\Models\StoreLanguage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The Spanish bootstrap (D2, D4) -- a ONE-TIME bootstrap, not a repeatable resync like
 * SalesRegionSeeder. This table is seeder-owned only until the first row exists; after that it
 * is entirely administrator-managed, so this seeder only ever writes into an EMPTY catalog and
 * never touches an administrator's subsequent choices (D2's own "a repeatable repair-the-default
 * branch would be a defect here, not a feature").
 */
class StoreLanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * The Spanish endonym is read from the bundled fixture (App\Models\StoreLanguage::
     * availableLanguages()) rather than hardcoded, so a fixture correction reaches a fresh
     * install automatically -- and a missing/malformed fixture fails this seed loudly rather
     * than seeding an unnamed entry, exactly like availableLanguages()'s own shape guard.
     */
    public function run(): void
    {
        if (StoreLanguage::query()->doesntExist()) {
            $languages = StoreLanguage::availableLanguages();

            throw_if(
                ! array_key_exists('es', $languages),
                RuntimeException::class,
                'The ISO 639-1 fixture is missing the [es] entry; the store default has no name to seed.',
            );

            StoreLanguage::forceCreate([
                'code' => 'es',
                'name' => $languages['es'],
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        // Seeder::$command is uninitialized (null) when invoked without an Artisan command in
        // context (e.g. directly from a test), matching SalesRegionSeeder's own defensive
        // nullsafe call.
        // @phpstan-ignore nullsafe.neverNull
        $this->command?->info('Store Language catalog seeded: '.StoreLanguage::count().' entries.');
    }
}
