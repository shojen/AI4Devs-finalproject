<?php

use App\Actions\Blog\BackfillBlogTagTranslations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0074 (D-1, D-10). The precondition is checked BEFORE any DDL runs, because MySQL
     * auto-commits CREATE TABLE. Per-language uniqueness binds `normalized_name`, never `name`:
     * that is the column that was globally unique on blog_tags.
     */
    public function up(): void
    {
        app(BackfillBlogTagTranslations::class)->assertCanBackfill();

        Schema::create('blog_tag_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_tag_id')->constrained('blog_tags')->cascadeOnDelete();
            $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 255);
            $table->timestamps();

            $table->unique(['blog_tag_id', 'store_language_id'], 'blog_tag_translations_tag_language_unique');
            $table->unique(
                ['store_language_id', 'normalized_name'],
                'blog_tag_translations_language_normalized_name_unique',
            );
        });

        app(BackfillBlogTagTranslations::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_tag_translations');
    }
};
