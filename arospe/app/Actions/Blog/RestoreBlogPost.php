<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\BlogPost;

class RestoreBlogPost
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Restore a soft-deleted blog post -- DeleteBlogPost's mirror image (D-20).
     *
     * Authorizes `restore` on the target as its own first statement (D-13); BlogPostPolicy maps that
     * ability to `blog.edit`, so an actor holding only `blog.delete` can hide a post but not bring it
     * back. The caller resolves the target with `BlogPost::withTrashed()`, since a default query
     * cannot see a trashed row; the action then re-reads it the same way. A row that is gone is a
     * ModelNotFoundException. Restoring a live post is a harmless no-op.
     *
     * Needs no category-existence guard: `blog_posts.blog_category_id` is `restrictOnDelete()` and a
     * trashed post is still a physical row holding a live foreign key, so the database itself refuses
     * to delete a category any trashed post references. It does not re-announce a previously
     * published post either: it dispatches nothing, and `status` never changed, so there is no
     * transition for UpdateBlogPost's guard to see (D-19).
     */
    public function __invoke(BlogPost $blogPost): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'restore',
            $blogPost,
            targetType: 'blog_post',
            targetId: $blogPost->id,
        );

        // Restore a fresh copy, never the caller's instance: restore() ends in save(), which writes the
        // whole dirty set, so an attribute the caller dirtied (body, slug, status) would ride along.
        // withTrashed() because a default query cannot see the trashed row this action exists for.
        $current = BlogPost::withTrashed()->whereKey($blogPost->getKey())->firstOrFail();

        return (bool) $current->restore();
    }
}
