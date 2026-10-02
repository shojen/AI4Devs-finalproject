<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0084 (D-4, reworked) -- a plain UUIDv7 greenfield table: the
     * record of the payment an administrator marked against an order. It
     * replaces the first design's `orders.paid_at` column (never merged).
     *
     * One payment per order: `order_id` is UNIQUE, so a second payment row
     * for the same order is rejected by the database itself, not only by
     * the action. The unique index is declared on the column BEFORE the
     * foreign key is added, so MySQL reuses it as the FK's supporting index
     * and no redundant `order_payments_order_id_foreign` index is created.
     *
     * `order_id` and `payment_method_id` -- restrictOnDelete(), never a
     * cascade (matching `refunds.order_item_id`): a payment is a financial
     * fact, so neither deleting an order nor deleting a payment method may
     * silently destroy it.
     *
     * `type` is a plain varchar(20) backed by App\Enums\OrderPaymentType
     * (not a database ENUM), NOT NULL with no default: every payment must
     * state how it was made. `paid_at` is NOT NULL with no default either --
     * the action always supplies the moment explicitly.
     *
     * `recorded_by` -- the user who marked the order as paid. NULLABLE so a
     * future system or checkout writer (no signed-in user) may leave it
     * empty; restrictOnDelete() (matching `refunds.refunded_by`) so a user who
     * recorded a payment cannot be deleted out from under it. It is an interim
     * audit trail: a later story replaces it with a movements log table.
     *
     * No backfill: orders already Paid before this table existed simply have
     * no payment row (a valid legacy state, `Order::$payment` is null). This
     * migration rewrites no existing row.
     *
     * No hand-written index anywhere, and none on `paid_at`: nothing filters
     * or sorts payments by it yet.
     */
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);
            $table->timestamp('paid_at');

            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
