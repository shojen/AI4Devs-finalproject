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
     * Story 0074 (D-2). A separate migration, ordered strictly after
     * create_blog_tag_translations_table so the backfill has already copied every name. The unique
     * index is dropped explicitly before the column.
     */
    public function up(): void
    {
        // A tag minted by old code between the two migrations would lose its only copy of the name.
        $hasUntranslatedTag = DB::table('blog_tags')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('blog_tag_translations')
                ->whereColumn('blog_tag_translations.blog_tag_id', 'blog_tags.id'))
            ->exists();

        throw_if(
            $hasUntranslatedTag,
            RuntimeException::class,
            'Cannot drop blog_tags.name: some tags have no blog_tag_translations row. Re-run the backfill first.',
        );

        Schema::table('blog_tags', function (Blueprint $table): void {
            $table->dropUnique(['normalized_name']);
            $table->dropColumn(['name', 'normalized_name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately NOT an inverse (D-11): the values now live per language on the child table.
     * Both columns come back nullable and the UNIQUE index is not re-added, matching story 0072.
     */
    public function down(): void
    {
        Schema::table('blog_tags', function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('id');
            $table->string('normalized_name', 255)->nullable()->after('name');
        });
    }
};
