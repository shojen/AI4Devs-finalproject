<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0041 — a plain UUIDv7 greenfield table, copying
     * create_sales_regions_table's own pattern (see
     * docs/database/migrations.md#uuid-primary-keys): `uuid('id')->primary()`,
     * every string column length-capped, exactly one index beyond the
     * primary key (`email` UNIQUE — D-10), and NO explicit index on any FK
     * column, because this table has no FK of any kind (D-8: no
     * sales_region_id, no FK to `users` either — D-6, uniqueness is scoped
     * to this table alone).
     *
     * Deliberately absent (see the story's task file for the reasoning
     * behind each):
     * - `deleted_at` — deferred to story 0042's own
     *   add_soft_deletes_to_customers_table migration (D-2).
     * - Any foreign key — D-8.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 255)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('shipping_address_line1', 255)->nullable();
            $table->string('shipping_address_line2', 255)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_province', 100)->nullable();
            $table->string('shipping_country', 2)->nullable();   // ISO 3166-1 alpha-2, shape-validated only (D-9)
            $table->string('billing_address_line1', 255)->nullable();
            $table->string('billing_address_line2', 255)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->string('billing_province', 100)->nullable();
            $table->string('billing_country', 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
