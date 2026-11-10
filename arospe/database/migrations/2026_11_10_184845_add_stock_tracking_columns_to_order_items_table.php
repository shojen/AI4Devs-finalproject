<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0088 -- the stock-tracking columns of an order line.
     *
     * Backfill (D6): a legacy order's units were never deducted from stock, so
     * every existing row gets `stock_undeducted_quantity = quantity -
     * refunded_quantity` and `stock_refunded_not_out_quantity =
     * refunded_quantity`; nothing is ever returned for it.
     *
     * Backfill (D9): `ordered_as_variant` is true where the line has a variant
     * id, or where the `product_sku` snapshot differs from the product's
     * current SKU (a deleted variant). Accepted blind spots: a plain line whose
     * product SKU was edited later is flagged, and a deleted variant whose SKU
     * equalled its product's SKU is not.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('ordered_as_variant')->default(false)->after('product_variant_id');
            $table->unsignedInteger('stock_undeducted_quantity')->default(0)->after('refunded_quantity');
            $table->unsignedInteger('stock_refunded_not_out_quantity')->default(0)->after('stock_undeducted_quantity');
        });

        DB::statement('UPDATE order_items SET stock_undeducted_quantity = GREATEST(CAST(quantity AS SIGNED) - CAST(refunded_quantity AS SIGNED), 0),stock_refunded_not_out_quantity = refunded_quantity');

        DB::statement(
            'UPDATE order_items LEFT JOIN products ON products.id = order_items.product_id '
            .'SET order_items.ordered_as_variant = 1 '
            .'WHERE order_items.product_variant_id IS NOT NULL '
            .'OR (products.id IS NOT NULL AND order_items.product_sku <> products.sku)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['ordered_as_variant', 'stock_undeducted_quantity', 'stock_refunded_not_out_quantity']);
        });
    }
};
