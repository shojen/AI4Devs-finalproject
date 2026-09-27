<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Story 0064b -- `blog_posts.created_by`, the first author attribution on a
     * blog post (D-7). Nullable FK to `users.id`, following the `media.uploaded_by`
     * / `refunds.refunded_by` precedent.
     *
     * `constrained('users')` is mandatory, not stylistic: the column name does not
     * match the table, and Laravel would infer a `created_bies` table.
     *
     * `nullOnDelete()`, not restrict (D-7): a post is meaningful without its
     * creator (unlike `refunds.refunded_by`, a financial record). It will
     * essentially never fire, because `users` is soft-deleted and a soft delete is
     * an UPDATE: the column stays populated and `BlogPost::creator()` resolves
     * `null` through the SoftDeletingScope. The notifier this story adds treats
     * "null relation" as "no reachable creator" for both causes (a NULL column and
     * a trashed user) and nothing branches on the distinction -- both fall back to
     * the `blog.edit` holders (D-8).
     *
     * No backfill: every pre-existing row stays NULL. Who created a legacy post is
     * unknowable, and a guess would be invented data. NULL is a first-class state
     * the notifier handles (D-8), not a gap to fill in.
     *
     * No hand-written index on `created_by` -- InnoDB creates the FK's own, and
     * nothing filters by creator
     * (docs/database/migrations/uuid-primary-keys.md#an-fk-column-does-not-also-get-an-explicit-index-here).
     *
     * Position: ->after('published_at'). 0078's migration drops title/slug/body
     * and re-adds them on down() with ->after('blog_category_id'), so there is no
     * column-order collision -- only migration timestamp order matters, and this
     * migration sorts before 0078's (D-7).
     */
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            // NOT `constrained()` with no argument: Laravel would infer a `created_bies` table.
            // nullOnDelete, not restrict (D-7). No explicit index: InnoDB creates the FK's own.
            $table->foreignUuid('created_by')->nullable()->after('published_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
