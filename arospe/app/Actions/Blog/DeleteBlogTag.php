<?php

namespace App\Actions\Blog;

use App\Actions\Auth\LogRefusedPrivilegedAttempt;
use App\Models\BlogTag;

class DeleteBlogTag
{
    public function __construct(
        private readonly LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt,
    ) {}

    /**
     * Deletes unconditionally -- there is no in-use guard, by design (story 0059, D-8).
     *
     * PRD Epic 4 requires that deleting a tag removes it from every post that
     * used it. That is honoured by the DATABASE, not here: story 0061's
     * blog_post_tag pivot MUST declare
     *
     *     $table->foreignUuid('blog_tag_id')->constrained()->cascadeOnDelete();
     *
     * Do NOT copy sales_regions.parent_id's restrictOnDelete() habit onto that
     * column. restrictOnDelete() is correct there because a child row carries
     * independently-configured data a cascade would destroy; a blog_post_tag row
     * carries no state of its own and is worthless once either side is gone --
     * the passkeys.user_id case, not the sales_regions.parent_id one. A
     * restricting FK would turn every in-use tag deletion into a database error,
     * silently contradicting this story's shipped test that an in-use tag
     * deletes successfully.
     *
     * Authorizes `delete` on the target as its own first statement (D-12), then re-reads the row
     * and deletes that fresh copy -- the passed instance is untrusted
     * (docs/security/model-instance-trust.md). A row already gone is a ModelNotFoundException, not
     * a silent success. Never a soft delete: a soft delete is an UPDATE, so the pivot's cascade
     * would not fire (D-5).
     */
    public function __invoke(BlogTag $blogTag): bool
    {
        $this->logRefusedPrivilegedAttempt->authorize(
            'delete',
            $blogTag,
            targetType: 'blog_tag',
            targetId: $blogTag->id,
        );

        $current = BlogTag::query()->whereKey($blogTag->getKey())->firstOrFail();

        return (bool) $current->delete();
    }
}
