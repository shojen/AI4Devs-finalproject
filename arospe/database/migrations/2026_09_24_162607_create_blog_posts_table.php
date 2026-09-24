<?php

use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
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
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // NOT NULL + restrictOnDelete() (D-2): a post has exactly one category, and deleting a
            // category any post -- trashed or not -- still references is refused by the database
            // itself, behind the application's own count guard in DeleteBlogCategory. Deliberately
            // no hand-written index: InnoDB creates the FK's own.
            $table->foreignUuid('blog_category_id')->constrained()->restrictOnDelete();

            // 255 in lockstep with BlogPost::TITLE_MAX_LENGTH and the validation `max:` (R-4).
            $table->string('title', BlogPost::TITLE_MAX_LENGTH);

            // Derived from `title` by the model's saving hook, never accepted from a caller (D-3).
            // Unique so two posts cannot silently compete for one URL; as wide as `title` because
            // Str::slug() is not length-preserving in general (R-4).
            $table->string('slug', BlogPost::TITLE_MAX_LENGTH)->unique();

            // Nullable: a Draft may be saved before its body is written (D-4). Published and
            // Scheduled require one, enforced by BlogPostValidationRules::bodyRules().
            $table->mediumText('body')->nullable();

            $table->string('status', 20)->default(BlogPostStatus::Draft->value);

            // One column whose meaning is fixed by the status beside it (D-6).
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // deleted_at leads: the SoftDeletingScope puts `deleted_at IS NULL` into every query on
            // this table, including the scheduler sweep of story 0064 (D-9).
            $table->index(['deleted_at', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
