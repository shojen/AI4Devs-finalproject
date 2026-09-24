<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\BlogCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class DeleteBlogCategory
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Delete a blog category (a hard delete, D-3), unless a post still uses it.
     *
     * Authorizes `delete` on the target as its own first statement (D-13), then re-reads the row
     * and deletes that fresh copy -- the passed instance is untrusted
     * (docs/security/model-instance-trust.md). A row already gone is a ModelNotFoundException,
     * not a silent success.
     *
     * The in-use guard (story 0061, D-18) is a hard block with a count and NO confirm-and-proceed
     * path, at any privilege level: it is a domain invariant ("would the data still be valid"), not
     * an authorization rule, so a Super Admin is refused identically. The refusal is a
     * ValidationException keyed on `blogCategoryId` -- that key is a hand-off contract: story 0062's
     * delete modal binds its `@error` outlet to it verbatim.
     *
     * The count is unfiltered by status (a draft still occupies the category) AND unfiltered by
     * `deleted_at`: a trashed post is still a physical row holding a live foreign key, so the
     * `restrictOnDelete()` FK refuses regardless (D-7d). An unscoped count would pass this guard, hit
     * the FK, and report "used by 0 posts".
     */
    public function __invoke(BlogCategory $blogCategory): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'delete',
            $blogCategory,
            targetType: 'blog_category',
            targetId: $blogCategory->id,
        );

        $current = BlogCategory::query()->whereKey($blogCategory->getKey())->firstOrFail();

        $inUseCount = $current->posts()->withTrashed()->count();

        if ($inUseCount > 0) {
            throw $this->blockedByPosts($current, $inUseCount);
        }

        try {
            // deleteOrFail(), not delete(): Larastan flags a plain delete() here as a dead catch,
            // because Model::delete() carries no traceable @throws. deleteOrFail() wraps the same
            // DELETE in DB::transaction(), which does propagate QueryException -- the identical
            // choice App\Actions\ProductCategories\DeleteProductCategory documents.
            return (bool) $current->deleteOrFail();
        } catch (QueryException $e) {
            // 1451 (ER_ROW_IS_REFERENCED_2) is blog_posts.blog_category_id refusing under
            // restrictOnDelete(): a post was assigned to this category between the count above and
            // this delete. Narrowed from the whole 23000 class so an unrelated integrity error
            // raised by a future `deleting` listener is never reported as a post count. The count is
            // the primary guard; the FK is the last word -- the same relationship CreateUser has with
            // the users.email unique index.
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw $this->blockedByPosts($current, $current->posts()->withTrashed()->count());
            }

            throw $e;
        }
    }

    private function blockedByPosts(BlogCategory $blogCategory, int $count): ValidationException
    {
        // max(1, ...): a PRESENTATION floor, not a claim about how many posts reference the row.
        // deleteOrFail() runs the DELETE in its own transaction, so a failed 1451 rolls back a post
        // that a racing `deleting` listener assigned inside it, and the recount reads 0. The floor
        // turns that into a coherent "used by 1 post". The primary guard above only ever reaches
        // here with a positive count and never needs it.
        $count = max(1, $count);

        $this->logRefusedPrivilegedAttempt->log(
            Auth::user(),
            'category_still_in_use',
            'blog_category',
            $blogCategory->id,
        );

        return ValidationException::withMessages([
            'blogCategoryId' => trans_choice('blog.categories.delete_blocked', $count, ['count' => $count]),
        ]);
    }
}
