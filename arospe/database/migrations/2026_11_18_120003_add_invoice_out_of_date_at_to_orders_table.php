<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Null means the order's invoices are current; story 0108 sets and clears
     * it. Existing rows stay null (no backfill).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('invoice_out_of_date_at')->nullable()->after('refunded_amount');
        });
    }

    /**
     * Reverse the migrations. Data loss: dropping the column discards every out-of-date marker.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('invoice_out_of_date_at');
        });
    }
};
