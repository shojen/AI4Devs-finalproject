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
     * Story 0078 (D-7). A separate migration, ordered strictly after create_blog_post_translations_table
     * so the backfill has already copied every title, slug and body. The unique index on `slug` is
     * dropped before the column.
     */
    public function up(): void
    {
        // DB::table() sees trashed posts too: a post written by old code between the two
        // migrations would lose its only copy of the content.
        $hasUntranslatedPost = DB::table('blog_posts')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('blog_post_translations')
                ->whereColumn('blog_post_translations.blog_post_id', 'blog_posts.id'))
            ->exists();

        throw_if(
            $hasUntranslatedPost,
            RuntimeException::class,
            'Cannot drop blog_posts.title/slug/body: some blog posts have no blog_post_translations row. Re-run the backfill first.',
        );

        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropUnique('blog_posts_slug_unique');
            $table->dropColumn(['title', 'slug', 'body']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately NOT an inverse (D-7): the values now live per language on the child table. All
     * three columns come back nullable and empty; the unique index is not re-added.
     */
    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->string('title', 255)->nullable()->after('blog_category_id');
            $table->string('slug', 255)->nullable()->after('title');
            $table->mediumText('body')->nullable()->after('slug');
        });
    }
};
