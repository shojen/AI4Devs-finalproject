<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0076a (D-5, D-6, D-9b, D-11b, D-31, D-34). No backfill: existing rows get NULL.
     */
    public function up(): void
    {
        Schema::table('product_translations', function (Blueprint $table): void {
            $table->string('slug', 160)->nullable()->after('description');
            $table->string('meta_title', 160)->nullable()->after('slug');
            $table->string('meta_description', 500)->nullable()->after('meta_title');

            $table->unique(['store_language_id', 'slug'], 'product_translations_language_slug_unique');
        });

        // D-34: after a down() the explicit FK-supporting index survives the new composite; it is
        // redundant now, so drop it to keep exactly three indexes. A no-op on a fresh migrate,
        // where MySQL already dropped the index it auto-created in 0076.
        if (Schema::hasIndex('product_translations', 'product_translations_store_language_id_foreign')) {
            Schema::table('product_translations', function (Blueprint $table): void {
                $table->dropIndex('product_translations_store_language_id_foreign');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * D-11b/D-34: the composite unique is the only index supporting the store_language_id foreign
     * key, so a plain index is restored first (dropping the unique otherwise fails with 1553).
     */
    public function down(): void
    {
        Schema::table('product_translations', function (Blueprint $table): void {
            $table->index('store_language_id', 'product_translations_store_language_id_foreign');
        });

        Schema::table('product_translations', function (Blueprint $table): void {
            $table->dropUnique('product_translations_language_slug_unique');
            $table->dropColumn(['slug', 'meta_title', 'meta_description']);
        });
    }
};
