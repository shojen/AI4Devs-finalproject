<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0084 -- the moment `App\Actions\Orders\MarkOrderAsPaid` recorded
     * the payment. Nullable with no default: `NULL` means "no recorded payment
     * moment" -- it does NOT mean "unpaid"; `payment_status` stays the source
     * of truth. The value survives a refund, so a set `paid_at` does not mean
     * "currently paid" either.
     *
     * No backfill statement, and that is a decision rather than an
     * omission: orders already paid before this story have no recorded
     * payment moment, and inventing one would be fabricated data, so
     * `NULL` stays the honest value for every pre-existing row.
     *
     * No database-level default or automatic stamping, because either would
     * date every existing row. No CHECK constraint: the repo has no
     * precedent, it would reject legacy and factory rows, and refunds keep
     * `paid_at` while moving `payment_status`. No index yet: `created_at` is
     * unindexed too and a `COALESCE` over both columns could not use one;
     * revisit together with the dashboard income query when a measured
     * query exceeds its budget.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('refunded_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('paid_at');
        });
    }
};
