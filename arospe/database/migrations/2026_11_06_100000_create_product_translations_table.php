<?php

use App\Actions\Products\BackfillProductTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0076 (D-2, D-11, D-13). The precondition is checked BEFORE any DDL runs, because MySQL
     * auto-commits CREATE TABLE. There is no unique on `name` (D-4) and no fold column.
     */
    public function up(): void
    {
        app(BackfillProductTranslations::class)->assertCanBackfill();

        Schema::create('product_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();
            $table->string('name', 255);
            $table->mediumText('description')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'store_language_id'], 'product_translations_product_language_unique');
        });

        app(BackfillProductTranslations::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_translations');
    }
};
