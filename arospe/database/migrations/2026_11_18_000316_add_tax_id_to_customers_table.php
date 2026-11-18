<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The customer's tax ID is optional free text (story 0100a), so existing
     * rows stay valid with a null value.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tax_id', 40)->nullable()->after('billing_country');
        });
    }

    /**
     * Reverse the migrations. Data loss: dropping the column permanently discards every stored
     * customer tax ID.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('tax_id');
        });
    }
};
