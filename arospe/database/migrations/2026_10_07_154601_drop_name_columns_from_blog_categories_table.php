<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0072 (D-2). A second, separate migration, ordered strictly after
     * create_blog_category_translations_table so the backfill has already copied every existing
     * name into the child table before these columns disappear. The unique index is dropped
     * explicitly before the column.
     */
    public function up(): void
    {
        Schema::table('blog_categories', function (Blueprint $table): void {
            $table->dropUnique(['normalized_name']);
            $table->dropColumn(['name', 'normalized_name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately NOT an inverse (D-11): the values now live per language on the child table, and
     * a parent row may hold zero, one or several translations, so there is no single value to
     * restore. Both columns come back nullable, and the UNIQUE index is NOT re-added -- over a
     * column every row shows as NULL it would protect nothing (NULLs are exempt from uniqueness)
     * while misrepresenting the state as restored.
     */
    public function down(): void
    {
        Schema::table('blog_categories', function (Blueprint $table): void {
            $table->string('name', 255)->nullable()->after('id');
            $table->string('normalized_name', 255)->nullable()->after('name');
        });
    }
};
