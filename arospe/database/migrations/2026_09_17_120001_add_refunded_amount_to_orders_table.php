<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0051 -- the running total `App\Actions\Orders\RecordRefund`
     * keeps consistent with the SUM of `refunds.amount` for the order.
     *
     * No backfill statement, and that is a decision rather than an
     * omission (docs/database/migrations.md's "when the new column's
     * default is wrong for existing rows, backfill in the same up()"
     * rule): no refund mechanism has ever existed before this story, so
     * `0.00` is the true value for every pre-existing order -- there is
     * nothing to backfill.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('refunded_amount', 10, 2)->default(0.00)->after('total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refunded_amount');
        });
    }
};
