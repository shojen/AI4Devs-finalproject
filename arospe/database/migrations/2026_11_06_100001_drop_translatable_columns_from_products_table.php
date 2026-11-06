<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0076 (D-4). A separate migration, ordered strictly after create_product_translations_table
     * so the backfill has already copied every name and description. No dropUnique(): products.name
     * never had a unique index.
     */
    public function up(): void
    {
        // A product written by old code between the two migrations would lose its only copy of
        // the name and description.
        $hasUntranslatedProduct = DB::table('products')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('product_translations')
                ->whereColumn('product_translations.product_id', 'products.id'))
            ->exists();

        throw_if(
            $hasUntranslatedProduct,
            RuntimeException::class,
            'Cannot drop products.name/description: some products have no product_translations row. Re-run the backfill first.',
        );

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['name', 'description']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately NOT an inverse (D-12): the values now live per language on the child table.
     * Both columns come back nullable and empty; no index is re-added because none existed.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('name', 255)->nullable()->after('product_category_id');
            $table->mediumText('description')->nullable()->after('stock');
        });
    }
};
