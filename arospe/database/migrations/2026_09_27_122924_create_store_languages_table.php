<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The greenfield UUID create_* pattern (docs/database/migrations/uuid-primary-keys.md),
     * matching create_sales_regions_table / create_media_table -- story 0068, D1. No FK column
     * exists on this table, so the "an FK column does not also get an explicit index" rule does
     * not apply here; it will apply to stories 0070+ when they add store_language_id to their
     * own tables.
     */
    public function up(): void
    {
        Schema::create('store_languages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_languages');
    }
};
