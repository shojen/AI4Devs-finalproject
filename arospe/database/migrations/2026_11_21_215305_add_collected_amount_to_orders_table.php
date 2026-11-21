<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Adds `orders.collected_amount` (written afterwards only by
     * SyncOrderCollection), then runs two data steps in this order: (1) carry over the collection of
     * every live (not voided), uncollected standard invoice of a paid-like order (`paid`, `refunded`,
     * `partially_refunded`), taking `collected_at`, method and type from the order's latest payment
     * row, else migration time, the order's method and a null type, with no actor; voided invoices
     * and correctives are untouched; (2) backfill `collected_amount`: net collected for orders with
     * invoices, `GREATEST(total - refunded_amount, 0)` for paid-like orders without invoices, 0.00
     * otherwise. `payment_status` is never written.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('collected_amount', 10, 2)->default(0.00)->after('refunded_amount');
        });

        $paidLike = [
            PaymentStatus::Paid->value,
            PaymentStatus::Refunded->value,
            PaymentStatus::PartiallyRefunded->value,
        ];
        $placeholders = implode(', ', array_fill(0, count($paidLike), '?'));
        $latestPayment = fn (string $column): string => "(SELECT p.{$column} FROM order_payments p WHERE p.order_id = o.id ORDER BY p.paid_at DESC, p.id DESC LIMIT 1)";

        DB::update(
            "UPDATE invoices i
            JOIN orders o ON o.id = i.order_id
            SET i.collection_carried_over = 1,
                i.collected_at = COALESCE({$latestPayment('paid_at')}, ?),
                i.collection_payment_method_id = COALESCE({$latestPayment('payment_method_id')}, o.payment_method_id),
                i.collection_type = {$latestPayment('type')},
                i.collected_by = NULL
            WHERE i.type = 'standard'
                AND i.voided_at IS NULL
                AND i.collected_at IS NULL
                AND o.payment_status IN ({$placeholders})",
            [now()->toDateTimeString(), ...$paidLike],
        );

        DB::update(
            "UPDATE orders o
            SET o.collected_amount = (
                SELECT COALESCE(SUM(i.total), 0)
                FROM invoices i
                LEFT JOIN invoices corrected ON corrected.id = i.corrects_invoice_id
                WHERE i.order_id = o.id
                    AND ((i.type = 'standard' AND i.collected_at IS NOT NULL)
                        OR (i.type = 'corrective' AND corrected.collected_at IS NOT NULL))
            )
            WHERE EXISTS (SELECT 1 FROM invoices x WHERE x.order_id = o.id)"
        );

        DB::update(
            "UPDATE orders o
            SET o.collected_amount = GREATEST(o.total - o.refunded_amount, 0)
            WHERE o.payment_status IN ({$placeholders})
                AND NOT EXISTS (SELECT 1 FROM invoices x WHERE x.order_id = o.id)",
            $paidLike,
        );
    }

    /**
     * Reverse the migrations. Data loss: dropping the column discards every collected amount, and
     * the carried-over invoice collections written by step 1 of up() are NOT reverted.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('collected_amount');
        });
    }
};
