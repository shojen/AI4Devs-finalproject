<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0045 -- a plain UUIDv7 greenfield table, later timestamp than
     * create_orders_table since it FKs into it; Laravel rolls back in
     * reverse timestamp order, so the dropIfExists pair below is symmetric
     * with no manual FK drops needed.
     *
     * `order_id` diverges from every other FK in this story ON PURPOSE:
     * cascadeOnDelete() -- an order_item has no independent meaning
     * without its order (D-11).
     *
     * `product_id` / `product_variant_id` are nullOnDelete(), NOT
     * restrictOnDelete(): catalog cleanup must never be blocked by
     * historical orders, and the snapshot columns below survive the null
     * (D-2).
     *
     * No hand-written $table->index() anywhere -- constrained() already
     * leaves every FK column indexed
     * (docs/database/migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here).
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots -- the line item's identity survives its catalog row being deleted.
            $table->string('product_name', 255);   // matches products.name
            $table->string('product_sku', 128);     // matches product_variants.sku, the longer of the two

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);   // price at the time of order -- the story's core invariant
            $table->decimal('line_total', 10, 2);

            $table->unsignedInteger('refunded_quantity')->default(0);   // D-3 -- column only; the logic is 0051/0052

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
