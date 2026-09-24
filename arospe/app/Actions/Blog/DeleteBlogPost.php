<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\BlogPost;

class DeleteBlogPost
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Delete a blog post -- a SOFT delete (D-7): the row survives with `deleted_at` set, keeps its
     * slug reserved and its `blog_post_tag` rows (a soft delete is an UPDATE, so the pivot's cascade
     * never fires), and RestoreBlogPost brings it back whole.
     *
     * Authorizes `delete` on the target as its own first statement (D-13), then re-reads the row
     * through a default query and deletes that fresh copy -- the passed instance is untrusted
     * (docs/security/model-instance-trust.md). A row already gone, or already trashed, is a
     * ModelNotFoundException, not a silent success. Always the instance ->delete(), never the query
     * builder. There is no force-delete path anywhere (D-20).
     */
    public function __invoke(BlogPost $blogPost): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'delete',
            $blogPost,
            targetType: 'blog_post',
            targetId: $blogPost->id,
        );

        $current = BlogPost::query()->whereKey($blogPost->getKey())->firstOrFail();

        return (bool) $current->delete();
    }
}
