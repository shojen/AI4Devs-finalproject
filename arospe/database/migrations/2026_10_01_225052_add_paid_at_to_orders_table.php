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
     * the payment. Nullable with no default: `NULL` means "not paid through
     * the mark-as-paid action".
     *
     * No backfill statement, and that is a decision rather than an
     * omission: orders already paid before this story have no recorded
     * payment moment, and inventing one would be fabricated data, so
     * `NULL` stays the honest value for every pre-existing row.
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
