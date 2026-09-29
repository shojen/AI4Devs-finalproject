<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Timestamped strictly later than 2026_09_07_090000_create_shipping_zones_table.php
     * (0033) and 2026_09_09_090000_create_shipping_carriers_table.php (0035), so both
     * FKs resolve on a fresh migrate and down() (rolled back first) drops this table
     * before either parent (story 0036, D-7/D-12).
     */
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);

            // restrictOnDelete on BOTH parents. Carriers are seeded and have no delete
            // path today (0035), so this one is defence in depth only -- but cascade
            // is the dangerous default and would silently destroy pricing config the
            // day a carrier delete is added. Cheap now, unrecoverable later.
            $table->foreignUuid('shipping_carrier_id')->constrained()->restrictOnDelete();

            // This one is load-bearing: it is the database half of the zone-delete
            // guard (D-5). See app/Actions/Shipping/DeleteShippingZone.php.
            $table->foreignUuid('shipping_zone_id')->constrained()->restrictOnDelete();

            // Grams precision. NOT ->unsigned(): deprecated on DECIMAL since MySQL
            // 8.0.17 and ignored by SQLite, so it would be a rule that exists in one
            // environment only. 'min:0' in validation is the enforcement.
            $table->decimal('min_weight_kg', 8, 3)->default(0);

            // NULL means "and above" -- an open-ended top tier (D-4). Every bracket
            // query MUST be null-aware: `where('max_weight_kg','>=',$w)` silently
            // drops every open-ended tier, because NULL >= 5 is NULL, not true.
            $table->decimal('max_weight_kg', 8, 3)->nullable();

            // decimal(10,2) verbatim from 0024 D-2's products.price -- same currency,
            // same minor unit, same epic. Casts to a STRING on the model.
            $table->decimal('price', 10, 2);

            // Free text by design (D-8): the prototype mixes '24h', '3-5 dias' and
            // '48-72h'. No structured day range represents all of those.
            $table->string('delivery_estimate', 50);

            $table->timestamps();

            // NO unique on (carrier, zone, min, max). It reads like "no duplicate
            // bracket" and is not: two named services may share a bracket (D-2/D-9),
            // and a unique cannot express range OVERLAP anyway -- only exact tuple
            // duplicates. It would be a real restriction bought for a false sense of
            // enforcement. See D-9 before "improving" this.
            //
            // NO hand-written index() on either FK column: constrained() already
            // leaves each column indexed, per migrations.md's "an FK column does not
            // also get an explicit index here" rule (ten confirming instances in this
            // schema). Verify with `php artisan db:table shipping_rates` -- expect
            // three indexes and no more.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
