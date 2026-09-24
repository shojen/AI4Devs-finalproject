<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blog_post_tag', function (Blueprint $table): void {
            // Both sides cascade (D-8): a pivot row carries no state of its own and is worthless once
            // either parent is gone. story 0059's DeleteBlogTag is unconditional BECAUSE blog_tag_id
            // cascades here -- never restrictOnDelete(). A post's soft delete is an UPDATE, so the
            // blog_post_id cascade does not fire for it and a trashed post keeps its tags (D-7c).
            $table->foreignUuid('blog_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('blog_post_id')->constrained()->cascadeOnDelete();

            // blog_tag_id leads so the tag-filtered post list is a contiguous range scan of the
            // clustered index. No surrogate id, no timestamps, no position (D-8); no hand-written
            // index on either FK column -- InnoDB creates blog_post_id's own.
            $table->primary(['blog_tag_id', 'blog_post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_post_tag');
    }
};
