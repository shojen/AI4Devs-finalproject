<?php

use App\Actions\Blog\BackfillBlogPostTranslations;
use App\Models\BlogPostTranslation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0078 (D-2, D-3, D-5, D-6). The precondition is checked BEFORE any DDL runs, because MySQL
     * auto-commits CREATE TABLE. The slug is unique per language, with no fold column and no
     * `deleted_at`: a trashed post's translations stay physically present and keep the slug reserved.
     */
    public function up(): void
    {
        app(BackfillBlogPostTranslations::class)->assertCanBackfill();

        Schema::create('blog_post_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('store_language_id')->constrained('store_languages')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->mediumText('body')->nullable();
            $table->timestamps();

            $table->unique(['blog_post_id', 'store_language_id'], 'blog_post_translations_post_language_unique');
            $table->unique(['store_language_id', 'slug'], BlogPostTranslation::LANGUAGE_SLUG_UNIQUE_INDEX);
        });

        app(BackfillBlogPostTranslations::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_post_translations');
    }
};
