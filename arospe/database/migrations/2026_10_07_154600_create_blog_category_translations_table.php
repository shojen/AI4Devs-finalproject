<?php

use App\Actions\Blog\BackfillBlogCategoryTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0072 (D-1, D-5). The precondition is checked BEFORE any DDL runs, because MySQL
     * auto-commits CREATE TABLE -- a throw afterwards would leave an orphan table blocking the
     * operator's re-run. Per-language uniqueness binds `normalized_name`, never `name`: that is
     * the column that was globally unique on blog_categories, and a raw-name index would reopen
     * the case/accent race the parent's index already closed.
     */
    public function up(): void
    {
        app(BackfillBlogCategoryTranslations::class)->assertCanBackfill();

        Schema::create('blog_category_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_category_id')->constrained('blog_categories')->cascadeOnDelete();
            $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('normalized_name', 255);
            $table->timestamps();

            // Named explicitly: the default `<table>_<cols>_unique` names are over MySQL's
            // 64-character identifier limit.
            $table->unique(
                ['blog_category_id', 'store_language_id'],
                'blog_category_translations_category_language_unique',
            );
            $table->unique(
                ['store_language_id', 'normalized_name'],
                'blog_category_translations_language_normalized_name_unique',
            );
        });

        app(BackfillBlogCategoryTranslations::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_category_translations');
    }
};
