<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0045 -- a plain UUIDv7 greenfield table, copying
     * create_sales_regions_table's own pattern (see
     * docs/database/migrations.md#uuid-primary-keys): `uuid('id')->primary()`,
     * every string column length-capped, every money-like column `decimal`,
     * `foreignUuid()` + `constrained()` for every FK, and NO hand-written
     * `$table->index()` anywhere -- `constrained()` already leaves every FK
     * column indexed (docs/database/migrations.md#an-fk-column-does-not-also-get-an-explicit-index-here).
     *
     * Every FK is written against a table that already exists at the time
     * this migration runs (DR-1 in the story's own task file):
     * `customers` (story 0041), `sales_regions` (task 0016), `shipping_rates`
     * (story 0036), `payment_methods` (story 0038).
     *
     * `customer_id` / `sales_region_id` / `shipping_rate_id` /
     * `payment_method_id` all `restrictOnDelete()` -- deleting a
     * catalog/configuration row must never silently destroy or orphan
     * historical order data.
     *
     * `order_number` is the only non-FK index (UNIQUE) -- D-1. No index on
     * `status`/`payment_status` -- the same low-cardinality-token argument
     * `users.status`/`sales_regions.kind` already establish. No
     * `deleted_at` -- orders are never deleted this phase; `Cancelled` is a
     * `status` value, not a soft delete.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number', 20)->unique();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();

            $table->string('status', 20)->default(OrderStatus::Pending->value);
            $table->string('payment_status', 20)->default(PaymentStatus::PendingPayment->value);

            // Nullable, and NULL at creation: resolution is a sibling story's job (D-9).
            $table->foreignUuid('sales_region_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('shipping_rate_id')->nullable()->constrained()->restrictOnDelete();

            // NOT nullable: PRD §3.2 requires every order to reference a configured method.
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();

            $table->decimal('tax_rate', 6, 3)->nullable();   // snapshot; mirrors sales_regions.rate
            $table->decimal('subtotal', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('shipping_amount', 10, 2);
            $table->decimal('total', 10, 2);

            $table->boolean('flagged_for_review')->default(false);   // D-10

            // Address snapshot -- frozen at order time, never a live join (D-4).
            // Lengths mirror `customers` column-for-column.
            $table->string('shipping_address_line1', 255)->nullable();
            $table->string('shipping_address_line2', 255)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_province', 100)->nullable();
            $table->string('shipping_country', 2)->nullable();
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
        Schema::dropIfExists('orders');
    }
};
