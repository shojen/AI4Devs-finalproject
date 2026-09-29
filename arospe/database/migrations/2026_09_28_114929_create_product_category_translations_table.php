<?php

use App\Actions\ProductCategories\BackfillProductCategoryTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0070 (D-1, D-2, D-3, D-7, D-11, D-16). The precondition is checked BEFORE any DDL
     * runs, because MySQL auto-commits CREATE TABLE -- a throw afterwards would leave an orphan
     * table blocking the operator's re-run (D-16). The backfill itself is an extracted,
     * container-resolved class rather than inline logic, so the part that can actually be wrong
     * (one row per category, the right language, byte-identical names) is directly testable
     * (D-11).
     */
    public function up(): void
    {
        app(BackfillProductCategoryTranslations::class)->assertCanBackfill();

        Schema::create('product_category_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();
            $table->string('name', 255);
            $table->timestamps();

            // Q2 decided as (a) -- uniqueness binds in every store language, not only the
            // default (D-7). Named explicitly: the default `<table>_<col1>_<col2>_unique` name
            // for this pair is 74 characters, over MySQL's 64-character identifier limit.
            $table->unique(
                ['product_category_id', 'store_language_id'],
                'product_category_translations_category_language_unique',
            );
            $table->unique(['store_language_id', 'name']);
        });

        app(BackfillProductCategoryTranslations::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_category_translations');
    }
};
