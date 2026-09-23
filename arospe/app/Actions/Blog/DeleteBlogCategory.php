<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\BlogCategory;

class DeleteBlogCategory
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Delete a blog category (a hard delete, D-3).
     *
     * Authorizes `delete` on the target as its own first statement (D-13), then re-reads the row
     * and deletes that fresh copy -- the passed instance is untrusted
     * (docs/security/model-instance-trust.md). A row already gone is a ModelNotFoundException,
     * not a silent success.
     *
     * This is its own file so story 0061 extends THIS action, in place, with the hard block that
     * refuses deletion while any post still uses the category (D-10). Nothing can use a category
     * yet -- `blog_posts` does not exist -- so there is deliberately no guard here today.
     */
    public function __invoke(BlogCategory $blogCategory): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'delete',
            $blogCategory,
            targetType: 'blog_category',
            targetId: $blogCategory->id,
        );

        $current = BlogCategory::query()->findOrFail($blogCategory->getKey());

        return (bool) $current->delete();
    }
}
