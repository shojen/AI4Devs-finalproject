<?php

namespace Database\Seeders;

use App\Models\ShippingCarrier;
use Illuminate\Database\Seeder;

/**
 * Seeds the four prototype-integrated shipping carriers (PRD §2.4). Required
 * application data, like SalesRegionSeeder and GeographyCatalogSeeder --
 * called unconditionally from DatabaseSeeder and ProductionSeeder, never
 * gated behind the ['local', 'testing'] fixture allow-list.
 *
 * Two properties are load-bearing and must not be "simplified":
 *
 * - **Create-if-missing, never update.** An existing row (matched on `code`)
 *   is left entirely untouched -- `name`/`description` are administrator-
 *   configurable (Confirmed decision E) and `is_active` is this table's own
 *   toggle -- so a re-seed after go-live can never silently re-enable a
 *   carrier an administrator disabled or overwrite an edited name.
 * - **Matched on `code`, never `name`.** `code` never changes after seeding;
 *   `name` is editable display text, so a `name`-keyed match would insert a
 *   duplicate row the moment an administrator renames a carrier.
 *
 * Written with a manual lookup + `forceFill()` rather than `firstOrCreate()`
 * -- `code` is deliberately absent from ShippingCarrier's #[Fillable] list
 * (Phase 4 security-audit finding F-1: a mass-assignable `code` would let an
 * edit silently duplicate the row on the next reseed, the exact hazard
 * `App\Models\SalesRegion` already avoids by omitting its own `slug`), so
 * `firstOrCreate()`'s internal `fill()` would silently drop `code` on the
 * INSERT branch and violate the column's `NOT NULL` constraint. The same
 * `new Model + forceFill()->save()` shape `SalesRegionSeeder::writeRegion()`
 * already uses for its own non-fillable `slug`.
 *
 * All four carriers seed active (Confirmed decision B) -- the prototype
 * ships MRW disabled, but that is a scenario precondition for tests and
 * factories to arrange, not a mandate for production to ship a carrier
 * switched off for cosmetic fidelity. `is_active` is left out of the insert
 * below entirely, landing active via the column's own `->default(true)`.
 */
class ShippingCarrierSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, description: string}>
     */
    private const CARRIERS = [
        ['code' => 'SEUR', 'name' => 'SEUR', 'description' => '24h · Península y Baleares'],
        ['code' => 'CRRS', 'name' => 'Correos', 'description' => 'Nacional · puntos de recogida'],
        ['code' => 'MRW', 'name' => 'MRW', 'description' => 'Entrega urgente 24-48h'],
        ['code' => 'DHL', 'name' => 'DHL Express', 'description' => 'Internacional express'],
    ];

    public function run(): void
    {
        $existingCodes = ShippingCarrier::query()->pluck('code')->all();

        foreach (self::CARRIERS as $carrier) {
            if (in_array($carrier['code'], $existingCodes, true)) {
                continue;
            }

            (new ShippingCarrier)->forceFill([
                'code' => $carrier['code'],
                'name' => $carrier['name'],
                'description' => $carrier['description'],
            ])->save();
        }
    }
}
