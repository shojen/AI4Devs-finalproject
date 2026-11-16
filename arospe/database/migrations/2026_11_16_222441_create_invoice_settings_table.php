<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The table holds one store-wide row (id 1); no row is inserted here.
     */
    public function up(): void
    {
        Schema::create('invoice_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('issuer_legal_name', 255)->nullable();
            $table->string('issuer_tax_id', 40)->nullable();
            $table->string('issuer_address_line1', 255)->nullable();
            $table->string('issuer_address_line2', 255)->nullable();
            $table->string('issuer_city', 100)->nullable();
            $table->string('issuer_postal_code', 20)->nullable();
            $table->string('issuer_province', 100)->nullable();
            $table->string('issuer_country', 2)->nullable();
            $table->foreignUuid('logo_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('primary_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->string('template', 30)->default('classic');
            $table->string('standard_prefix', 10)->default('F');
            $table->string('corrective_prefix', 10)->default('R');
            $table->string('standard_year_format', 4)->default('YYYY');
            $table->string('corrective_year_format', 4)->default('YYYY');
            $table->string('standard_separator', 3)->default('-');
            $table->string('corrective_separator', 3)->default('-');
            $table->unsignedTinyInteger('standard_padding')->default(5);
            $table->unsignedTinyInteger('corrective_padding')->default(5);
            $table->boolean('reset_numbering_yearly')->default(true);
            $table->boolean('auto_issue_on_order')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_settings');
    }
};
