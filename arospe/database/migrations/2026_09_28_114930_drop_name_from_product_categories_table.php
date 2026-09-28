<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0070 (D-4). A second, separate migration, ordered strictly after
     * create_product_category_translations_table so the backfill above has already copied every
     * existing name into the child table before this column disappears. The unique index is
     * dropped explicitly before the column, per this repo's own migration convention.
     */
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropUnique(['name']);
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately NOT a perfect inverse (D-4): it restores the column and the index, but not
     * the values, which now live in product_category_translations. `nullable()` for exactly
     * that reason -- a non-nullable restore would fail against any existing row.
     */
    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->unique('name');
        });
    }
};
