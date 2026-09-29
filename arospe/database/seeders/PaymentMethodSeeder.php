<?php

namespace Database\Seeders;

use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Seeds the single bank-transfer payment method (PRD §2.5). Required
 * application data, like SalesRegionSeeder/GeographyCatalogSeeder/
 * ShippingCarrierSeeder -- called unconditionally from DatabaseSeeder and
 * ProductionSeeder, never gated behind the ['local', 'testing'] fixture
 * allow-list.
 *
 * **Create-if-missing, never update -- the single most important property
 * of this class.** An existing row (matched on `code`) is left entirely
 * untouched: `iban` is administrator-configured, so a re-seed after go-live
 * must never rewrite it toward absence and silently wipe a configured bank
 * account.
 *
 * Written with a manual lookup + `forceFill()` rather than Eloquent's
 * `firstOrCreate()` -- `code` is deliberately absent from PaymentMethod's
 * #[Fillable] list (it is this table's own seeder idempotency key, the
 * identical reasoning App\Models\ShippingCarrier already gives for omitting
 * its own `code`), so `firstOrCreate()`'s internal `fill()` on the create
 * branch would silently drop `code` and violate the column's `NOT NULL`
 * constraint. The same `new Model + forceFill()->save()` shape
 * `ShippingCarrierSeeder`/`SalesRegionSeeder::writeRegion()` already use for
 * their own non-fillable identity columns.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        if (PaymentMethod::query()->where('code', PaymentMethodCode::BankTransfer)->exists()) {
            return;
        }

        (new PaymentMethod)->forceFill([
            'code' => PaymentMethodCode::BankTransfer,
        ])->save();
    }
}
